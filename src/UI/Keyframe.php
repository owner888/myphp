<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * A value at $at seconds since a sequence starts (mygo's Keyframe). The
 * first $at must be zero, later ones strictly increase. $ease controls
 * the segment arriving at this frame; null means linear.
 */
final class Keyframe
{
    public function __construct(
        public readonly float $at,
        public readonly float $value,
        public readonly ?Closure $ease = null,
    ) {}
}
