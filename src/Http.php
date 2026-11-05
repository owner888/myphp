<?php
declare(strict_types=1);

namespace MyPHP;

use FFI;
use MyPHP\Native\{Curl, CurlLoop, NetProxy};
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
 *     App::go(function () use ($state, $win) {
 *         $r = Http::get('https://api.github.com/repos/egoist/mygo', ['Accept' => 'application/vnd.github+json']);
 *         $state->stars = $r->ok() ? $r->json()['stargazers_count'] : null;
 *         $win->invalidate(); // the view shows it
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

    /** GET $url with $headers (name => value). */
    public static function get(string $url, array $headers = []): HttpResponse
    {
        return self::request('GET', $url, $headers);
    }

    /**
     * $method of $url (http or https) with $headers (name => value), $body
     * sent when not null, taking $timeout seconds at most (0: no limit, as
     * Go's default client).
     * @param array<string, string> $headers
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 0.0): HttpResponse
    {
        return self::send($method, $url, $headers, $body, $timeout, 'http,https', 'MyPHP', 'http');
    }

    /**
     * request() with the protocols $protocols allows (curl's list, the
     * redirects' too), the User-Agent $agent unless $headers names one, and
     * $who before curl's reason when it fails. @internal Media\Https
     * @param array<string, string> $headers
     */
    public static function send(string $method, string $url, array $headers, ?string $body, float $timeout, string $protocols, string $agent, string $who): HttpResponse
    {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $method) !== 1) {
            throw new RuntimeException("$who: invalid method \"$method\"");
        }
        $f = Curl::ffi();
        $h = $f->curl_easy_init();
        $state = (object)['status' => 0, 'body' => '', 'headers' => []];
        $cbs = $f->new('myphp_curl_callbacks');
        $cbs->header = function ($ptr, $size, $n, $user) use ($state): int {
            $line = FFI::string($ptr, $size * $n);
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) { [$state->status, $state->body, $state->headers] = [(int)$m[1], '', []]; } // the last response's, after redirects
            elseif (($at = strpos($line, ':')) !== false) { $state->headers[strtolower(substr($line, 0, $at))] = trim(substr($line, $at + 1)); }
            return $size * $n;
        };
        $cbs->write = function ($ptr, $size, $n, $user) use ($state): int {
            $state->body .= FFI::string($ptr, $size * $n);
            return $size * $n;
        };
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
            if ($done !== 0) {
                throw new RuntimeException("$who: " . $f->curl_easy_strerror($done));
            }
            return new HttpResponse($state->status, $state->body, $state->headers);
        } finally {
            if ($done === null) { CurlLoop::stop($h); }
            $f->curl_easy_cleanup($h);
            if ($list !== null) { $f->curl_slist_free_all($list); }
        }
    }
}
