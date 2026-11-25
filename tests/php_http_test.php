<?php
// Http over MyPHP's own network stack (Http::useTransport('php'), Net\PhpHttp over src/Net: stream_select and
// fibers, HTTP in PHP) against the local server http_test uses: what curl's transport answers, this one answers.
// Without the app running the request turns the stack's loop itself.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Http, HttpResponse, URLError};

same('curl', Http::transport(), 'curl carries the requests unless told otherwise');
try {
    Http::useTransport('quic');
    check(false, 'a transport that is none is refused');
} catch (\InvalidArgumentException $e) {
    check(str_contains($e->getMessage(), 'neither curl nor php'), 'a transport that is none is refused: ' . $e->getMessage());
}

(function () {
    $dir = sys_get_temp_dir() . '/myphp-php-http-' . getmypid();
    @mkdir($dir);
    $big = str_repeat('0123456789abcdef', 20000); // 320 KB: several pieces
    file_put_contents("$dir/repo.json", json_encode(['name' => 'myphp', 'stargazers_count' => 42]));
    file_put_contents("$dir/big.bin", $big);
    [$base, $stop] = rangeServer($dir);
    Http::useTransport('php');
    try {
        same('php', Http::transport(), 'useTransport chooses it');
        $r = Http::get("$base/repo.json");
        check($r instanceof HttpResponse && $r->status === 200 && $r->ok(), "a GET: {$r->status}");
        same(['name' => 'myphp', 'stargazers_count' => 42], $r->json(), 'its body, as JSON');
        same('application/octet-stream', $r->headers['content-type'] ?? null, 'its headers, by their names in lower case');
        $missing = Http::get("$base/nothing.json");
        check($missing->status === 404 && !$missing->ok() && $missing->body === 'not found', 'a 404 is a response, not an error');
        same(403, Http::get("$base/repo.json?need")->status, 'a request without the header the server needs');
        same(200, Http::get("$base/repo.json?need", ['X-Myphp-Test' => 'yes'])->status, 'and with it, sent');
        same(200, Http::request('POST', "$base/repo.json", ['Content-Type' => 'application/json'], '{"a":1}', timeout: 10)->status, 'a POST with a body');
        same(200, Http::request('HEAD', "$base/repo.json")->status, 'a HEAD');

        $pieces = [];
        $told = [];
        $r = Http::request('GET', "$base/big.bin", saveTo: "$dir/out.bin",
            progress: function (int $upTotal, int $upNow, int $downTotal, int $downNow) use (&$told): void { $told[] = [$downTotal, $downNow]; },
            onData: function (string $chunk) use (&$pieces): void { $pieces[] = strlen($chunk); });
        same([200, '', true], [$r->status, $r->body, file_get_contents("$dir/out.bin") === $big], 'a body saved to a file as it comes, not kept');
        check(count($pieces) > 1 && array_sum($pieces) === strlen($big), 'each piece given to onData: ' . count($pieces));
        same([strlen($big), strlen($big)], end($told), 'progress told to the end, of the length the server said');
        try {
            Http::request('GET', "$base/big.bin", progress: fn(): bool => false);
            check(false, 'progress answering false ends the transfer');
        } catch (URLError $e) {
            same(URLError::CANCELLED, $e->getCode(), 'progress answering false ends the transfer: cancelled');
        }
        same(200, Http::get("$base/repo.json")->status, 'and the next request goes on, on another connection');

        try {
            Http::get('http://127.0.0.1:' . freePort() . '/');
            check(false, 'a refused connection throws');
        } catch (\RuntimeException $e) {
            check(str_starts_with($e->getMessage(), 'http: '), 'a refused connection throws, as curl\'s does, with the reason: ' . $e->getMessage());
        }
        try {
            Http::send('GET', "$base/repo.json", [], null, 0.0, 'https', 'MyPHP', 'media');
            check(false, 'a protocol not allowed is refused');
        } catch (\RuntimeException $e) {
            same('media: unsupported protocol "http"', $e->getMessage(), 'a protocol not allowed is refused');
        }

        // In fibers without the app: each request turns the loop until it is done, one after the other, as curl's pump.
        $got = [];
        foreach ([1, 2, 3] as $i) {
            \MyPHP\App::go(function () use ($base, $i, &$got): void { $got[$i] = Http::get("$base/repo.json")->status; });
        }
        same([1 => 200, 2 => 200, 3 => 200], $got, 'requests made in fibers, the app not running');
    } finally {
        Http::useTransport(null);
        $stop();
        array_map('unlink', glob("$dir/*") ?: []);
        @rmdir($dir);
    }
})();
