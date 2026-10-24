<?php
declare(strict_types=1);

namespace MyPHP;

use DateTimeInterface;
use IntlCalendar;
use IntlChar;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use Locale;
use MyPHP\Native\CldrTables;

/**
 * Languages and regional formats, as mygo's package i18n, over ICU
 * (ext-intl) and CLDR's tables of mygo: tags normalized and matched, their
 * parents and the direction they are written in, dates, numbers, names of
 * months, weekdays and day periods written in a locale, plural categories,
 * and the pseudo locales. Formatters are kept by locale and options: the
 * controls write the same dates and numbers frame after frame. Messages
 * and their catalogs are Message and Catalog.
 */
final class I18n
{
    /** en-XA: the letters accented, the text a third longer and in brackets, "[Šéļéçţ ƒîļéš one two]", to find strings that are not in the catalog, are cut, or are put together from pieces. */
    public const PSEUDO_ACCENTS = 'en-XA';
    /** ar-XB: the source's words shown right to left, with the layout of the app right to left too, to find what does not mirror. */
    public const PSEUDO_BIDI = 'ar-XB';
    /** The scripts of Unicode written right to left. */
    private const RTL_SCRIPTS = ['Adlm', 'Arab', 'Aran', 'Armi', 'Avst', 'Chrs', 'Cprt', 'Elym', 'Hatr', 'Hebr', 'Hung', 'Khar', 'Lydi', 'Mand', 'Mani', 'Mend', 'Merc', 'Mero', 'Narb', 'Nbat', 'Nkoo', 'Orkh', 'Ougr', 'Palm', 'Phli', 'Phlp', 'Phnx', 'Prti', 'Rohg', 'Samr', 'Sarb', 'Sogd', 'Sogo', 'Syrc', 'Thaa', 'Yezi'];
    /** The scripts of languages ICU has no name of in themselves, by language (CLDR's likely subtags). */
    private const LIKELY_SCRIPTS = ['arc' => 'Armi', 'nqo' => 'Nkoo', 'syr' => 'Syrc', 'rhg' => 'Rohg', 'ja' => 'Jpan', 'ko' => 'Kore', 'dv' => 'Thaa'];
    /** @var array<string, DateFormatter> */
    private static array $dates = [];
    /** @var array<string, NumberFormatter> */
    private static array $numbers = [];
    /** @var array<string, string> */
    private static array $scripts = [], $formatted = [];
    /** @var array<string, array> */
    private static array $names = [];

    /**
     * A BCP 47 tag in canonical form, as "zh-Hans-CN" or "en-GB", from a
     * tag or a POSIX locale ("zh_CN.UTF-8", "en_US@rg=gbzzzz" loses its
     * keywords), or '' for one that names no language.
     */
    public static function normalize(string $tag): string
    {
        // A POSIX locale: language[_territory][.codeset][@modifier], the modifier perhaps a script.
        $tag = trim($tag); $modifier = '';
        if (($i = strpos($tag, '@')) !== false) { [$tag, $modifier] = [substr($tag, 0, $i), strtolower(substr($tag, $i + 1))]; }
        if (($i = strpos($tag, '.')) !== false) { $tag = substr($tag, 0, $i); }
        if ($tag === '' || $tag === 'C' || $tag === 'POSIX' || !preg_match('/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{1,8})*$/', $tag)) { return ''; }
        $parsed = Locale::parseLocale($tag);
        if ($parsed === null || !isset($parsed['language']) || in_array(strtolower($parsed['language']), ['root', 'und'], true)) { return ''; }
        if (isset(self::MODIFIER_SCRIPTS[$modifier]) && !isset($parsed['script'])) {
            $tag = $parsed['language'] . '-' . self::MODIFIER_SCRIPTS[$modifier] . (isset($parsed['region']) ? '-' . $parsed['region'] : '');
        }
        // ICU canonicalizes the language, script and region; extensions keep their BCP 47 form ("-u-hc-h23"), lowercase.
        $parts = preg_split('/[-_]/', $tag);
        $ext = '';
        foreach ($parts as $i => $p) {
            if ($i > 0 && strlen($p) === 1) { $ext = '-' . strtolower(implode('-', array_slice($parts, $i))); $parts = array_slice($parts, 0, $i); break; }
        }
        $base = explode('_', Locale::canonicalize(implode('-', $parts)));
        $base[0] = self::LEGACY_LANGUAGES[$base[0]] ?? $base[0];
        return implode('-', $base) . $ext;
    }

