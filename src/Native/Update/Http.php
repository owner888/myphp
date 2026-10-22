<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

use FFI;
use MyPHP\Native\{Curl, Fibers};
use RuntimeException;

/**
 * The updater's requests (mygo's httpGet): GETs over HTTPS, or plain HTTP
 * from the loopback interface for tests, through the system's libcurl,
 * following redirects, waiting with Fibers so that the app runs meanwhile.
 *
 * @internal
 */
final class Http
{
    /** The User-Agent of the requests, as mygo's "MyGo/<version> <app>/<version>". */
    public static string $userAgent = 'MyPHP';
    /** A token for the GitHub API, which lifts its limit on requests, as `myphp build` passes CI's. */
    public static string $githubToken = '';

    /** The body of a successful response to GET $url, at most $max bytes. @param ?\Closure(): bool $stop */
    public static function get(string $url, int $max, ?\Closure $stop = null): string
    {
        $body = '';
        self::request($url, function (string $b) use (&$body, $max): bool {
            $body .= $b;
            return strlen($body) <= $max;
        }, $stop);
        if (strlen($body) > $max) {
            throw new RuntimeException("$url is over $max bytes");
        }
        return $body;
    }

    /**
     * Downloads $url into $path, which must have $size bytes, and returns its
     * SHA-256. $progress gets the bytes downloaded so far, at most every
     * 0.1 s and once at the end.
     *
     * @param ?\Closure(int, int): void $progress
     * @param ?\Closure(): bool $stop
     */
    public static function download(string $url, int $size, string $path, ?\Closure $progress, ?\Closure $stop = null): string
    {
        $out = fopen($path, 'wb') ?: throw new RuntimeException("cannot create $path");
        $sum = hash_init('sha256');
        $n = 0;
        $last = 0.0;
        try {
            self::request($url, function (string $b) use ($out, $sum, &$n, $size, $progress, &$last): bool {
                $n += strlen($b);
                if ($n > $size) {
                    return false;
                }
                hash_update($sum, $b);
                fwrite($out, $b);
                if ($progress !== null && ($n === $size || microtime(true) - $last > 0.1)) {
                    $last = microtime(true);
                    $progress($n, $size);
                }
                return true;
            }, $stop);
        } catch (RuntimeException $e) {
            if ($n <= $size) {
                throw new RuntimeException("downloading: {$e->getMessage()}", 0, $e);
            }
        } finally {
            fclose($out);
        }
        if ($n !== $size) {
            throw new RuntimeException("downloaded $n bytes, want $size");
        }
        return hash_final($sum, true);
    }

    /** GETs $url, giving the body to $take as it comes, which returns false to stop. @param \Closure(string): bool $take @param ?\Closure(): bool $stop */
    private static function request(string $url, \Closure $take, ?\Closure $stop): void
    {
        $u = parse_url($url);
        $scheme = strtolower((string)($u['scheme'] ?? ''));
        $host = trim((string)($u['host'] ?? ''), '[]');
        if ($scheme !== 'https' && !($scheme === 'http' && ($host === 'localhost' || $host === '::1' || str_starts_with($host, '127.')))) {
            throw new RuntimeException("refusing to download $url: updates need HTTPS");
        }
        $f = Curl::ffi();
        $h = $f->curl_easy_init();
        $m = $f->curl_multi_init();
        $list = null;
        $status = 0;
        $refused = false;
        $cbs = $f->new('myphp_curl_callbacks');
        $cbs->header = function ($ptr, $size, $n, $user) use (&$status): int {
            $line = FFI::string($ptr, $size * $n);
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $mm) === 1) {
                $status = (int)$mm[1]; // the last response's, after redirects
            }
            return $size * $n;
        };
        $cbs->write = function ($ptr, $size, $n, $user) use ($take, &$status, &$refused): int {
            if ($status !== 200) {
                return $size * $n; // a redirect's body, or an error's
            }
            if (!$take(FFI::string($ptr, $size * $n))) {
                $refused = true;
                return 0;
            }
            return $size * $n;
        };
        try {
            $set = fn(int $opt, mixed $v) => $f->curl_easy_setopt($h, $opt, $v);
            $set(Curl::OPT_URL, $url);
            $set(Curl::OPT_NOSIGNAL, 1);
            $set(Curl::OPT_PROTOCOLS_STR, 'http,https');
            $set(Curl::OPT_REDIR_PROTOCOLS_STR, 'http,https');
            $set(Curl::OPT_FOLLOWLOCATION, 1);
            $set(Curl::OPT_MAXREDIRS, 10);
            $set(Curl::OPT_USERAGENT, self::$userAgent);
            $set(Curl::OPT_HEADERFUNCTION, $cbs->header);
            $set(Curl::OPT_WRITEFUNCTION, $cbs->write);
            if (self::$githubToken !== '' && $host === 'api.github.com') {
                $list = $f->curl_slist_append($list, 'Authorization: Bearer ' . self::$githubToken);
            }
            if ($list !== null) {
                $set(Curl::OPT_HTTPHEADER, $list);
            }
            $f->curl_multi_add_handle($m, $h);
            $running = $f->new('int');
            $left = $f->new('int');
            $result = null;
            Fibers::until(function () use ($f, $m, $running, $left, &$result, $stop): bool {
                if ($stop !== null && $stop()) {
                    throw new Canceled;
                }
                $f->curl_multi_perform($m, FFI::addr($running));
                while (($msg = $f->curl_multi_info_read($m, FFI::addr($left))) !== null && !FFI::isNull($msg)) {
                    if ($msg->msg === Curl::MSG_DONE) {
                        $result = $msg->data->result;
                    }
                }
                return $result !== null;
            }, 0.002, 0.05);
            if ($refused) {
                throw new RuntimeException("$url is longer than expected");
            }
            if ($result !== 0) {
                throw new RuntimeException("GET $url: " . $f->curl_easy_strerror($result));
            }
            if ($status !== 200) {
                throw new RuntimeException("GET $url: $status " . self::statusText($status), $status);
            }
        } finally {
            $f->curl_multi_remove_handle($m, $h);
            $f->curl_easy_cleanup($h);
            $f->curl_multi_cleanup($m);
            if ($list !== null) {
                $f->curl_slist_free_all($list);
            }
        }
    }

    private static function statusText(int $code): string
    {
        return [301 => 'Moved Permanently', 302 => 'Found', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 429 => 'Too Many Requests', 500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable'][$code] ?? '';
    }
}
