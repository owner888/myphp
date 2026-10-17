<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Asks for the completions at $offset of the text at $version: $prefix
 * is the word before the caret, which the editor filters the completions
 * it is given by as typing goes on (mygo's CompletionRequest).
 */
final class CompletionRequest
{
    /** @param bool $explicit asked for with Ctrl+Space, rather than by typing */
    public function __construct(public int $offset, public string $prefix, public int $version, public bool $explicit) {}
}