    /** The scripts the modifiers of POSIX locales name. */
    private const MODIFIER_SCRIPTS = ['latin' => 'Latn', 'cyrillic' => 'Cyrl', 'arabic' => 'Arab', 'devanagari' => 'Deva', 'hebrew' => 'Hebr'];

    /** The deprecated codes of languages and those that replaced them, as CLDR's aliases. */
    private const LEGACY_LANGUAGES = ['iw' => 'he', 'ji' => 'yi', 'in' => 'id', 'jw' => 'jv', 'mo' => 'ro'];

    /**
     * The language of $supported that best suits a reader of $preferred,
     * the languages they read, most preferred first, or '' when none suits
     * them; the tag as $supported spells it (mygo's Match). A supported
     * language suits a reader when it is close to one they read, by CLDR's
     * parents: the same tag, an ancestor of it (es-419 for es-MX), a
     * sibling under the same parent (en-GB for en-AU, both en-001's), or
     * another region of the same language and script (pt-BR for pt-PT).
     * A language in another script does not suit them: zh-Hans is no
     * match for zh-TW, nor sr-Latn for sr-Cyrl. A match of a language they
     * prefer more wins over a closer one of a language they prefer less.
     */
    public static function match(array $preferred, array $supported): string
    {
        $kept = [];
        foreach ($supported as $s) {
            $t = self::stripExtensions(self::normalize((string)$s));
            if ($t !== '') { $kept[] = [$s, $t, self::chain($t)]; }
        }
        foreach ($preferred as $p) {
            $want = self::stripExtensions(self::normalize((string)$p));
            if ($want === '') { continue; }
            $chain = self::chain($want);
            [$best, $score] = [null, 0];
            foreach ($kept as [$spelled, $t, $theirs]) {
                if (self::sameLanguage($want, $t) === false) { continue; }
                $sc = 1;
                if ($t === $want) { $sc = 100; }
                elseif (($i = array_search($t, $chain, true)) !== false) { $sc = 90 - $i; }
                else {
                    $shared = array_intersect(array_slice($chain, 1), $theirs);
                    if ($shared !== []) { $sc = 50 - array_key_first($shared) + count($shared); }
                }
                if ($sc > $score) { [$best, $score] = [$spelled, $sc]; }
            }
            if ($best !== null) { return (string)$best; }
        }
        return '';
    }

    /** Whether two normalized tags are of the same language in the same script (nb and no are one language). */
    private static function sameLanguage(string $a, string $b): bool
    {
        $lang = fn(string $t) => match ($l = (string)Locale::getPrimaryLanguage($t)) { 'nb' => 'no', default => $l };
        return $lang($a) === $lang($b) && self::likelyScript($a) === self::likelyScript($b);
    }

    /** The language of a tag, without its script and region: "zh" for "zh-Hant-TW", "he" for "iw"; '' when it names none. */
    public static function base(string $tag): string
    {
        $t = self::normalize($tag);
        return $t === '' ? '' : (string)Locale::getPrimaryLanguage($t);
    }

    /**
     * The locale a tag inherits what it lacks from, by CLDR's parent
     * locales, or '' for the root: "en-001" for "en-AU", "en" for
     * "en-001", "zh-Hant-HK" for "zh-Hant-MO", "no" for "nb", and the tag
     * without its last subtag otherwise ("de" for "de-CH"). A language in
     * a script other than its usual one inherits from nothing (CLDR's
     * nonlikelyScript rule): "zh-Hant" and "sr-Latn" have the root.
     * Extensions ("-u-ca-buddhist") are dropped first.
     */
    public static function parent(string $tag): string
    {
        $t = self::stripExtensions(self::normalize($tag));
        if ($t === '') { return ''; }
        $i = self::search(CldrTables::PARENT_CHILDREN, $t);
        if ($i >= 0) { return CldrTables::PARENT_LOCALES[$i]; }
        if (($dash = strpos($t, '-')) === false) { return ''; }
        $rest = substr($t, $dash + 1);
        if (strlen($rest) === 4 && ctype_alpha($rest) && self::likelyScript(substr($t, 0, $dash)) !== $rest) { return ''; }
        return substr($t, 0, (int)strrpos($t, '-'));
    }

