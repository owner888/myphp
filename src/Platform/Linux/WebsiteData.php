<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use DateTimeImmutable;
use FFI\CData;
use MyPHP\{App, Cookie, PathName, SiteData};
use MyPHP\Platform;
use RuntimeException;

/**
 * What pages store, over WebKitGTK (mygo's linux session.go, cookies.go
 * and content_rules.go): sessions' web contexts, the cookies of a
 * context's WebKitCookieManager as libsoup's SoupCookies, the data its
 * WebKitWebsiteDataManager clears, and content rules compiled into
 * WebKitUserContentFilters a WebKitUserContentFilterStore keeps. Each
 * call waits for WebKit's GAsyncReadyCallback, the main loop turning
 * meanwhile (App::wait).
 *
 * @internal App, WebView
 */
final class WebsiteData
{
    /** WEBKIT_COOKIE_PERSISTENT_STORAGE_SQLITE */
    private const SQLITE = 1;
    /** WebKitWebsiteDataTypes by SiteData kind (mygo's websiteDataTypes); WEBKIT_WEBSITE_DATA_ALL. */
    private const TYPES = [SiteData::COOKIES => 1 << 8, SiteData::LOCAL_STORAGE => 1 << 3 | 1 << 4, SiteData::INDEXED_DB => 1 << 5 | 1 << 6,
        SiteData::CACHES => 1 << 0 | 1 << 1 | 1 << 2 | 1 << 13, SiteData::SERVICE_WORKERS => 1 << 12];
    private const ALL = (1 << 14) - 1;
    /** SoupSameSitePolicy's values by Cookie's names. */
    private const SAME_SITE = [Cookie::SAME_SITE_NONE => 0, Cookie::SAME_SITE_LAX => 1, Cookie::SAME_SITE_STRICT => 2];

    private static ?CData $ready = null;
    /** @var array<int, Closure(CData, CData): void> the calls waiting for WebKit, by key */
    private static array $waiting = [];
    private static int $next = 0;
    private static ?CData $filters = null;

    /**
     * Keeps the default context's cookies in cookies.sqlite of the app's
     * user data directory, as macOS keeps its: WebKitGTK keeps them in
     * memory unless told where (mygo's persistCookies). WebKit::ffi() calls
     * it once WebKitGTK loaded, before any page loads.
     */
    public static function persistCookies(): void
    {
        $f = WebKit::ffi();
        $f->webkit_cookie_manager_set_persistent_storage($f->webkit_web_context_get_cookie_manager($f->webkit_web_context_get_default()),
            App::path(PathName::UserData) . '/cookies.sqlite', self::SQLITE);
    }

    /** A web context with a website data manager of its own (mygo's linux NewDataStore), its cookies kept in its data directory. */
    public static function newStore(Platform\DataStoreOptions $o): DataStore
    {
        $f = WebKit::ffi();
        if ($o->ephemeral) {
            $manager = $f->webkit_website_data_manager_new_ephemeral();
        } else {
            if ($o->dataDir === '' || $o->cacheDir === '') {
                throw new RuntimeException('myphp: no directory for the session\'s data');
            }
            if (!is_dir($o->dataDir) && !mkdir($o->dataDir, 0700, true) && !is_dir($o->dataDir)) {
                throw new RuntimeException("myphp: cannot create {$o->dataDir}");
            }
            $manager = $f->webkit_website_data_manager_new('base-data-directory', $o->dataDir, 'base-cache-directory', $o->cacheDir, null);
        }
        $ctx = $f->webkit_web_context_new_with_website_data_manager($manager);
        $f->g_object_unref($manager); // the context holds it
        if (!$o->ephemeral) {
            $f->webkit_cookie_manager_set_persistent_storage($f->webkit_web_context_get_cookie_manager($ctx), "{$o->dataDir}/cookies.sqlite", self::SQLITE);
        }
        WebView::contextMade($ctx);
        return new DataStore($ctx);
    }

    /** The web context of $store, the default one for null. */
    public static function context(?Platform\DataStore $store): CData
    {
        return $store instanceof DataStore ? $store->ctx : WebKit::ffi()->webkit_web_context_get_default();
    }

    /** Clears the data of $types (SiteData bits) of $store's context (mygo's ClearBrowsingData). */
    public static function clear(?Platform\DataStore $store, int $types): void
    {
        $f = WebKit::ffi();
        $kinds = 0;
        if (($types & SiteData::ALL) === SiteData::ALL) {
            $kinds = self::ALL;
        } else {
            foreach (self::TYPES as $bit => $k) {
                if ($types & $bit) { $kinds |= $k; }
            }
        }
        $manager = $f->webkit_web_context_get_website_data_manager(self::context($store));
        self::await(fn($cb, $data) => $f->webkit_website_data_manager_clear($manager, $kinds, 0, null, $cb, $data),
            fn($source, $res) => self::check('clearing data', fn($err) => $f->webkit_website_data_manager_clear_finish($source, $res, $err)));
    }

