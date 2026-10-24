<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * How I18n::formatDate writes a time (mygo's i18n.DateOptions): its date,
 * its time or both by style, as Intl.DateTimeFormat's dateStyle and
 * timeStyle, or the fields of a skeleton.
 */
final class DateOptions
{
    public function __construct(
        public readonly Style $date = Style::None,
        public readonly Style $time = Style::None,
        /**
         * The fields to write when the date and the time are Style::None,
         * in CLDR's letters and any order: "yMMMd" (Jan 5, 2026), "yMMMM"
         * (January 2026), "MMMd", "Ed", "jm" (the hour and minute in the
         * locale's hour cycle), "Hm", "hms"; the locale's pattern that
         * suits it best, as ICU's DateTimePatternGenerator finds it. No
         * style and no skeleton is "yMd". An hour letter other than j is
         * that hour cycle.
         */
        public readonly string $skeleton = '',
        /** 12 or 24 to write times so whatever the locale's hours, as the user's regional settings say (Region::$hourCycle); 0 keeps the locale's, or the one its extension names ("-u-hc-h23"). */
        public readonly int $hourCycle = 0,
    ) {}
}
