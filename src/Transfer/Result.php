<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

/** How a native drag ended (mygo's transfer.Result): the destination's effect, none for no drop. */
final class Result
{
    public function __construct(
        public readonly int $operation = Operation::NONE,
        public readonly bool $canceled = false,
        public readonly ?\Throwable $error = null,
    ) {}
}
