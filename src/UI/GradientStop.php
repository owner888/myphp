<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A color of a gradient (LinearGradient, RadialGradient, ConicGradient)
 * at its place along it, from 0 to 1, as CSS's color stops are (mygo's
 * ui.GradientStop): $color at $alpha. The gradient shows its first color
 * before its first stop, its last after its last, and between two stops
 * their colors blended; two stops at one place make a sharp edge. A
 * gradient takes 32 stops at most, in sRGB, each at its place or after
 * the one before it.
 */
final class GradientStop
{
    public function __construct(
        public int $color = 0x000000,
        public float $at = 0,
        public float $alpha = 1.0,
    ) {}
}
