<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use DateTimeImmutable;
use FFI;
use FFI\CData;
use MyPHP\{App, Cookie, SiteData};
use MyPHP\Native\Com;
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;
use RuntimeException;

/**
 * What pages store, over WebView2 (mygo's windows cookies.go and
 * app.go's ClearBrowsingData): the cookies of the profile's
 * ICoreWebView2CookieManager, the data its ICoreWebView2Profile2 clears.
 * WebView2 has those only through a web view: a call takes that of a page
 * shown, or, before there is one, that of a hidden web view in the app's
 * window, made for it and closed once a page shows. Each call waits for
 * WebView2's completion handler, the message loop turning meanwhile
 * (App::wait). WebView2 changes cookies after its calls return, so a
 * change waits till GetCookies sees it (mygo's settle).
 *
 * @internal App
 */
final class WebsiteData
{
    private const IID_VIEW2 = '9e8f0cf8-e670-4b5e-b2bc-73e061e3184c', IID_VIEW13 = 'f75f09a8-667e-4983-88d6-c8773f315e84', IID_PROFILE2 = 'fa740d4b-5eae-4344-a8ad-74be31925397';
    /** COREWEBVIEW2_COOKIE_SAME_SITE_KIND by Cookie's names. */
    private const SAME_SITE = [Cookie::SAME_SITE_NONE => 0, Cookie::SAME_SITE_LAX => 1, Cookie::SAME_SITE_STRICT => 2];
    /** How long a change may take to show in GetCookies, in seconds. */
    private const SETTLE = 2.0;
    /** Seconds WebView2 is waited for to answer a call, after which the call fails: one that never answers (a runtime gone wrong) holds no app. */
    private const ANSWER = 15;
    /** The calls waiting for WebView2's answer, in App::wait's nested loops. */
    private static int $waiting = 0;

    private static ?FFI $f = null;
    /** The hidden web view, while there is no page. */
    /** @var array<int, array{0: CData, 1: CData}> the hidden web views of the profiles, their controllers and views, by the profile's key (0 the default one's) */
    private static array $hidden = [];

