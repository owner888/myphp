<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

/** What a native drop target takes (mygo's transfer.DropOptions): one of $formats must be offered; none takes nothing; no operations permit copy. */
final class DropOptions
{
    /** @param list<string> $formats */
    public function __construct(
        public readonly array $formats = [],
        public readonly int $operations = Operation::NONE,
    ) {}
}
