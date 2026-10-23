<?php
declare(strict_types=1);

namespace MyPHP;

/** How Window::findInPage() finds (mygo's FindOptions). */
final class FindOptions
{
    public function __construct(
        /** Finds only text of the same capitalization. */
        public bool $matchCase = false,
        /** Goes to the previous match rather than the next. */
        public bool $backward = false,
        /** Moves on from the current match of the same text; without it the search starts over at the first match (the last, backward). */
        public bool $findNext = false,
    ) {}
}
