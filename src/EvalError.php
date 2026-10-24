<?php
declare(strict_types=1);

namespace MyPHP;

/** Thrown by Page::eval when the script throws (mygo's EvalError): the script's error message. */
final class EvalError extends \RuntimeException
{
    public function __construct(public readonly string $scriptMessage)
    {
        parent::__construct("myphp: javascript error: $scriptMessage");
    }
}
