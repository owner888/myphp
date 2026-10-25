<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The order and the widths the user gave the columns of a table, by their
 * ids (mygo's TableLayout). Columns it does not name keep the order and
 * the widths the table gives them.
 */
final class TableLayout
{
    /**
     * @param list<string> $order
     * @param array<string, float> $widths
     */
    public function __construct(
        public array $order = [],
        public array $widths = [],
    ) {}

    /**
     * The indexes of $columns in the order the layout gives them: those it
     * names in its order, the others after them in theirs, and fixed
     * columns where they are (mygo's arrange).
     * @param list<TableColumn> $columns
     * @return list<int>
     */
    public function arrange(array $columns): array
    {
        $ids = array_map(fn(TableColumn $col) => $col->id(), $columns);
        $named = [];
        foreach ($this->order as $id) {
            $j = array_search($id, $ids, true);
            if ($j !== false && !$columns[$j]->fixed && !in_array($j, $named, true)) { $named[] = $j; }
        }
        $movable = $named;
        foreach ($columns as $j => $col) {
            if (!$col->fixed && !in_array($j, $named, true)) { $movable[] = $j; }
        }
        $order = [];
        foreach ($columns as $j => $col) {
            $order[] = $col->fixed ? $j : array_shift($movable);
        }
        return $order;
    }

    /** The width of $col: the user's, or the table's, or null for one sharing the room left (mygo's width). */
    public function width(TableColumn $col): ?float
    {
        $w = $col->width;
        if (!$col->fixed && isset($this->widths[$col->id()])) { $w = $this->widths[$col->id()]; }
        return $w > 0 ? $col->clampWidth($w) : null;
    }
}
