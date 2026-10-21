<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

/**
 * The system can't watch this way (mygo's platform.ErrUnsupported).
 *
 * @internal
 */
final class Unsupported extends \RuntimeException
{
    public function __construct(string $message = 'unsupported operation', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
