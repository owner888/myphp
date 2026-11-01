<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;
use MyPHP\App;
use MyPHP\Logger;

/**
 * IINA's WebSocketServer of a plugin's: a WebSocket server on a TCP port
 * of all the machine's addresses, without TLS (IINA's NWListener of
 * NWProtocolWebSocket). Its state ("setup", "ready", "failed",
 * "cancelled") and each connection's ("preparing" as its handshake goes,
 * "ready", "failed", "cancelled") told as they change; a connection named
 * by a UUID; its messages (a text's or a binary's, whole) told as they
 * come, pings answered; what is sent sent as a binary message (IINA's).
 * Its sockets are looked at every POLL seconds where the app runs, and
 * by poll().
 */
final class WebSocketServer
{
    public const POLL = 0.02;
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MAX_MESSAGE = 64 << 20;

    public string $state = 'setup';
    /** @var resource|null */
    private $socket = null;
    /** @var array<string, array{socket: resource, in: string, ready: bool, message: string, opcode: int, peer: string}> the connections, by their UUID */
    private array $connections = [];
    private bool $polling = false;

    /**
     * @param Closure(string, ?array<string, string>): void $onState
     * @param Closure(string, array<string, ?string>): void $onConnection
     * @param Closure(string, string, ?array<string, string>): void $onConnectionState
     * @param Closure(string, string): void $onMessage
     */
    public function __construct(
        public readonly int $port,
        public readonly string $label,
        private readonly Closure $onState,
        private readonly Closure $onConnection,
        private readonly Closure $onConnectionState,
        private readonly Closure $onMessage,
    ) {}

    /** Listens: "ready", or "failed" with why (the port taken). */
    public function start(): void
    {
        $socket = @stream_socket_server("tcp://0.0.0.0:{$this->port}", $errno, $err, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['socket' => ['so_reuseport' => true]]));
        if ($socket === false) {
            $this->setState('failed', ['description' => "POSIXErrorCode($errno): $err", 'message' => $err]);
            return;
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->setState('ready');
        $this->schedule();
    }

    /** Stops listening and closes its connections: "cancelled". */
    public function stop(): void
    {
        foreach (array_keys($this->connections) as $id) { $this->drop($id, 'cancelled'); }
        if ($this->socket !== null) { fclose($this->socket); $this->socket = null; }
        if ($this->state !== 'cancelled') { $this->setState('cancelled'); }
    }

    /** Whether connection $id is there. */
    public function has(string $id): bool
    {
        return isset($this->connections[$id]);
    }

    /** Sends $data to connection $id as a binary message: null, else why it could not. */
    public function send(string $id, string $data): ?string
    {
        $c = $this->connections[$id] ?? null;
        if ($c === null || !$c['ready']) { return 'no such connection'; }
        return $this->write($id, self::frame(0x2, $data)) ? null : 'the connection is closed';
    }

    /** Takes the connections that came and what they sent. */
    public function poll(): void
    {
        if ($this->socket === null) { return; }
        while (($conn = @stream_socket_accept($this->socket, 0, $peer)) !== false) {
            stream_set_blocking($conn, false);
            $id = self::uuid();
            $this->connections[$id] = ['socket' => $conn, 'in' => '', 'ready' => false, 'message' => '', 'opcode' => 0, 'peer' => (string)$peer];
            Logger::debug("New connection: $id", 'ws-server');
            ($this->onConnection)($id, ['path' => (string)$peer]);
            $this->connState($id, 'preparing');
        }
        foreach (array_keys($this->connections) as $id) {
            $socket = $this->connections[$id]['socket'];
            $chunk = @fread($socket, 1 << 16);
            if ($chunk === false || ($chunk === '' && feof($socket))) {
                $this->drop($id, 'cancelled');
                continue;
            }
            if ($chunk === '') { continue; }
            $this->connections[$id]['in'] .= $chunk;
            $this->connections[$id]['ready'] ? $this->frames($id) : $this->handshake($id);
        }
    }

