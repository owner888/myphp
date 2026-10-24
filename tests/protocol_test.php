<?php
// Custom schemes and the frontend (mygo's TestProtocol, TestProtocolCORS,
// TestFrontend and TestUntrustedOriginsCannotCall): what handlers answer
// through a task, streaming, failures, the CORS headers of the app's own
// pages, files with ranges, the frontend's URLs and files, downloads,
// which pages may call the app's methods.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{App, Protocol, ProtocolRequest, ProtocolResponse};
use MyPHP\Native\{Frontend, Scheme, SchemeTask};

/** A task that records what it was told. */
final class ProtoTask implements SchemeTask
{
    public int $status = 0;
    /** @var array<string, string> */
    public array $headers = [];
    public string $body = '';
    public bool $finished = false, $isStopped = false;
    public string $failed = '';
    public int $writes = 0;

    public function respond(int $status, array $headers): void
    {
        if ($this->stopped() || $this->status !== 0) { return; }
        [$this->status, $this->headers] = [$status, $headers];
    }
    public function write(string $bytes): void
    {
        if ($this->stopped() || $bytes === '') { return; }
        if ($this->status === 0) { $this->status = 200; }
        $this->body .= $bytes;
        $this->writes++;
    }
    public function finish(): void { if (!$this->stopped()) { $this->finished = true; } }
    public function fail(string $message): void { if (!$this->stopped()) { $this->failed = $message; } }
    public function stopped(): bool { return $this->isStopped || $this->finished || $this->failed !== ''; }
    public function header(string $name): string
    {
        foreach ($this->headers as $k => $v) { if (strcasecmp($k, $name) === 0) { return $v; } }
        return '';
    }
}

$protoLog = ini_set('error_log', '/dev/null'); // the failures served on purpose
$protoLater = [];
Scheme::$later = function (\Closure $fn) use (&$protoLater): void { $protoLater[] = $fn; };
$protoDrain = function () use (&$protoLater): void {
    while ($protoLater !== []) { array_shift($protoLater)(); }
};
$protoTrusted = ['https://app.example.com'];
/** Serves a request, and runs the run loop's turns it asked for. */
$serve = function (string $method, string $url, array $headers = [], string $body = '') use ($protoDrain, &$protoTrusted): ProtoTask {
    $t = new ProtoTask;
    Scheme::serve(new ProtocolRequest($method, $url, $headers, $body), $t, fn(string $o): bool => Frontend::isTrusted($o, $protoTrusted));
    $protoDrain();
    return $t;
};

// Handling schemes.
$threw = 0;
foreach (['http', 'Bad Scheme', '1x', 'file'] as $s) {
    try { Protocol::handle($s, fn() => new ProtocolResponse); } catch (\InvalidArgumentException) { $threw++; }
}
same(4, $threw, 'reserved and invalid schemes are refused');
Protocol::handle('test', function (ProtocolRequest $r): ProtocolResponse {
    return match ($r->path()) {
        '/hello' => new ProtocolResponse(200, ['X-Test' => (string)($r->query()['q'] ?? '')], '<p>hi</p>'),
        '/echo' => new ProtocolResponse(201, [], $r->body),
        '/stream' => new ProtocolResponse(200, ['Content-Type' => 'text/plain'], (function () { for ($i = 0; $i < 3; $i++) { yield "chunk$i;"; } })()),
        '/big' => new ProtocolResponse(200, [], str_repeat('x', Scheme::CHUNK * 2 + 10)),
        '/throws' => throw new \RuntimeException('oops'),
        '/broken' => new ProtocolResponse(200, [], (function () { yield 'a'; throw new \RuntimeException('midway'); })()),
        '/not' => 'a string',
        default => new ProtocolResponse(404),
    };
});
check(Protocol::isHandled('TEST') && in_array('test', Protocol::schemes(), true) && in_array('myphp', Protocol::schemes(), true), 'handled, whatever the case; windows take it and the frontend\'s');
$t = $serve('GET', 'test://localhost/hello?q=1');
check($t->status === 200 && $t->body === '<p>hi</p>' && $t->header('X-Test') === '1' && $t->finished, 'GET: ' . json_encode([$t->status, $t->body, $t->headers]));
same('text/html; charset=utf-8', $t->header('Content-Type'), 'the content type sniffed');
$t = $serve('POST', 'test://localhost/echo', [], 'payload');
same([201, 'payload'], [$t->status, $t->body], 'POST');
$t = $serve('GET', 'test://localhost/stream');
check($t->body === 'chunk0;chunk1;chunk2;' && $t->writes === 3 && $t->finished, 'streamed, a chunk a turn');
$t = $serve('GET', 'test://localhost/big');
check(strlen($t->body) === Scheme::CHUNK * 2 + 10 && $t->writes === 3, 'a large body in chunks');
same(500, $serve('GET', 'test://localhost/throws')->status, 'a handler that throws: 500');
same(500, $serve('GET', 'test://localhost/not')->status, 'nor one answering no ProtocolResponse');
$t = $serve('GET', 'test://localhost/broken');
check($t->body === 'a' && $t->failed !== '' && !$t->finished, 'a stream that breaks fails the task');
same(404, $serve('GET', 'unknown://localhost/')->status, 'a scheme without a handler: 404');
// The page stops reading: the stream stops.
$t = new ProtoTask;
$pulled = 0;
Protocol::handle('slow', fn() => new ProtocolResponse(200, ['Content-Type' => 'text/plain'], (function () use (&$pulled) { while (true) { $pulled++; yield 'x'; } })()));
Scheme::serve(new ProtocolRequest('GET', 'slow://localhost/'), $t, fn() => false);
array_shift($protoLater)();
$t->isStopped = true;
$protoDrain();
check($pulled <= 3 && $protoLater === [], "stopped, nothing more is pulled ($pulled)");
Protocol::unhandle('slow');

