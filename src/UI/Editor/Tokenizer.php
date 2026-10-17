<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Splits lines of code into tokens (mygo's Tokenizer). tokenize appends
 * the tokens of $line, which has no newline, to $tokens, starting in
 * $state, and returns them with the state the line ends in, which the
 * next line starts in: 0 at the start of the text, and states that
 * compare equal for lines that go on alike, as being inside a comment.
 */
interface Tokenizer
{
    /** @param list<array{0: int, 1: int}> $tokens @return array{0: list<array{0: int, 1: int}>, 1: int} */
    public function tokenize(string $line, int $state, array $tokens): array;
}