    private static function f(): FFI
    {
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct { void *r0[66]; int32_t (*get_CookieManager)(void *self, void **manager); } View2Vtbl;
            typedef struct { View2Vtbl *v; } View2;
            typedef struct { void *r0[105]; int32_t (*get_Profile)(void *self, void **profile); } View13Vtbl;
            typedef struct { View13Vtbl *v; } View13;
            typedef struct { void *r0[10]; int32_t (*ClearBrowsingData)(void *self, int32_t kinds, void *handler); void *r11; int32_t (*ClearBrowsingDataAll)(void *self, void *handler); } Profile2Vtbl;
            typedef struct { Profile2Vtbl *v; } Profile2;
            typedef struct {
                void *r0[3];
                int32_t (*CreateCookie)(void *self, const void *name, const void *value, const void *domain, const void *path, void **cookie);
                void *r4;
                int32_t (*GetCookies)(void *self, const void *uri, void *handler);
                int32_t (*AddOrUpdateCookie)(void *self, void *cookie);
                void *r7[2];
                int32_t (*DeleteCookiesWithDomainAndPath)(void *self, const void *name, const void *domain, const void *path);
                int32_t (*DeleteAllCookies)(void *self);
            } ManagerVtbl;
            typedef struct { ManagerVtbl *v; } Manager;
            typedef struct { void *r0[3]; int32_t (*get_Count)(void *self, uint32_t *count); int32_t (*GetValueAtIndex)(void *self, uint32_t index, void **cookie); } ListVtbl;
            typedef struct { ListVtbl *v; } CookieList;
            typedef struct {
                void *r0[3];
                int32_t (*get_Name)(void *self, uint16_t **s);
                int32_t (*get_Value)(void *self, uint16_t **s);
                void *r5;
                int32_t (*get_Domain)(void *self, uint16_t **s);
                int32_t (*get_Path)(void *self, uint16_t **s);
                int32_t (*get_Expires)(void *self, double *seconds);
                int32_t (*put_Expires)(void *self, double seconds);
                int32_t (*get_IsHttpOnly)(void *self, int32_t *on);
                int32_t (*put_IsHttpOnly)(void *self, int32_t on);
                int32_t (*get_SameSite)(void *self, int32_t *kind);
                int32_t (*put_SameSite)(void *self, int32_t kind);
                int32_t (*get_IsSecure)(void *self, int32_t *on);
                int32_t (*put_IsSecure)(void *self, int32_t on);
                int32_t (*get_IsSession)(void *self, int32_t *on);
            } CookieVtbl;
            typedef struct { CookieVtbl *v; } NativeCookie;
            C);
    }

    /** The profile of $store: a session's (DataStore), null for the default one. */
    private static function store(?Platform\DataStore $store): ?DataStore
    {
        return $store instanceof DataStore ? $store : null;
    }

    /** Every cookie of $store (mygo's ListCookies). @return list<Cookie> */
    public static function cookies(?Platform\DataStore $store): array
    {
        return self::withManager($store, function (CData $m): array {
            $out = [];
            foreach (self::nativeCookies($m) as $c) {
                try { $out[] = self::read($c); } finally { Com::release($c); }
            }
            return $out;
        });
    }

    /** Adds the cookie, normalized, or replaces the one of its name, domain and path, once GetCookies sees it (mygo's SetCookie). */
    public static function setCookie(?Platform\DataStore $store, Cookie $c): void
    {
        self::withManager($store, function (CData $m) use ($c): void {
            $f = self::f();
            $out = $f->new('void *');
            Com::check(($m->v->CreateCookie)($m, Win32::wide($c->name), Win32::wide($c->value), Win32::wide($c->domain), Win32::wide($c->path === '' ? '/' : $c->path), FFI::addr($out)), 'cookies: CreateCookie');
            $n = Com::typed($f, $out, 'NativeCookie *');
            try {
                Com::check(($n->v->put_Expires)($n, $c->expires === null ? -1.0 : (float)$c->expires->getTimestamp()), 'cookies: put_Expires');
                Com::check(($n->v->put_IsSecure)($n, $c->secure ? 1 : 0), 'cookies: put_IsSecure');
                Com::check(($n->v->put_IsHttpOnly)($n, $c->httpOnly ? 1 : 0), 'cookies: put_IsHttpOnly');
                Com::check(($n->v->put_SameSite)($n, self::SAME_SITE[$c->sameSite] ?? 1), 'cookies: put_SameSite');
                Com::check(($m->v->AddOrUpdateCookie)($m, $n), 'cookies: AddOrUpdateCookie');
            } finally {
                Com::release($out);
            }
            $want = [$c->name, strtolower($c->domain), $c->path === '' ? '/' : $c->path, $c->value];
            self::settle($m, true, fn(array $k) => $k === $want, 'the engine did not keep the cookie');
        });
    }

    /** Deletes the cookie of the name, domain and path, which none is no error (mygo's DeleteCookie). */
    public static function deleteCookie(?Platform\DataStore $store, string $name, string $domain, string $path): void
    {
        self::withManager($store, function (CData $m) use ($name, $domain, $path): void {
            $key = [$name, strtolower($domain), $path];
            $old = self::stored($m, fn(array $k) => array_slice($k, 0, 3) === $key); // those there now must go; a page may set it again meanwhile
            if ($old === []) {
                return;
            }
            Com::check(($m->v->DeleteCookiesWithDomainAndPath)($m, Win32::wide($name), Win32::wide($domain), Win32::wide($path)), 'cookies: DeleteCookiesWithDomainAndPath');
            self::settle($m, false, fn(array $k) => in_array($k, $old, true), 'the cookie is still there');
        });
    }

    /** Deletes every cookie, those there now; pages may set new ones meanwhile (mygo's ClearCookies). */
    public static function clearCookies(?Platform\DataStore $store): void
    {
        self::withManager($store, function (CData $m): void {
            $old = self::stored($m, fn() => true);
            Com::check(($m->v->DeleteAllCookies)($m), 'cookies: DeleteAllCookies');
            self::settle($m, false, fn(array $k) => in_array($k, $old, true), 'cookies are still there');
        });
    }

    /** Clears the data of $types (SiteData bits) of the profile (mygo's ClearBrowsingData): ClearBrowsingDataAll for all of it. */
    public static function clear(?Platform\DataStore $store, int $types): void
    {
        self::withView($store, function (CData $view) use ($types): void {
            $f = self::f();
            $v13 = Com::query($view, self::IID_VIEW13) ?? throw new RuntimeException('clearing browsing data needs a newer WebView2 Runtime');
            $out = $f->new('void *');
            $x = Com::typed($f, $v13, 'View13 *');
            ($x->v->get_Profile)($x, FFI::addr($out));
            Com::release($v13);
            $p2 = FFI::isNull($out) ? null : Com::query($out, self::IID_PROFILE2);
            Com::release($out);
            if ($p2 === null) {
                throw new RuntimeException('clearing browsing data needs a newer WebView2 Runtime');
            }
            $p = Com::typed($f, $p2, 'Profile2 *');
            try {
                self::await(function (CData $handler) use ($p, $types): int {
                    if (($types & SiteData::ALL) === SiteData::ALL) {
                        return ($p->v->ClearBrowsingDataAll)($p, $handler);
                    }
                    $kinds = 0;
                    foreach ([SiteData::COOKIES => 0x40, SiteData::LOCAL_STORAGE => 0x4, SiteData::INDEXED_DB => 0x2 | 0x8,
                        SiteData::CACHES => 0x10 | 0x100, SiteData::SERVICE_WORKERS => 0x8000] as $bit => $k) {
                        if ($types & $bit) { $kinds |= $k; }
                    }
                    return ($p->v->ClearBrowsingData)($p, $kinds, $handler);
                }, 'clearing browsing data');
            } finally {
                Com::release($p2);
            }
        });
    }

    // Waiting for WebView2.

    /**
     * Calls $start with a completion handler, and waits till WebView2
     * calls it: its result, held, which the caller releases, or what
     * failed. @return ?CData the handler's second argument (a `void *`)
     */
    private static function await(Closure $start, string $what): mixed
    {
        $done = false; $result = null; $failed = null;
        $handler = WebView::handler(function (int $a, $b) use (&$done, &$result, &$failed, $what): void {
            if (WebView::hr($a) < 0) {
                $failed = sprintf('%s failed (HRESULT 0x%08X)', $what, $a & 0xFFFFFFFF);
            } elseif ($b !== null && !FFI::isNull($b)) {
                $result = Com::addRef(Com::typed(self::f(), $b, 'void *')); // held: WebView2 releases it once the handler returns
            }
            $done = true;
        });
        \MyPHP\Logger::debug("{$what}…", 'windows');
        self::$waiting++;
        try {
            $hr = $start($handler);
            if ($hr < 0) {
                throw new RuntimeException(sprintf('%s failed (HRESULT 0x%08X)', $what, $hr & 0xFFFFFFFF));
            }
            $deadline = microtime(true) + self::ANSWER;
            App::wait(function () use (&$done, $deadline): bool { return $done || microtime(true) > $deadline; }); // by reference: an arrow function keeps the false it was made with
        } finally {
            self::$waiting--;
        }
        \MyPHP\Logger::debug($done ? "$what answered" : "$what: no answer", 'windows');
        $done || throw new RuntimeException("$what: WebView2 did not answer in " . self::ANSWER . ' s');
        if ($failed !== null) {
            throw new RuntimeException($failed);
        }
        return $result;
    }

    /**
     * Closes the hidden web view from the message loop once no call waits
     * for WebView2 (mygo's closeCookieView and closeCookieViewNow): a call
     * may run in a completion handler, where closing a controller crashes
     * the message loop, and one waiting may be using the view.
     */
    private static function closeLater(CData $c, CData $v): void
    {
        App::backend()->timer(0.1, static function () use ($c, $v): void {
            if (self::$waiting > 0) {
                self::closeLater($c, $v);
                return;
            }
            \MyPHP\Logger::debug('cookies: closing the hidden web view', 'windows');
            WebView::closeHidden($c, $v);
            \MyPHP\Logger::debug('cookies: the hidden web view closed', 'windows');
        });
    }

    /** @internal DataStore::close: its hidden web view goes once no call needs it */
    public static function closeStore(DataStore $store): void
    {
        $key = spl_object_id($store);
        if (isset(self::$hidden[$key])) {
            [$c, $v] = self::$hidden[$key];
            unset(self::$hidden[$key]);
            self::closeLater($c, $v);
        }
    }

    /**
     * Runs $fn with a web view of the profile of $store (null: the default
     * one): a page's, else the hidden one, made now; the hidden one goes
     * once a page of the profile shows.
     */
    private static function withView(?Platform\DataStore $store, Closure $fn): mixed
    {
        $store = self::store($store);
        $key = $store === null ? 0 : spl_object_id($store);
        $view = WebView::shownView($store);
        if ($view !== null && isset(self::$hidden[$key])) {
            // Closed from the message loop, not here (closeLater).
            [$c, $v] = self::$hidden[$key];
            unset(self::$hidden[$key]);
            \MyPHP\Logger::debug('cookies: the hidden web view closes, a page shown', 'windows');
            self::closeLater($c, $v);
        }
        if ($view === null && !isset(self::$hidden[$key])) {
            $hwnd = App::backend()->hwnd() ?? throw new RuntimeException('cookies need the running app');
            $done = false; $why = null; $made = null;
            \MyPHP\Logger::debug('cookies: making a hidden web view' . ($store === null ? '' : " of the profile {$store->profile}"), 'windows');
            WebView::hiddenView($hwnd, $store, function (?CData $c, ?CData $v, ?string $error = null) use (&$done, &$why, &$made): void {
                [$made, $why, $done] = [$c === null ? null : [$c, $v], $error, true];
            });
            $deadline = microtime(true) + self::ANSWER;
            App::wait(function () use (&$done, $deadline): bool { return $done || microtime(true) > $deadline; });
            $why = $done ? $why : 'WebView2 did not answer in ' . self::ANSWER . ' s';
            \MyPHP\Logger::debug('cookies: the hidden web view ' . ($why ?? 'made'), 'windows');
            if ($made === null) {
                throw new RuntimeException("cookies: $why");
            }
            self::$hidden[$key] = $made;
        }
        return $fn($view ?? self::$hidden[$key][1]);
    }

    private static function withManager(?Platform\DataStore $store, Closure $fn): mixed
    {
        return self::withView($store, function (CData $view) use ($fn): mixed {
            $f = self::f();
            $v2 = Com::query($view, self::IID_VIEW2) ?? throw new Unsupported('myphp: cookies need WebView2 ICoreWebView2_2 (a runtime of SDK 1.0.705.50 or newer)');
            $out = $f->new('void *');
            $x = Com::typed($f, $v2, 'View2 *');
            $hr = ($x->v->get_CookieManager)($x, FFI::addr($out));
            Com::release($v2);
            if ($hr < 0 || FFI::isNull($out)) {
                throw new RuntimeException(sprintf('cookies: get_CookieManager failed (HRESULT 0x%08X)', $hr & 0xFFFFFFFF));
            }
            try {
                return $fn(Com::typed($f, $out, 'Manager *'));
            } finally {
                Com::release($out);
            }
        });
    }

    // The cookies.

    /** @return list<CData> every cookie of the manager, as NativeCookie *, held: the caller releases them */
    private static function nativeCookies(CData $m): array
    {
        $f = self::f();
        $list = self::await(fn(CData $h) => ($m->v->GetCookies)($m, Win32::wide(''), $h), 'cookies: GetCookies');
        if ($list === null || FFI::isNull($list)) {
            throw new RuntimeException('cookies: GetCookies gave no list');
        }
        $l = Com::typed($f, $list, 'CookieList *');
        $count = $f->new('uint32_t');
        $out = [];
        try {
            Com::check(($l->v->get_Count)($l, FFI::addr($count)), 'cookies: get_Count');
        } catch (RuntimeException $e) {
            Com::release($list);
            throw $e;
        }
        for ($i = 0; $i < $count->cdata; $i++) {
            $c = $f->new('void *');
            if (($hr = ($l->v->GetValueAtIndex)($l, $i, FFI::addr($c))) < 0 || FFI::isNull($c)) {
                array_map(Com::release(...), $out);
                Com::release($list);
                Com::check($hr < 0 ? $hr : -1, 'cookies: GetValueAtIndex');
            }
            $out[] = Com::typed($f, $c, 'NativeCookie *');
        }
        Com::release($list);
        return $out;
    }

    private static function string(CData $c, string $getter): string
    {
        $p = self::f()->new('uint16_t *');
        Com::check(($c->v->$getter)($c, FFI::addr($p)), "cookies: $getter");
        return WebView::take($p);
    }

    private static function flag(CData $c, string $getter): int
    {
        $v = self::f()->new('int32_t');
        Com::check(($c->v->$getter)($c, FFI::addr($v)), "cookies: $getter");
        return $v->cdata;
    }

    private static function read(CData $c): Cookie
    {
        $expires = null;
        if (self::flag($c, 'get_IsSession') === 0) {
            $s = self::f()->new('double');
            Com::check(($c->v->get_Expires)($c, FFI::addr($s)), 'cookies: get_Expires');
            if (!is_finite($s->cdata) || $s->cdata < 0 || $s->cdata > 253402300799) {
                throw new RuntimeException('cookies: an invalid expiration');
            }
            $expires = new DateTimeImmutable('@' . (int)$s->cdata);
        }
        $same = array_search(self::flag($c, 'get_SameSite'), self::SAME_SITE, true);
        return new Cookie(self::string($c, 'get_Name'), self::string($c, 'get_Value'), strtolower(self::string($c, 'get_Domain')), self::string($c, 'get_Path'),
            $expires, self::flag($c, 'get_IsSecure') !== 0, self::flag($c, 'get_IsHttpOnly') !== 0, $same === false ? '' : $same);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> the [name, domain, path, value] of the cookies that $match takes */
    private static function stored(CData $m, Closure $match): array
    {
        $out = [];
        foreach (self::nativeCookies($m) as $c) {
            try {
                $k = [self::string($c, 'get_Name'), strtolower(self::string($c, 'get_Domain')), self::string($c, 'get_Path'), self::string($c, 'get_Value')];
            } finally {
                Com::release($c);
            }
            if ($match($k)) { $out[] = $k; }
        }
        return $out;
    }

    /** Waits till a cookie $match takes is there ($present), or none is, failing with $why after SETTLE seconds. */
    private static function settle(CData $m, bool $present, Closure $match, string $why): void
    {
        $deadline = microtime(true) + self::SETTLE;
        while ((self::stored($m, $match) !== []) !== $present) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("cookies: $why");
            }
        }
    }
}
