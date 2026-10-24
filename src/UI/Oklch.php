<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * CSS's oklch() as a color myphp draws (mygo's Oklch and internal/gamut):
 * mapped into Display P3, then, as myphp draws sRGB alone, into sRGB, each
 * time losing chroma, keeping lightness and hue, as CSS Color 4 maps a
 * color into a gamut.
 */
final class Oklch
{
    private const JND = 0.02, EPSILON = 0.0001, IN_GAMUT = 0.00001;

    /** The 0xRRGGBB of oklch($l $c $h): lightness from 0 to 1, chroma about 0 to 0.4 (negative is 0), hue in degrees. */
    public static function rgb(float $l, float $c, float $h): int
    {
        [$r, $g, $b] = self::map(true, $l, $c, $h);
        [$r, $g, $b] = [1.2249401 * $r - 0.2249404 * $g, -0.0420569 * $r + 1.0420571 * $g, -0.0196376 * $r - 0.0786361 * $g + 1.0982735 * $b]; // linear P3 to linear sRGB
        if (!self::within($r, $g, $b)) { // outside sRGB: the nearest it shows
            [$l2, $a2, $b2] = self::oklab($r, $g, $b);
            [$r, $g, $b] = self::map(false, $l2, hypot($a2, $b2), fmod(rad2deg(atan2($b2, $a2)) + 360, 360));
        }
        $byte = static fn(float $v): int => (int)(self::encode(max(0.0, min($v, 1.0))) * 255 + 0.5);
        return $byte($r) << 16 | $byte($g) << 8 | $byte($b);
    }

    private static function encode(float $v): float
    {
        $a = abs($v);
        return $a <= 0.0031308 ? $v * 12.92 : ($v < 0 ? -1 : 1) * (1.055 * $a ** (1 / 2.4) - 0.055);
    }

    /** Linear sRGB to Oklab. @return array{0: float, 1: float, 2: float} */
    private static function oklab(float $r, float $g, float $b): array
    {
        $cbrt = static fn(float $v): float => $v < 0 ? -((-$v) ** (1 / 3)) : $v ** (1 / 3);
        $l = $cbrt(0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b);
        $m = $cbrt(0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b);
        $s = $cbrt(0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b);
        return [0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s, 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s, 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s];
    }

    /** Oklab to linear RGB of P3 ($p3) or sRGB. @return array{0: float, 1: float, 2: float} */
    private static function toSpace(bool $p3, float $l, float $a, float $b): array
    {
        $lc = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $mc = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $sc = ($l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;
        $r = 4.0767416621 * $lc - 3.3077115913 * $mc + 0.2309699292 * $sc;
        $g = -1.2684380046 * $lc + 2.6097574011 * $mc - 0.3413193965 * $sc;
        $bb = -0.0041960863 * $lc - 0.7034186147 * $mc + 1.7076147010 * $sc;
        return $p3 ? [0.8224621 * $r + 0.1775380 * $g, 0.0331941 * $r + 0.9668058 * $g, 0.0170827 * $r + 0.0723974 * $g + 0.9105199 * $bb] : [$r, $g, $bb];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function fromSpace(bool $p3, float $r, float $g, float $b): array
    {
        if ($p3) { [$r, $g, $b] = [1.2249401 * $r - 0.2249404 * $g, -0.0420569 * $r + 1.0420571 * $g, -0.0196376 * $r - 0.0786361 * $g + 1.0982735 * $b]; }
        return self::oklab($r, $g, $b);
    }

    private static function within(float $r, float $g, float $b): bool
    {
        foreach ([$r, $g, $b] as $v) { if ($v < -self::IN_GAMUT || $v > 1 + self::IN_GAMUT) { return false; } }
        return true;
    }

    /** The Oklch color as linear RGB inside the gamut, its chroma reduced until clipping what is left out changes it imperceptibly (CSS Color 4's gamut mapping, mygo's gamut.Map). @return array{0: float, 1: float, 2: float} */
    private static function map(bool $p3, float $l, float $c, float $h): array
    {
        if ($l >= 1) { return [1.0, 1.0, 1.0]; }
        if (!($l > 0)) { return [0.0, 0.0, 0.0]; }
        $c = min(max($c, 0.0), 1.0);
        if (!is_finite($h)) { $h = 0.0; }
        $ab = static fn(float $c) => [$c * cos(deg2rad($h)), $c * sin(deg2rad($h))];
        $clip = static fn(float $v): float => max(0.0, min($v, 1.0));
        [$oa, $ob] = $ab($c);
        [$r, $g, $b] = self::toSpace($p3, $l, $oa, $ob);
        if (self::within($r, $g, $b)) { return [$clip($r), $clip($g), $clip($b)]; }
        $clipped = function (float $a, float $bb) use ($p3, $l, $clip): array {
            [$r, $g, $b] = self::toSpace($p3, $l, $a, $bb);
            [$cr, $cg, $cb] = [$clip($r), $clip($g), $clip($b)];
            [$cl, $ca, $cbb] = self::fromSpace($p3, $cr, $cg, $cb);
            return [$cr, $cg, $cb, sqrt(($cl - $l) ** 2 + ($ca - $a) ** 2 + ($cbb - $bb) ** 2)];
        };
        [$cr, $cg, $cb, $e] = $clipped($oa, $ob);
        if ($e < self::JND) { return [$cr, $cg, $cb]; }
        $lo = 0.0; $hi = $c; $loIn = true;
        while ($hi - $lo > self::EPSILON) {
            $mid = ($lo + $hi) / 2;
            [$a, $bb] = $ab($mid);
            [$r, $g, $b] = self::toSpace($p3, $l, $a, $bb);
            if ($loIn && self::within($r, $g, $b)) { $lo = $mid; continue; }
            [$cr, $cg, $cb, $e] = $clipped($a, $bb);
            if ($e < self::JND) {
                if (self::JND - $e < self::EPSILON) { return [$cr, $cg, $cb]; }
                $loIn = false; $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return [$cr, $cg, $cb];
    }
}
