<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * A Highlighter matching brackets, as do and end: enclosingBrackets
 * returns the innermost pair around bytes $start to $end, [open, close],
 * which editors highlight around the caret and move between, or null. As
 * in Zed, a pair is around them when its brackets are, or touch them:
 * the opening bracket starts at or before $start, and the closing one
 * ends at or after $end, so that a caret just after a closing bracket is
 * in its pair.
 */
interface BracketMatcher
{
    /** @return ?array{0: Range, 1: Range} */
    public function enclosingBrackets(int $start, int $end): ?array;
}
