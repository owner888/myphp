<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A conic gradient (mygo's ui.ConicGradient, CSS's conic-gradient(),
 * SwiftUI's AngularGradient, Flutter's SweepGradient): its $stops at their
 * places around a turn of ($centerX, $centerY), in fractions of the box's
 * width and height from its top left, its middle by default, clockwise
 * from $angle degrees from upwards, as CSS's "from": 90 starts at the
 * right, where SwiftUI's and Flutter's start. $oklab mixes the colors in
 * the Oklab color space, as LinearGradient's does.
 */
final class ConicGradient
{
    /** @param list<GradientStop> $stops */
    public function __construct(
        public array $stops = [],
        public float $centerX = 0.5,
        public float $centerY = 0.5,
        public float $angle = 0,
        public bool $oklab = false,
    ) {}
}
