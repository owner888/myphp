<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * What a URLSession transfer that ended for another reason than the server's answer throws, as Foundation's URLError:
 * the transfer was cancelled (`$task->cancel()`, a `$progress` that answered false), code URLError::CANCELLED, as
 * `URLError.cancelled` (-999). Other failures (an address that does not resolve, a refused connection, a timeout) are
 * the RuntimeException of Http, with curl's reason.
 */
final class URLError extends \RuntimeException
{
    /** NSURLErrorCancelled, `URLError.Code.cancelled`. */
    public const CANCELLED = -999;

    public static function cancelled(): self
    {
        return new self('cancelled', self::CANCELLED);
    }

    public function isCancelled(): bool
    {
        return $this->getCode() === self::CANCELLED;
    }
}
