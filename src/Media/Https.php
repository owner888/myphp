<?php
declare(strict_types=1);

namespace MyPHP\Media;

use FFI;
use MyPHP\Native\{Curl, CurlLoop};
use RuntimeException;

/**
 * A request of the web APIs the player asks (OpenSubtitles', TMDB's):
 * HTTPS only, the redirects followed, what the server compresses undone,
 * the User-Agent "MyPHP", 30 seconds at the most; it waits as
 * CurlLoop::waitUntil does (in a fiber, the app going on).
 *
 * @internal Media\OpenSubtitles, Media\Tmdb, Media\Assrt, Media\Shooter, PlayerPlugins\HttpApi
 */
final class Https
{
    private const USER_AGENT = 'MyPHP';
    /** CURLOPT_COPYPOSTFIELDS, CURLOPT_TIMEOUT. */
    private const OPT_COPYPOSTFIELDS = 10165, OPT_TIMEOUT = 13;

    /**
     * $verb of $url with $headers, $body posted: the last response's status,
     * body and headers (their names in lower case). HTTPS alone, but where
     * $plain lets HTTP too (assrt.net's files, which it serves over HTTP
     * only, as IINA fetches them). What fails to be sent throws, curl's
     * reason after $who.
     * @param array<string, string> $headers
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    public static function request(string $verb, string $url, array $headers, ?string $body, string $who, bool $plain = false): array
    {
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
            \MyPHP\Native\NetProxy::apply($h, $url); // the system's proxy
            $set(Curl::OPT_NOSIGNAL, 1);
            $set(Curl::OPT_PROTOCOLS_STR, $plain ? 'http,https' : 'https');
            $set(Curl::OPT_REDIR_PROTOCOLS_STR, $plain ? 'http,https' : 'https');
            $set(Curl::OPT_FOLLOWLOCATION, 1);
            $set(Curl::OPT_MAXREDIRS, 10);
            $set(Curl::OPT_ACCEPT_ENCODING, ''); // what the server compresses, undone
            $set(Curl::OPT_USERAGENT, self::USER_AGENT);
            $set(self::OPT_TIMEOUT, 30);
            $set(Curl::OPT_HEADERFUNCTION, $cbs->header);
            $set(Curl::OPT_WRITEFUNCTION, $cbs->write);
            if ($list !== null) { $set(Curl::OPT_HTTPHEADER, $list); }
            if ($body !== null) {
                $set(Curl::OPT_POSTFIELDSIZE_LARGE, strlen($body));
                $set(self::OPT_COPYPOSTFIELDS, $body);
            }
            if ($verb !== 'GET' && !($verb === 'POST' && $body !== null)) { // a body posts; another verb's said
                $set(Curl::OPT_CUSTOMREQUEST, $verb);
            }
            CurlLoop::start($h, function (int $code) use (&$done): void { $done = $code; });
            CurlLoop::waitUntil(function () use (&$done): bool { return $done !== null; }); // by reference: an arrow function would keep the null
            if ($done !== 0) {
                throw new RuntimeException("$who: " . $f->curl_easy_strerror($done));
            }
            return [$state->status, $state->body, $state->headers];
        } finally {
            if ($done === null) { CurlLoop::stop($h); }
            $f->curl_easy_cleanup($h);
            if ($list !== null) { $f->curl_slist_free_all($list); }
        }
    }
}
