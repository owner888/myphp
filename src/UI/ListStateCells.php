<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * What ListState does for choosing by dragging over rows and for a table
 * choosing blocks of cells (mygo's dragselect.go and cellselect.go): the
 * state the app sets, what it reads, and what Context::list,
 * Context::table and the runtime ask of it.
 */
trait ListStateCells
{
    /** Leaves the rows chosen as they are when a right-click opens a row's context menu (mygo's MenuKeepsSelection): without it, a list with a $selection chooses the row clicked unless it is chosen, and in a table choosing cells the cell too, unless it is in the block. A list with $selected alone never chooses on a right-click. */
    public bool $menuKeepsSelection = false;
    /**
     * With a $selection, chooses rows as the pointer drags over them from
     * the row it pressed (mygo's DragSelect): those from that row to the
     * one under the pointer, as a Shift-click on it would, and with Cmd
     * held as the press began, those besides the rows chosen. Held near
     * the list's top or bottom, or past them, the drag scrolls the list. A
     * press on a row not chosen chooses it as the pointer goes down, as a
     * click would, and a press on a row chosen waits for the release, so
     * that a drag of the choice keeps it; so in a table choosing cells too.
     * A list cannot both drag-select and $reorder.
     */
    public bool $dragSelect = false;
    /**
     * With a $selection, has a table choose a block of cells (mygo's
     * CellSelect): the rows chosen, times the columns from $cells->anchor
     * to $cells->cursor in the order shown. A click on a cell chooses its
     * row as a click on the row does, and its column; a Shift-click
     * extends the rows and the columns to it, a Cmd-click adds its row or
     * takes it out, keeping the columns. Dragging over cells chooses those
     * it goes over, as $dragSelect does the rows, scrolling sideways too.
     * Left and Right move $cells->cursor, and Shift with them extends the
     * columns; the keys that move the rows leave one column, unless with
     * Shift. The cells of the block show as a chosen row does, as do the
     * row header cells of whole rows chosen, and the one at the cursor in
     * the row the keys move from has a border while the table has the
     * focus. A row header column (TableColumn::$rowHeader) chooses whole
     * rows. A table cannot both choose cells and $reorder.
     */
    public bool $cellSelect = false;
    /** The columns of the block $cellSelect chooses. */
    public ColumnRange $cells;
    /** Gives a table's cells a context menu, built as Node::contextMenu's, over the whole cell with its padding: fn(Menu $m, int $row, int $col), as the cell builder receives them (mygo's CellMenu). A menu built without items does not open; a context menu of what a cell holds takes the right-click over it instead. */
    public ?Closure $cellMenu = null;

    /** @internal Context::table: the columns of the table choosing cells, in the order shown this frame: their ids, their places among the columns given, whether they hold row headers, and the ids of their headers' cells. @var list<array{id: string, col: int, rowHeader: bool, head: int}> */
    public array $tableCols = [];
    /** @internal Context::list: the drag choosing rows: the row it began on, whether it adds to the rows chosen, and the column of the cell pressed (a row header's ''). @var ?array{from: int, add: bool, fromCol: string} */
    public ?array $dragSel = null;
    /** @internal Context::list: the rows the left button went down on while it is held, and in the frame it is let go, by row: where, in the window, and whether the press chose the row, which its click then does not (mygo's rowPressed and pressChose). @var array<int, array{0: float, 1: float, 2: bool}> */
    public array $rowPresses = [];
    /** @internal Context::list: the row the press held now began on, and how far the pointer went since. */
    public ?int $pressOn = null;
    /** @internal */
    public float $pressDist = 0.0;

    /** Whether the list holds the rows of a table choosing blocks of cells. @internal */
    public function choosesCells(): bool
    {
        return $this->cellSelect && $this->selection !== null && $this->tableCols !== [];
    }

    /** Whether a drag over the rows chooses them ($dragSelect), as it does in a table choosing cells. @internal */
    public function dragSelects(): bool
    {
        return $this->selection !== null && ($this->dragSelect || $this->choosesCells());
    }

    /**
     * Whether the cell of $row and $col, as a table's cell builder receives
     * them, is chosen (mygo's CellSelected): its row is chosen and, in a
     * table choosing cells, its column is in the block, or it is a row
     * header's cell of a row chosen with every column. In a table that does
     * not choose cells, every cell of a chosen row is.
     */
    public function cellSelected(int $row, int $col): bool
    {
        if ($this->selected === null || $row < 0 || $row >= $this->count || $this->isHeader($row) || !$this->chosen($row)) {
            return false;
        }
        if (!$this->choosesCells()) {
            return true;
        }
        foreach ($this->tableCols as $k => $tc) {
            if ($tc['col'] === $col) {
                return $tc['rowHeader'] ? $this->cells->all : $this->inBlock($k);
            }
        }
        return false;
    }

    /** The ids of the columns of the block of cells a table chooses, in the order shown in the last frame, without row headers (mygo's SelectedColumns). @return list<string> */
    public function selectedColumns(): array
    {
        [$lo, $hi] = $this->block();
        $ids = [];
        for ($k = $lo; $k <= $hi; $k++) {
            if (!$this->tableCols[$k]['rowHeader']) { $ids[] = $this->tableCols[$k]['id']; }
        }
        return $ids;
    }

    /** Chooses every row of a table choosing cells and the columns from the one whose id is $from to the one whose id is $to, the cursor's (mygo's SelectColumns). Call it after the table is built. */
    public function selectColumns(string $from, string $to): void
    {
        if (!$this->choosesCells()) {
            return;
        }
        $this->selectAll();
        $this->setCells(new ColumnRange($to, $from));
    }

    /** Whether a drag choosing rows ($dragSelect), or the cells of a table, goes on: the pointer holds it (mygo's DragSelecting). An app can put off work that follows the choice until it ends. */
    public function dragSelecting(): bool
    {
        return $this->dragSel !== null && ($this->ownerContext?->runtime?->held() ?? false);
    }

    /** Whether row $i is chosen and, in a table choosing cells, the cell at the window's point ($x, $y) is in the block (mygo's chosenAt). @internal */
    public function chosenAt(int $i, float $x, float $y, Runtime $rt): bool
    {
        return $this->chosen($i) && (!$this->choosesCells() || $this->cellInBlockAt($x, $y, $rt));
    }

    /** Chooses row $i as a click with $mods on it does, and in a table choosing cells the cell at the window's point ($x, $y) (mygo's clickAt). @internal */
    public function clickAt(int $i, float $x, float $y, int $mods, Runtime $rt): void
    {
        $this->click($i, $mods);
        if ($this->choosesCells()) { $this->clickCell($x, $y, $mods, $rt); }
    }

    /** Makes the block every column, the rows as they are. @internal */
    public function chooseAllColumns(): void
    {
        $c = clone $this->cells;
        $c->all = true;
        $this->setCells($c);
    }

    /** Makes $c the columns of the block, a new choice. */
    private function setCells(ColumnRange $c): void
    {
        if ($c != $this->cells) {
            $this->cells = $c;
            $this->noteChange();
        }
    }

    /** The place of the column whose id is $id among those shown, or -1. */
    private function colIndex(string $id): int
    {
        foreach ($this->tableCols as $k => $col) {
            if ($col['id'] === $id) { return $k; }
        }
        return -1;
    }

    /** Moves the cursor to the first column shown that holds no row headers when it names none, and the anchor to the cursor when it names none (mygo's fixCells). @internal */
    public function fixCells(): void
    {
        $c = $this->cells;
        if (($k = $this->colIndex($c->cursor)) < 0 || $this->tableCols[$k]['rowHeader']) {
            $k = $this->nextCol(-1, 1);
            $c->cursor = $k >= 0 ? $this->tableCols[$k]['id'] : '';
        }
        if (($k = $this->colIndex($c->anchor)) < 0 || $this->tableCols[$k]['rowHeader']) {
            $c->anchor = $c->cursor;
        }
    }

    /** The first column from place $k on by $d that holds no row headers, or -1. */
    private function nextCol(int $k, int $d): int
    {
        for ($k += $d; $k >= 0 && $k < count($this->tableCols); $k += $d) {
            if (!$this->tableCols[$k]['rowHeader']) { return $k; }
        }
        return -1;
    }

    /** The places of the first and the last column of the block among those shown, the last below the first for none. @internal @return array{0: int, 1: int} */
    public function block(): array
    {
        if ($this->cells->all) {
            return [0, count($this->tableCols) - 1];
        }
        $a = $this->colIndex($this->cells->anchor); $c = $this->colIndex($this->cells->cursor);
        return $a < 0 || $c < 0 ? [0, -1] : [min($a, $c), max($a, $c)];
    }

    /** Whether the column at place $k is one of the block's. */
    private function inBlock(int $k): bool
    {
        [$lo, $hi] = $this->block();
        return $k >= $lo && $k <= $hi && !$this->tableCols[$k]['rowHeader'];
    }

    /** The place of the column at the window's point ($x, $y) in the last frame, as its header showed, the nearest past them, or -1 (mygo's columnAt). */
    private function columnAt(float $x, float $y, Runtime $rt): int
    {
        $at = -1; $best = 0.0;
        foreach ($this->tableCols as $k => $col) {
            $r = $rt->rectOf($col['head']); $p = $rt->toLocal($col['head'], $x, $y);
            if ($r === null || $p === null) { continue; }
            $d = max(-$p[0], $p[0] - $r[2], 0.0);
            if ($at < 0 || $d < $best) { [$at, $best] = [$k, $d]; }
        }
        return $at;
    }

    /** Sets the columns of the block for a click with $mods at the window's point ($x, $y): those of a row header's cell, every one (mygo's clickCell). */
    private function clickCell(float $x, float $y, int $mods, Runtime $rt): void
    {
        if (($k = $this->columnAt($x, $y, $rt)) < 0) {
            return;
        }
        $c = clone $this->cells; $id = $this->tableCols[$k]['id'];
        if ($this->tableCols[$k]['rowHeader']) {
            $c->all = true;
        } elseif ($mods & Key::SHIFT) {
            [$c->cursor, $c->all] = [$id, false];
        } elseif ($mods & Key::COMMAND) {
            return;
        } else {
            $c = new ColumnRange($id, $id);
        }
        $this->setCells($c);
    }

    /** Whether the cell at the window's point ($x, $y), in a row chosen, is in the block: a row header's, or none, counts as in it. */
    private function cellInBlockAt(float $x, float $y, Runtime $rt): bool
    {
        $k = $this->columnAt($x, $y, $rt);
        return $k < 0 || $this->tableCols[$k]['rowHeader'] || $this->inBlock($k);
    }

    /**
     * The cursor moved by $d columns as the screen shows them (Left and
     * Right, -1 and 1, 0 for neither), extending the columns with
     * $extend, without which the row of the keys is chosen alone; $collapse
     * leaves one column, as the keys moving the rows without Shift do,
     * and $all chooses every column, as Cmd+A does (mygo's cellKeys).
     * $list is the list's node, which scrolls sideways to show the cursor.
     * @internal
     */
    public function cellKeys(int $d, bool $extend, bool $collapse, bool $all, Runtime $rt, int $list): void
    {
        $c = clone $this->cells;
        if ($d !== 0) {
            if (($at = $this->colIndex($c->cursor)) >= 0 && ($k = $this->nextCol($at, $d)) >= 0) {
                $c->cursor = $this->tableCols[$k]['id'];
                $this->revealColumn($k, $rt, $list);
            }
            $collapse = $collapse || !$extend;
            if (!$extend && ($cur = $this->selected ?? -1) >= 0 && $cur < $this->count && !$this->isHeader($cur)) {
                $this->choose($cur); // one cell, as the keys moving the rows leave
            }
        }
        if ($collapse) { [$c->anchor, $c->all] = [$c->cursor, false]; }
        if ($all) { $c->all = true; }
        $this->setCells($c);
    }

    /** Scrolls the table sideways as little as shows the column at place $k, as its header showed in the last frame (mygo's revealColumn). */
    private function revealColumn(int $k, Runtime $rt, int $list): void
    {
        $h = $rt->rectOf($this->tableCols[$k]['head']); $l = $rt->rectOf($list); // in the layout's points, the table's own
        if ($h === null || $l === null) {
            return;
        }
        $dx = 0.0;
        if ($h[0] < $l[0]) {
            $dx = $h[0] - $l[0];
        } elseif ($h[0] + $h[2] > $l[0] + $l[2]) {
            $dx = min($h[0] + $h[2] - $l[0] - $l[2], $h[0] - $l[0]);
        }
        if ($dx != 0) { $this->scrollByX($dx); }
    }

    /** Notes the column a drag over the cells extends from: that of the cell pressed at the window's point ($x, $y), or with $extend, a Shift-press, the anchor; a row header's cell chooses every column (mygo's startDragCells). @internal */
    public function startDragCells(float $x, float $y, bool $extend, Runtime $rt): void
    {
        if ($extend && $this->colIndex($this->cells->anchor) >= 0) {
            $this->dragSel['fromCol'] = $this->cells->anchor;
            return;
        }
        $this->dragSel['fromCol'] = '';
        if (($k = $this->columnAt($x, $y, $rt)) < 0) {
            return;
        }
        if ($this->tableCols[$k]['rowHeader']) {
            $this->chooseAllColumns();
            return;
        }
        $this->dragSel['fromCol'] = $this->tableCols[$k]['id'];
    }

    /** Sets the columns of the block a drag over the cells chooses: from the column of the cell pressed to the one under the pointer at the window's point ($x, $y), over a row header column the nearest of the others toward the cell pressed (mygo's dragCells). @internal */
    public function dragCells(float $x, float $y, Runtime $rt): void
    {
        $from = $this->colIndex($this->dragSel['fromCol'] ?? '');
        if ($from < 0 || $this->tableCols[$from]['rowHeader']) {
            return; // none, or a drag from a row header's cell, which chooses whole rows
        }
        if (($to = $this->columnAt($x, $y, $rt)) < 0) {
            return;
        }
        if ($this->tableCols[$to]['rowHeader']) {
            $to = $this->nextCol($to, $to > $from ? -1 : 1);
        }
        $this->setCells(new ColumnRange($this->tableCols[$to]['id'], $this->dragSel['fromCol']));
    }
}
