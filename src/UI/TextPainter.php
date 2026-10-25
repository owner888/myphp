<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** What the painter needs from a text engine besides its metrics: glyphs painted, images placed, the backing scale. Text implements it; the tests and the benchmark fake it. */
interface TextPainter extends TextMetrics
{
    /** Paints $text at $size from ($x, $y), the line's top left, in $rgb at $alpha; $style is Font bits. */
    public function paint(DisplayList $list, string $text, float $size, float $x, float $y, int $rgb, float $alpha = 1.0, int $style = 0): void;

    /** Paints $text as paint does, each glyph in the color $colorAt(int $byte): array{0: int, 1: float} gives for the byte of $text its cluster starts at (mygo's TextLine with a color a cluster). */
    public function paintRuns(DisplayList $list, string $text, float $size, float $x, float $y, int $style, \Closure $colorAt): void;

    /**
     * Paints the glyphs of $l, its top left at ($x, $y), each in the color
     * $colorAt(int $rune): array{0: int, 1: float} gives the rune its
     * cluster starts at; inline objects draw nothing.
     */
    public function paintLayout(DisplayList $list, \MyPHP\Text\Layout $l, float $x, float $y, \Closure $colorAt): void;

    /**
     * The coverage of $glyphs (Context::shape) drawn with the pen at ($x,
     * $y) on the baseline, in points, onto $w × $h pixels at $scale pixels
     * a point: a byte a pixel, top row first (what Context::renderGlyphs
     * returns).
     * @param list<\MyPHP\Text\Glyph> $glyphs
     */
    public function glyphCoverage(array $glyphs, float $x, float $y, int $w, int $h, float $scale): string;

    /** The image atlas slot of $image: u, v, w, h in pixels and its page. @return array{u: int, v: int, w: int, h: int, page: int} */
    public function imageSlot(Image $image): array;

    /**
     * The glyph quad of $svg's shapes fitted in the rect, turned by $rotate
     * degrees around its center, from a mask cached in the atlas by the
     * SVG, its size in pixels and the turn, as mygo's drawIcon: x, y, w, h
     * in points and the slot's u, v; null when there is nothing to draw.
     * @return array{0: float, 1: float, 2: float, 3: float, 4: int, 5: int}|null
     */
    public function iconQuad(SVG $svg, float $x, float $y, float $w, float $h, float $rotate = 0.0): ?array;

    /**
     * The image atlas slot of $svg drawn in its own colors at $w × $h
     * pixels, $current (0xRRGGBB) for currentColor, as mygo's picture:
     * drawn once at a size and color, kept while frames draw it.
     * @return array{u: int, v: int, w: int, h: int, page: int}
     */
    public function svgSlot(SVG $svg, int $w, int $h, int $current): array;

    /** The backing scale glyphs are rasterized at: device pixels a point. */
    public function scale(): float;
}
