<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\UI\Theme as UITheme;

/**
 * The least contrast of a terminal's text with its background
 * (Options::$minimumContrast), as Ghostty's minimum-contrast, by WCAG's
 * reckoning (mygo's contrasted, luminance and contrast).
 *
 * @internal
 */
final class Contrast
{
    /** $fg with at least $ratio of contrast with $bg (0xRRGGBB both): $fg itself when it has it, else $fg mixed toward black or white, whichever contrasts more with $bg, as little as reaches it. */
    public static function contrasted(int $fg, int $bg, float $ratio): int
    {
        $lb = self::luminance($bg);
        if (self::ratio(self::luminance($fg), $lb) >= $ratio) {
            return $fg;
        }
        $to = self::ratio(1.0, $lb) > self::ratio(0.0, $lb) ? 0xffffff : 0x000000;
        [$lo, $hi] = [0.0, 1.0];
        for ($i = 0; $i < 8; $i++) {
            $mid = ($lo + $hi) / 2;
            if (self::ratio(self::luminance(UITheme::mix($fg, $to, $mid)), $lb) >= $ratio) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }
        return UITheme::mix($fg, $to, $hi);
    }

    /** The relative luminance of an sRGB color, from 0 for black to 1 for white. */
    public static function luminance(int $rgb): float
    {
        $lin = static function (int $v): float {
            $f = $v / 255;
            return $f <= 0.04045 ? $f / 12.92 : (($f + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $lin(($rgb >> 16) & 255) + 0.7152 * $lin(($rgb >> 8) & 255) + 0.0722 * $lin($rgb & 255);
    }

    /** The contrast ratio of two luminances, from 1 to 21. */
    public static function ratio(float $a, float $b): float
    {
        if ($a < $b) { [$a, $b] = [$b, $a]; }
        return ($a + 0.05) / ($b + 0.05);
    }
}
