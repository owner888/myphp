<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

use Closure;

/**
 * Math within text, as Options::$inlineMath makes it (mygo's
 * markdown.InlineObject): a box $width wide, $height above the baseline
 * and $depth below it, in points, which $draw(Painter $p, array $box)
 * paints in its box [x, y, w, h] in the window. $draw only paints: it may
 * run more than once a frame.
 */
final class InlineObject
{
    /** @param ?Closure(\MyPHP\UI\Painter, array{0: float, 1: float, 2: float, 3: float}): void $draw */
    public function __construct(
        public float $width,
        public float $height,
        public float $depth = 0,
        public ?Closure $draw = null,
    ) {}
}
