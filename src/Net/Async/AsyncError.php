<?php

declare(strict_types=1);

namespace MyPHP\Net\Async;

use RuntimeException;

/** A fault in the async runtime itself: a dead loop, a bad await, a failed select. */
final class AsyncError extends RuntimeException
{
}
