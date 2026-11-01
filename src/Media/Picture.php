<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * Where a video's picture shows in a box, as players lay it out (IINA's
 * aspect ratio, crop and rotation): the picture, of its own aspect or the
 * one asked, cut to the crop's aspect around its middle, turned by the
 * rotation, fitted in the box and centered.
 *
 * @internal the window places the video's layer by it
 */
final class Picture
{
    /**
     * The part of a box $w by $h the picture shows in, [x, y, w, h]; the
     * size of the whole picture before it turns, [w, h], which the part
     * cuts (as wide as it, or as tall, when there is a crop); and the
     * picture as it is decoded, [w, h, dx, dy], its size and where its
     * middle is from the whole picture's, before it turns, y down: the
     * whole picture is its part $part of it ([x, y, w, h] over its size,
     * the video filters' crop and expand, VideoFilters::$part), itself
     * where null. $aspect is the whole picture's width over its height,
     * $crop the aspect it is cut to (null for none), $rotation 0, 90, 180
     * or 270 degrees.
     * @param ?array{0: float, 1: float, 2: float, 3: float} $part
     * @return array{0: array{0: float, 1: float, 2: float, 3: float}, 1: array{0: float, 1: float}, 2: array{0: float, 1: float, 2: float, 3: float}}
     */
    public static function fit(float $w, float $h, float $aspect, ?float $crop, int $rotation, ?array $part = null): array
    {
        $shown = $crop ?? $aspect;
        $turned = $rotation === 90 || $rotation === 270;
        $a = $turned ? 1 / $shown : $shown; // the aspect of what shows, as it shows
        [$vw, $vh] = $w / $h > $a ? [$h * $a, $h] : [$w, $w / $a];
        [$uw, $uh] = $turned ? [$vh, $vw] : [$vw, $vh]; // the part shown, before it turns
        $pw = $uw; $ph = $uh;
        if ($crop !== null) { // the picture as wide as the part and taller (a crop wider than it), or as tall and wider
            [$pw, $ph] = $crop > $aspect ? [$uw, $uw / $aspect] : [$uh * $aspect, $uh];
        }
        return [[($w - $vw) / 2, ($h - $vh) / 2, $vw, $vh], [$pw, $ph], self::decoded($pw, $ph, $part)];
    }

    /**
     * The decoded picture of whole picture $pw by $ph that is its part
     * $part: its size and its middle from the whole's, y down.
     * @param ?array{0: float, 1: float, 2: float, 3: float} $part
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function decoded(float $pw, float $ph, ?array $part): array
    {
        [$x, $y, $pw2, $ph2] = $part ?? [0.0, 0.0, 1.0, 1.0];
        [$rw, $rh] = [$pw / $pw2, $ph / $ph2];
        return [$rw, $rh, (0.5 - ($x + $pw2 / 2)) * $rw, (0.5 - ($y + $ph2 / 2)) * $rh];
    }
}