    /** Every cookie of $store (mygo's ListCookies). @return list<Cookie> */
    public static function cookies(?Platform\DataStore $store): array
    {
        $out = [];
        foreach (self::soupCookies($store) as $c) {
            $out[] = self::read($c);
            WebKit::ffi()->soup_cookie_free($c);
        }
        return $out;
    }

    /** @return list<CData> the SoupCookies of $store's context, which the caller frees */
    private static function soupCookies(?Platform\DataStore $store): array
    {
        $f = WebKit::ffi();
        $manager = $f->webkit_web_context_get_cookie_manager(self::context($store));
        return self::await(fn($cb, $data) => $f->webkit_cookie_manager_get_all_cookies($manager, null, $cb, $data), function ($source, $res) use ($f): array {
            $err = $f->new('void*[1]');
            $list = $f->webkit_cookie_manager_get_all_cookies_finish($source, $res, $err);
            $cookies = [];
            for ($n = $list; $n !== null; $n = $n->next) { $cookies[] = $n->data; } // WebKit hands over the list and its cookies
            if ($list !== null) { $f->g_list_free($list); }
            if ($err[0] !== null) {
                foreach ($cookies as $c) { $f->soup_cookie_free($c); }
                throw new RuntimeException('myphp: cookies: ' . WebKit::errorMessage($err[0]));
            }
            return $cookies;
        });
    }

    private static function read(CData $c): Cookie
    {
        $f = WebKit::ffi();
        $d = $f->soup_cookie_get_expires($c);
        $same = array_search($f->soup_cookie_get_same_site_policy($c), self::SAME_SITE, true);
        return new Cookie((string)$f->soup_cookie_get_name($c), (string)$f->soup_cookie_get_value($c), strtolower((string)$f->soup_cookie_get_domain($c)),
            (string)$f->soup_cookie_get_path($c), $d === null ? null : new DateTimeImmutable('@' . $f->g_date_time_to_unix($d)),
            (bool)$f->soup_cookie_get_secure($c), (bool)$f->soup_cookie_get_http_only($c), $same === false ? '' : $same);
    }

    /** Adds $c, normalized, to $store's cookies (mygo's SetCookie). */
    public static function setCookie(?Platform\DataStore $store, Cookie $c): void
    {
        $f = WebKit::ffi();
        $native = $f->soup_cookie_new($c->name, $c->value, $c->domain, $c->path, -1);
        if ($native === null) {
            throw new RuntimeException('myphp: cookies: set could not construct SoupCookie');
        }
        $f->soup_cookie_set_secure($native, $c->secure ? 1 : 0);
        $f->soup_cookie_set_http_only($native, $c->httpOnly ? 1 : 0);
        $f->soup_cookie_set_same_site_policy($native, self::SAME_SITE[$c->sameSite] ?? 1);
        if ($c->expires !== null) {
            $date = $f->g_date_time_new_from_unix_utc($c->expires->getTimestamp());
            $f->soup_cookie_set_expires($native, $date);
            $f->g_date_time_unref($date);
        }
        $manager = $f->webkit_web_context_get_cookie_manager(self::context($store));
        try {
            self::await(fn($cb, $data) => $f->webkit_cookie_manager_add_cookie($manager, $native, null, $cb, $data),
                fn($source, $res) => self::check('cookies: set', fn($err) => $f->webkit_cookie_manager_add_cookie_finish($source, $res, $err)));
        } finally {
            $f->soup_cookie_free($native);
        }
    }

    /** Deletes the stored cookie of the key: libsoup deletes the one of the same name, path and value, which the key lacks (mygo's DeleteCookie). */
    public static function deleteCookie(?Platform\DataStore $store, string $name, string $domain, string $path): void
    {
        $f = WebKit::ffi();
        $match = null;
        $ambiguous = false;
        foreach (self::soupCookies($store) as $c) {
            if ((string)$f->soup_cookie_get_name($c) === $name && strtolower((string)$f->soup_cookie_get_domain($c)) === $domain && (string)$f->soup_cookie_get_path($c) === $path) {
                $ambiguous = $ambiguous || $match !== null;
                $match ??= $f->soup_cookie_copy($c);
            }
            $f->soup_cookie_free($c);
        }
        if ($match === null) {
            return;
        }
        $manager = $f->webkit_web_context_get_cookie_manager(self::context($store));
        try {
            if ($ambiguous) {
                throw new RuntimeException('myphp: cookies: ambiguous cookie key');
            }
            self::await(fn($cb, $data) => $f->webkit_cookie_manager_delete_cookie($manager, $match, null, $cb, $data),
                fn($source, $res) => self::check('cookies: delete', fn($err) => $f->webkit_cookie_manager_delete_cookie_finish($source, $res, $err)));
        } finally {
            $f->soup_cookie_free($match);
        }
    }

