<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A radial gradient (mygo's ui.RadialGradient, CSS's radial-gradient(),
 * SwiftUI's and Flutter's RadialGradient): from $from at $fromAlpha at
 * its center out in circles to $to at $toAlpha $radius points away, or,
 * with no radius, at the corner of what it fills farthest from the
 * center, as CSS's farthest-corner; $from before the center, $to past
 * the radius, for glows, spotlights and vignettes. The center is
 * ($centerX, $centerY) in fractions of the box's width and height from
 * its top left, its middle by default. $oklab mixes the colors in the
 * Oklab color space, as LinearGradient's does. $stops, unless empty, are
 * its colors in place of $from and $to, at their places from the center,
 * at 0, out to the radius, at 1 (GradientStop).
 */
final class RadialGradient
{
    /** @param list<GradientStop> $stops */
    public function __construct(
        public int $from = 0x000000,
        public float $fromAlpha = 1.0,
        public int $to = 0x000000,
        public float $toAlpha = 0.0,
        public float $centerX = 0.5,
        public float $centerY = 0.5,
        public float $radius = 0,
        public bool $oklab = false,
        public array $stops = [],
    ) {}
}
