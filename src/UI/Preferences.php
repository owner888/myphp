<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Settings of the desktop that the system's own controls follow, which the default theme follows too (mygo's Preferences); Context::preferences() reads them for a theme or drawing of one's own. */
final class Preferences
{
    public function __construct(
        /** The accent color the user chose, 0xRRGGBB; null where the desktop has none. */
        public readonly ?int $accent = null,
        /** Less motion (Reduce Motion): what animates goes to its target at once; loops showing that something goes on go on. */
        public readonly bool $reduceMotion = false,
        /** More contrast (Increase Contrast): the default theme draws borders and secondary text darker (lighter in the dark), and the focus ring opaque. */
        public readonly bool $highContrast = false,
        /** How many times larger than usual text should be, 1 for usual: the default theme's font size that much larger. macOS has no such setting: 1 there. */
        public readonly float $textScale = 1.0,
    ) {}
}
