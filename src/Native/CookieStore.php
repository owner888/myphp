<?php
declare(strict_types=1);

namespace MyPHP\Native;

use DateTimeImmutable;
use MyPHP\{App, Cookie};
use RuntimeException;

/**
 * The cookies of a website data store, a session's (mygo's darwin
 * cookies.go and session.go):
 * WKHTTPCookieStore, whose completion handlers come on the main thread;
 * each call turns the run loop until its handler ran (App::wait).
 *
 * @internal
 */
final class CookieStore
{
    /** The cookie store of WKWebsiteDataStore $data. */
    private static function store($data)
    {
        return ObjC::msg('p:', $data, 'httpCookieStore');
    }

    /** The default WKWebsiteDataStore, the default session's. */
    public static function defaultData()
    {
        App::running() || throw new RuntimeException('myphp: sessions and cookies need the running app (call them from App::whenReady)');
        return ObjC::msg('p:', ObjC::cls('WKWebsiteDataStore'), 'defaultDataStore');
    }

    /**
     * Removes the data of $types (SiteData bits) from WKWebsiteDataStore
     * $data, whatever its age, and waits until WebKit is done (mygo's
     * removeData): the WKWebsiteDataTypes of each kind this WebKit has.
     */
    public static function removeData($data, int $types): void
    {
        App::running() || throw new RuntimeException('myphp: sessions need the running app (call them from App::whenReady)');
        $cls = ObjC::cls('WKWebsiteDataStore');
        if (($types & \MyPHP\SiteData::ALL) === \MyPHP\SiteData::ALL) {
            $set = ObjC::msg('p:', $cls, 'allWebsiteDataTypes');
        } else {
            $set = ObjC::msg('p:', ObjC::cls('NSMutableSet'), 'set');
            $lib = ObjC::$c->dlopen('/System/Library/Frameworks/WebKit.framework/WebKit', 2);
            $kinds = [\MyPHP\SiteData::COOKIES => ['WKWebsiteDataTypeCookies'], \MyPHP\SiteData::LOCAL_STORAGE => ['WKWebsiteDataTypeLocalStorage', 'WKWebsiteDataTypeSessionStorage'],
                \MyPHP\SiteData::INDEXED_DB => ['WKWebsiteDataTypeIndexedDBDatabases', 'WKWebsiteDataTypeWebSQLDatabases'],
                \MyPHP\SiteData::CACHES => ['WKWebsiteDataTypeDiskCache', 'WKWebsiteDataTypeMemoryCache', 'WKWebsiteDataTypeOfflineWebApplicationCache', 'WKWebsiteDataTypeFetchCache'],
                \MyPHP\SiteData::SERVICE_WORKERS => ['WKWebsiteDataTypeServiceWorkerRegistrations']];
            foreach ($kinds as $bit => $names) {
                if (($types & $bit) === 0) { continue; }
                foreach ($names as $n) {
                    $sym = ObjC::$c->dlsym($lib, $n); // the constant, an NSString*, where this WebKit has it
                    if ($sym !== null) { ObjC::msg('v:p', $set, 'addObject:', ObjC::import(ObjC::$c->cast('void*[1]', $sym)[0])); }
                }
            }
        }
        self::await('', fn($block) => ObjC::msg('v:ppp', $data, 'removeDataOfTypes:modifiedSince:completionHandler:', $set, ObjC::msg('p:', ObjC::cls('NSDate'), 'distantPast'), $block));
    }

    /** Runs $start with a block's arguments' receiver, and waits until the block was called. @return list<mixed> what the block got */
    private static function await(string $args, \Closure $start): array
    {
        $got = null;
        $start(ObjC::block($args, function (...$a) use (&$got): void { $got = $a; }));
        App::wait(function () use (&$got): bool { return $got !== null; });
        return $got;
    }

    /** @return list<mixed> the cookies of an NSArray */
    private static function items($array): array
    {
        $out = [];
        for ($i = 0, $n = ObjC::msg('L:', $array, 'count'); $i < $n; $i++) {
            $out[] = ObjC::msg('p:L', $array, 'objectAtIndex:', $i);
        }
        return $out;
    }

    private static function str($s): string
    {
        return $s === null ? '' : ObjC::phpStr($s);
    }

    private static function read($c): Cookie
    {
        // Foundation names only Lax and Strict, and gives a cookie set with SameSite=None no policy, which WebKit sends as None.
        $same = strtolower(self::str(ObjC::msg('p:', $c, 'sameSitePolicy')));
        $expires = null;
        $date = ObjC::msg('p:', $c, 'expiresDate');
        if ($date !== null && !ObjC::msg('b:', $c, 'isSessionOnly')) {
            $expires = new DateTimeImmutable('@' . (int)ObjC::msg('d:', $date, 'timeIntervalSince1970'));
        }
        return new Cookie(self::str(ObjC::msg('p:', $c, 'name')), self::str(ObjC::msg('p:', $c, 'value')), strtolower(self::str(ObjC::msg('p:', $c, 'domain'))),
            self::str(ObjC::msg('p:', $c, 'path')), $expires, ObjC::msg('b:', $c, 'isSecure'), ObjC::msg('b:', $c, 'isHTTPOnly'), $same === '' ? Cookie::SAME_SITE_NONE : $same);
    }

