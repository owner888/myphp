<?php
declare(strict_types=1);

namespace MyPHP;

/** The length of a date or a time as CLDR writes them by style (mygo's i18n.Style). */
enum Style
{
    /** Leaves the date, or the time, out. */
    case None;
    /** Numeric: "1/5/26", "1:07 PM". */
    case Short;
    /** Abbreviated: "Jan 5, 2026", "1:07:09 PM". */
    case Medium;
    /** The month written out: "January 5, 2026", "1:07:09 PM UTC". */
    case Long;
    /** Everything: "Monday, January 5, 2026", "1:07:09 PM Coordinated Universal Time". */
    case Full;

    /** @internal IntlDateFormatter's constant */
    public function icu(): int
    {
        return match ($this) { self::None => \IntlDateFormatter::NONE, self::Short => \IntlDateFormatter::SHORT, self::Medium => \IntlDateFormatter::MEDIUM, self::Long => \IntlDateFormatter::LONG, self::Full => \IntlDateFormatter::FULL };
    }
}
