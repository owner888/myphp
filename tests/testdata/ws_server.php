<?php
// The WebSocket server of tests/websocket_test.php (mygo's testServer and
// echo), on the plugin's own Conn as the server's side: php ws_server.php
// PORT. "/" echoes; "close" makes it close the connection with 4000 "bye",
// "ping" pings, "split" sends a fragmented message, "origin" sends the
// handshake's Origin. "/reject" refuses the handshake; "/record?out=FILE"
// writes the code of the close it gets to FILE; "/silent" never answers.
declare(strict_types=1);

require __DIR__ . '/../../src/autoload.php';

use MyPHP\Plugins\WebSocket\{Conn, StreamTransport};

$server = stream_socket_server('tcp://127.0.0.1:' . (int)$argv[1], $errno, $error);
if ($server === false) {
    fwrite(STDERR, "$error\n");
    exit(1);
}
stream_set_blocking($server, false);
/** @var list<array{s: resource, head: string, conn: ?Conn, t: ?StreamTransport, path: string, query: array, origin: string}> $clients */
$clients = [];
while (true) {
    $read = [$server, ...array_map(fn($c) => $c['s'], array_filter($clients, fn($c) => is_resource($c['s'])))];
    $w = $e = null;
    @stream_select($read, $w, $e, 0, 10000);
    if (($s = @stream_socket_accept($server, 0)) !== false) {
        stream_set_blocking($s, false);
        $clients[] = ['s' => $s, 'head' => '', 'conn' => null, 't' => null, 'path' => '', 'query' => [], 'origin' => ''];
    }
    foreach ($clients as $i => &$c) {
        if ($c['conn'] === null) {
            $c['head'] .= (string)fread($c['s'], 65536);
            if (($end = strpos($c['head'], "\r\n\r\n")) === false) {
                if (feof($c['s'])) { fclose($c['s']); unset($clients[$i]); }
                continue;
            }
            $lines = explode("\r\n", substr($c['head'], 0, $end));
            $h = [];
            foreach (array_slice($lines, 1) as $l) {
                [$k, $v] = array_map('trim', explode(':', $l, 2)) + [1 => ''];
                $h[strtolower($k)] ??= $v;
            }
            $u = parse_url(explode(' ', $lines[0])[1]);
            $c['path'] = $u['path'] ?? '/';
            parse_str($u['query'] ?? '', $c['query']);
            $c['origin'] = $h['origin'] ?? '';
            if ($c['path'] === '/reject' || ($h['sec-websocket-version'] ?? '') !== '13' || strcasecmp($h['upgrade'] ?? '', 'websocket') !== 0) {
                stream_set_blocking($c['s'], true);
                fwrite($c['s'], "HTTP/1.1 400 Bad Request\r\nContent-Length: 27\r\nConnection: close\r\n\r\nnot a WebSocket handshake\n");
                fclose($c['s']);
                unset($clients[$i]);
                continue;
            }
            $proto = isset($h['sec-websocket-protocol']) ? 'Sec-WebSocket-Protocol: ' . trim(explode(',', $h['sec-websocket-protocol'])[0]) . "\r\n" : '';
            stream_set_blocking($c['s'], true);
            fwrite($c['s'], "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . Conn::acceptKey($h['sec-websocket-key'] ?? '') . "\r\n$proto\r\n");
            $c['t'] = new StreamTransport($c['s']);
            $c['conn'] = new Conn($c['t'], true, 1 << 20, (string)substr($c['head'], $end + 4));
        }
        $conn = $c['conn'];
        if ($c['path'] === '/silent') {
            continue; // never answers the close
        }
        try {
            $conn->pump();
            while (($m = $conn->readMessage()) !== null) {
                [$op, $data] = $m;
                if ($c['path'] === '/record') {
                    file_put_contents($c['query']['out'], (string)Conn::parseClose($data)[0]);
                    throw new RuntimeException('done');
                }
                if ($op === Conn::OP_CLOSE) {
                    $conn->writeClose(Conn::parseClose($data)[0]);
                    throw new RuntimeException('done');
                }
                match ($data) {
                    'close' => $conn->writeClose(4000, 'bye'),
                    'ping' => [$conn->writeFrame(Conn::OP_PING, 'p'), $conn->writeFrame(Conn::OP_TEXT, 'pinged')],
                    'split' => [$c['t']->write(chr(Conn::OP_TEXT) . "\x02ab"), $c['t']->write(chr(0x80 | Conn::OP_CONTINUATION) . "\x01c")],
                    'origin' => $conn->writeFrame(Conn::OP_TEXT, $c['origin']),
                    default => $conn->writeFrame($op, $data),
                };
            }
        } catch (Throwable) {
            $conn->flush();
            $conn->terminate();
            unset($clients[$i]);
        }
    }
    unset($c);
}
