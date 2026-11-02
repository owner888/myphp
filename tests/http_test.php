<?php
// MyPHP\Http, HTTP requests from PHP, against a local server
// (testdata/range_server.php under php -S): a GET's status, body, headers
// and JSON; a status that is not 2xx returned; headers sent; a POST; a
// request that cannot be made throws with curl's reason. Without the app
// running, CurlLoop::pump drives it; in a running app on Linux, the main
// loop does (screenshot_test's "net" app).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Http, HttpResponse};

try {
    \MyPHP\Native\Curl::ffi();
} catch (\Throwable $e) {
    echo "  skipped: http: {$e->getMessage()}\n";
    return;
}

(function () {
    $dir = sys_get_temp_dir() . '/myphp-http-api-' . getmypid();
    @mkdir($dir);
    file_put_contents("$dir/repo.json", json_encode(['name' => 'mygo', 'stargazers_count' => 42]));
    [$base, $stop] = rangeServer($dir);
    try {
        $r = Http::get("$base/repo.json");
        check($r instanceof HttpResponse && $r->status === 200 && $r->ok(), "a GET: {$r->status}");
        same(['name' => 'mygo', 'stargazers_count' => 42], $r->json(), 'its body, as JSON');
        same('application/octet-stream', $r->headers['content-type'] ?? null, 'its headers, by their names in lower case');
        $missing = Http::get("$base/nothing.json");
        check($missing->status === 404 && !$missing->ok() && $missing->body === 'not found', 'a 404 is a response, not an error');
        same(403, Http::get("$base/repo.json?need")->status, 'a request without the header the server needs');
        same(200, Http::get("$base/repo.json?need", ['X-Myphp-Test' => 'yes'])->status, 'and with it, sent');
        same(200, Http::request('POST', "$base/repo.json", ['Content-Type' => 'application/json'], '{"a":1}', timeout: 10)->status, 'a POST with a body');
        same(200, Http::request('HEAD', "$base/repo.json")->status, 'a HEAD');
        try {
            Http::get('http://127.0.0.1:' . freePort() . '/');
            check(false, 'a refused connection throws');
        } catch (\RuntimeException $e) {
            check(str_starts_with($e->getMessage(), 'http: '), 'a refused connection throws curl\'s reason: ' . $e->getMessage());
        }
        try {
            Http::request('GET /', "$base/repo.json");
            check(false, 'an invalid method throws');
        } catch (\RuntimeException $e) {
            check(str_contains($e->getMessage(), 'invalid method'), 'an invalid method throws: ' . $e->getMessage());
        }
    } finally {
        $stop();
        @unlink("$dir/repo.json");
        @rmdir($dir);
    }
})();
