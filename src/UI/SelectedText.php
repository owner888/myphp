<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The part of a paragraph that the selection of a Selectable container holds (mygo's SelectedText). */
final class SelectedText
{
    public function __construct(
        /** The paragraph's node's (Node::$id). */
        public readonly int $id,
        /** The paragraph's text, and the runes of it the selection holds. */
        public readonly string $text,
        public readonly int $start,
        public readonly int $end,
    ) {}
}
