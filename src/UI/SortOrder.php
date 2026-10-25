<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** How a table's rows are sorted: by the column whose id is $column, ascending unless $descending (mygo's SortOrder). */
final class SortOrder
{
    public function __construct(
        public readonly string $column,
        public readonly bool $descending = false,
    ) {}
}