    /** @internal a tag and its parents, nearest first, without the root. @return list<string> */
    public static function chain(string $tag): array
    {
        $out = [];
        for ($t = self::stripExtensions(self::normalize($tag)); $t !== '' && count($out) < 8; $t = self::parent($t)) { $out[] = $t; }
        return $out;
    }

    /** @internal a normalized tag without its extensions and private use subtags, as "-u-ca-buddhist". */
    public static function stripExtensions(string $tag): string
    {
        $parts = explode('-', $tag);
        foreach ($parts as $i => $p) {
            if ($i > 0 && strlen($p) === 1) { return implode('-', array_slice($parts, 0, $i)); }
        }
        return $tag;
    }

    /** Whether a tag is a pseudo locale, en-XA or ar-XB. */
    public static function isPseudo(string $tag): bool
    {
        return in_array(self::stripExtensions(self::normalize($tag)), [self::PSEUDO_ACCENTS, self::PSEUDO_BIDI], true);
    }

    /** @internal The script a tag names, or the one its language is likely written in: Hant for Chinese in Taiwan, Hong Kong and Macau, Hans elsewhere; otherwise the script ICU writes the language's name in, in itself. */
    public static function likelyScript(string $tag): string
    {
        $script = Locale::getScript($tag);
        if ($script !== '' && $script !== null) { return $script; }
        $lang = Locale::getPrimaryLanguage($tag) ?? ''; $region = Locale::getRegion($tag) ?? '';
        if ($lang === 'zh') { return in_array($region, ['TW', 'HK', 'MO'], true) ? 'Hant' : 'Hans'; }
        return self::$scripts[$lang] ??= (function () use ($lang) {
            $own = (string)Locale::getDisplayLanguage($lang, $lang);
            if (isset(self::LIKELY_SCRIPTS[$lang]) || $own === '' || $own === Locale::getDisplayLanguage($lang, 'en')) { return self::LIKELY_SCRIPTS[$lang] ?? 'Latn'; }
            $v = IntlChar::getIntPropertyValue(mb_substr($own, 0, 1), IntlChar::PROPERTY_SCRIPT);
            return (string)IntlChar::getPropertyValueName(IntlChar::PROPERTY_SCRIPT, $v, IntlChar::SHORT_PROPERTY_NAME);
        })();
    }

    /**
     * The direction a tag is written in, that of its script: right to left
     * for Arabic, Hebrew and the other scripts written so; where the tag
     * names no script, the one its language is likely written in (ar, and
     * ar-XB, the pseudo locale). A tag that names no language is left to
     * right.
     */
    public static function direction(string $tag): Dir
    {
        return self::normalize($tag) !== '' && in_array(self::likelyScript($tag), self::RTL_SCRIPTS, true) ? Dir::RightToLeft : Dir::LeftToRight;
    }

    /** The day calendars start their weeks on in a locale, 0 Sunday to 6 Saturday, by CLDR's week data for its region (or the one "fw" of its extension names). */
    public static function firstWeekday(string $locale): int
    {
        return IntlCalendar::createInstance(null, $locale)->getFirstDayOfWeek() - 1;
    }

    /** How a locale writes times: 12 for times with AM and PM, 24 for times without, by CLDR's time data for its region (or "hc" of its extension). */
    public static function hourCycle(string $locale): int
    {
        return self::hourCycleOf((new IntlDatePatternGenerator($locale === '' ? 'en' : $locale))->getBestPattern('j')) === 12 ? 12 : 24;
    }

    /** @internal The hour cycle of a date format pattern: 12 for h and K, 24 for H and k, 0 for none. Quoted text is not a field. */
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

    /** @internal $locale with the key $k of its Unicode extension set to $v ("en-GB-u-hc-h23"). */
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

