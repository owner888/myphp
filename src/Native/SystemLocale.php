<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\I18n;
use MyPHP\Region;

/**
 * The user's languages and regional formats as macOS has them (mygo's
 * darwin locale.go): AppleLanguages of the app's own defaults, as System
 * Settings' language of an app writes it and as the language the app
 * chose is saved, over the global ones; the current locale, its
 * calendar's first weekday and its hour cycle for the formats.
 */
final class SystemLocale
{
    /** The languages the user reads, most preferred first: AppleLanguages of the global domain, else NSLocale's. @return list<string> */
    public static function preferredLanguages(): array
    {
        $langs = self::appleLanguages(false);
        if ($langs !== []) { return $langs; }
        return self::strings(ObjC::msg('p:', ObjC::cls('NSLocale'), 'preferredLanguages'));
    }

    /** The language the app chose in an earlier run: the first of AppleLanguages of its own domain, or ''. */
    public static function savedLocale(): string
    {
        return self::appleLanguages(true)[0] ?? '';
    }

    /** Keeps the language the app chose as AppleLanguages of its own domain, which AppKit and WebKit say their own texts in from the next launch; '' forgets it. */
    public static function saveLocale(string $tag): void
    {
        $defaults = ObjC::msg('p:', ObjC::cls('NSUserDefaults'), 'standardUserDefaults');
        if ($tag === '') { ObjC::msg('v:p', $defaults, 'removeObjectForKey:', ObjC::str('AppleLanguages')); }
        else { ObjC::msg('v:pp', $defaults, 'setObject:forKey:', ObjC::msg('p:p', ObjC::cls('NSArray'), 'arrayWithObject:', ObjC::str($tag)), ObjC::str('AppleLanguages')); }
        ObjC::msg('b:', $defaults, 'synchronize');
    }

    /** AppleLanguages of the current user: of the app's own domain alone when $own, else of the global domain alone. @return list<string> */
    private static function appleLanguages(bool $own): array
    {
        $defaults = ObjC::msg('p:', ObjC::cls('NSUserDefaults'), 'standardUserDefaults');
        $domain = ObjC::msg('p:p', $defaults, 'persistentDomainForName:', ObjC::str($own ? self::ownDomain() : 'NSGlobalDomain'));
        if ($domain === null) { return []; }
        $v = ObjC::msg('p:p', $domain, 'objectForKey:', ObjC::str('AppleLanguages'));
        return $v === null || !ObjC::msg('b:p', $v, 'isKindOfClass:', ObjC::cls('NSArray')) ? [] : self::strings($v);
    }

    /** The app's own defaults domain: its bundle identifier, or the process name when it runs unbundled (as php does), as CFPreferencesCurrentApplication. */
    private static function ownDomain(): string
    {
        $id = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSBundle'), 'mainBundle'), 'bundleIdentifier');
        return $id !== null ? ObjC::phpStr($id) : ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSProcessInfo'), 'processInfo'), 'processName'));
    }

    /** The current locale, which macOS makes of the region and the formats the user chose, its calendar's first weekday and its hour cycle (the hour of the skeleton "j" in its date formats). */
    public static function region(): Region
    {
        $locale = ObjC::msg('p:', ObjC::cls('NSLocale'), 'autoupdatingCurrentLocale');
        $id = ObjC::phpStr(ObjC::msg('p:', $locale, 'localeIdentifier'));
        $calendar = ObjC::msg('p:', ObjC::cls('NSCalendar'), 'autoupdatingCurrentCalendar');
        $d = ObjC::msg('L:', $calendar, 'firstWeekday');
        $format = ObjC::phpStr(ObjC::msg('p:pLp', ObjC::cls('NSDateFormatter'), 'dateFormatFromTemplate:options:locale:', ObjC::str('j'), 0, $locale));
        return new Region(self::regionLocale($id), $d >= 1 && $d <= 7 ? $d - 1 : -1, I18n::hourCycleOf($format));
    }

    /** Normalizes the locale of regional formats, whose region the "rg" key of macOS's locales changes: en_US@rg=gbzzzz has the formats of en-GB. */
    public static function regionLocale(string $locale): string
    {
        if (($i = strpos($locale, '@')) !== false) {
            $rg = '';
            foreach (explode(';', substr($locale, $i + 1)) as $kw) { // keywords, as "@calendar=japanese;rg=gbzzzz"
                if (str_contains($kw, '=') && strcasecmp(strstr($kw, '=', true), 'rg') === 0) { $rg = substr(strstr($kw, '='), 1); }
            }
            $base = I18n::normalize(substr($locale, 0, $i));
            if (strlen($rg) >= 2 && $base !== '') {
                $parts = \Locale::parseLocale($base) ?? []; $parts['region'] = strtoupper(substr($rg, 0, 2));
                if (($n = I18n::normalize(\Locale::composeLocale($parts) ?: '')) !== '') { return $n; }
            }
            $locale = substr($locale, 0, $i);
        }
        return I18n::normalize($locale);
    }

    /** The strings of an NSArray of NSStrings. @return list<string> */
    private static function strings($array): array
    {
        $out = [];
        for ($i = 0, $n = ObjC::msg('L:', $array, 'count'); $i < $n; $i++) {
            $s = ObjC::msg('p:L', $array, 'objectAtIndex:', $i);
            if (ObjC::msg('b:p', $s, 'isKindOfClass:', ObjC::cls('NSString'))) { $out[] = ObjC::phpStr($s); }
        }
        return $out;
    }
}
