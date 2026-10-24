<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * How I18n::formatNumber writes a number (mygo's i18n.NumberOptions). The
 * defaults write it as Intl.NumberFormat does: grouped, with up to three
 * fraction digits, rounded half away from zero.
 */
final class NumberOptions
{
    public function __construct(
        /** The number times 100, with the locale's percent sign where its pattern puts it: "25%", "25 %", "%25"; no fraction digits unless $maxFractionDigits says otherwise. */
        public readonly bool $percent = false,
        /** Rounds to a whole number. */
        public readonly bool $integer = false,
        /** The digits after the decimal separator, at least: 1.5 with 2 is "1.50". */
        public readonly int $minFractionDigits = 0,
        /** The digits after the decimal separator, at most: 1.2345 with 2 is "1.23"; 0 is the style's default (3, none for percents), or $minFractionDigits when that is more. */
        public readonly int $maxFractionDigits = 0,
        /** Pads the integer part with zeros: 7 with 2 is "07". */
        public readonly int $minIntegerDigits = 0,
        /** No separators of thousands: "12345". */
        public readonly bool $noGrouping = false,
        /** Rounds a number halfway between two to the even one, as ICU's MessageFormat does ("2.5" is "2"), rather than away from zero ("3"). */
        public readonly bool $halfEven = false,
    ) {}
}
