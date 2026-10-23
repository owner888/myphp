<?php
declare(strict_types=1);

namespace MyPHP;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MyPHP\Native\CookieStore;
use RuntimeException;

/**
 * The cookies of the app's pages, HttpOnly ones included (mygo's Cookies):
 * the default cookie store, which every window shares and the system
 * webview keeps past the app. Set one, as to sign the pages in with a
 * token the backend got, before the first page loads:
 *
 *     Cookies::set(new Cookie('session', $token, 'example.com', secure: true, httpOnly: true));
 *
 * Call them once the app runs: they wait for the store, the app handling
 * events meanwhile. Cookies may carry the user's credentials: bind a
 * method that does what the page needs rather than give the page them.
 */
final class Cookies
{
    /**
     * The cookies $f chooses, sorted by domain, path and name (mygo's List):
     *
     *     $all = Cookies::list();
     *     $sent = Cookies::list(new CookieFilter(url: 'https://example.com/account'));
     *
     * @return list<Cookie>
     */
    public static function list(CookieFilter $f = new CookieFilter): array
    {
        $url = null;
        if ($f->url !== '') {
            $u = parse_url($f->url);
            if ($u === false || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || ($u['host'] ?? '') === '') {
                throw new InvalidArgumentException("myphp: cookies: list needs an http or https URL, not \"{$f->url}\"");
            }
            $url = $u;
        }
        $domain = strtolower(str_starts_with($f->domain, '.') ? substr($f->domain, 1) : $f->domain);
        $out = [];
        foreach (CookieStore::all() as $c) {
            $c->domain = strtolower($c->domain);
            $host = str_starts_with($c->domain, '.') ? substr($c->domain, 1) : $c->domain;
            if ($f->name !== '' && $c->name !== $f->name || $f->path !== '' && $c->path !== $f->path
                || $domain !== '' && $host !== $domain && !str_ends_with($host, ".$domain")
                || $url !== null && !self::matchesURL($c, $url)) {
                continue;
            }
            $out[] = $c;
        }
        usort($out, fn(Cookie $a, Cookie $b): int => [$a->domain, $a->path, $a->name] <=> [$b->domain, $b->path, $b->name]);
        return $out;
    }

    /**
     * Adds the cookie, or replaces the one of its name, domain and path
     * (mygo's Set). An empty path is "/" and an empty sameSite Lax; None
     * needs secure, as do the __Secure- and __Host- name prefixes. The name
     * and value take what browsers take: no control characters, ";" or
     * spaces at either end, and the name no "=". An expiry in the past
     * deletes the cookie.
     */
    public static function set(Cookie $c): void
    {
        $c = self::normalize($c);
        if ($c->expires !== null && $c->expires <= new DateTimeImmutable) {
            self::delete($c);
            return;
        }
        CookieStore::set($c);
    }

    /** Deletes the cookie of $c's name, domain and path, its other fields aside, so that it deletes one list() returned (mygo's Delete): "example.com" and ".example.com" are different cookies; none is no error. */
    public static function delete(Cookie $c): void
    {
        [$name, $domain, $path] = self::key($c->name, $c->domain, $c->path);
        CookieStore::delete($name, $domain, $path);
    }

    /** Deletes every cookie, and nothing else the pages stored (mygo's Clear). */
    public static function clear(): void
    {
        CookieStore::clear();
    }

    /** Whether a request to $u sends $c (mygo's platform.CookieMatchesURL). @param array<string, mixed> $u parse_url's */
    public static function matchesURL(Cookie $c, array $u): bool
    {
        $host = strtolower(trim((string)$u['host'], '[]'));
        $domain = strtolower($c->domain);
        if (str_starts_with($domain, '.')) {
            $d = substr($domain, 1);
            if ($host !== $d && (!str_ends_with($host, ".$d") || filter_var($host, FILTER_VALIDATE_IP) !== false)) {
                return false;
            }
        } elseif ($host !== $domain) {
            return false;
        }
        $path = (string)($u['path'] ?? '');
        if (!str_starts_with($path, '/')) {
            $path = '/';
        }
        $p = $c->path === '' ? '/' : $c->path;
        if ($path !== $p && (!str_starts_with($path, $p) || !str_ends_with($p, '/') && $path[strlen($p)] !== '/')) {
            return false;
        }
        return !$c->secure || strcasecmp((string)$u['scheme'], 'https') === 0;
    }

    /** The cookie's name, domain in lowercase, and path, checked (mygo's normalizeCookieKey). @return array{0: string, 1: string, 2: string} */
    public static function key(string $name, string $domain, string $path): array
    {
        $bad = fn(string $what) => throw new InvalidArgumentException("myphp: cookies: invalid $what");
        if (preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            $bad('name');
        }
        if (preg_match('/[\x80-\xff]/', $domain) === 1) {
            $bad('domain');
        }
        $domain = strtolower($domain);
        $host = str_starts_with($domain, '.') ? substr($domain, 1) : $domain;
        if ($host === '' || str_ends_with($host, '.')) {
            $bad('domain');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($host !== $domain) {
                $bad('IP domain');
            }
        } else {
            if (strlen($host) > 253) {
                $bad('domain');
            }
            foreach (explode('.', $host) as $label) {
                if ($label === '' || strlen($label) > 63 || $label[0] === '-' || $label[-1] === '-' || preg_match('/^[a-z0-9_-]+$/', $label) !== 1) {
                    $bad('domain');
                }
            }
        }
        if ($path === '') {
            $path = '/';
        }
        if ($path[0] !== '/' || preg_match('/[\x00-\x1f\x7f;]/', $path) === 1) {
            $bad('path');
        }
        return [$name, $domain, $path];
    }

    /** Whether $s may be a cookie's name or value, as browsers parse them: no control character but tab, no ";", no space or tab at either end. */
    private static function text(string $s): bool
    {
        return preg_match('/[\x00-\x08\x0a-\x1f\x7f;]/', $s) !== 1 && trim($s, " \t") === $s;
    }

    /** $c checked, its domain lowercase, its path and sameSite filled in, its expiry in whole seconds of UTC (mygo's normalizeCookie). */
    public static function normalize(Cookie $c): Cookie
    {
        $bad = fn(string $what) => throw new InvalidArgumentException("myphp: cookies: invalid $what");
        if ($c->name === '' || !self::text($c->name) || str_contains($c->name, '=')) {
            $bad('name');
        }
        if (!self::text($c->value)) {
            $bad('value');
        }
        $c = clone $c;
        [, $c->domain, $c->path] = self::key($c->name, $c->domain, $c->path);
        if ($c->sameSite === '') {
            $c->sameSite = Cookie::SAME_SITE_LAX;
        }
        match ($c->sameSite) {
            Cookie::SAME_SITE_LAX, Cookie::SAME_SITE_STRICT => null,
            Cookie::SAME_SITE_NONE => $c->secure || $bad('SameSite=None without Secure'),
            default => $bad('SameSite'),
        };
        if ($c->expires !== null) {
            $c->expires = (new DateTimeImmutable('@' . $c->expires->getTimestamp()))->setTimezone(new DateTimeZone('UTC'));
            $year = (int)$c->expires->format('Y');
            if ($year < 1601 || $year > 9999) {
                $bad('expiration year');
            }
        }
        if ((str_starts_with($c->name, '__Secure-') || str_starts_with($c->name, '__Host-')) && !$c->secure) {
            $bad('cookie prefix without Secure');
        }
        if (str_starts_with($c->name, '__Host-') && (str_starts_with($c->domain, '.') || $c->path !== '/')) {
            $bad('__Host- domain or path');
        }
        return $c;
    }
}