    /**
     * $t, in its time zone, written in the way of $locale in the Gregorian
     * calendar: the locale's names, patterns and digits (DateOptions). The
     * names of time zones are ICU's.
     */
    public static function formatDate(string $locale, DateTimeInterface $t, DateOptions $o = new DateOptions): string
    {
        $fkey = $locale . '/' . $o->date->name . '/' . $o->time->name . "/$o->skeleton/$o->hourCycle";
        $key = $fkey . '/' . $t->getTimestamp() . '/' . $t->getTimezone()->getName();
        if (isset(self::$formatted[$key])) { return self::$formatted[$key]; }
        if (count(self::$formatted) >= 1024) { self::$formatted = []; } // the controls write the same dates frame after frame; a bounded cache, as mygo's fmtCache
        if (count(self::$dates) > 512) { self::$dates = []; }
        return self::$formatted[$key] = (self::$dates[$fkey] ??= new DateFormatter($locale, $o))->format($t);
    }

    /** $n written in the way of $locale (NumberFormatter, NumberOptions). */
    public static function formatNumber(string $locale, int|float|string $n, NumberOptions $o = new NumberOptions): string
    {
        return self::numberFormatter($locale, $o)->format($n);
    }

    /** @internal the formatter of a locale and options, kept */
    public static function numberFormatter(string $locale, NumberOptions $o): NumberFormatter
    {
        $key = $locale . '/' . serialize([$o->percent, $o->integer, $o->minFractionDigits, $o->maxFractionDigits, $o->minIntegerDigits, $o->noGrouping, $o->halfEven]);
        if (count(self::$numbers) > 512) { self::$numbers = []; }
        return self::$numbers[$key] ??= new NumberFormatter($locale, $o);
    }

    /**
     * The names of the months in a locale, January first, in a width
     * (Short is Abbreviated). A name that stands alone, as a calendar's
     * heading, takes $standAlone: some languages write it otherwise inside
     * a date ("января" of "5 января" but "январь" alone, in Russian).
     * @return list<string>
     */
    public static function months(string $locale, Width $width = Width::Wide, bool $standAlone = false): array
    {
        $letters = match ($width) { Width::Wide => 'MMMM', Width::Abbreviated, Width::Short => 'MMM', Width::Narrow => 'MMMMM' };
        if ($standAlone) { $letters = str_replace('M', 'L', $letters); }
        return self::names($locale, $letters, array_map(fn(int $m) => sprintf('2023-%02d-15', $m), range(1, 12)));
    }

    /** The names of the days of the week in a locale, Sunday first, in a width, standing alone or inside a date as months() does. @return list<string> */
    public static function weekdays(string $locale, Width $width = Width::Wide, bool $standAlone = false): array
    {
        $letters = match ($width) { Width::Wide => 'EEEE', Width::Abbreviated => 'EEE', Width::Short => 'EEEEEE', Width::Narrow => 'EEEEE' };
        if ($standAlone) { $letters = str_replace('E', 'c', $letters); }
        return self::names($locale, $letters, array_map(fn(int $d) => '2023-01-0' . (1 + $d), range(0, 6))); // 2023-01-01 is a Sunday
    }

    /** The names of the halves of the day in a locale, before noon and after, in a width: ["AM", "PM"] in English, ["上午", "下午"] in Chinese. @return array{0: string, 1: string} */
    public static function dayPeriods(string $locale, Width $width = Width::Abbreviated): array
    {
        $letters = match ($width) { Width::Wide => 'aaaa', Width::Abbreviated, Width::Short => 'a', Width::Narrow => 'aaaaa' };
        return self::names($locale, $letters, ['2023-01-01 09:00', '2023-01-01 15:00']);
    }

