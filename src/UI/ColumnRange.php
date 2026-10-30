<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The columns of the block of cells a table chooses (ListState::$cellSelect,
 * mygo's ColumnRange), by column id (TableColumn::id()): $cursor the column
 * the keys move from, where the border shows; $anchor the one Shift extends
 * the columns from; $all every column, as a click on a row header's cell
 * chooses.
 */
final class ColumnRange
{
    public function __construct(
        public string $cursor = '',
        public string $anchor = '',
        public bool $all = false,
    ) {}
}
