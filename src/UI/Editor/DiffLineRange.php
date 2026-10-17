<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Lines $start to $end, inclusive, from 0, of a side of a diff (DiffState::OLD or NEW) (mygo's DiffLineRange). */
final class DiffLineRange
{
    public function __construct(public int $side, public int $start, public int $end) {}

    public function equals(DiffLineRange $r): bool { return $this->side === $r->side && $this->start === $r->start && $this->end === $r->end; }
}
