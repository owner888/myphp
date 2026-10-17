<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A Highlighter knowing the nodes of a syntax tree: Select Larger selects the smallest node holding more than the bytes $start to $end, rather than the word, string or brackets around them. */
interface SyntaxSelector
{
    public function enclosing(int $start, int $end): ?Range;
}
