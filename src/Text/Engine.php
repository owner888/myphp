<?php
declare(strict_types=1);

namespace MyPHP\Text;

/**
 * The system's own text stack (mygo's text engine): it finds fonts,
 * falling back to the system's choices for what a font lacks, and shapes
 * and breaks paragraphs into lines. Core Text on macOS (Native\TextEngine);
 * StubEngine where there is none, as in the tests.
 */
interface Engine
{
    /** The font a style asks for, at its size, which sets the default line height: the first family of the style the system has, or its interface font. */
    public function font(Style $style): ?Font;

    /**
     * Breaks $runes, a paragraph without newlines, into lines of at most
     * $width points, or into one line when $width is 0, and shapes them.
     * $rtl sets the paragraph's direction; $wholeWords lets a word longer
     * than a line overflow it instead of breaking it; $spans style runs of
     * the text apart from $style, with their ends relative to it.
     *
     * Each line holds its runes [start, end), whitespace ending it
     * included, and its runs, each of one font and direction, whose glyphs
     * are positioned from the start of the line's pen, y down from the
     * baseline, their cluster the paragraph's rune that starts it.
     *
     * @param list<int> $runes
     * @param list<Span> $spans
     * @return list<array{start: int, end: int, runs: list<array{font: ?Font, start: int, end: int, glyphs: list<Glyph>}>}>
     */
    public function shape(array $runes, Style $style, array $spans, float $width, bool $rtl, bool $wholeWords): array;

    /**
     * The outline of glyph $glyph of $font in points from its origin, y
     * up, as line segments [x0, y0, x1, y1] (mygo's glyphOutline), which
     * underlines skip where it crosses them; none for a glyph without one.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function outline(Font $font, int $glyph): array;

    /** Adds a TrueType or OpenType font, or collection, under $family, or its own family name for '', throwing what it is not (mygo's register). */
    public function register(string $data, string $family): void;

    /** The families the app registered, then the system's, each with whether its regular font is monospaced. @return array{0: list<array{0: string, 1: bool}>, 1: list<array{0: string, 1: bool}>} */
    public function fontFamilies(): array;
}
