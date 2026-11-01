<?php
declare(strict_types=1);

namespace MyPHP\Platform;

use MyPHP\Text\Engine;

/**
 * The system's own glyphs, which the UI's text paints from the atlas: the
 * text engine laying out paragraphs over them, the shaping of a single
 * line, and the rasterizing of glyphs at the backing scale (mygo's text
 * engine's glyph, positions and baseline: Core Text on macOS).
 */
interface Fonts
{
    /** The text engine that lays out paragraphs with these fonts, the same each call. */
    public function engine(): Engine;

    /** Pixels a point glyphs rasterize at by default. */
    public function scale(): float;

    public function setScale(float $scale): void;

    /** How many horizontal positions within a pixel glyphs of $px pixels an em are drawn at; a glyph goes to the one left of its pen (mygo's positions). */
    public function positions(float $px): int;

    /** The whole pixel a baseline $y device pixels from the top is drawn at (mygo's baseline). */
    public function baseline(float $y): float;

    /** Whether the glyphs of font $fontId are bitmaps in color, which rasterize() draws in their colors. */
    public function isColor(int $fontId): bool;

    /**
     * Shapes $text, one line, at $size with UI\Font style bits; positions in
     * points from the pen origin on the baseline, y up.
     * @return array{width: float, ascent: float, descent: float, glyphs: list<array{font: int, glyph: int, x: float, y: float, index: int}>, carets: array<int, float>} index is the UTF-16 index of the glyph's cluster
     */
    public function shape(string $text, float $size, int $style = 0): array;

    /**
     * Rasterizes a glyph at scale(), or at $scale; null for glyphs without
     * ink. Its rows are Native\Mem-allocated, freed by the caller.
     * @return array{w: int, h: int, ox: int, oy: int, rows: \FFI\CData, addr: int, color: bool}|null
     */
    public function rasterize(int $fontId, int $glyph, ?float $scale = null, int $shade = -1, float $stroke = 0.0, float $blur = 0.0, bool $cutOut = false, float $dx = 0.0): ?array;
}
