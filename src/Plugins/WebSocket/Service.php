<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

use Generator;
use MyPHP\{CallContext, Channel, ChannelClosed};
use RuntimeException;
use Throwable;

/**
 * The WebSocket plugin's service (mygo's websocket service), bound as
 * "plugin:websocket": connect() opens a connection and streams what
 * happens to it, send() writes the page's messages on it in the order the
 * page sent them.
 *
 * @internal
 */
final class Service
{
    /** How soon a connection is looked at again while data flows, and at most while it is quiet. */
    private const POLL_BUSY = 0.001, POLL_IDLE = 0.025;
    /** The longest handshake response read. */
    private const MAX_HEAD = 1 << 20;

    /** @var array<string, Conn> the open connections, by their page and the id the page chose */
    private array $conns = [];
    private Options $opts;

    public function __construct(Options $opts)
    {
        $this->opts = clone $opts;
        if ($this->opts->maxMessageSize <= 0) { $this->opts->maxMessageSize = 64 << 20; }
        if ($this->opts->closeTimeout <= 0) { $this->opts->closeTimeout = 5.0; }
    }

    /** Identifies a connection: each page chooses ids for itself. */
    private static function key(CallContext $ctx, string $id): string
    {
        return spl_object_id($ctx) . "\0" . $id;
    }

    /**
     * Opens the connection and streams its events to the page until it
     * closes: {type: "open", protocol}, then "text" (text), "binary"
     * (data, base64) and finally "close" (code, reason, clean, error).
     *
     * @param array{id?: string, url?: string, protocols?: list<string>, headers?: list<array{0: string, 1: string}>} $opts
     * @param Channel $events
     */
    public function connect(CallContext $ctx, array $opts, Channel $events): Generator
    {
        try {
            yield from $this->run($ctx, $opts);
        } catch (ChannelClosed) {
            // the page stopped it
        }
    }

    /**
     * Sends the $seq-th message of the page on the connection $id, after
     * those before it, and returns once it is written: {type: "text",
     * text}, "binary" (data, base64), "close" (code, reason) or "skip" (a
     * message the page could not read).
     *
     * @param array{type?: string, text?: string, data?: string, code?: int, reason?: string} $m
     */
    public function send(CallContext $ctx, string $id, int $seq, array $m): Generator
    {
        $c = $this->conns[self::key($ctx, $id)] ?? throw new ErrClosed;
        while ($c->next !== $seq && !$c->done()) {
            yield Channel::wait(self::POLL_BUSY);
        }
        if ($c->done()) {
            throw new ErrClosed;
        }
        try {
            $end = match ($m['type'] ?? '') {
                'text' => $c->writeFrame(Conn::OP_TEXT, (string)($m['text'] ?? '')),
                'binary' => $c->writeFrame(Conn::OP_BINARY, self::bytes($m['data'] ?? '')),
                'skip' => null,
                'close' => $this->close($c, (int)($m['code'] ?? 0), (string)($m['reason'] ?? '')),
                default => throw new RuntimeException('unknown message type "' . ($m['type'] ?? '') . '"'),
            };
        } finally {
            $c->next++;
        }
        // Written once the connection took it.
        while ($end !== null && $c->written() < $end) {
            if ($c->done()) {
                throw new ErrClosed;
            }
            yield Channel::wait(self::POLL_BUSY);
            $c->flush();
        }
        return null;
    }

    /** Starts the closing handshake the page asked for. */
    private function close(Conn $c, int $code, string $reason): null
    {
        if ($code !== Conn::CLOSE_NO_STATUS && $code !== Conn::CLOSE_NORMAL && ($code < 3000 || $code > 4999)) {
            throw new RuntimeException("invalid close code $code");
        }
        if (strlen($reason) > 123) {
            throw new RuntimeException('close reason over 123 bytes');
        }
        $c->close($code, $reason, $this->opts->closeTimeout);
        return null;
    }

    /** Bytes the page sent as base64, as Go's JSON carries []byte. */
    private static function bytes(mixed $data): string
    {
        $bytes = base64_decode((string)$data, true);
        if ($bytes === false) {
            throw new RuntimeException('websocket: data is not base64');
        }
        return $bytes;
    }

