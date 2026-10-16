<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * The user's regional formats, as mygo's Region (App::region): the locale
 * of the formats (a BCP 47 tag such as "en-GB" or "de-DE", or '' when the
 * system names none), the day calendars start their weeks on (0 Sunday to
 * 6 Saturday) and the hour cycle, 12 for times with AM and PM, 24 for
 * times without, or 0 when it is not known.
 */
final class Region
{
    public function __construct(public readonly string $locale = '', public readonly int $firstWeekday = 0, public readonly int $hourCycle = 0) {}

    /**
     * The locale a reader of $lang with these regional formats writes
     * numbers and dates in, as Apple's Locale.current puts the two
     * together: the language with the region of the formats, and their
     * first weekday and hour cycle as the keys of its Unicode extension,
     * as "en-GB-u-fw-mon-hc-h23" for English read with British formats,
     * or "en-DE-u-fw-mon-hc-h23" with German ones. Without a locale of
     * formats it is $lang's, with its first weekday and hour cycle when set.
     */
    public function formatLocale(string $lang): string
    {
        $tag = I18n::normalize($lang);
        if ($tag === '') { $tag = 'en'; }
        if ($this->locale !== '') {
            $region = \Locale::getRegion($this->locale);
            if ($region !== '' && $region !== null) {
                $parts = \Locale::parseLocale($tag) ?? [];
                $parts['region'] = $region;
                $tag = str_replace('_', '-', \Locale::composeLocale($parts));
            }
        }
        if ($this->hourCycle === 12 || $this->hourCycle === 24) {
            $tag = I18n::withExtension($tag, 'fw', ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][$this->firstWeekday]);
            $tag = I18n::withExtension($tag, 'hc', $this->hourCycle === 12 ? 'h12' : 'h23');
        }
        return $tag;
    }

    /** The formats CLDR gives a locale: its region's first weekday and hour cycle (mygo's i18n.RegionFor). The system's settings may differ. */
    public static function for(string $locale): self
    {
        return new self(I18n::normalize($locale), I18n::firstWeekday($locale), I18n::hourCycle($locale));
    }

    public function equals(Region $o): bool
    {
        return $this->locale === $o->locale && $this->firstWeekday === $o->firstWeekday && $this->hourCycle === $o->hourCycle;
    }
}
