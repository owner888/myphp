<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** An opening and a closing text, as ( and ), that the editor matches, closes as the opening is typed, and wraps a selection in (mygo's BracketPair). */
final class BracketPair
{
    /** @param list<int> $notIn kinds of tokens in which typing $open does not close it, as a quote typed in a string */
    public function __construct(public readonly string $open, public readonly string $close, public readonly array $notIn = []) {}
}
