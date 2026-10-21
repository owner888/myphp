<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

/** A cell of a row (mygo's vt.Cell). @internal */
final class Cell
{
    /** How wide a cell's character is (mygo's vt.Wide): WIDE takes this cell and the next, a SPACER_TAIL; SPACER_HEAD ends a soft-wrapped row before a wide character that did not fit. */
    public const NARROW = 0, WIDE = 1, SPACER_TAIL = 2, SPACER_HEAD = 3;

    public function __construct(
        /** The character, 0 for none; $grapheme tells of more codepoints after it (RenderState::cellGraphemes). */
        public int $codepoint = 0,
        public bool $grapheme = false,
        /** Identifies the cell's style (RenderState::cellStyle) among the row's cells: 0 is the default. */
        public int $style = 0,
        public int $wide = self::NARROW,
        public bool $hyperlink = false,
        /** The color of a cell without text whose background a program filled, 0xRRGGBB, when $hasBackground; $palette tells it is the palette's color of index $background. */
        public int $background = 0,
        public bool $hasBackground = false,
        public bool $palette = false,
    ) {}
}
