<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A press on a table's header (mygo's tableDrag): the column pressed, by
 * id, how far the pointer moved since, whether it moves the column, and
 * the column to fit to its cells in this frame.
 *
 * @internal
 */
final class TableDrag
{
    public ?string $id = null;
    public float $dx = 0.0;
    public bool $moving = false;
    public ?string $fit = null;
}