    /** @return list<Cookie> every cookie of WKWebsiteDataStore $data */
    public static function all($data): array
    {
        $store = self::store($data);
        [$array] = self::await('p', fn($block) => ObjC::msg('v:p', $store, 'getAllCookies:', $block));
        return array_map(self::read(...), self::items($array));
    }

    public static function set(Cookie $c, $data): void
    {
        $store = self::store($data);
        $host = ltrim($c->domain, '.');
        if (str_contains($host, ':')) {
            $host = "[$host]";
        }
        // Foundation parses the attributes, with a stand-in name and value: it would split a value with ", " in it as two cookies.
        $header = "n=v; Path={$c->path}; SameSite={$c->sameSite}";
        if (str_starts_with($c->domain, '.')) {
            $header .= "; Domain={$c->domain}";
        }
        if ($c->expires !== null) {
            $header .= '; Expires=' . $c->expires->format('D, d M Y H:i:s') . ' GMT';
        }
        if ($c->secure) {
            $header .= '; Secure';
        }
        if ($c->httpOnly) {
            $header .= '; HttpOnly';
        }
        $headers = ObjC::msg('p:pp', ObjC::cls('NSDictionary'), 'dictionaryWithObject:forKey:', ObjC::str($header), ObjC::str('Set-Cookie'));
        $url = ObjC::msg('p:p', ObjC::cls('NSURL'), 'URLWithString:', ObjC::str(($c->secure ? 'https' : 'http') . "://$host/"));
        $array = ObjC::msg('p:pp', ObjC::cls('NSHTTPCookie'), 'cookiesWithResponseHeaderFields:forURL:', $headers, $url);
        if (ObjC::msg('L:', $array, 'count') !== 1) {
            throw new RuntimeException('myphp: cookies: set could not construct NSHTTPCookie');
        }
        $props = ObjC::msg('p:', ObjC::msg('p:', ObjC::msg('p:L', $array, 'objectAtIndex:', 0), 'properties'), 'mutableCopy');
        ObjC::msg('v:pp', $props, 'setObject:forKey:', ObjC::str($c->name), ObjC::str('Name')); // NSHTTPCookieName
        ObjC::msg('v:pp', $props, 'setObject:forKey:', ObjC::str($c->value), ObjC::str('Value')); // NSHTTPCookieValue
        $native = ObjC::msg('p:p', ObjC::cls('NSHTTPCookie'), 'cookieWithProperties:', $props);
        ObjC::msg('v:', $props, 'release');
        if ($native === null) {
            throw new RuntimeException('myphp: cookies: set could not construct NSHTTPCookie');
        }
        $p = self::read($native);
        if ($p->name !== $c->name || $p->domain !== $c->domain || $p->path !== $c->path || $p->value !== $c->value || $p->secure !== $c->secure || $p->httpOnly !== $c->httpOnly || $p->sameSite !== $c->sameSite) {
            throw new RuntimeException(sprintf('myphp: cookies: set made a cookie of domain %s, path %s, secure %s, httpOnly %s, sameSite %s', $p->domain, $p->path, json_encode($p->secure), json_encode($p->httpOnly), $p->sameSite));
        }
        self::await('', fn($block) => ObjC::msg('v:pp', $store, 'setCookie:completionHandler:', $native, $block));
    }

    public static function delete(string $name, string $domain, string $path, $data): void
    {
        $store = self::store($data);
        [$array] = self::await('p', fn($block) => ObjC::msg('v:p', $store, 'getAllCookies:', $block));
        $match = null;
        foreach (self::items($array) as $c) {
            if (self::str(ObjC::msg('p:', $c, 'name')) === $name && strtolower(self::str(ObjC::msg('p:', $c, 'domain'))) === $domain && self::str(ObjC::msg('p:', $c, 'path')) === $path) {
                if ($match !== null) {
                    throw new RuntimeException('myphp: cookies: ambiguous cookie key');
                }
                $match = $c;
            }
        }
        if ($match !== null) {
            self::await('', fn($block) => ObjC::msg('v:pp', $store, 'deleteCookie:completionHandler:', $match, $block));
        }
    }

    /** Removes the cookies of WKWebsiteDataStore $data, its other data kept. */
    public static function clear($data): void
    {
        $types = ObjC::msg('p:p', ObjC::cls('NSSet'), 'setWithObject:', ObjC::str('WKWebsiteDataTypeCookies'));
        self::await('', fn($block) => ObjC::msg('v:ppp', $data, 'removeDataOfTypes:modifiedSince:completionHandler:', $types, ObjC::msg('p:', ObjC::cls('NSDate'), 'distantPast'), $block));
    }
}
