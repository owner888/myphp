<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use FFI\CData;

/**
 * Sends myphp's own requests (the updater's, the fetch and WebSocket
 * plugins', a film's over HTTP) through the proxy the user set in the
 * system's settings, as browsers and the app's pages do, with its
 * exceptions and setup scripts (mygo's netproxy). For each request:
 *
 *  1. Loopback hosts (localhost, *.localhost, 127.0.0.0/8, ::1) are
 *     reached directly.
 *  2. When any of HTTP_PROXY, HTTPS_PROXY, ALL_PROXY or NO_PROXY (or its
 *     lowercase name) is set, the environment decides, as curl reads it:
 *     an explicit setting wins over the system's.
 *  3. Otherwise the system's settings for the URL do (SystemProxy); where
 *     they give none, requests go direct.
 *
 * The system's answer for a scheme and host is kept for 5 seconds, so a
 * change of the settings applies to the requests that follow without
 * restarting the app. HTTP, HTTPS and SOCKS5 proxies are taken (a SOCKS
 * proxy of no stated version for SOCKS5), SOCKS4 ones skipped.
 * @internal
 */
final class NetProxy
{
    private const TTL = 5.0, CURLOPT_PROXY = 10004;
    private const ENV = ['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy', 'NO_PROXY', 'no_proxy'];
    /** @var array<string, array{0: ?string, 1: float}> the system's answers by scheme://host: the proxy, until when */
    private static array $cache = [];
    /** What asks the system: SystemProxy::forURL; tests replace it, and the environment's variables and the clock. @var ?Closure(string): list<string> */
    public static ?Closure $system = null;
    /** @var ?Closure(string): (string|false) */
    public static ?Closure $env = null;
    /** @var ?Closure(): float */
    public static ?Closure $clock = null;

    /** Sets curl handle $h's proxy for $url: none for a loopback host, the system's unless the environment names one, which curl reads then. */
    public static function apply(CData $h, string $url): void
    {
        $p = self::decide($url);
        if ($p !== false) {
            Curl::ffi()->curl_easy_setopt($h, self::CURLOPT_PROXY, $p ?? ''); // "" is no proxy, the environment's neither
        }
    }

    /** The proxy of $url: a URL, null to go direct, false when the environment decides. */
    public static function decide(string $url): string|null|false
    {
        $u = parse_url($url);
        $host = strtolower(trim((string)($u['host'] ?? ''), '[]'));
        if (self::loopback($host)) { return null; }
        $env = self::$env ?? getenv(...);
        foreach (self::ENV as $name) {
            if ((string)$env($name) !== '') { return false; }
        }
        $scheme = strtolower((string)($u['scheme'] ?? 'http'));
        $scheme = match ($scheme) { 'wss' => 'https', 'ws' => 'http', default => $scheme }; // WebSockets go as their HTTP
        $key = $scheme . '://' . (str_contains($host, ':') ? "[$host]" : $host) . (isset($u['port']) ? ':' . $u['port'] : '');
        $now = (self::$clock ?? microtime(...))(true);
        [$proxy, $until] = self::$cache[$key] ?? [null, -1.0];
        if ($now >= $until) {
            $proxy = self::first((self::$system ?? SystemProxy::forURL(...))("$key/"));
            if (count(self::$cache) >= 256) { self::$cache = []; }
            self::$cache[$key] = [$proxy, $now + self::TTL];
        }
        return $proxy;
    }

    /** The first proxy of $list curl can use, null to go direct: "direct://" comes first, or none can be used (mygo's firstSupported). @param list<string> $list */
    public static function first(array $list): ?string
    {
        foreach ($list as $s) {
            $s = trim($s);
            if (strcasecmp($s, 'direct://') === 0 || strcasecmp($s, 'direct') === 0) { return null; }
            $u = parse_url($s);
            if ($u === false || ($u['host'] ?? '') === '' || !isset($u['scheme'])) { continue; }
            $scheme = strtolower($u['scheme']);
            if ($scheme === 'socks') { $scheme = 'socks5'; } // a SOCKS proxy of no stated version
            if (!in_array($scheme, ['http', 'https', 'socks5', 'socks5h'], true)) { continue; } // socks4, ftp…
            $auth = isset($u['user']) ? $u['user'] . (isset($u['pass']) ? ':' . $u['pass'] : '') . '@' : '';
            $host = str_contains($u['host'], ':') && $u['host'][0] !== '[' ? "[{$u['host']}]" : $u['host'];
            return "$scheme://$auth$host" . (isset($u['port']) ? ':' . $u['port'] : '');
        }
        return null;
    }

    private static function loopback(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1') { return true; }
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
    }

    /** Forgets the system's answers. @internal tests */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
