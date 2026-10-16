<?php
declare(strict_types=1);

namespace MyPHP;

use DateTimeInterface;
use IntlCalendar;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use Locale;
use NumberFormatter;

/**
 * Languages and regional formats, as mygo's package i18n, over ICU
 * (ext-intl): tags normalized and matched, the direction a language is
 * written in, and dates, numbers, weekdays and day periods written in a
 * locale, with CLDR's data for what a region does not say. Formatters are
 * kept by locale and options: the controls write the same dates and
 * numbers frame after frame.
 */
final class I18n
{
    public const WIDE = 0, ABBREVIATED = 1, SHORT = 2, NARROW = 3;
    /** The scripts of Unicode written right to left. */
    private const RTL_SCRIPTS = ['Adlm', 'Arab', 'Aran', 'Armi', 'Avst', 'Chrs', 'Cprt', 'Elym', 'Hatr', 'Hebr', 'Hung', 'Khar', 'Lydi', 'Mand', 'Mani', 'Mend', 'Merc', 'Mero', 'Narb', 'Nbat', 'Nkoo', 'Orkh', 'Ougr', 'Palm', 'Phli', 'Phlp', 'Phnx', 'Prti', 'Rohg', 'Samr', 'Sarb', 'Sogd', 'Sogo', 'Syrc', 'Thaa', 'Yezi'];
    /** @var array<string, IntlDateFormatter> */
    private static array $dates = [];
    /** @var array<string, NumberFormatter> */
    private static array $numbers = [];
    /** @var array<string, string> */
    private static array $patterns = [], $formatted = [];
    /** @var array<string, array> */
    private static array $names = [];

    /**
     * A BCP 47 tag in canonical form, as "zh-Hans-CN" or "en-GB", from a
     * tag or a POSIX locale ("zh_CN.UTF-8", "en_US@rg=gbzzzz" loses its
     * keywords), or '' for one that names no language.
     */
    public static function normalize(string $tag): string
    {
        $tag = trim($tag);
        if (($i = strpos($tag, '@')) !== false) { $tag = substr($tag, 0, $i); }
        if (($i = strpos($tag, '.')) !== false) { $tag = substr($tag, 0, $i); }
        if ($tag === '' || !preg_match('/^[A-Za-z]{2,8}([-_][A-Za-z0-9]{1,8})*$/', $tag)) { return ''; }
        $parsed = Locale::parseLocale($tag);
        if ($parsed === null || !isset($parsed['language']) || $parsed['language'] === 'root') { return ''; }
        return str_replace('_', '-', Locale::canonicalize($tag));
    }

    /**
     * The language of $supported to show a reader of $preferred (tags,
     * most preferred first): the best match as ICU finds it, or '' when
     * none is close.
     */
    public static function match(array $preferred, array $supported): string
    {
        $kept = []; foreach ($supported as $s) { if (self::normalize($s) !== '') { $kept[] = $s; } }
        $want = []; foreach ($preferred as $p) { if (self::normalize($p) !== '') { $want[] = $p; } }
        if ($kept === [] || $want === []) { return ''; }
        foreach ($want as $w) {
            $m = Locale::lookup($kept, $w, true);
            if ($m !== null && $m !== '') { foreach ($kept as $k) { if (strcasecmp(str_replace('_', '-', Locale::canonicalize($k)), str_replace('_', '-', Locale::canonicalize($m))) === 0) { return $k; } } }
            // The same language in another script or region (zh-Hans for zh-CN): the likely script decides.
            foreach ($kept as $k) {
                if (Locale::getPrimaryLanguage($w) === Locale::getPrimaryLanguage($k) && self::likelyScript($w) === self::likelyScript($k)) { return $k; }
            }
        }
        return '';
    }

    /** The script a tag names, or the one its language is likely written in: Hant for Chinese in Taiwan, Hong Kong and Macau, Hans elsewhere; the languages of the right-to-left scripts theirs; Latn otherwise. */
    public static function likelyScript(string $tag): string
    {
        $script = Locale::getScript($tag);
        if ($script !== '' && $script !== null) { return $script; }
        $lang = Locale::getPrimaryLanguage($tag) ?? ''; $region = Locale::getRegion($tag) ?? '';
        if ($lang === 'zh') { return in_array($region, ['TW', 'HK', 'MO'], true) ? 'Hant' : 'Hans'; }
        return self::LIKELY_SCRIPTS[$lang] ?? 'Latn';
    }

    /** The scripts of the languages written right to left, by language (CLDR's likely subtags for them). */
    private const LIKELY_SCRIPTS = ['ar' => 'Arab', 'arc' => 'Armi', 'ckb' => 'Arab', 'dv' => 'Thaa', 'fa' => 'Arab', 'he' => 'Hebr', 'iw' => 'Hebr', 'ks' => 'Arab', 'nqo' => 'Nkoo', 'ps' => 'Arab', 'sd' => 'Arab', 'syr' => 'Syrc', 'ug' => 'Arab', 'ur' => 'Arab', 'yi' => 'Hebr', 'ji' => 'Hebr', 'lrc' => 'Arab', 'mzn' => 'Arab', 'bal' => 'Arab', 'rhg' => 'Rohg'];

    /** Whether a language is written right to left (by its script, named or likely). */
    public static function rightToLeft(string $tag): bool
    {
        return self::normalize($tag) !== '' && in_array(self::likelyScript($tag), self::RTL_SCRIPTS, true);
    }

    /** The day calendars start their weeks on in a locale, 0 Sunday to 6 Saturday, by CLDR's week data for its region (or the one "fw" of its extension names). */
    public static function firstWeekday(string $locale): int
    {
        return IntlCalendar::createInstance(null, $locale)->getFirstDayOfWeek() - 1;
    }

