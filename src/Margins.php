<?php
declare(strict_types=1);

namespace MyPHP;

/** Page margins in inches (mygo's Margins). */
final class Margins
{
    public function __construct(
        public readonly float $top = 0,
        public readonly float $right = 0,
        public readonly float $bottom = 0,
        public readonly float $left = 0,
    ) {}
}
