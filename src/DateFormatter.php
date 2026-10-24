<?php
declare(strict_types=1);

namespace MyPHP;

use DateTimeInterface;
use IntlDateFormatter;
use IntlDatePatternGenerator;

/**
 * Writes times in a locale with options, for writing many (mygo's
 * i18n.DateFormatter), over ICU: the pattern of the options as V8's
 * Intl.DateTimeFormat works it out, written in the Gregorian calendar
 * with the locale's names and digits. I18n::formatDate keeps one per
 * locale and options.
 */
final class DateFormatter
{
    private readonly string $pattern;
    private readonly IntlDateFormatter $icu;

    public function __construct(string $locale, DateOptions $o = new DateOptions)
    {
        $locale = $locale === '' ? 'en' : $locale;
        $this->pattern = self::patternOf($locale, $o);
        $this->icu = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, IntlDateFormatter::GREGORIAN, $this->pattern);
    }

    /** The pattern of CLDR the formatter writes, as "MMM d, y". */
    public function pattern(): string
    {
        return $this->pattern;
    }

    /** Writes $t in its time zone; CLDR's narrow no-break spaces (1:07 PM) as spaces, as V8 writes them, so that native UI and pages agree. */
    public function format(DateTimeInterface $t): string
    {
        $this->icu->setTimeZone($t->getTimezone());
        return str_replace("\u{202F}", ' ', (string)$this->icu->format($t));
    }

    /** The pattern of $o in $locale, as mygo's datePattern. */
    private static function patternOf(string $locale, DateOptions $o): string
    {
        $gen = new IntlDatePatternGenerator($locale);
        $hc = match ($o->hourCycle) { 12 => 'h', 24 => 'H', default => self::extensionHour($locale) };
        if ($o->date === Style::None && $o->time === Style::None) {
            $skel = $o->skeleton === '' ? 'yMd' : $o->skeleton;
            // An hour letter of the skeleton is an hour cycle, as V8 reads
            // the options Intl makes of it: "hm" is 12:30 AM in Japanese
            // too, whose own patterns have 0:30.
            if ($o->hourCycle === 0 && preg_match('/[hHkK]/', $skel, $m)) {
                $hc = $m[0];
            }
            if ($hc !== '') {
                $skel = self::replaceHours(str_replace('j', 'h', $skel), $hc);
            }
            return self::replaceHours($gen->getBestPattern($skel), $hc);
        }
        $p = (new IntlDateFormatter($locale, $o->date->icu(), $o->time->icu(), 'UTC', IntlDateFormatter::GREGORIAN))->getPattern();
        if ($hc === '' || $o->time === Style::None || self::hourLetter($p) === $hc) {
            return $p;
        }
        return self::replaceHours($gen->getBestPattern(self::replaceHours(self::skeletonOf($p), $hc)), $hc);
    }

    /** The hour letter the locale's extension names ("-u-hc-h23" is H), or ''. */
    private static function extensionHour(string $locale): string
    {
        return match (\Locale::getKeywords($locale)['hours'] ?? '') { 'h11' => 'K', 'h12' => 'h', 'h23' => 'H', 'h24' => 'k', default => '' };
    }

    /** $p with its hour fields, outside quotes, in the letter $hc; $p as it is for none. */
    private static function replaceHours(string $p, string $hc): string
    {
        if ($hc === '') {
            return $p;
        }
        $quoted = false;
        $out = '';
        foreach (str_split($p) as $c) {
            if ($c === "'") {
                $quoted = !$quoted;
            } elseif (!$quoted && strpbrk($c, 'hHkK') !== false) {
                $c = $hc;
            }
            $out .= $c;
        }
        return $out;
    }

    /** The first hour letter of a pattern, outside quotes, or ''. */
    private static function hourLetter(string $p): string
    {
        return preg_match('/[hHkK]/', (string)preg_replace("/'[^']*'/", '', $p), $m) ? $m[0] : '';
    }

    /** The fields of a pattern, its letters outside quotes, as a skeleton. */
    private static function skeletonOf(string $p): string
    {
        return (string)preg_replace('/[^A-Za-z]/', '', (string)preg_replace("/'[^']*'/", '', $p));
    }
}