    /** @param list<string> $times @return list<string> */
    private static function names(string $locale, string $letters, array $times): array
    {
        return self::$names["$locale/$letters"] ??= (function () use ($locale, $letters, $times) {
            $f = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, $letters);
            return array_map(fn(string $t) => (string)$f->format(new \DateTimeImmutable($t, new \DateTimeZone('UTC'))), $times);
        })();
    }

    /**
     * The name of a language in itself, for menus of languages: "English",
     * "British English", "Deutsch", "español latinoamericano", "简体中文",
     * "繁體中文", "日本語", in CLDR's form (lowercase in the languages that
     * write names so: "français"). A locale of a region or script has its
     * own name where CLDR has it, else the language's with them
     * ("português (Brasil)"); a pseudo locale is its tag; '' for a
     * language ICU has no data of.
     */
    public static function autonym(string $tag): string
    {
        $t = self::stripExtensions(self::normalize($tag));
        if ($t === '' || self::isPseudo($t)) { return $t; }
        $key = str_replace('-', '_', $t);
        foreach ([...self::chain($t), 'root'] as $in) {
            $b = \ResourceBundle::create(str_replace('-', '_', $in), 'ICUDATA-lang', false);
            $name = $b?->get('Languages')?->get($key);
            if (is_string($name)) { return $name; }
        }
        $lang = (string)Locale::getPrimaryLanguage($t);
        if (Locale::getDisplayLanguage($lang, $t) === $lang) { return ''; }
        return (string)Locale::getDisplayName($t, $t);
    }

    /** The locales ICU has formatting data of, as BCP 47 tags, sorted: "af", "ar", "ar-AE"... @return list<string> */
    public static function formatLocales(): array
    {
        $out = array_values(array_filter(array_map(fn(string $l) => str_replace('_', '-', $l), \ResourceBundle::getLocales('') ?: []), fn(string $l) => $l !== 'root' && self::normalize($l) !== ''));
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Adds an app's catalog to those the app ships (mygo's Register), which
     * the class `myphp generate` writes for it does: its languages become
     * the app's (languages(), App::languages when the app set none), and
     * its keys "<namespace>.<key>" change the messages of libraries, as
     * MyPHP's own ("myphp.cancel"). A library's catalog is not registered.
     */
    public static function register(Catalog $c): void
    {
        Catalog::register($c);
    }

    /** The languages of the catalogs the app registered, the source language of the first first; [] when it registered none. @return list<string> */
    public static function languages(): array
    {
        return Catalog::registeredLanguages();
    }

    /** The language the app shows (App::locale), in which the classes `myphp generate` writes say their messages. */
    public static function locale(): string
    {
        return Locales::locale();
    }

    /**
     * The plural category of a cardinal number in the language of a tag,
     * as "1 file" and "2 files": CLDR's rules for the language, or for it
     * without its region and script when CLDR has none of its own (pt-PT
     * has its own, pt-BR is pt's); Other for a language without rules.
     */
    public static function pluralCategory(string $tag, int|float|string|Operands $n): Plural
    {
        return CldrTables::cardinal(self::ruleOf($tag, CldrTables::CARDINAL_LANGS, CldrTables::CARDINAL_RULES), Operands::of($n));
    }

    /** The plural category of an ordinal number in the language of a tag, as "1st" and "2nd". */
    public static function ordinalCategory(string $tag, int|float|string|Operands $n): Plural
    {
        return CldrTables::ordinal(self::ruleOf($tag, CldrTables::ORDINAL_LANGS, CldrTables::ORDINAL_RULES), Operands::of($n));
    }

    /** The categories of cardinal numbers in the language of a tag, in the order of Plural: [One, Other] for English. @return list<Plural> */
    public static function pluralCategories(string $tag): array
    {
        return Plural::ofMask(CldrTables::CARDINAL_CATEGORIES[self::ruleOf($tag, CldrTables::CARDINAL_LANGS, CldrTables::CARDINAL_RULES)]);
    }

    /** The categories of ordinal numbers in the language of a tag: [One, Two, Few, Other] for English. @return list<Plural> */
    public static function ordinalCategories(string $tag): array
    {
        return Plural::ofMask(CldrTables::ORDINAL_CATEGORIES[self::ruleOf($tag, CldrTables::ORDINAL_LANGS, CldrTables::ORDINAL_RULES)]);
    }

    /** The rule set of a tag among $langs, which $rules index: the tag's, or its without its last subtag, down to its language, as ICU looks plural rules up; 0 has Other only. */
    private static function ruleOf(string $tag, array $langs, array $rules): int
    {
        for ($t = self::stripExtensions(self::normalize($tag)); $t !== '';) {
            $i = self::search($langs, $t);
            if ($i >= 0) { return $rules[$i]; }
            if (($j = strrpos($t, '-')) === false) { break; }
            $t = substr($t, 0, $j);
        }
        return 0;
    }

    /** The index of $s in a sorted list, or -1. */
    private static function search(array $list, string $s): int
    {
        [$lo, $hi] = [0, count($list)];
        while ($lo < $hi) {
            $m = ($lo + $hi) >> 1;
            if (strcmp($list[$m], $s) < 0) { $lo = $m + 1; } else { $hi = $m; }
        }
        return $lo < count($list) && $list[$lo] === $s ? $lo : -1;
    }
}