    /** @param array<string, mixed> $opts */
    private function run(CallContext $ctx, array $opts): Generator
    {
        $id = (string)($opts['id'] ?? '');
        $k = self::key($ctx, $id);
        try {
            [$c, $protocol] = yield from $this->dial((string)($opts['url'] ?? ''), array_map('strval', $opts['protocols'] ?? []), $opts['headers'] ?? []);
            if (isset($this->conns[$k])) {
                $c->terminate();
                throw new RuntimeException("connection \"$id\" is already open");
            }
        } catch (ChannelClosed $e) {
            throw $e;
        } catch (Throwable $e) {
            yield ['type' => 'close', 'code' => Conn::CLOSE_ABNORMAL, 'error' => $e->getMessage()];
            return;
        }
        $this->conns[$k] = $c;
        // The page going away closes the connection.
        $stop = $ctx->afterFunc(function () use ($c): void {
            try {
                $c->writeClose(Conn::CLOSE_GOING_AWAY);
            } catch (ErrClosed) {
            }
            $c->terminate();
        });
        try {
            yield ['type' => 'open'] + ($protocol !== '' ? ['protocol' => $protocol] : []);
            $wait = self::POLL_BUSY;
            while (true) {
                try {
                    $c->pump();
                    $msg = $c->readMessage();
                } catch (Failure $f) {
                    try {
                        $c->writeClose($f->closeCode);
                    } catch (ErrClosed) {
                    }
                    yield ['type' => 'close', 'code' => Conn::CLOSE_ABNORMAL, 'error' => $f->getMessage()];
                    return;
                } catch (Throwable $e) {
                    // Not the server missing the closing handshake the page started.
                    yield ['type' => 'close', 'code' => Conn::CLOSE_ABNORMAL] + ($c->closing() ? [] : ['error' => $e->getMessage()]);
                    return;
                }
                if ($msg === null) {
                    yield Channel::wait($wait);
                    $wait = min($wait * 2, self::POLL_IDLE);
                    continue;
                }
                $wait = self::POLL_BUSY;
                [$op, $data] = $msg;
                if ($op === Conn::OP_TEXT) {
                    yield ['type' => 'text'] + ($data !== '' ? ['text' => $data] : []);
                } elseif ($op === Conn::OP_BINARY) {
                    yield ['type' => 'binary'] + ($data !== '' ? ['data' => base64_encode($data)] : []);
                } else {
                    try {
                        [$code, $reason] = Conn::parseClose($data);
                    } catch (Failure $f) {
                        try {
                            $c->writeClose($f->closeCode);
                        } catch (ErrClosed) {
                        }
                        yield ['type' => 'close', 'code' => Conn::CLOSE_ABNORMAL, 'error' => $f->getMessage()];
                        return;
                    }
                    // Echo the code, unless the page closed first.
                    try {
                        $c->writeClose($code);
                    } catch (ErrClosed) {
                    }
                    yield ['type' => 'close', 'code' => $code] + ($reason !== '' ? ['reason' => $reason] : []) + ['clean' => true];
                    return;
                }
            }
        } finally {
            $stop();
            unset($this->conns[$k]);
            $c->flush();
            $c->terminate();
        }
    }

    /**
     * Opens a connection with the opening handshake: [the connection, the
     * subprotocol the server chose].
     *
     * @param list<string> $protocols
     * @param list<array{0: string, 1: string}> $headers
     * @return Generator<int, mixed, mixed, array{0: Conn, 1: string}>
     */
    private function dial(string $raw, array $protocols, array $headers): Generator
    {
        $u = parse_url($raw);
        if ($u === false || !isset($u['scheme'])) {
            throw new RuntimeException("parse \"$raw\": invalid URL");
        }
        $scheme = match (strtolower($u['scheme'])) {
            'ws', 'http' => 'http',
            'wss', 'https' => 'https',
            default => throw new RuntimeException("unsupported URL scheme \"{$u['scheme']}\""),
        };
        if (($u['host'] ?? '') === '') {
            throw new RuntimeException("parse \"$raw\": no host");
        }
        $rest = substr($raw, strlen($u['scheme']));
        $hash = strpos($rest, '#');
        $url = $scheme . ($hash === false ? $rest : substr($rest, 0, $hash));
        $host = $u['host'] . (isset($u['port']) ? ":{$u['port']}" : '');
        $target = ($u['path'] ?? '') === '' ? '/' : $u['path'];
        if (isset($u['query'])) {
            $target .= "?{$u['query']}";
        }
        $own = ['upgrade', 'connection', 'sec-websocket-key', 'sec-websocket-version', 'sec-websocket-extensions', 'sec-websocket-protocol'];
        $list = [];
        foreach ($headers as $h) {
            [$name, $value] = [(string)$h[0], (string)$h[1]];
            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
                throw new RuntimeException("net/http: invalid header field name \"$name\"");
            }
            if (strpbrk($value, "\r\n\0") !== false) {
                throw new RuntimeException("net/http: invalid header field value for \"$name\"");
            }
            if (strcasecmp($name, 'host') === 0) {
                $host = $value;
            } elseif (!in_array(strtolower($name), $own, true)) {
                $list[] = [$name, $value];
            }
        }
        $key = base64_encode(random_bytes(16));
        array_push($list, ['Upgrade', 'websocket'], ['Connection', 'Upgrade'], ['Sec-WebSocket-Key', $key], ['Sec-WebSocket-Version', '13']);
        if ($protocols !== []) {
            $list[] = ['Sec-WebSocket-Protocol', implode(', ', $protocols)];
        }
        $req = new Request($url, $list);
        if ($this->opts->allow !== null && !($this->opts->allow)($req)) {
            throw new RuntimeException('the app does not allow connections to ' . $req->redacted());
        }

