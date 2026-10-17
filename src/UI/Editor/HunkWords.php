<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** The inline diff of a hunk (mygo's hunkWords): which line of each side pairs with which, and the ranges changed in each line. */
final class HunkWords
{
    /** How many rows the shorter side is pushed down to pair its lines with the most similar ones. */
    public int $offset = 0;
    /** @var array<int, list<array{0: int, 1: int}>> the ranges changed, by old line */
    public array $oldWords = [];
    /** @var array<int, list<array{0: int, 1: int}>> by new line */
    public array $newWords = [];
}
