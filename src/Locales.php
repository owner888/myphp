<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\SystemLocale;
use RuntimeException;

/**
 * The locale model, as mygo's locale.go: the one language the app shows,
 * which its own texts, MyPHP's controls and menus all follow, and the
 * regional formats, which are a setting of their own. The app's language
 * is the user's preferred languages, or the language the app chose for
 * them (setLocale), matched against the languages the app is written in
 * (setLanguages), and the first of those, the app's own, where none
 * matches: an app written in English alone shows English to a reader of
 * Chinese, as AppKit does. App::locale() and the rest read it from here,
 * so that no part of the app resolves its own. Without a running app (the
 * tests), the languages are English and the formats CLDR's for it.
 */
final class Locales
{
    /** @var ?list<string> those setLanguages set, or null for English alone */
    private static ?array $languages = null;
    /** The language the app chose (setLocale, the one saved, or MYPHP_LOCALE), '' to follow the user's; $fromEnv tells that MYPHP_LOCALE set it, which makes setLocale save nothing. */
    private static string $override = '';
    private static bool $fromEnv = false, $loaded = false;
    /** setLocale chose the language, which reading the saved one again at start does not undo. */
    private static bool $chosen = false;
    /** What the app shows, as the windows and listeners last heard of it: [locale, preferred, region], or null before anything asked. */
    private static ?array $shown = null;
    /** @var list<Closure> */
    private static array $onLocale = [], $onRegion = [];

    /** The languages the user reads, most preferred first, as normalized BCP 47 tags; the language the app chose never changes them. @return list<string> */
    public static function preferredLanguages(): array
    {
        return self::shown()[1];
    }

    /** Sets the languages the app is written in, its own language first, which the app falls back to: those its texts are translated to, e.g. "en", "de", "zh-Hans". locale() picks among them. English alone by default. */
    public static function setLanguages(string ...$tags): void
    {
        foreach ($tags as $t) {
            if (I18n::normalize($t) === '') { throw new RuntimeException("setLanguages: $t is not a language tag"); }
        }
        self::$languages = $tags === [] ? null : array_values($tags);
        self::update(true);
    }

    /** The languages the app is written in, its own first: those setLanguages set, else those of the catalogs it registered (I18n::languages), else English. @return list<string> */
    public static function languages(): array
    {
        return self::$languages ?? (I18n::languages() ?: ['en']);
    }

    /** @internal a catalog was registered: the languages the app is written in may have changed */
    public static function catalogsChanged(): void
    {
        if (self::$shown !== null && self::$languages === null) { self::update(true); }
    }

    /** The language the app shows, as a tag of the languages it is written in: the one the user reads best of them, or the one setLocale chose, else the app's own. */
    public static function locale(): string
    {
        return self::shown()[0];
    }

    /**
     * Makes the app show the language of $tag, of those it is written in,
     * or '' to follow the user's languages again, which is the default.
     * The app shows it at once: windows draw anew, menus say their items
     * in it, listeners hear of it. It is kept for the next launches as the
     * app's language in its defaults (AppleLanguages), which System
     * Settings shows. The MYPHP_LOCALE environment variable chooses the
     * language for one run and keeps nothing.
     */
    public static function setLocale(string $tag): void
    {
        $normalized = '';
        if (trim($tag) !== '' && ($normalized = I18n::normalize($tag)) === '') { throw new RuntimeException("setLocale: $tag is not a language tag"); }
        self::load();
        if (self::$fromEnv && !App::isReady()) { return; } // MYPHP_LOCALE wins over the language the app starts with
        self::$override = $normalized; self::$chosen = true;
        if (!self::$fromEnv && App::isReady()) { SystemLocale::saveLocale($normalized); }
        self::update(true);
    }

    /** Calls $fn with the language the app shows when it changes; returns what stops the listener. */
    public static function onLocaleChanged(Closure $fn): Closure
    {
        self::$onLocale[] = $fn;
        return function () use ($fn) { self::$onLocale = array_values(array_filter(self::$onLocale, fn($f) => $f !== $fn)); };
    }

    /** The user's regional formats, as the system's settings have them: the locale of the formats (which may be another language's than the app's), the first day of the week and the hour cycle. */
    public static function region(): Region
    {
        return self::shown()[2];
    }

