<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** What the painter needs from a text engine besides its metrics: glyphs painted, images placed, the backing scale. Text implements it; the tests and the benchmark fake it. */
interface TextPainter extends TextMetrics
{
    /** Paints $text at $size from ($x, $y), the line's top left, in $rgb at $alpha; $style is Font bits. */
    public function paint(DisplayList $list, string $text, float $size, float $x, float $y, int $rgb, float $alpha = 1.0, int $style = 0): void;

    /** The image atlas slot of $image: u, v, w, h in pixels and its page. @return array{u: int, v: int, w: int, h: int, page: int} */
    public function imageSlot(Image $image): array;

    /** The backing scale glyphs are rasterized at: device pixels a point. */
    public function scale(): float;
}
