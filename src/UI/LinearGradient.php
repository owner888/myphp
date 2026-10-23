<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A linear gradient from $from at $fromAlpha to $to at $toAlpha (mygo's
 * ui.LinearGradient), along a line through the middle of what it fills,
 * at $angle degrees clockwise from upwards: 180 goes from top to bottom,
 * 90 from left to right, as CSS's linear-gradient. $start and $end place
 * the colors along the line, from 0 to 1, solid before and after them;
 * both 0 mean 0 and 1.
 */
final class LinearGradient
{
    public function __construct(
        public int $from = 0x000000,
        public float $fromAlpha = 1.0,
        public int $to = 0x000000,
        public float $toAlpha = 0.0,
        public float $angle = 180,
        public float $start = 0,
        public float $end = 0,
    ) {}
}
