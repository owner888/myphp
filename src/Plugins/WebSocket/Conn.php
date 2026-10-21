<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

use RuntimeException;

/**
 * A WebSocket connection (RFC 6455; mygo's conn), of a client unless
 * $server is set (the tests' server). Nothing blocks: pump() reads what
 * arrived and writes what it can, readMessage() returns a message once a
 * whole one arrived, and frames written wait in a buffer until the
 * transport takes them.
 *
 * @internal
 */
final class Conn
{
    // Opcodes (RFC 6455, section 5.2).
    public const OP_CONTINUATION = 0x0, OP_TEXT = 0x1, OP_BINARY = 0x2, OP_CLOSE = 0x8, OP_PING = 0x9, OP_PONG = 0xa;
    // Close codes (RFC 6455, section 7.4.1).
    public const CLOSE_NORMAL = 1000, CLOSE_GOING_AWAY = 1001, CLOSE_PROTOCOL_ERROR = 1002, CLOSE_NO_STATUS = 1005,
        CLOSE_ABNORMAL = 1006, CLOSE_INVALID_DATA = 1007, CLOSE_TOO_BIG = 1009;

    /** What arrived and is not read yet, and what is written but not taken by the transport. */
    private string $in;
    private string $out = '';
    /** Bytes ever queued, and taken by the transport. */
    private int $queued = 0;
    private int $written = 0;
    private bool $eof = false;
    private bool $closeSent = false;
    private bool $done = false;
    /** The message whose frames are arriving: its opcode (0 for none) and its data so far. */
    private int $msgOp = 0;
    private string $msg = '';
    /** When the connection is dropped, after a close the other side doesn't answer. */
    private ?float $deadline = null;
    /** The sequence number of the page's next message: messages are written in the order the page sent them. */
    public int $next = 1;

    public function __construct(private readonly Transport $t, private readonly bool $server, private readonly int $max, string $buffered = '')
    {
        $this->in = $buffered;
    }

