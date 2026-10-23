<?php
declare(strict_types=1);

namespace MyPHP;

/** The matches of Window::findInPage() (mygo's FindResult): how many there are, and which is the current one, from 1; both 0 without matches. */
final class FindResult
{
    public function __construct(
        public readonly int $matches = 0,
        public readonly int $active = 0,
    ) {}
}
