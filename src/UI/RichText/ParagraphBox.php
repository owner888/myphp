<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/** Where a paragraph is in an editor, as Document::visibleParagraphs gives it (mygo's ParagraphBox). */
final class ParagraphBox
{
    public function __construct(
        /** The paragraph's index, from 0, and the rune it starts at. */
        public readonly int $index,
        public readonly int $start,
        /** The top and height of its lines, in points from the top of the editor's node, scrolled: the room above and below the paragraph (a heading's margins, the spacing between paragraphs) is left out, so that a mark at its top lines up with its first line. The top is below 0 for a paragraph scrolled partly out at the top. */
        public readonly float $top,
        public readonly float $height,
    ) {}
}
