<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A run of unchanged lines between hunks, from the old line $a and the new line $b, $n lines, and which of them collapse (mygo's diffGap). */
final class DiffGap
{
    /** Where the hidden lines start in the old and new text, and how many. */
    public int $hideA = 0, $hideB = 0, $hidden = 0;

    /** @param bool $before before the first hunk @param bool $after after the last */
    public function __construct(public int $a, public int $b, public int $n, public bool $before, public bool $after) {}
}
