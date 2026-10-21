<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

/**
 * The watched directory, or one being looked at, was replaced (mygo's errWatchRootChanged).
 *
 * @internal
 */
final class IdentityChanged extends \RuntimeException
{
    public function __construct(string $message = 'watched root identity changed', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
