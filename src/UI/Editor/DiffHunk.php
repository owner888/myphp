<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A change of a diff: the old lines $oldStart to $oldEnd, exclusive, replaced by the new lines $newStart to $newEnd (mygo's DiffHunk). */
final class DiffHunk
{
    public function __construct(public int $oldStart, public int $oldEnd, public int $newStart, public int $newEnd) {}
}
