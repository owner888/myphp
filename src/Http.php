<?php
declare(strict_types=1);

namespace MyPHP;

use FFI;
use MyPHP\Native\{Curl, CurlLoop, NetProxy};
use Closure;
use RuntimeException;

/**
 * HTTP requests from PHP, for native UI and services (a mygo app makes
 * them with Go's net/http in a goroutine; MyPHP's own): libcurl's, the
 * system's, driven by the run loop as their sockets are ready
 * (Native\CurlLoop), redirects followed, what the server compresses
 * undone, the system's proxy.
 *
 * A request returns once its response came. In a fiber of App::go the
 * fiber waits and the app goes on, drawing and handling input, which is
 * how a view loads what it shows; elsewhere in a running app the run loop
 * turns meanwhile (App::wait); before App::run it blocks.
 *
 *     App::go(function () use ($state) {
 *         $r = Http::get('https://api.github.com/repos/owner888/myphp', ['Accept' => 'application/vnd.github+json']);
 *         $state->stars = $r->ok() ? $r->json()['stargazers_count'] : null;
 *     });
 *
 * A response of any status is returned; what keeps the request from being
 * made or answered (a name that does not resolve, a refused connection, the
 * timeout) throws a RuntimeException with curl's reason.
 */
final class Http
{
    /** CURLOPT_COPYPOSTFIELDS, CURLOPT_TIMEOUT_MS. */
    private const OPT_COPYPOSTFIELDS = 10165, OPT_TIMEOUT_MS = 155;
    /** The most often a progress callback is called, in seconds: 30 times a second. */
    private const PROGRESS_INTERVAL = 1 / 30;

    /** What useTransport() chose, null for the environment's. */
    private static ?string $transport = null;

    /**
     * What carries the requests: 'php', MyPHP's own stack in PHP (the default: Net\PhpHttp, stream_select and fibers,
     * TLS by PHP's openssl extension, HTTP/1.1 and HTTP/2, waiting on nothing of the system's), or 'curl', the system's
     * libcurl. null leaves it to MYPHP_HTTP in the environment (MYPHP_HTTP=curl chooses curl).
     */
    public static function useTransport(?string $transport): void
    {
        $transport === null || $transport === 'curl' || $transport === 'php' || throw new \InvalidArgumentException("Http::useTransport: \"$transport\" is neither curl nor php");
        self::$transport = $transport;
    }

    /** 'php' or 'curl': what useTransport() chose, else MYPHP_HTTP=curl in the environment, else php. */
    public static function transport(): string
    {
        return self::$transport ?? (getenv('MYPHP_HTTP') === 'curl' ? 'curl' : 'php');
    }

    /** GET $url with $headers (name => value). */
    public static function get(string $url, array $headers = [], float $timeout = 0.0): HttpResponse
    {
        return self::request('GET', $url, $headers, null, $timeout);
    }

    /**
     * POST $url with $body: a raw string, an array of fields or file paths
     * (encoded as multipart/form-data when a value is a file path or a
     * CURLFile, else JSON when Content-Type is application/json, else
     * multipart/form-data), with $headers. $progress as request()'s.
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public static function post(string $url, array|string|null $body = null, array $headers = [], float $timeout = 0.0, ?Closure $progress = null): HttpResponse
    {
        if (is_array($body)) {
            [$body, $headers] = self::encodeBody($body, $headers);
        }
        return self::request('POST', $url, $headers, $body, $timeout, $progress);
    }

    /** PUT $url with $body and $headers. @param array<string, mixed>|string|null $body @param array<string, string> $headers */
    public static function put(string $url, array|string|null $body = null, array $headers = [], float $timeout = 0.0): HttpResponse
    {
        if (is_array($body)) {
            [$body, $headers] = self::encodeBody($body, $headers);
        }
        return self::request('PUT', $url, $headers, $body, $timeout);
    }

    /** PATCH $url with $body and $headers. @param array<string, mixed>|string|null $body @param array<string, string> $headers */
    public static function patch(string $url, array|string|null $body = null, array $headers = [], float $timeout = 0.0): HttpResponse
    {
        if (is_array($body)) {
            [$body, $headers] = self::encodeBody($body, $headers);
        }
        return self::request('PATCH', $url, $headers, $body, $timeout);
    }

    /** DELETE $url with $headers. @param array<string, string> $headers */
    public static function delete(string $url, array $headers = [], ?string $body = null, float $timeout = 0.0): HttpResponse
    {
        return self::request('DELETE', $url, $headers, $body, $timeout);
    }

    /** POST $url with $data encoded as application/json. @param array<string, string> $headers */
    public static function postJson(string $url, mixed $data, array $headers = [], float $timeout = 0.0): HttpResponse
    {
        $headers['Content-Type'] ??= 'application/json';
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return self::request('POST', $url, $headers, $json, $timeout);
    }

