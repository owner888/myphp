<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

/**
 * The system dropped changes, as when its queue overflowed (mygo's platform.ErrFileWatchOverflow); the scan looks at everything again.
 *
 * @internal
 */
final class Overflow extends \RuntimeException
{
    public function __construct(string $message = 'file watch queue overflow', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