    /**
     * The WebKitUserContentFilter kept as $identifier, else one compiled
     * from $rules and kept, after which $id's filters of other rules go
     * (mygo's compileContentRules): its reference kept for good, as a page
     * may apply it at any time. The store is in the app's user data
     * directory, content-rules.
     */
    public static function compileContentRules(string $id, string $identifier, string $rules): CData
    {
        $f = WebKit::ffi();
        if (self::$filters === null) {
            $dir = App::path(PathName::UserData) . '/content-rules';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("cannot create $dir");
            }
            self::$filters = $f->webkit_user_content_filter_store_new($dir);
        }
        $store = self::$filters;
        $filter = self::await(fn($cb, $data) => $f->webkit_user_content_filter_store_load($store, $identifier, null, $cb, $data), function ($source, $res) use ($f, $store) {
            $err = $f->new('void*[1]');
            $filter = $f->webkit_user_content_filter_store_load_finish($store, $res, $err);
            if ($err[0] !== null) { $f->g_error_free($f->cast('GError*', $err[0])); } // none kept yet
            return $filter;
        });
        if ($filter !== null) {
            return $filter;
        }
        $source = $f->g_bytes_new($rules, strlen($rules)); // copies them
        try {
            $filter = self::await(fn($cb, $data) => $f->webkit_user_content_filter_store_save($store, $identifier, $source, null, $cb, $data), function ($s, $res) use ($f, $store) {
                $err = $f->new('void*[1]');
                $filter = $f->webkit_user_content_filter_store_save_finish($store, $res, $err);
                if ($err[0] !== null) { throw new RuntimeException(WebKit::errorMessage($err[0])); }
                return $filter ?? throw new RuntimeException('WebKitGTK made no filter');
            });
        } finally {
            $f->g_bytes_unref($source);
        }
        $names = self::await(fn($cb, $data) => $f->webkit_user_content_filter_store_fetch_identifiers($store, null, $cb, $data), function ($s, $res) use ($f, $store): array {
            $list = $f->webkit_user_content_filter_store_fetch_identifiers_finish($store, $res);
            $out = [];
            for ($i = 0; $list !== null && $list[$i] !== null; $i++) { $out[] = \FFI::string($list[$i]); }
            if ($list !== null) { $f->g_strfreev($list); }
            return $out;
        });
        foreach ($names as $name) {
            if (\MyPHP\ContentRules::stale($id, $identifier, $name)) {
                self::await(fn($cb, $data) => $f->webkit_user_content_filter_store_remove($store, $name, null, $cb, $data), function ($s, $res) use ($f, $store, $name): void {
                    $err = $f->new('void*[1]');
                    if (!$f->webkit_user_content_filter_store_remove_finish($store, $res, $err)) {
                        \MyPHP\Logger::warn("content rules: removing $name: " . WebKit::errorMessage($err[0]), 'linux');
                    }
                });
            }
        }
        return $filter;
    }

    /** A call's gboolean result, its GError thrown as "myphp: $what: …". @param Closure(CData): int $finish */
    private static function check(string $what, Closure $finish): void
    {
        $err = WebKit::ffi()->new('void*[1]');
        $ok = $finish($err);
        if ($err[0] !== null) {
            throw new RuntimeException("myphp: $what: " . WebKit::errorMessage($err[0]));
        }
        if (!$ok) {
            throw new RuntimeException("myphp: $what failed");
        }
    }

    /**
     * Starts a call with $start(callback, data) and waits until WebKit
     * calls back; $finish(source, result) runs in the callback, as the
     * result lives only there, and what it returns or throws is the call's.
     * @param Closure(CData, CData): void $start @param Closure(CData, CData): mixed $finish
     */
    private static function await(Closure $start, Closure $finish): mixed
    {
        $f = WebKit::ffi();
        if (self::$ready === null) {
            self::$ready = $f->new('HNotify', false);
            self::$ready->fn = function ($source, $res, $data): void {
                $k = WebKit::addr($data);
                $fn = self::$waiting[$k] ?? null;
                unset(self::$waiting[$k]);
                $fn?->__invoke($source, $res);
            };
        }
        $key = ++self::$next;
        $done = false; $value = null; $error = null;
        self::$waiting[$key] = function ($source, $res) use ($finish, &$done, &$value, &$error): void {
            try {
                $value = $finish($source, $res);
            } catch (\Throwable $e) {
                $error = $e; // thrown after, never out of the callback
            }
            $done = true;
        };
        $start(self::$ready->fn, $f->cast('void*', $key));
        App::wait(function () use (&$done): bool { return $done; });
        if ($error !== null) {
            throw $error;
        }
        return $value;
    }
}
