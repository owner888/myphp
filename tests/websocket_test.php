<?php
// The WebSocket plugin (mygo's plugins/websocket websocket_test.go):
// connections to a local server (testdata/ws_server.php, the plugin's
// own Conn as the server's side) through the service's generators, run
// here as Ipc pumps them: messages in the order the page sent them, pings,
// fragments, closing either way, failed handshakes, the page going away
// and a close the server never answers.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{CallContext, Channel, ChannelWait};
use MyPHP\Plugins\WebSocket\{Options, Request, Service, WebSocket};

try {
    \MyPHP\Plugins\Curl::ffi();
} catch (\Throwable $e) {
    echo "  skipped: websocket: {$e->getMessage()}\n";
    return;
}

/** A generator run as Ipc pumps it, a step at a time. */
final class WsTask
{
    /** @var list<mixed> what it yielded but waits */
    public array $out = [];
    public bool $done = false;
    public mixed $result = null;
    public ?\Throwable $err = null;
    private bool $started = false;
    private float $at = 0;

    public function __construct(private \Generator $gen) {}

    public function step(): void
    {
        if ($this->done || microtime(true) < $this->at) { return; }
        try {
            if ($this->started) { $this->gen->next(); } else { $this->started = true; }
            if (!$this->gen->valid()) { $this->done = true; $this->result = $this->gen->getReturn(); return; }
            $v = $this->gen->current();
            if ($v instanceof ChannelWait) { $this->at = microtime(true) + $v->seconds; } else { $this->out[] = $v; }
        } catch (\Throwable $e) {
            $this->err = $e;
            $this->done = true;
        }
    }
}

/** Steps every task until $until() holds, at most 5 seconds. @param list<WsTask> $tasks */
function wsRun(array $tasks, \Closure $until): bool
{
    for ($t = microtime(true); microtime(true) - $t < 5; usleep(300)) {
        foreach ($tasks as $task) { $task->step(); }
        if ($until()) { return true; }
    }
    return false;
}

/** The next event of a connection, running $others meanwhile. @param list<WsTask> $others */
function wsNext(WsTask $conn, array $others = []): ?array
{
    $n = count($conn->out);
    wsRun([$conn, ...$others], fn() => count($conn->out) > $n || $conn->done);
    if (($conn->out[$n]['type'] ?? '') === 'close') {
        $conn->step(); // as Ipc goes on once it sent it: the generator ends
    }
    return $conn->out[$n] ?? null;
}

