<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** What a line of code being lexed knows (mygo's lexState). */
final class LexState
{
    /** @param list<array{0: int, 1: int}> $toks */
    public function __construct(
        public readonly string $line, public array $toks, public int $mode, public int $depth, public int $stack,
        /** the last token was a dot */
        public bool $prevDot = false,
        /** an expression may start */
        public bool $regexOK = true,
    ) {}
}