    /** Sec-WebSocket-Accept of $key. */
    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    }

    /** Reads what arrived and writes what it can; drops the connection once a close went unanswered too long. */
    public function pump(): void
    {
        if ($this->done) {
            return;
        }
        if ($this->deadline !== null && microtime(true) >= $this->deadline) {
            $this->terminate();
            return;
        }
        $this->flush();
        while (!$this->eof) {
            $data = $this->t->read();
            if ($data === null) {
                $this->eof = true;
            } elseif ($data === '') {
                break;
            } else {
                $this->in .= $data;
            }
        }
    }

    /** Writes what the transport takes of the frames waiting. */
    public function flush(): void
    {
        while (!$this->done && $this->out !== '') {
            $n = $this->t->write($this->out);
            if ($n === 0) {
                return;
            }
            $this->out = (string)substr($this->out, $n);
            $this->written += $n;
        }
    }

    /** How many bytes the transport has taken, which a frame's offset (writeFrame's result) says it was written by. */
    public function written(): int
    {
        return $this->written;
    }

    public function done(): bool
    {
        return $this->done;
    }

    /**
     * The next text, binary or close message, answering pings: [opcode,
     * data], or null until a whole one arrived. It throws a Failure for
     * what the other side got wrong, and a RuntimeException once the
     * connection is gone.
     *
     * @return ?array{0: int, 1: string}
     */
    public function readMessage(): ?array
    {
        while (true) {
            $frame = $this->readFrame();
            if ($frame === null) {
                return null;
            }
            [$fin, $op, $p] = $frame;
            switch ($op) {
                case self::OP_PING:
                    try {
                        $this->writeFrame(self::OP_PONG, $p);
                    } catch (ErrClosed) {
                    }
                    continue 2;
                case self::OP_PONG:
                    continue 2;
                case self::OP_CLOSE:
                    return [self::OP_CLOSE, $p];
                case self::OP_CONTINUATION:
                    if ($this->msgOp === 0) {
                        throw Failure::protocol('unexpected continuation frame');
                    }
                    break;
                case self::OP_TEXT:
                case self::OP_BINARY:
                    if ($this->msgOp !== 0) {
                        throw Failure::protocol('new message before the last one ended');
                    }
                    $this->msgOp = $op;
                    break;
                default:
                    throw Failure::protocol("unknown opcode $op");
            }
            if (strlen($this->msg) + strlen($p) > $this->max) {
                throw new Failure(self::CLOSE_TOO_BIG, "message over {$this->max} bytes");
            }
            $this->msg .= $p;
            if ($fin) {
                [$op, $data] = [$this->msgOp, $this->msg];
                $this->msgOp = 0;
                $this->msg = '';
                if ($op === self::OP_TEXT && !mb_check_encoding($data, 'UTF-8')) {
                    throw new Failure(self::CLOSE_INVALID_DATA, 'text message is not valid UTF-8');
                }
                return [$op, $data];
            }
        }
    }

    /** A whole frame: [fin, opcode, payload], or null until one arrived. @return ?array{0: bool, 1: int, 2: string} */
    private function readFrame(): ?array
    {
        if ($this->done) {
            throw new RuntimeException('websocket: connection closed');
        }
        $have = strlen($this->in);
        if ($have < 2) {
            return $this->more($have === 0 ? 'EOF' : 'unexpected EOF');
        }
        $b0 = ord($this->in[0]);
        $b1 = ord($this->in[1]);
        $fin = ($b0 & 0x80) !== 0;
        $op = $b0 & 0x0f;
        if (($b0 & 0x70) !== 0) {
            throw Failure::protocol('reserved bits set in a frame');
        }
        if ((($b1 & 0x80) !== 0) !== $this->server) {
            throw Failure::protocol('wrong frame masking');
        }
        $n = $b1 & 0x7f;
        $at = 2;
        if ($n === 126) {
            if ($have < 4) {
                return $this->more('unexpected EOF');
            }
            $n = unpack('n', $this->in, 2)[1];
            $at = 4;
        } elseif ($n === 127) {
            if ($have < 10) {
                return $this->more('unexpected EOF');
            }
            $n = unpack('J', $this->in, 2)[1];
            if ($n < 0) {
                throw Failure::protocol('invalid frame length');
            }
            $at = 10;
        }
        if ($op >= self::OP_CLOSE && (!$fin || $n > 125)) {
            throw Failure::protocol('invalid control frame');
        }
        if ($n > $this->max) {
            throw new Failure(self::CLOSE_TOO_BIG, "message over {$this->max} bytes");
        }
        $mask = '';
        if ($this->server) {
            if ($have < $at + 4) {
                return $this->more('unexpected EOF');
            }
            $mask = substr($this->in, $at, 4);
            $at += 4;
        }
        if ($have < $at + $n) {
            return $this->more('unexpected EOF');
        }
        $payload = substr($this->in, $at, $n);
        $this->in = (string)substr($this->in, $at + $n);
        if ($mask !== '') {
            $payload = self::mask($payload, $mask);
        }
        return [$fin, $op, $payload];
    }

    /** More bytes are needed: null while they may come, the error once the other side closed. */
    private function more(string $eof): ?array
    {
        if ($this->eof) {
            throw new RuntimeException($eof);
        }
        return null;
    }

    private static function mask(string $p, string $mask): string
    {
        return $p === '' ? '' : $p ^ substr(str_repeat($mask, intdiv(strlen($p), 4) + 1), 0, strlen($p));
    }

    /**
     * Writes a whole message in one frame, and returns the offset it ends
     * at in what the transport takes (written()). Nothing is written after
     * a close frame.
     */
    public function writeFrame(int $op, string $p): int
    {
        if ($this->closeSent || $this->done) {
            throw new ErrClosed;
        }
        if ($op === self::OP_CLOSE) {
            $this->closeSent = true;
        }
        $maskBit = $this->server ? 0 : 0x80;
        $n = strlen($p);
        $b = chr(0x80 | $op) . match (true) {
            $n <= 125 => chr($maskBit | $n),
            $n <= 0xffff => chr($maskBit | 126) . pack('n', $n),
            default => chr($maskBit | 127) . pack('J', $n),
        };
        if ($this->server) {
            $b .= $p;
        } else {
            $mask = random_bytes(4);
            $b .= $mask . self::mask($p, $mask);
        }
        $this->out .= $b;
        $this->queued += strlen($b);
        $this->flush();
        return $this->queued;
    }

    /** Sends a close frame, unless one was sent. A code of CLOSE_NO_STATUS sends none. */
    public function writeClose(int $code, string $reason = ''): int
    {
        return $this->writeFrame(self::OP_CLOSE, $code === self::CLOSE_NO_STATUS ? '' : pack('n', $code) . $reason);
    }

    /** Whether a close frame was sent. */
    public function closing(): bool
    {
        return $this->closeSent;
    }

    /** The code and reason of a close frame's payload. @return array{0: int, 1: string} */
    public static function parseClose(string $p): array
    {
        if ($p === '') {
            return [self::CLOSE_NO_STATUS, ''];
        }
        if (strlen($p) === 1) {
            throw Failure::protocol('invalid close frame');
        }
        $code = unpack('n', $p)[1];
        if (!self::validCloseCode($code)) {
            throw Failure::protocol("invalid close code $code");
        }
        $reason = substr($p, 2);
        if (!mb_check_encoding($reason, 'UTF-8')) {
            throw new Failure(self::CLOSE_INVALID_DATA, 'close reason is not valid UTF-8');
        }
        return [$code, $reason];
    }

    /** Whether $code may be sent in a close frame. */
    public static function validCloseCode(int $code): bool
    {
        return $code >= 1000 && $code <= 1003 || $code >= 1007 && $code <= 1014 || $code >= 3000 && $code <= 4999;
    }

    /** Starts the closing handshake: the connection is dropped if the other side doesn't finish it within $timeout seconds. */
    public function close(int $code, string $reason, float $timeout): void
    {
        try {
            $this->writeClose($code, $reason);
        } finally {
            $this->deadline ??= microtime(true) + $timeout;
        }
    }

    /** Drops the connection. */
    public function terminate(): void
    {
        if (!$this->done) {
            $this->done = true;
            $this->t->close();
        }
    }
}
