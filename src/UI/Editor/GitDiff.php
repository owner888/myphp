<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Marks an editor's changes against its DiffBase in the gutter, as Zed
 * marks a file's changes against Git: a bar for lines added or
 * modified, a wedge where lines were deleted (mygo's gitDiff).
 */
final class GitDiff
{
    public DiffModel $d;

    public function __construct() { $this->d = new DiffModel; }

    /** How the gutter marks $line against the DiffBase: 1 added, 2 modified, 3 deleted above it, 0 nothing. */
    public function status(int $line): int
    {
        $i = $this->d->hunkAtNew($line);
        if ($i < 0) { return 0; }
        $h = $this->d->hunks[$i];
        if ($h->b0 === $h->b1) { return 3; }
        return $h->a0 === $h->a1 ? 1 : 2;
    }
}
