<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * A request of the web APIs the player asks (OpenSubtitles', TMDB's):
 * HTTPS only, the redirects followed, what the server compresses undone,
 * the User-Agent "MyPHP", 30 seconds at the most; MyPHP\Http's, which
 * waits as CurlLoop::waitUntil does (in a fiber, the app going on).
 *
 * @internal Media\OpenSubtitles, Media\Tmdb, Media\Assrt, Media\Shooter, PlayerPlugins\HttpApi
 */
final class Https
{
    private const USER_AGENT = 'MyPHP';
    /** Seconds a request takes at the most. */
    private const TIMEOUT = 30.0;

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
        $r = \MyPHP\Http::send($verb, $url, $headers, $body, self::TIMEOUT, $plain ? 'http,https' : 'https', self::USER_AGENT, $who);
        return [$r->status, $r->body, $r->headers];
    }
}
