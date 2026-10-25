<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Colors outside the sRGB gamut (mygo's Color with its wide twin): a
 * color is an int whose low 24 bits are its 0xRRGGBB, the nearest sRGB
 * color, found as CSS Color 4 maps a color into a gamut; a wide one holds,
 * above them, the id of its components in extended sRGB (sRGB-encoded,
 * beyond 0 to 1), which a window drawing a wide gamut shows in its place
 * (on a Mac whose screen shows Display P3), and every other ignores. So
 * what reads a color's bytes reads its sRGB twin, and what draws it finds
 * the wide one (DisplayList).
 *
 * The components are kept in fixed point, ONE a unit, as mygo's: a color
 * mixed with itself is itself. The last KEEP colors made are kept; an
 * older one draws its sRGB twin, as mygo's past what a scene counts.
 */
final class WideColor
{
    public const SHIFT = 24;
    private const ONE = 8192;
    private const KEEP = 4096;
    private const EPS = 1 / 512;

    /** @var array<int, array{0: float, 1: float, 2: float, 3: string}> the components of each id, and their key */
    private static array $colors = [];
    /** @var array<string, int> the id of each color's components */
    private static array $ids = [];
    private static int $next = 1;

    /** The color of extended sRGB components $r, $g, $b (sRGB-encoded, past 0 to 1 outside the gamut): its 0xRRGGBB when inside sRGB, else its nearest sRGB with the wide color kept (mygo's fromWide). */
    public static function of(float $r, float $g, float $b): int
    {
        if (!is_finite($r) || !is_finite($g) || !is_finite($b)) {
            return 0x000000;
        }
        $q = static fn(float $v): int => (int)max(-32767, min(round($v * self::ONE), 32767));
        [$qr, $qg, $qb] = [$q($r), $q($g), $q($b)];
        [$r, $g, $b] = [$qr / self::ONE, $qg / self::ONE, $qb / self::ONE];
        $in = static fn(float $v): bool => $v >= -self::EPS && $v <= 1 + self::EPS;
        if ($in($r) && $in($g) && $in($b)) {
            $byte = static fn(float $v): int => (int)(max(0.0, min($v, 1.0)) * 255 + 0.5);
            return $byte($r) << 16 | $byte($g) << 8 | $byte($b);
        }
        $key = "$qr,$qg,$qb";
        if (!isset(self::$ids[$key])) {
            if (count(self::$colors) >= self::KEEP) { // the oldest goes: it draws its sRGB twin
                $old = array_key_first(self::$colors);
                unset(self::$ids[self::$colors[$old][3]], self::$colors[$old]);
            }
            self::$ids[$key] = self::$next;
            self::$colors[self::$next++] = [$r, $g, $b, $key];
        }
        return self::$ids[$key] << self::SHIFT | Oklch::nearestSRGB(self::decode($r), self::decode($g), self::decode($b));
    }

    /** Whether $rgb is a color outside the sRGB gamut, with a wide twin. */
    public static function isWide(int $rgb): bool
    {
        return $rgb >> self::SHIFT !== 0;
    }

    /** The color's sRGB twin, 0xRRGGBB (mygo's SRGB). */
    public static function srgb(int $rgb): int
    {
        return $rgb & 0xffffff;
    }

    /** The extended sRGB components of a wide color, null for an sRGB one, or a wide one no longer kept. @return ?array{0: float, 1: float, 2: float} */
    public static function wide(int $rgb): ?array
    {
        $c = self::$colors[$rgb >> self::SHIFT] ?? null;
        return $c === null ? null : [$c[0], $c[1], $c[2]];
    }

    /** The extended sRGB components of $rgb: its wide ones, else its bytes from 0 to 1 (mygo's extended). @return array{0: float, 1: float, 2: float} */
    public static function components(int $rgb): array
    {
        return self::wide($rgb) ?? [($rgb >> 16 & 255) / 255, ($rgb >> 8 & 255) / 255, ($rgb & 255) / 255];
    }

    /** $a mixed with $b by $k, one of them wide: their extended components mixed, wide only where the mix is outside sRGB (mygo's mixWide). */
    public static function mix(int $a, int $b, float $k): int
    {
        [$ar, $ag, $ab] = self::components($a);
        [$br, $bg, $bb] = self::components($b);
        $m = static fn(float $x, float $y): float => $x * (1 - $k) + $y * $k;
        return self::of($m($ar, $br), $m($ag, $bg), $m($ab, $bb));
    }

    /** An sRGB-encoded component, signed, to linear light. */
    private static function decode(float $v): float
    {
        $a = abs($v);
        return ($v < 0 ? -1 : 1) * ($a <= 0.04045 ? $a / 12.92 : (($a + 0.055) / 1.055) ** 2.4);
    }
}
