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
     * The part of a box $w by $h the picture shows in, [x, y, w, h], and
     * the size of the whole picture before it turns, [w, h], which the
     * part cuts (as wide as it, or as tall, when there is a crop):
     * $aspect the picture's width over its height, $crop the aspect it is
     * cut to (null for none), $rotation 0, 90, 180 or 270 degrees.
     * @return array{0: array{0: float, 1: float, 2: float, 3: float}, 1: array{0: float, 1: float}}
     */
    public static function fit(float $w, float $h, float $aspect, ?float $crop, int $rotation): array
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
        return [[($w - $vw) / 2, ($h - $vh) / 2, $vw, $vh], [$pw, $ph]];
    }
}
