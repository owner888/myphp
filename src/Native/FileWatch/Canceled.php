<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

/**
 * The watch was closed while it looked (Go's context.Canceled).
 *
 * @internal
 */
final class Canceled extends \RuntimeException
{
    public function __construct(string $message = 'myphp: file watch closed', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
