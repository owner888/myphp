<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A selection of an editor (mygo's editor.Selection): the text from $anchor to $head, byte offsets, with the caret at $head. $anchor == $head is a caret alone. */
final class Selection
{
    public function __construct(public int $anchor = 0, public int $head = 0) {}

    /** The selection's edges in order. */
    public function start(): int { return min($this->anchor, $this->head); }
    public function end(): int { return max($this->anchor, $this->head); }

    /** Whether the selection is a caret alone. */
    public function empty(): bool { return $this->anchor === $this->head; }
}
