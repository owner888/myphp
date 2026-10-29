<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A line of a TextLayout (mygo's TextLine), in points from the layout's
 * top-left corner and in UTF-16 offsets, as its other methods take them.
 */
final class TextLine
{
    /** @param array{0: float, 1: float, 2: float, 3: float} $rect */
    public function __construct(
        /** The line's box: its x includes the alignment and the indent, and its width the whitespace ending the line, which the layout keeps. */
        public readonly array $rect,
        /** The y of the line's baseline; how far its fonts and objects reach above and below it. */
        public readonly float $baseline,
        public readonly float $ascent,
        public readonly float $descent,
        /** The offsets of the text the line holds: a newline ending it is not included, and $end is the next line's $start when the line wraps. */
        public readonly int $start,
        public readonly int $end,
        /** A line of a paragraph reading right to left. */
        public readonly bool $rtl,
    ) {}
}
