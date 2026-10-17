<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * A value going from $from to $to along $ease over $duration seconds,
 * which a Timeline places and plays (mygo's Tween). With no ease it eases
 * out (Easing::easeOut), as an Animation does.
 */
final class Tween
{
    public function __construct(
        public readonly float $from,
        public readonly float $to,
        public readonly float $duration = 0.0,
        public readonly ?Closure $ease = null,
    ) {}
}