    /** @param array<string, mixed> $fields @param array<string, string> $headers @return array{0: string, 1: array<string, string>} */
    private static function encodeBody(array $fields, array $headers): array
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'content-type') === 0 && stripos($v, 'application/json') !== false) {
                return [json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $headers];
            }
        }
        $boundary = '----MyPHPFormBoundary' . bin2hex(random_bytes(12));
        $parts = '';
        foreach ($fields as $name => $val) {
            $qName = str_replace('"', '%22', (string)$name);
            if ($val instanceof \CURLFile) {
                $file = $val->getFilename();
                $postName = str_replace('"', '%22', $val->getPostFilename() !== '' ? $val->getPostFilename() : basename($file));
                $mime = $val->getMimeType() !== '' ? $val->getMimeType() : 'application/octet-stream';
                $data = (string)@file_get_contents($file);
                $parts .= "--$boundary\r\nContent-Disposition: form-data; name=\"$qName\"; filename=\"$postName\"\r\nContent-Type: $mime\r\n\r\n$data\r\n";
            } elseif (is_string($val) && ($val !== '' && ($val[0] === '/' || str_starts_with($val, 'file://') || preg_match('/^[A-Za-z]:[\\\/]/', $val) === 1)) && is_file($val)) {
                $postName = str_replace('"', '%22', basename($val));
                $data = (string)@file_get_contents($val);
                $parts .= "--$boundary\r\nContent-Disposition: form-data; name=\"$qName\"; filename=\"$postName\"\r\nContent-Type: application/octet-stream\r\n\r\n$data\r\n";
            } else {
                $str = is_scalar($val) || $val === null ? (string)$val : json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $parts .= "--$boundary\r\nContent-Disposition: form-data; name=\"$qName\"\r\n\r\n$str\r\n";
            }
        }
        $parts .= "--$boundary--\r\n";
        $headers['Content-Type'] = "multipart/form-data; boundary=$boundary";
        return [$parts, $headers];
    }

    /**
     * $method of $url (http or https) with $headers (name => value), $body
     * sent when not null, taking $timeout seconds at most (0: no limit, as
     * Go's default client).
     *
     * $progress(int $uploadTotal, int $uploadNow, int $downloadTotal, int $downloadNow): ?bool is told how much of the
     * body has gone and how much of the answer has come (a total is 0 until it is known), at most 30 times a second and
     * once more when a direction is complete and at the end; it runs on the main thread, in the run loop's turn (not in
     * the fiber that waits), so it may change the app's state and ask for a frame. Answering false ends the transfer: a
     * URLError (cancelled) is thrown; so is what $progress throws, after the transfer is ended.
     * $saveTo, a file path, takes the answer's body there as it comes, not in memory (the response's body is empty);
     * $onData(string $chunk) gets each piece of the body as it comes. @internal $saveTo and $onData: URLSession
     * @param array<string, string> $headers
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 0.0, ?Closure $progress = null, ?string $saveTo = null, ?Closure $onData = null): HttpResponse
    {
        return self::send($method, $url, $headers, $body, $timeout, 'http,https', 'MyPHP', 'http', $progress, $saveTo, $onData);
    }

    /**
     * request() with the protocols $protocols allows (curl's list, the
     * redirects' too), the User-Agent $agent unless $headers names one, and
     * $who before curl's reason when it fails. @internal Media\Https
     * @param array<string, string> $headers
     */
    public static function send(string $method, string $url, array $headers, ?string $body, float $timeout, string $protocols, string $agent, string $who, ?Closure $progress = null, ?string $saveTo = null, ?Closure $onData = null): HttpResponse
    {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $method) !== 1) {
            throw new RuntimeException("$who: invalid method \"$method\"");
        }
        if (self::transport() === 'php') {
            return Net\PhpHttp::send($method, $url, $headers, $body, $timeout, explode(',', $protocols), $agent, $who, $progress, $saveTo, $onData);
        }
        $f = Curl::ffi();
        $h = $f->curl_easy_init();
        $state = new class { public int $status = 0; public string $body = ''; public array $headers = []; };
        $out = null; // the file $saveTo names, open
        if ($saveTo !== null) {
            $out = @fopen($saveTo, 'wb') ?: throw new RuntimeException("$who: cannot write $saveTo");
        }
        $thrown = null; // what $progress or $onData threw: the transfer is ended and it is thrown after
        $cbs = $f->new('myphp_curl_callbacks');
        $cbs->header = function ($ptr, $size, $n, $user) use ($state, &$out): int {
            $line = FFI::string($ptr, $size * $n);
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) { // the last response's, after redirects
                [$state->status, $state->body, $state->headers] = [(int)$m[1], '', []];
                if ($out !== null) { ftruncate($out, 0); rewind($out); }
            }
            elseif (($at = strpos($line, ':')) !== false) { $state->headers[strtolower(substr($line, 0, $at))] = trim(substr($line, $at + 1)); }
            return $size * $n;
        };
        $cbs->write = function ($ptr, $size, $n, $user) use ($state, &$out, $onData, &$thrown): int {
            $chunk = FFI::string($ptr, $size * $n);
            if ($out !== null) {
                if (fwrite($out, $chunk) !== strlen($chunk)) { return 0; } // curl ends the transfer: a write error
            } else {
                $state->body .= $chunk;
            }
            if ($onData !== null) {
                try { $onData($chunk); } catch (\Throwable $e) { $thrown = $e; return 0; }
            }
            return $size * $n;
        };
        // What the progress callback is given, throttled: the last values curl reported ($latest) and the last ones told ($told).
        $latest = $told = null;
        $toldAt = 0.0;
        $finished = [false, false]; // upload complete, download complete: told once, whatever the pace
        $tell = function (array $v) use ($progress, &$told, &$toldAt, &$thrown): int {
            $told = $v;
            $toldAt = hrtime(true) / 1e9;
            try {
                return $progress(...$v) === false ? 1 : 0;
            } catch (\Throwable $e) {
                $thrown = $e;
                return 1;
            }
        };
        if ($progress !== null) {
            $cbs->xfer = function ($user, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$latest, &$told, &$toldAt, &$finished, $tell): int {
                $v = $latest = [(int)$ulTotal, (int)$ulNow, (int)$dlTotal, (int)$dlNow];
                if ($v === $told) { return 0; }
                $upDone = $v[0] > 0 && $v[1] >= $v[0] && !$finished[0];
                $downDone = $v[2] > 0 && $v[3] >= $v[2] && !$finished[1];
                if ($upDone) { $finished[0] = true; }
                if ($downDone) { $finished[1] = true; }
                if (!$upDone && !$downDone && hrtime(true) / 1e9 - $toldAt < self::PROGRESS_INTERVAL) { return 0; }
                return $tell($v);
            };
        }
        $list = Curl::headers($headers);
        $done = null;
        try {
            $set = fn(int $opt, mixed $v) => $f->curl_easy_setopt($h, $opt, $v);
            $set(Curl::OPT_URL, $url);
            NetProxy::apply($h, $url); // the system's proxy
            Curl::trust($h); // what it trusts: the phones' bundle
            $set(Curl::OPT_NOSIGNAL, 1);
            $set(Curl::OPT_PROTOCOLS_STR, $protocols);
            $set(Curl::OPT_REDIR_PROTOCOLS_STR, $protocols);
            $set(Curl::OPT_FOLLOWLOCATION, 1);
            $set(Curl::OPT_MAXREDIRS, 10); // as Go's client
            $set(Curl::OPT_ACCEPT_ENCODING, ''); // what the server compresses, undone
            $set(Curl::OPT_USERAGENT, $agent);
            if ($timeout > 0) { $set(self::OPT_TIMEOUT_MS, (int)ceil($timeout * 1000)); }
            $set(Curl::OPT_HEADERFUNCTION, $cbs->header);
            $set(Curl::OPT_WRITEFUNCTION, $cbs->write);
            if ($progress !== null) {
                $set(Curl::OPT_NOPROGRESS, 0);
                $set(Curl::OPT_XFERINFOFUNCTION, $cbs->xfer);
            }
            if ($list !== null) { $set(Curl::OPT_HTTPHEADER, $list); }
            if ($body !== null) {
                $set(Curl::OPT_POSTFIELDSIZE_LARGE, strlen($body));
                $set(self::OPT_COPYPOSTFIELDS, $body === '' ? "\0" : $body);
            }
            if ($method === 'HEAD') {
                $set(Curl::OPT_NOBODY, 1);
            } elseif ($method !== 'GET' && !($method === 'POST' && $body !== null)) { // a body posts; another method is said
                $set(Curl::OPT_CUSTOMREQUEST, $method);
            }
            CurlLoop::start($h, function (int $code) use (&$done): void { $done = $code; });
            CurlLoop::waitUntil(function () use (&$done): bool { return $done !== null; }); // by reference: an arrow function would keep the null
            if ($thrown !== null) {
                throw $thrown;
            }
            if ($done === Curl::E_ABORTED_BY_CALLBACK) {
                throw URLError::cancelled();
            }
            if ($done !== 0) {
                throw new RuntimeException("$who: " . $f->curl_easy_strerror($done));
            }
            if ($progress !== null && $latest !== null && $latest !== $told && $tell($latest) !== 0) { // the last values, whatever the pace
                throw $thrown ?? URLError::cancelled();
            }
            return new HttpResponse($state->status, $state->body, $state->headers);
        } finally {
            if ($out !== null) { fclose($out); }
            if ($done === null) { CurlLoop::stop($h); }
            $f->curl_easy_cleanup($h);
            if ($list !== null) { $f->curl_slist_free_all($list); }
        }
    }
}