        // The connection outlives the request: no timeout, and like browsers, no redirects.
        $t = new CurlTransport($url);
        try {
            while (($r = $t->connected()) === null) {
                yield Channel::wait(self::POLL_BUSY);
            }
            if ($r !== 0) {
                throw new RuntimeException("Get \"{$req->redacted()}\": " . $t->error($r));
            }
            $out = "GET $target HTTP/1.1\r\nHost: $host\r\n";
            foreach ($list as [$name, $value]) {
                $out .= "$name: $value\r\n";
            }
            $out .= "\r\n";
            while ($out !== '') {
                $n = $t->write($out);
                $out = (string)substr($out, $n);
                if ($out !== '') {
                    yield Channel::wait(self::POLL_BUSY);
                }
            }
            $in = '';
            $wait = self::POLL_BUSY;
            while (($end = strpos($in, "\r\n\r\n")) === false) {
                if (strlen($in) > self::MAX_HEAD) {
                    throw new RuntimeException('the server answered the handshake with too large a header');
                }
                $data = $t->read();
                if ($data === null) {
                    throw new RuntimeException("Get \"{$req->redacted()}\": EOF");
                }
                if ($data === '') {
                    yield Channel::wait($wait);
                    $wait = min($wait * 2, self::POLL_IDLE);
                }
                $in .= $data;
            }
            $lines = explode("\r\n", substr($in, 0, $end));
            if (preg_match('~^HTTP/\d\.\d (\d{3})(?: (.*))?$~', $lines[0], $m) !== 1) {
                throw new RuntimeException("malformed HTTP response \"{$lines[0]}\"");
            }
            $fields = [];
            foreach (array_slice($lines, 1) as $line) {
                $i = strpos($line, ':');
                if ($i !== false) {
                    $fields[strtolower(trim(substr($line, 0, $i)))] ??= trim(substr($line, $i + 1)); // the first of each, as Go's Header.Get
                }
            }
            $get = fn(string $name): string => $fields[$name] ?? '';
            if ($m[1] !== '101') {
                throw new RuntimeException('the server answered the handshake with ' . trim("{$m[1]} " . ($m[2] ?? '')));
            }
            if (strcasecmp($get('upgrade'), 'websocket') !== 0 || !self::hasToken($get('connection'), 'upgrade')) {
                throw new RuntimeException('the server did not upgrade the connection to WebSocket');
            }
            if ($get('sec-websocket-accept') !== Conn::acceptKey($key)) {
                throw new RuntimeException('the server answered with a wrong Sec-WebSocket-Accept');
            }
            $protocol = $get('sec-websocket-protocol');
            if ($protocol !== '' && !in_array($protocol, $protocols, true)) {
                throw new RuntimeException("the server chose subprotocol \"$protocol\", which was not requested");
            }
            if ($get('sec-websocket-extensions') !== '') {
                throw new RuntimeException('the server chose extensions, which were not requested');
            }
        } catch (Throwable $e) {
            $t->close();
            throw $e;
        }
        return [new Conn($t, false, $this->opts->maxMessageSize, (string)substr($in, $end + 4)), $protocol];
    }

    private static function hasToken(string $header, string $token): bool
    {
        foreach (explode(',', $header) as $t) {
            if (strcasecmp(trim($t), $token) === 0) {
                return true;
            }
        }
        return false;
    }
}