(function () {
    $port = 19000 + getmypid() % 1000;
    $server = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/testdata/ws_server.php', (string)$port], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) { usleep(20000); }
    $url = "ws://127.0.0.1:$port";
    try {
        same('websocket', WebSocket::plugin()->name, 'the plugin is websocket');
        $s = WebSocket::plugin()->service;
        $page = new CallContext(null);
        $connect = fn(Service $s, CallContext $ctx, array $opts) => new WsTask($s->connect($ctx, $opts, new Channel(fn() => null, fn() => null, 1, 'k')));
        $send = fn(string $id, int $seq, array $m, ?CallContext $ctx = null) => new WsTask($s->send($ctx ?? $page, $id, $seq, $m));

        $a = $connect($s, $page, ['id' => 'a', 'url' => $url, 'protocols' => ['chat', 'v2'], 'headers' => [['origin', 'https://example.com']]]);
        same(['type' => 'open', 'protocol' => 'chat'], wsNext($a), 'open, with the subprotocol chosen');
        // Sent in the order of their sequence numbers, whatever the order of the calls.
        $sends = [$send('a', 3, ['type' => 'binary', 'data' => base64_encode("\1\2")]), $send('a', 2, ['type' => 'text', 'text' => 'two'])];
        wsRun([$a, ...$sends], fn() => false || count($a->out) > 1);
        same(1, count($a->out), 'nothing goes before the first message');
        $sends[] = $send('a', 1, ['type' => 'text', 'text' => 'one']);
        wsRun([$a, ...$sends], fn() => count($a->out) >= 4 && array_filter($sends, fn($t) => !$t->done) === []);
        same([null, null, null], array_map(fn($t) => $t->err, $sends), 'every send written');
        same([['type' => 'text', 'text' => 'one'], ['type' => 'text', 'text' => 'two'], ['type' => 'binary', 'data' => base64_encode("\1\2")]], array_slice($a->out, 1, 3), 'in their order');
        $sends = [];
        foreach (['ping', 'split', 'origin'] as $i => $m) { $sends[] = $send('a', 4 + $i, ['type' => 'text', 'text' => $m]); }
        wsRun([$a, ...$sends], fn() => count($a->out) >= 7);
        same(['pinged', 'abc', 'https://example.com'], array_map(fn($e) => $e['text'] ?? json_encode($e), array_slice($a->out, 4, 3)), 'pings answered, fragments joined, the page\'s Origin sent');

        // The page closes the connection.
        $close = $send('a', 7, ['type' => 'close', 'code' => 1000, 'reason' => 'done']);
        same(['type' => 'close', 'code' => 1000, 'clean' => true], wsNext($a, [$close]), 'closed by the page, the server echoing its code');
        check($a->done, 'the connection ended');
        $late = $send('a', 8, ['type' => 'text', 'text' => 'late']);
        wsRun([$late], fn() => $late->done);
        check($late->err !== null, 'nothing is sent on a closed connection');

        // The server closes it.
        $b = $connect($s, $page, ['id' => 'b', 'url' => $url]);
        same('open', wsNext($b)['type'] ?? '', 'b open');
        $bye = $send('b', 1, ['type' => 'text', 'text' => 'close']);
        same(['type' => 'close', 'code' => 4000, 'reason' => 'bye', 'clean' => true], wsNext($b, [$bye]), 'closed by the server');

        // Failed handshakes.
        $c = $connect($s, $page, ['id' => 'c', 'url' => "http://127.0.0.1:$port/reject", 'headers' => [['sec-websocket-version', '8']]]);
        $e = wsNext($c);
        check(($e['type'] ?? '') === 'close' && $e['code'] === 1006 && str_contains($e['error'] ?? '', '400 Bad Request'), 'a rejected handshake: ' . json_encode($e));
        $deny = WebSocket::new(new Options(allow: function (Request $r) use (&$asked) { $asked = $r; return false; }))->service;
        $d = $connect($deny, $page, ['id' => 'd', 'url' => "ws://user:secret@127.0.0.1:$port/x", 'headers' => [['authorization', 'Bearer t']]]);
        $e = wsNext($d);
        same("the app does not allow connections to http://user:xxxxx@127.0.0.1:$port/x", $e['error'] ?? '', 'Options::$allow, without the password');
        same([['authorization', 'Bearer t'], ['Upgrade', 'websocket'], ['Connection', 'Upgrade']], array_slice($asked->headers, 0, 3), 'Allow sees the handshake');
        foreach (['ftp://x' => 'unsupported URL scheme', 'ws://127.0.0.1:1/' => 'Get "http://127.0.0.1:1/"'] as $bad => $why) {
            $e = wsNext($connect($s, $page, ['id' => 'e', 'url' => $bad]));
            check(str_contains($e['error'] ?? '', $why), "$bad: " . json_encode($e));
        }
        $e = wsNext($connect($s, $page, ['id' => 'f', 'url' => $url, 'headers' => [['x-evil', "a\r\nInjected: 1"]]]));
        check(str_contains($e['error'] ?? '', 'invalid header field value'), 'a header with a line break: ' . json_encode($e));

        // An id open twice; another page's ids are its own.
        $g = $connect($s, $page, ['id' => 'g', 'url' => $url]);
        wsNext($g);
        $g2 = $connect($s, $page, ['id' => 'g', 'url' => $url]);
        check(str_contains(wsNext($g2, [$g])['error'] ?? '', 'already open'), 'an id open twice');
        $other = new CallContext(null);
        $g3 = $connect($s, $other, ['id' => 'g', 'url' => $url]);
        same('open', wsNext($g3, [$g])['type'] ?? '', 'another page\'s g');

        // The page goes away: the server is told "going away".
        $out = sys_get_temp_dir() . '/myphp-ws-' . getmypid();
        @unlink($out);
        $page2 = new CallContext(null);
        $h = $connect($s, $page2, ['id' => 'h', 'url' => "$url/record?out=" . urlencode($out)]);
        same('open', wsNext($h)['type'] ?? '', 'h open');
        $page2->cancel();
        $e = wsNext($h);
        same(['type' => 'close', 'code' => 1006], $e, 'the page gone ends it, without an error');
        wsRun([], fn() => is_file($out) && filesize($out) > 0);
        same('1001', @file_get_contents($out), 'closed going away');
        @unlink($out);
        $page->cancel();
        $other->cancel();
        wsRun([$g, $g3], fn() => $g->done && $g3->done);
        same([], \Closure::bind(fn() => $this->conns, $s, Service::class)(), 'no connections left');

        // A close the server never answers.
        $quick = WebSocket::new(new Options(closeTimeout: 0.1))->service;
        $p3 = new CallContext(null);
        $i = $connect($quick, $p3, ['id' => 'i', 'url' => "$url/silent"]);
        same('open', wsNext($i)['type'] ?? '', 'i open');
        $t = microtime(true);
        $close = new WsTask($quick->send($p3, 'i', 1, ['type' => 'close', 'code' => 1000]));
        same(['type' => 'close', 'code' => 1006], wsNext($i, [$close]), 'a close without an answer, without an error');
        check(microtime(true) - $t >= 0.09, 'after the timeout');
        foreach ([['type' => 'close', 'code' => 1001], ['type' => 'close', 'code' => 1000, 'reason' => str_repeat('x', 124)], ['type' => 'nope']] as $bad) {
            $p4 = new CallContext(null);
            $j = $connect($s, $p4, ['id' => 'j', 'url' => $url]);
            wsNext($j);
            $task = new WsTask($s->send($p4, 'j', 1, $bad));
            wsRun([$task], fn() => $task->done);
            check($task->err !== null, 'refused: ' . json_encode($bad) . ' ' . $task->err?->getMessage());
            $p4->cancel();
            wsRun([$j], fn() => $j->done);
        }
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
})();

/** Bytes in memory as a Transport: what the other side sent, and what was written. */
final class WsPipe implements \MyPHP\Plugins\WebSocket\Transport
{
    public string $written = '';
    public bool $closed = false;

    public function __construct(public string $incoming = '', public bool $eof = false, public int $room = PHP_INT_MAX /* what it takes, in all */) {}

    public function read(): ?string
    {
        if ($this->incoming === '') { return $this->eof ? null : ''; }
        [$d, $this->incoming] = [substr($this->incoming, 0, 7), (string)substr($this->incoming, 7)]; // a few bytes at a time
        return $d;
    }

    public function write(string $bytes): int
    {
        $n = min(strlen($bytes), $this->room);
        $this->room -= $n;
        $this->written .= substr($bytes, 0, $n);
        return $n;
    }

    public function close(): void { $this->closed = true; }
}

(function () {
    // Frames (mygo's conn): lengths, masking, limits, invalid data.
    $Conn = \MyPHP\Plugins\WebSocket\Conn::class;
    $read = function (string $bytes, int $max = 1 << 20, bool $server = false): mixed {
        $c = new \MyPHP\Plugins\WebSocket\Conn($p = new WsPipe($bytes, true), $server, $max);
        try {
            $msgs = [];
            for ($i = 0; $i < 100; $i++) {
                $c->pump();
                if (($m = $c->readMessage()) !== null) { $msgs[] = $m; }
            }
            return $msgs;
        } catch (\MyPHP\Plugins\WebSocket\Failure $f) {
            return [$f->closeCode, $f->getMessage()];
        } catch (\RuntimeException $e) {
            return [$e->getMessage(), $msgs ?? []];
        }
    };
    $frame = fn(int $b0, string $p) => chr($b0) . (strlen($p) <= 125 ? chr(strlen($p)) : (strlen($p) <= 0xffff ? chr(126) . pack('n', strlen($p)) : chr(127) . pack('J', strlen($p)))) . $p;
    $big = str_repeat('y', 70000);
    same(['EOF', [[1, 'hi'], [2, str_repeat('x', 300)], [1, $big]]], $read($frame(0x81, 'hi') . $frame(0x82, str_repeat('x', 300)) . $frame(0x81, $big)), 'lengths of 7, 16 and 64 bits, read a few bytes at a time');
    same([1009, 'message over 100 bytes'], $read($frame(0x82, str_repeat('x', 101)), 100), 'a frame over the limit');
    same([1009, 'message over 100 bytes'], $read($frame(0x02, str_repeat('x', 60)) . $frame(0x80, str_repeat('x', 60)), 100), 'a message over the limit in fragments');
    same([1007, 'text message is not valid UTF-8'], $read($frame(0x81, "\xff")), 'invalid UTF-8');
    same([1002, 'reserved bits set in a frame'], $read($frame(0xc1, 'x')), 'reserved bits');
    same([1002, 'wrong frame masking'], $read(chr(0x81) . chr(0x81) . 'mask' . 'x'), 'a masked frame to a client');
    same([1002, 'invalid control frame'], $read($frame(0x09, 'x')), 'a fragmented ping');
    same([1002, 'unexpected continuation frame'], $read($frame(0x80, 'x')), 'a continuation without a message');
    same(['unexpected EOF', []], $read(substr($frame(0x81, 'hello'), 0, 4)), 'cut short');
    // A server reads masked frames, and a client writes them.
    $client = new \MyPHP\Plugins\WebSocket\Conn($out = new WsPipe, false, 1 << 20);
    $client->writeFrame($Conn::OP_TEXT, 'masked');
    same([[1, 'masked']], array_slice($read($out->written, 1 << 20, true)[1], 0, 1), 'a client\'s frame, unmasked by the server');
    check(!str_contains($out->written, 'masked'), 'the client masks what it writes');
    // What the transport can't take yet waits, in order.
    $slow = new \MyPHP\Plugins\WebSocket\Conn($p = new WsPipe('', false, 3), true, 1 << 20);
    $end1 = $slow->writeFrame($Conn::OP_TEXT, 'one');
    $end2 = $slow->writeFrame($Conn::OP_TEXT, 'two');
    same([3, 5, 10], [$slow->written(), $end1, $end2], 'written as the transport takes it');
    $p->room = PHP_INT_MAX;
    $slow->flush();
    same(10, $slow->written(), 'then the rest');
    same("\x81\x03one\x81\x03two", $p->written, 'in order');
    $slow->writeClose(1000, 'bye');
    try { $slow->writeFrame($Conn::OP_TEXT, 'late'); check(false, 'wrote after a close'); } catch (\MyPHP\Plugins\WebSocket\ErrClosed) { check(true, 'nothing after a close frame'); }
    same([[1000, 'bye'], [1005, '']], [$Conn::parseClose(pack('n', 1000) . 'bye'), $Conn::parseClose('')], 'close payloads');
    foreach (["\x03", pack('n', 1005), pack('n', 2999), pack('n', 1000) . "\xff"] as $bad) {
        try { $Conn::parseClose($bad); check(false, 'accepted ' . bin2hex($bad)); } catch (\MyPHP\Plugins\WebSocket\Failure) { check(true, 'refused close payload ' . bin2hex($bad)); }
    }
    same('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $Conn::acceptKey('dGhlIHNhbXBsZSBub25jZQ=='), 'RFC 6455\'s example accept key');
})();