    /** Calls $fn with the regional formats when they change; returns what stops the listener. */
    public static function onRegionChanged(Closure $fn): Closure
    {
        self::$onRegion[] = $fn;
        return function () use ($fn) { self::$onRegion = array_values(array_filter(self::$onRegion, fn($f) => $f !== $fn)); };
    }

    /** Once the app runs: the saved language is read where the system keeps it (unless setLocale chose one before), the user's languages and formats with it. */
    public static function start(): void
    {
        if (!self::$chosen) { self::$loaded = false; }
        self::update(false);
    }

    /** The user's languages or formats may have changed (NSCurrentLocaleDidChangeNotification). */
    public static function changedFromSystem(): void
    {
        self::update(true);
    }

    private static function shown(): array
    {
        if (self::$shown === null) { self::update(false); }
        return self::$shown;
    }

    /** Reads the language the app chose in an earlier run, or MYPHP_LOCALE, which wins and is never saved, once. */
    private static function load(): void
    {
        if (self::$loaded) { return; }
        self::$loaded = true;
        $tag = getenv('MYPHP_LOCALE') ?: ''; $fromEnv = true;
        if (trim($tag) === '') { $tag = App::isReady() ? SystemLocale::savedLocale() : ''; $fromEnv = false; }
        self::$override = I18n::normalize($tag);
        self::$fromEnv = $fromEnv && self::$override !== '';
    }

    /** Works out what the app shows and, when $tell is set and it changed, tells the windows, the menus and the listeners. */
    private static function update(bool $tell): void
    {
        self::load();
        $ready = App::isReady();
        $preferred = self::normalizeTags($ready ? SystemLocale::preferredLanguages() : []);
        $locale = self::resolve(self::$override, $preferred, self::languages());
        $region = $ready ? self::regionOf(SystemLocale::region(), $preferred) : new Region; // the zero Region without an app: the controls take CLDR's formats for the language
        $before = self::$shown;
        self::$shown = [$locale, $preferred, $region];
        if ($before === null || !$tell) { return; }
        $localeChanged = $before[0] !== $locale; $regionChanged = !$before[2]->equals($region);
        if (!$localeChanged && !$regionChanged) { return; }
        foreach (Window::all() as $w) { $w->invalidate(); }
        if ($localeChanged) {
            App::relabelMenus();
            foreach (Window::all() as $w) {
                foreach ($w->pages() as $p) { $p->settingsChanged(true); } // pages loaded later start in it, the ones shown hear of it
            }
            foreach (self::$onLocale as $fn) { $fn($locale); }
        }
        if ($regionChanged) { foreach (self::$onRegion as $fn) { $fn($region); } }
    }

    /** The language of $supported to show a reader of $preferred, or of $override and then $preferred when the app chose one: the best match, else the app's own. */
    private static function resolve(string $override, array $preferred, array $supported): string
    {
        if (I18n::isPseudo($override)) { return $override; } // the pseudo locales are always shown, to test what translations would do
        $want = $override !== '' ? [$override, ...$preferred] : $preferred;
        $m = I18n::match($want, $supported);
        return $m !== '' ? $m : $supported[0];
    }

    /** The formats of the system, with what it does not say filled in from the locale of the formats or, without one, the language the user reads, by CLDR's week and time data. */
    private static function regionOf(Region $r, array $preferred): Region
    {
        $locale = $r->locale !== '' ? $r->locale : ($preferred[0] ?? '');
        $first = $r->firstWeekday >= 0 && $r->firstWeekday <= 6 ? $r->firstWeekday : ($locale !== '' ? I18n::firstWeekday($locale) : 1);
        $hours = $r->hourCycle === 12 || $r->hourCycle === 24 ? $r->hourCycle : ($locale !== '' ? I18n::hourCycle($locale) : 24);
        return new Region($locale, $first, $hours);
    }

    /** Normalizes tags, leaving out those that name no language and those named before. @return list<string> */
    private static function normalizeTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $t) { if (($n = I18n::normalize($t)) !== '' && !in_array($n, $out, true)) { $out[] = $n; } }
        return $out;
    }
}
