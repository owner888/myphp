<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A Highlighter knowing what folds: foldEnd returns the last line folding $line hides, or null when $line starts no fold, rather than what indentation and brackets say. */
interface Folder
{
    public function foldEnd(int $line): ?int;
}