    /** Answers the HTTP upgrade request once it came whole: "ready", else "failed". */
    private function handshake(string $id): void
    {
        $in = $this->connections[$id]['in'];
        $end = strpos($in, "\r\n\r\n");
        if ($end === false) {
            if (strlen($in) > 16384) { $this->drop($id, 'failed', 'the request is too long'); }
            return;
        }
        $key = preg_match('/^Sec-WebSocket-Key:\s*(\S+)/mi', substr($in, 0, $end), $m) === 1 ? $m[1] : null;
        if ($key === null || preg_match('/^Upgrade:\s*websocket/mi', substr($in, 0, $end)) !== 1) {
            $this->write($id, "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n");
            $this->drop($id, 'failed', 'not a WebSocket request');
            return;
        }
        $accept = base64_encode(sha1($key . self::GUID, true));
        $this->write($id, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
        $this->connections[$id]['in'] = substr($in, $end + 4);
        $this->connections[$id]['ready'] = true;
        $this->connState($id, 'ready');
        $this->frames($id);
    }

    /** Reads the whole frames come: a message's told once its last frame came, a ping answered, a close closing. */
    private function frames(string $id): void
    {
        while (isset($this->connections[$id])) {
            $in = $this->connections[$id]['in'];
            if (strlen($in) < 2) { return; }
            $b0 = ord($in[0]); $b1 = ord($in[1]);
            $len = $b1 & 0x7f; $at = 2;
            if ($len === 126) { if (strlen($in) < 4) { return; } $len = unpack('n', substr($in, 2, 2))[1]; $at = 4; }
            elseif ($len === 127) { if (strlen($in) < 10) { return; } $len = unpack('J', substr($in, 2, 8))[1]; $at = 10; }
            $masked = ($b1 & 0x80) !== 0;
            if ($len > self::MAX_MESSAGE) { $this->drop($id, 'failed', 'the message is too large'); return; }
            if (strlen($in) < $at + ($masked ? 4 : 0) + $len) { return; }
            $mask = $masked ? substr($in, $at, 4) : '';
            $at += $masked ? 4 : 0;
            $payload = substr($in, $at, $len);
            if ($masked) { $payload ^= str_repeat($mask, intdiv($len, 4) + 1); $payload = substr($payload, 0, $len); }
            $this->connections[$id]['in'] = substr($in, $at + $len);
            $opcode = $b0 & 0x0f; $fin = ($b0 & 0x80) !== 0;
            switch ($opcode) {
                case 0x9: // a ping: its pong (IINA's)
                    Logger::debug("Ping ($id)", 'ws-server');
                    $this->write($id, self::frame(0xA, $payload));
                    break;
                case 0xA:
                    break;
                case 0x8: // a close: its close, the connection gone
                    $this->write($id, self::frame(0x8, substr($payload, 0, 2)));
                    $this->drop($id, 'cancelled');
                    return;
                default: // a text's or a binary's frame, or one continuing it: the message told once whole
                    if ($opcode !== 0) { $this->connections[$id]['opcode'] = $opcode; $this->connections[$id]['message'] = ''; }
                    $this->connections[$id]['message'] .= $payload;
                    if ($fin) {
                        $message = $this->connections[$id]['message'];
                        $this->connections[$id]['message'] = '';
                        Logger::debug("Data ($id)", 'ws-server');
                        ($this->onMessage)($id, $message);
                    }
            }
        }
    }

    private function write(string $id, string $bytes): bool
    {
        $s = $this->connections[$id]['socket'] ?? null;
        if ($s === null) { return false; }
        stream_set_blocking($s, true);
        $ok = @fwrite($s, $bytes) === strlen($bytes);
        stream_set_blocking($s, false);
        return $ok;
    }

    /** A frame of $opcode of $payload, whole and unmasked (a server's). */
    private static function frame(int $opcode, string $payload): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode) . match (true) {
            $len < 126 => chr($len),
            $len < 65536 => chr(126) . pack('n', $len),
            default => chr(127) . pack('J', $len),
        };
        return $head . $payload;
    }

    private function drop(string $id, string $state, ?string $why = null): void
    {
        $c = $this->connections[$id] ?? null;
        if ($c === null) { return; }
        unset($this->connections[$id]);
        @fclose($c['socket']);
        $this->connState($id, $state, $why === null ? null : ['description' => $why, 'message' => $why]);
    }

    /** @param ?array<string, string> $error */
    private function connState(string $id, string $state, ?array $error = null): void
    {
        Logger::debug("Connection $state ($id)", 'ws-server');
        ($this->onConnectionState)($id, $state, $error);
    }

    /** @param ?array<string, string> $error */
    private function setState(string $state, ?array $error = null): void
    {
        $this->state = $state;
        Logger::debug("Server $state", 'ws-server');
        ($this->onState)($state, $error);
    }

    /** Looks at its sockets again in a moment, while it listens and the app runs. */
    private function schedule(): void
    {
        if ($this->polling || !App::running()) { return; }
        $this->polling = true;
        App::after(self::POLL, function (): void {
            $this->polling = false;
            if ($this->socket === null) { return; }
            $this->poll();
            $this->schedule();
        });
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)));
    }
}
