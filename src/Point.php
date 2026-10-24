<?php
declare(strict_types=1);

namespace MyPHP;

/** A point on the screens, in points from the top-left corner of the primary display (mygo's Point). */
final class Point
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
    ) {}
}
