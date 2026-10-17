<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A change (mygo's diff.Hunk): elements $a0 to $a1 of the old sequence replaced by $b0 to $b1 of the new one. $a0 == $a1 is an insertion before $a0, $b0 == $b1 a deletion. */
final class Hunk
{
    public function __construct(public int $a0, public int $a1, public int $b0, public int $b1) {}
}
