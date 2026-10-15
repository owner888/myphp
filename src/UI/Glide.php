<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The easing of smooth scrolls: each frame covers this share of what is left. */
final class Glide
{
    /** Time constant in seconds; the scroll is within half a point of its target after about five. */
    public const TAU = 0.08;

    public static function step(float $dt): float
    {
        return 1.0 - exp(-$dt / self::TAU);
    }
}
