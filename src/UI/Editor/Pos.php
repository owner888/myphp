<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A place in a text: a line, from 0, and a column in bytes from the start of the line (mygo's editor.Pos). */
final class Pos
{
    public function __construct(public int $line = 0, public int $col = 0) {}

    /** Whether this comes before $q. */
    public function less(Pos $q): bool
    {
        return $this->line < $q->line || $this->line === $q->line && $this->col < $q->col;
    }

    public function equals(Pos $q): bool
    {
        return $this->line === $q->line && $this->col === $q->col;
    }
}
