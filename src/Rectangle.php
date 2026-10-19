<?php
declare(strict_types=1);

namespace MyPHP;

/** A window's place on the screens, in points from the top-left corner of the primary screen (mygo's Rectangle). */
final class Rectangle
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
    ) {}
}
