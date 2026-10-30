<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

use RuntimeException;

/** What is wrong with a source's syntax, at a byte of it (go/scanner's Error). @internal */
final class SyntaxError extends RuntimeException
{
    public function __construct(public readonly int $at, string $message)
    {
        parent::__construct($message);
    }
}