// CORS: the app's own pages read custom schemes, others don't.
Protocol::handle('assets', fn(ProtocolRequest $r) => $r->path() === '/own'
    ? new ProtocolResponse(200, ['Access-Control-Allow-Origin' => 'https://cdn.example.com'])
    : new ProtocolResponse(200, ['X-Count' => '3'], 'data'));
foreach (['myphp://localhost', 'test://localhost', 'https://app.example.com', 'http://localhost:5173'] as $origin) {
    $t = $serve('GET', 'assets://localhost/data', ['Origin' => $origin]);
    check($t->body === 'data' && $t->header('Access-Control-Allow-Origin') === $origin && $t->header('Access-Control-Expose-Headers') === '*' && $t->header('Vary') === 'Origin', "$origin reads it");
}
foreach (['', 'null', 'https://example.com', 'http://192.168.1.2:5173'] as $origin) {
    same('', $serve('GET', 'assets://localhost/data', $origin === '' ? [] : ['Origin' => $origin])->header('Access-Control-Allow-Origin'), "\"$origin\" gets no CORS headers");
}
$t = $serve('OPTIONS', 'assets://localhost/data', ['Origin' => 'myphp://localhost', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type']);
check($t->status === 204 && $t->body === '' && $t->header('Access-Control-Allow-Methods') === 'POST' && $t->header('Access-Control-Allow-Headers') === 'content-type', 'a preflight of the app\'s is answered');
check($serve('OPTIONS', 'assets://localhost/data', ['Origin' => 'https://example.com', 'Access-Control-Request-Method' => 'POST'])->status !== 204, 'one of another site is not');
same('https://cdn.example.com', $serve('GET', 'assets://localhost/own', ['Origin' => 'myphp://localhost'])->header('Access-Control-Allow-Origin'), 'a handler\'s own CORS headers win');
Protocol::unhandle('assets');

// Content types sniffed.
foreach (["\x89PNG\r\n\x1a\nxx" => 'image/png', '%PDF-1.7' => 'application/pdf', "  <!DOCTYPE html><p>" => 'text/html; charset=utf-8', '{"a":1}' => 'text/plain; charset=utf-8', "\x00\x01\x02" => 'application/octet-stream', 'héllo' => 'text/plain; charset=utf-8', '<pre>' => 'text/plain; charset=utf-8'] as $bytes => $want) {
    same($want, Scheme::sniff($bytes), 'sniffed ' . json_encode(mb_convert_encoding($bytes, 'UTF-8', 'UTF-8')));
}

// Files: their types, ranges, index.html for client routes, nothing outside.
$dir = sys_get_temp_dir() . '/myphp-proto-' . getmypid();
@mkdir("$dir/site/sub", 0755, true);
file_put_contents("$dir/site/index.html", '<p>app</p>');
file_put_contents("$dir/site/app.js", 'go()');
file_put_contents("$dir/site/sub/index.html", '<p>sub</p>');
file_put_contents("$dir/site/video.mp4", '0123456789');
file_put_contents("$dir/secret.txt", 'no');
Protocol::handle('files', Protocol::fileServer("$dir/site"));
$t = $serve('GET', 'files://localhost/app.js');
check($t->body === 'go()' && $t->header('Content-Type') === 'text/javascript; charset=utf-8' && $t->header('Content-Length') === '4', 'a file with its type');
same('<p>app</p>', $serve('GET', 'files://localhost/todos/1')->body, 'a client-side route serves index.html');
same('<p>sub</p>', $serve('GET', 'files://localhost/sub/')->body, 'a directory its index.html');
same(404, $serve('GET', 'files://localhost/missing.png')->status, 'a missing file with an extension: 404');
same(404, $serve('GET', 'files://localhost/../secret.txt')->status, 'nothing outside');
same(404, $serve('GET', 'files://localhost/%2e%2e/secret.txt')->status, 'nor encoded');
$t = $serve('GET', 'files://localhost/video.mp4', ['Range' => 'bytes=2-5']);
check($t->status === 206 && $t->body === '2345' && $t->header('Content-Range') === 'bytes 2-5/10', 'a range');
same('789', $serve('GET', 'files://localhost/video.mp4', ['Range' => 'bytes=-3'])->body, 'a suffix range');
same(416, $serve('GET', 'files://localhost/video.mp4', ['Range' => 'bytes=20-'])->status, 'a range past the end');
Protocol::unhandle('files');

// The frontend: URLs without a scheme are its pages.
same('myphp://localhost/settings?tab=1', Frontend::resolve('/settings?tab=1'), 'a path is the frontend\'s');
same('myphp://localhost/index.html', Frontend::resolve('index.html'), 'relative too');
same('https://example.com/', Frontend::resolve('https://example.com/'), 'a URL stays');
$t = $serve('GET', 'myphp://localhost/');
check($t->status === 404 && str_contains($t->body, 'myphp dev'), 'no frontend: a page says how to get one');
App::setFrontend("$dir/site");
same('go()', $serve('GET', 'myphp://localhost/app.js')->body, 'the frontend\'s files');
same('<p>app</p>', $serve('GET', 'myphp://localhost/todos/1')->body, 'and its client-side routes');
Protocol::handle('myphp', fn() => new ProtocolResponse(200, [], 'own'));
same('own', $serve('GET', 'myphp://localhost/app.js')->body, 'the app\'s own handler wins');
Protocol::unhandle('myphp');
App::setFrontend(null);
putenv("MYPHP_FRONTEND_DIST=$dir/site/sub");
same('<p>sub</p>', $serve('GET', 'myphp://localhost/')->body, 'frontendDist from disk under myphp dev');
putenv('MYPHP_FRONTEND_DIST');
putenv('MYPHP_DEV_URL=http://192.168.1.2:5173');
same('http://192.168.1.2:5173/', Frontend::resolve('/'), 'the dev server under myphp dev');
check(Frontend::isTrusted('http://192.168.1.2:5173/x', []) && !Frontend::isTrusted('http://192.168.1.3:5173/', []), 'only the dev server is trusted');
putenv('MYPHP_DEV_URL');

// Which pages may call the app's methods.
Protocol::handle('trusted', fn() => new ProtocolResponse(404));
foreach ([
    'https://evil.example/' => false,
    'https://partner.example/page' => true,
    'https://partner.example.evil.com/' => false,
    'trusted://localhost/' => true,
    'myphp://localhost/' => true,
    'file:///tmp/index.html' => true,
    'about:blank' => true,
    'http://localhost:5173/' => true, // a loopback dev server in development
    'http://[::1]:5173/' => true,
    'http://192.168.1.2:5173/' => false,
    '' => false,
] as $url => $ok) {
    same($ok, Frontend::isTrusted((string)$url, ['https://partner.example/']), "a call from \"$url\"");
}
check(Frontend::isTrusted('https://anything.example/', ['*']), '"*" trusts every origin');
Protocol::unhandle('trusted');

// Downloads of a custom scheme: served into the file the page's download goes to.
Protocol::handle('dl', fn(ProtocolRequest $r) => $r->path() === '/gone'
    ? new ProtocolResponse(404)
    : new ProtocolResponse(200, ['Content-Disposition' => 'attachment; filename="report.csv"'], (function () { yield "a,b\n"; yield "1,2\n"; })()));
$asked = $ended = null;
Scheme::download('dl://localhost/x', new ProtocolRequest('GET', 'dl://localhost/x'), function (string $url, string $name) use (&$asked, $dir): string { $asked = $name; return "$dir/$name"; }, function (string $url, string $path, ?string $err) use (&$ended): void { $ended = [$path, $err]; });
check($asked === 'report.csv' && $ended === ["$dir/report.csv", null] && file_get_contents("$dir/report.csv") === "a,b\n1,2\n", 'downloaded under its name: ' . json_encode([$asked, $ended]));
Scheme::download('dl://localhost/gone', new ProtocolRequest('GET', 'dl://localhost/gone'), fn() => "$dir/never", function (string $url, string $path, ?string $err) use (&$ended): void { $ended = [$path, $err]; });
check($ended[0] === '' && str_contains((string)$ended[1], '404') && !file_exists("$dir/never"), 'a 404 is an error');
Protocol::unhandle('dl');
Protocol::handle('dl2', fn() => new ProtocolResponse(200, ['Content-Disposition' => 'attachment; filename="r"'], 'x'));
$serve('GET', 'dl2://localhost/a');
Protocol::unhandle('dl2');
same('attachment; filename="r"', Scheme::$dispositions['dl2://localhost/a'] ?? '', 'served responses note their disposition, for the navigation policy');

Protocol::unhandle('test');
Scheme::$later = null;
ini_set('error_log', (string)$protoLog);
exec('rm -rf ' . escapeshellarg($dir));
