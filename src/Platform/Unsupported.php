<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** What a feature the current backend cannot provide throws (mygo's platform.ErrUnsupported). */
final class Unsupported extends \RuntimeException
{
    public function __construct(string $message = 'not supported on this platform')
    {
        parent::__construct($message);
    }
}
