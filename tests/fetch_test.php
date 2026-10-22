<?php
// The fetch plugin (mygo's plugins/fetch fetch_test.go): requests against
// a local server (testdata/fetch_server.php under php -S): headers any
// page may not set, bodies, repeated headers, big bodies in chunks,
// redirects followed, returned or refused, schemes and the app's veto,
// gzip undone, a streamed body as it comes, and a request that stops.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{CallContext, ChannelWait};
use MyPHP\Plugins\Fetch\{Fetch, Options, Request, Service};

try {
    \MyPHP\Native\Curl::ffi();
} catch (\Throwable $e) {
    echo "  skipped: fetch: {$e->getMessage()}\n";
    return;
}

(function () {
    $port = 18000 + getmypid() % 1000;
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/testdata/fetch_server.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, null, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv()); // workers: a slow response holds one
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) { usleep(20000); }
    $base = "http://127.0.0.1:$port";
    try {
        /** The parts of a response, the waits slept, as the page's channel gets them; $stop ends it after a part. */
        $run = function (Service $s, array $req, ?CallContext $ctx = null, ?\Closure $stop = null): array {
            $gen = \Closure::bind(fn($c, $r) => $this->run($c, $r), $s, Service::class)($ctx ?? new CallContext(null), $req);
            $parts = [];
            foreach ($gen as $p) {
                if ($p instanceof ChannelWait) { usleep((int)($p->seconds * 1e6)); continue; }
                $parts[] = $p;
                if ($stop !== null && $stop($p)) { break; } // the page closed its channel
            }
            return $parts;
        };
        $header = function (array $head, string $name): string {
            foreach ($head['headers'] as [$k, $v]) { if ($k === $name) { return $v; } }
            return '';
        };
        $body = fn(array $parts): string => implode('', array_map(fn($p) => base64_decode($p['chunk']), array_slice($parts, 1)));
        $error = function (\Closure $fn): string {
            try { $fn(); } catch (\RuntimeException $e) { return $e->getMessage(); }
            return '';
        };
        $s = Fetch::plugin()->service;
        same('fetch', Fetch::plugin()->name, 'the plugin is fetch');

        $parts = $run($s, ['url' => "$base/echo", 'method' => 'POST', 'headers' => [['origin', 'https://example.com'], ['host', 'api.test'], ['content-length', '999']], 'body' => base64_encode('hi')]);
        $head = $parts[0];
        same([201, 'Created', false], [$head['status'], $head['statusText'], $head['redirected']], 'the head');
        same(['https://example.com', 'api.test', '(none)'], [$header($head, 'x-origin'), $header($head, 'x-host'), $header($head, 'x-accept')], 'Origin and Host as the page set them, and no Accept of curl\'s');
        same(2, count(array_filter($head['headers'], fn($h) => $h[0] === 'x-multi')), 'a repeated header');
        same('POST hi', $body($parts), 'the body');

        $parts = $run($s, ['url' => "$base/big", 'method' => 'GET']);
        check(count($parts) >= 3 && strlen($body($parts)) === 3 * Service::CHUNK_SIZE / 2, 'a big body in chunks: ' . count($parts) . ' parts');
        check(array_filter(array_slice($parts, 1), fn($p) => strlen(base64_decode($p['chunk'])) > Service::CHUNK_SIZE) === [], 'no chunk over 64 KiB');

        $parts = $run($s, ['url' => "$base/redirect", 'method' => 'GET']);
        same([201, true, "$base/echo"], [$parts[0]['status'], $parts[0]['redirected'], $parts[0]['url']], 'a redirect followed');
        $parts = $run($s, ['url' => "$base/redirect", 'method' => 'GET', 'redirect' => 'manual']);
        same([302, '/echo'], [$parts[0]['status'], $header($parts[0], 'location')], 'a manual redirect');
        check($error(fn() => $run($s, ['url' => "$base/redirect", 'method' => 'GET', 'redirect' => 'error'])) !== '', 'redirect mode error refuses it');
        check(str_contains($error(fn() => $run($s, ['url' => 'file:///etc/passwd', 'method' => 'GET'])), 'unsupported URL scheme'), 'a file URL');

        $parts = $run($s, ['url' => "$base/gzip", 'method' => 'GET']);
        same(['unzipped', ''], [$body($parts), $header($parts[0], 'content-encoding')], 'gzip undone, as Go\'s transport does');

        $deny = Fetch::new(new Options(allow: fn(Request $r) => parse_url($r->url, PHP_URL_PATH) !== '/echo'))->service;
        check(str_contains($error(fn() => $run($deny, ['url' => "$base/echo", 'method' => 'GET'])), 'does not allow'), 'Options::$allow');

        // A streamed body comes as it is written, and the request stops when the page stops taking it, or goes away.
        $t = microtime(true);
        $parts = $run($s, ['url' => "$base/slow", 'method' => 'GET'], null, fn($p) => isset($p['chunk']));
        check($body($parts) === 'first' && microtime(true) - $t < 5, 'the first chunk of a streamed body, at once');
        $ctx = new CallContext(null);
        $t = microtime(true);
        $parts = $run($s, ['url' => "$base/slow", 'method' => 'GET'], $ctx, function ($p) use ($ctx) { if (isset($p['chunk'])) { $ctx->cancel(); } return false; });
        check($body($parts) === 'first' && microtime(true) - $t < 5, 'a request stops when its page goes away');
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
})();
