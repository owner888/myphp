<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Watch;

/**
 * The code of a failure, as pages see it: never a path (mygo's failure).
 *
 * @internal
 */
final class Failure extends \RuntimeException
{
    public const DENIED = 'denied', LIMIT = 'limit', NOT_FOUND = 'not-found', UNSUPPORTED = 'unsupported', ENCODING = 'path-encoding', IO = 'io';

    public function __construct(public readonly string $failure)
    {
        parent::__construct('filesystem watch ' . str_replace('-', ' ', $failure));
    }
}
