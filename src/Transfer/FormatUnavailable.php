<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

/** The item or representation asked for is not there (mygo's transfer.ErrFormat). */
final class FormatUnavailable extends \RuntimeException
{
    public function __construct(string $message = 'transfer: representation unavailable')
    {
        parent::__construct($message);
    }
}
