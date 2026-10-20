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
}