    /** How a locale writes times: 12 for times with AM and PM, 24 for times without, by CLDR's time data for its region (or "hc" of its extension). */
    public static function hourCycle(string $locale): int
    {
        return self::hourCycleOf(self::pattern($locale, 'j'));
    }

    /** The hour cycle of a date format pattern: 12 for h and K, 24 for H and k, 0 for none. Quoted text is not a field. */
    public static function hourCycleOf(string $pattern): int
    {
        $quoted = false;
        foreach (str_split($pattern) as $c) {
            if ($c === "'") { $quoted = !$quoted; }
            elseif ($quoted) {}
            elseif ($c === 'h' || $c === 'K') { return 12; }
            elseif ($c === 'H' || $c === 'k') { return 24; }
        }
        return 0;
    }

    /**
     * The locale's pattern that suits a skeleton best, as ICU's
     * DateTimePatternGenerator finds it: "yMd" (1/5/2026), "yMMMM"
     * (January 2026), "jmm" (the hour and minute in the locale's hour
     * cycle); $hourCycle 12 or 24 makes j that cycle.
     */
    public static function pattern(string $locale, string $skeleton, int $hourCycle = 0): string
    {
        $tag = $hourCycle === 12 ? self::withExtension($locale, 'hc', 'h12') : ($hourCycle === 24 ? self::withExtension($locale, 'hc', 'h23') : $locale);
        return self::$patterns["$tag/$skeleton"] ??= (new IntlDatePatternGenerator($tag))->getBestPattern($skeleton);
    }

    /** $locale with the key $k of its Unicode extension set to $v ("en-GB-u-hc-h23"). */
    public static function withExtension(string $locale, string $k, string $v): string
    {
        $tag = str_replace('_', '-', $locale);
        if (preg_match('/-u-(.*)$/', $tag, $m, PREG_OFFSET_CAPTURE)) {
            $keys = []; $parts = explode('-', $m[1][0]);
            for ($i = 0; $i < count($parts); $i += 2) { $keys[$parts[$i]] = $parts[$i + 1] ?? ''; }
            $keys[$k] = $v; ksort($keys);
            $ext = []; foreach ($keys as $kk => $vv) { $ext[] = $kk; if ($vv !== '') { $ext[] = $vv; } }
            return substr($tag, 0, $m[0][1]) . '-u-' . implode('-', $ext);
        }
        return "$tag-u-$k-$v";
    }

    /** $t written in $locale by the pattern of $skeleton (pattern()). */
    public static function formatDate(string $locale, DateTimeInterface $t, string $skeleton, int $hourCycle = 0): string
    {
        $key = "$locale/$skeleton/$hourCycle/" . $t->getTimestamp() . '/' . $t->getTimezone()->getName();
        if (isset(self::$formatted[$key])) { return self::$formatted[$key]; }
        if (count(self::$formatted) >= 1024) { self::$formatted = []; } // the controls write the same dates frame after frame; a bounded cache, as mygo's fmtCache
        $f = self::$dates["$locale/$skeleton/$hourCycle"] ??= new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, self::pattern($locale, $skeleton, $hourCycle));
        $f->setTimeZone($t->getTimezone());
        return self::$formatted[$key] = $f->format($t);
    }

    /** $n in the locale's digits, with $min digits at least and without grouping. */
    public static function formatNumber(string $locale, int $n, int $min = 1): string
    {
        if (isset(self::$formatted["$locale/$min/$n"])) { return self::$formatted["$locale/$min/$n"]; }
        if (count(self::$formatted) >= 1024) { self::$formatted = []; }
        $f = self::$numbers["$locale/$min"] ??= (function () use ($locale, $min) {
            $f = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $f->setAttribute(NumberFormatter::GROUPING_USED, 0);
            $f->setAttribute(NumberFormatter::MIN_INTEGER_DIGITS, $min);
            return $f;
        })();
        return self::$formatted["$locale/$min/$n"] = $f->format($n);
    }

    /** The names of the days of the week in a locale, Sunday first, in a width (WIDE "Monday", ABBREVIATED "Mon", SHORT "Mo", NARROW "M"), standing alone or in a date. @return list<string> */
    public static function weekdays(string $locale, int $width = self::WIDE, bool $standAlone = false): array
    {
        $letters = [self::WIDE => 'EEEE', self::ABBREVIATED => 'EEE', self::SHORT => 'EEEEEE', self::NARROW => 'EEEEE'][$width];
        if ($standAlone) { $letters = str_replace('E', 'c', $letters); }
        return self::$names["$locale/$letters"] ??= (function () use ($locale, $letters) {
            $f = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, $letters);
            $out = [];
            for ($d = 0; $d < 7; $d++) { $out[] = $f->format(new \DateTimeImmutable("2023-01-0" . (1 + $d), new \DateTimeZone('UTC'))); } // 2023-01-01 is a Sunday
            return $out;
        })();
    }

    /** The names of the halves of the day in a locale, before noon and after, in a width: ["AM", "PM"] in English, ["上午", "下午"] in Chinese. @return array{0: string, 1: string} */
    public static function dayPeriods(string $locale, int $width = self::ABBREVIATED): array
    {
        $letters = [self::WIDE => 'aaaa', self::ABBREVIATED => 'a', self::SHORT => 'a', self::NARROW => 'aaaaa'][$width];
        return self::$names["$locale/$letters"] ??= (function () use ($locale, $letters) {
            $f = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, $letters);
            return [$f->format(new \DateTimeImmutable('2023-01-01 09:00', new \DateTimeZone('UTC'))), $f->format(new \DateTimeImmutable('2023-01-01 15:00', new \DateTimeZone('UTC')))];
        })();
    }
}
