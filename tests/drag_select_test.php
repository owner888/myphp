<?php
// Choosing by dragging over rows, blocks of cells, a right-click choosing
// its row, and a cell seeing its row's double click (mygo's
// dragselect_test.go, cellselect_test.go, list_test.go and
// rowclick_test.go of 87d400a).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{ColumnRange, Context, Direction, Key, ListState, Node, Selection, SortOrder, TableColumn};

/** The rows $sel chooses, in order. @return list<int> */
function dsChosen(Selection $sel): array
{
    $k = $sel->all();
    sort($k);
    return $k;
}

/** A list of $n rows "Row i" choosing by dragging over them. @return array{0: Headless, 1: ListState, 2: Selection} */
function dsList(int $n, bool $drag = true, ?\Closure $around = null): array
{
    $s = new ListState(); $s->selected = -1; $s->selection = $sel = new Selection(); $s->dragSelect = $drag;
    $h = new Headless(function (Context $c) use ($s, $n, $around): void {
        $list = fn() => $c->list($s, $n, fn(int $i) => $c->text("Row $i"))->grow = 1;
        $around === null ? $list() : $around($c, $list);
    }, 300, 300);
    $h->frame();
    return [$h, $s, $sel];
}

/** The middle of the node showing $text. @return array{0: float, 1: float} */
function dsAt(Headless $h, string $text): array
{
    return $h->middle($h->byText($text) ?? throw new \RuntimeException("no $text"));
}

// Pressing a row and dragging over others chooses the rows between, and the release leaves them chosen, though it comes back to the first row.
[$h, $s, $sel] = dsList(20);
[$x, $y] = dsAt($h, 'Row 1'); [, $y4] = dsAt($h, 'Row 4'); [, $y2] = dsAt($h, 'Row 2');
$h->press($x, $y); $h->drag($x, $y + 3); $h->drag($x, $y4);
same([1, 2, 3, 4], dsChosen($sel), 'dragged Row 1 to Row 4');
check($s->dragSelecting(), 'drag selecting while it goes on');
$h->drag($x, $y2); $h->release($x, $y2);
same([1, 2], dsChosen($sel), 'back to Row 2 and released');
check(!$s->dragSelecting(), 'not drag selecting after the release');
$h->press($x, $y); $h->drag($x, $y4); $h->drag($x, $y); $h->release($x, $y);
same([1], dsChosen($sel), 'a drag back onto the row pressed is no click on it');
$h->clickText('Row 9');
[, $y3] = dsAt($h, 'Row 3');
$h->press($x, $y, Key::COMMAND); $h->drag($x, $y + 3); $h->drag($x, $y3); $h->release($x, $y3);
same([1, 2, 3, 9], dsChosen($sel), 'Cmd adds the rows dragged over to those chosen');

// Held below the list, the drag scrolls it and goes on choosing, to the end.
[$h, $s, $sel] = dsList(200);
[$x, $y] = dsAt($h, 'Row 1');
$h->press($x, $y); $h->drag($x, $y + 3); $h->drag($x, 299);
for ($k = 0; $k < 400 && count($sel->all()) < 199; $k++) { $h->frame(1); }
$got = dsChosen($sel);
check($s->dragSel !== null && count($got) === 199 && $got[0] === 1 && end($got) === 199, 'held at the bottom edge: chose ' . count($got) . ' rows');
$h->release($x, 299);

// A list cannot both drag-select and reorder.
$s = new ListState(); $s->selected = -1; $s->selection = new Selection(); $s->dragSelect = true; $s->reorder = function (array $rows, int $to): void {};
$threw = false;
try { (new Headless(fn(Context $c) => $c->list($s, 3, fn(int $i) => $c->text("Row $i")), 100, 100))->frame(); } catch (\LogicException) { $threw = true; }
check($threw, 'drag-select with reorder throws');

// A press on a row not chosen chooses it as the pointer goes down; one on a row chosen waits for the release. Other lists choose on the release.
[$h, $s, $sel] = dsList(20);
[$x, $y] = dsAt($h, 'Row 3'); [$x5, $y5] = dsAt($h, 'Row 5');
$h->press($x, $y);
same([3], dsChosen($sel), 'a press on Row 3 chose it before the release');
$h->release($x, $y);
same([3], dsChosen($sel), 'its release chose nothing more');
$h->press($x5, $y5, Key::COMMAND);
same([3, 5], dsChosen($sel), 'a Cmd-press on Row 5 adds it');
$h->input->push(['up', $x5, $y5, 0, 1, Key::COMMAND]); $h->frame();
same([3, 5], dsChosen($sel), 'the release of the Cmd-click does not take it out again');
$h->press($x, $y);
same([3, 5], dsChosen($sel), 'a press on chosen Row 3 changes nothing before the release');
$h->release($x, $y);
same([3], dsChosen($sel), 'the click on chosen Row 3 chooses it alone');
[$h, $s, $sel] = dsList(6, false);
[$x, $y] = dsAt($h, 'Row 2');
$h->press($x, $y);
same([], dsChosen($sel), 'a plain list: no choice at the press');
$h->release($x, $y);
same([2], dsChosen($sel), 'a plain list: the click chooses');

// A press that chose a row but ended elsewhere does not swallow the next click on it; a release and a press in one frame do not cross.
[$h, $s, $sel] = dsList(20);
[$x, $y] = dsAt($h, 'Row 3'); [, $y5] = dsAt($h, 'Row 5'); [, $y1] = dsAt($h, 'Row 1'); [, $y7] = dsAt($h, 'Row 7');
$h->press($x, $y); $h->drag($x, $y + 6); $h->drag($x, $y5); $h->release($x, $y5);
same([3, 4, 5], dsChosen($sel), 'dragged Row 3 to Row 5');
$h->click($x, $y);
same([3], dsChosen($sel), 'then a click on Row 3 within one frame');
$h->press($x, $y7);
$h->input->push(['up', $x, $y7, 0, 1, 0]); $h->input->mouse($x, $y1); $h->input->push(['down', $x, $y1, 0, 1, 0]); $h->frame();
$h->release($x, $y1);
same([1], dsChosen($sel), 'Row 7 released and Row 1 pressed in one frame, then released');

// A Shift-press extends the choice, and a drag from it goes on extending from where the choice began.
[$h, $s, $sel] = dsList(20);
$h->clickText('Row 2');
[$x, $y] = dsAt($h, 'Row 5'); [, $y6] = dsAt($h, 'Row 6');
$h->press($x, $y, Key::SHIFT); $h->drag($x, $y + 6); $h->drag($x, $y6);
same([2, 3, 4, 5, 6], dsChosen($sel), 'click Row 2, Shift-press Row 5, drag to Row 6');
$h->release($x, $y6);
same([2, 3, 4, 5, 6], dsChosen($sel), 'and released');

// The rows under the pointer as they show, moved by a transform around the list.
[$h, $s, $sel] = dsList(20, true, function (Context $c, \Closure $list): void {
    $c->column(0, 0, 1)->translate(0, -40); $list(); $c->end();
});
$p2 = $h->rt->windowRect($h->rt->rectOf($h->byText('Row 2')->id));
$p5 = $h->rt->windowRect($h->rt->rectOf($h->byText('Row 5')->id));
[$x, $y] = [$p2[0] + 10, $p2[1] + $p2[3] / 2 - 40]; $y5 = $p5[1] + $p5[3] / 2 - 40;
$h->press($x, $y); $h->drag($x, $y + 6); $h->drag($x, $y5); $h->release($x, $y5);
same([2, 3, 4, 5], dsChosen($sel), 'dragged Row 2 to Row 5 under a translation');

// A right-click on a row not chosen chooses it before its menu opens; one chosen keeps the choice; menuKeepsSelection keeps it always.
$s = new ListState(); $s->selected = -1; $s->selection = $sel = new Selection();
$h = new Headless(function (Context $c) use ($s): void {
    $c->list($s, 6, fn(int $i) => $c->text("Row $i")->contextMenu(fn($m) => $m->item('Copy')))->grow = 1;
}, 300, 300);
$h->frame();
$h->clickText('Row 1'); $h->clickOn($h->byText('Row 3'), Key::SHIFT);
[$x, $y] = dsAt($h, 'Row 2');
$h->rightClick($x, $y);
same([1, 2, 3], dsChosen($sel), 'a right-click on a chosen row keeps the choice');
$h->closeMenu();
[$x, $y] = dsAt($h, 'Row 5');
$h->rightClick($x, $y);
same([5], dsChosen($sel), 'a right-click on a row not chosen chooses it');
check($h->menu !== null, 'and its menu opens');
$h->closeMenu();
$s->menuKeepsSelection = true;
[$x, $y] = dsAt($h, 'Row 0');
$h->rightClick($x, $y);
same([5], dsChosen($sel), 'menuKeepsSelection keeps it');
$h->closeMenu();
$s2 = new ListState(); $s2->selected = -1;
$h = new Headless(function (Context $c) use ($s2): void {
    $c->list($s2, 6, fn(int $i) => $c->text("Row $i")->contextMenu(fn($m) => $m->item('Copy')))->grow = 1;
}, 300, 300);
$h->frame();
[$x, $y] = dsAt($h, 'Row 4');
$h->rightClick($x, $y);
same(-1, $s2->selected, 'a list choosing one row alone does not choose on a right-click');

/** A table choosing blocks of cells, of $n rows: a column of row headers, "#", then columns A to the $count-th letter, $width wide (0 sharing the room); cell r1c2 is row 1's in column B. @return array{0: Headless, 1: ListState, 2: Selection} */
function cellTable(int $n, int $count, float $width, bool $rtl = false, mixed &$sort = null, bool $sortable = false): array
{
    $s = new ListState(); $s->selected = -1; $s->selection = $sel = new Selection(); $s->cellSelect = true;
    if ($sortable) { $s->sort = $sort = new SortOrder(''); }
    $cols = [new TableColumn('#', 40, sortable: $sortable, fixed: true, rowHeader: true)];
    for ($k = 0; $k < $count; $k++) { $cols[] = new TableColumn(chr(65 + $k), $width, sortable: $sortable); }
    $h = new Headless(function (Context $c) use ($s, $cols, $n, $rtl): void {
        $c->column(0, 0, 1)->alignItems(Node::STRETCH)->direction($rtl ? Direction::RightToLeft : Direction::LeftToRight);
          $c->table($s, $cols, $n, fn(int $row, int $col) => $c->text("r{$row}c{$col}")->contextMenu(fn($m) => $m->item('Copy')))->grow = 1;
        $c->end();
    }, 400, 300);
    $h->frame();
    return [$h, $s, $sel];
}

/** Whether $s chooses $rows, the block $cells and the columns $cols. @param list<int> $rows @param list<string> $cols */
function wantBlock(ListState $s, Selection $sel, string $what, array $rows, ColumnRange $cells, array $cols): void
{
    same([$rows, [$cells->cursor, $cells->anchor, $cells->all], $cols], [dsChosen($sel), [$s->cells->cursor, $s->cells->anchor, $s->cells->all], $s->selectedColumns()], $what);
}

// A click on a cell chooses its row and its column, a Shift-click the rows and the columns from there, a Cmd-click adds the row keeping the columns, a drag chooses the rows and columns it goes over.
[$h, $s, $sel] = cellTable(8, 3, 0);
$h->clickText('r1c1');
wantBlock($s, $sel, 'click r1c1', [1], new ColumnRange('A', 'A'), ['A']);
$h->clickOn($h->byText('r3c3'), Key::SHIFT);
wantBlock($s, $sel, 'Shift-click r3c3', [1, 2, 3], new ColumnRange('C', 'A'), ['A', 'B', 'C']);
$h->clickOn($h->byText('r5c2'), Key::COMMAND);
wantBlock($s, $sel, 'Cmd-click r5c2', [1, 2, 3, 5], new ColumnRange('C', 'A'), ['A', 'B', 'C']);
[$x, $y] = dsAt($h, 'r0c2'); [$x2, $y2] = dsAt($h, 'r2c1');
$h->press($x, $y); $h->drag($x, $y + 3); $h->drag($x2, $y2); $h->release($x2, $y2);
wantBlock($s, $sel, 'drag r0c2 to r2c1', [0, 1, 2], new ColumnRange('A', 'B'), ['A', 'B']);
check(!$h->byText('r1c1')->selected && dsCell($h, 'r1c1')->selected && dsCell($h, 'r1c2')->selected && !dsCell($h, 'r1c3')->selected && !dsCell($h, 'r3c1')->selected, 'the cells of the block show chosen, those outside not');
check(dsCell($h, 'r1c1')->role === \MyPHP\UI\Role::CELL, 'a cell');
check($s->cellSelected(1, 1) && $s->cellSelected(2, 2) && !$s->cellSelected(1, 3) && !$s->cellSelected(4, 1) && !$s->cellSelected(1, 0), 'cellSelected tells the block');

/** The cell holding $text. */
function dsCell(Headless $h, string $text): Node
{
    $walk = function (Node $n, ?Node $cell) use (&$walk, $text): ?Node {
        if ($n->role === \MyPHP\UI\Role::CELL) { $cell = $n; }
        if ($n->text === $text) { return $cell; }
        foreach ($n->kids as $k) { if (($f = $walk($k, $cell)) !== null) { return $f; } }
        return null;
    };
    return $walk($h->root, null) ?? throw new \RuntimeException("no cell $text");
}

// Left and Right move the cursor's column, Shift with them extends the columns, the keys moving the rows leave one column.
[$h, $s, $sel] = cellTable(8, 3, 0);
$h->clickText('r1c2');
$h->right();
wantBlock($s, $sel, 'Right from B', [1], new ColumnRange('C', 'C'), ['C']);
$h->right();
wantBlock($s, $sel, 'Right from the last column', [1], new ColumnRange('C', 'C'), ['C']);
$h->key(Key::LEFT, 'moveLeftAndModifySelection:', Key::SHIFT);
wantBlock($s, $sel, 'Shift+Left', [1], new ColumnRange('B', 'C'), ['B', 'C']);
$h->down(Key::SHIFT);
wantBlock($s, $sel, 'Shift+Down', [1, 2], new ColumnRange('B', 'C'), ['B', 'C']);
$h->down();
wantBlock($s, $sel, 'Down', [3], new ColumnRange('B', 'B'), ['B']);
$h->left(); $h->left();
wantBlock($s, $sel, 'Left twice, past the row headers', [3], new ColumnRange('A', 'A'), ['A']);
$h->key(Key::A, 'selectAll:', Key::COMMAND);
wantBlock($s, $sel, 'Cmd+A', [0, 1, 2, 3, 4, 5, 6, 7], new ColumnRange('A', 'A', true), ['A', 'B', 'C']);
$h->frame();
check(dsCell($h, 'r3c1')->drawOver !== null && dsCell($h, 'r3c2')->drawOver === null, 'the cursor\'s cell, in the row the keys move from, has a border while the table has the focus');
[$h, $s, $sel] = cellTable(8, 3, 0, true);
$h->clickText('r1c2');
$h->left();
wantBlock($s, $sel, 'Left from B right to left', [1], new ColumnRange('C', 'C'), ['C']);

// A click or a drag on the row headers chooses whole rows, a click on their header every row; that header does not sort.
[$h, $s, $sel] = cellTable(6, 3, 0);
$h->clickText('r2c0');
wantBlock($s, $sel, 'click the row header of row 2', [2], new ColumnRange('A', 'A', true), ['A', 'B', 'C']);
check(dsCell($h, 'r2c0')->selected, 'the row header of a whole row chosen shows chosen');
$h->clickOn($h->byText('r4c0'), Key::SHIFT);
wantBlock($s, $sel, 'Shift-click the row header of row 4', [2, 3, 4], new ColumnRange('A', 'A', true), ['A', 'B', 'C']);
$h->clickText('r1c2');
check(!dsCell($h, 'r1c0')->selected, 'with one cell of its row, the row header shows as it is');
[$x, $y] = dsAt($h, 'r0c0'); [, $y2] = dsAt($h, 'r1c0');
$h->press($x, $y); $h->drag($x, $y + 3); $h->drag($x, $y2); $h->release($x, $y2);
wantBlock($s, $sel, 'drag the row headers of rows 0 to 1', [0, 1], new ColumnRange('B', 'B', true), ['A', 'B', 'C']);
$h->clickText('r3c3');
$h->clickText('#');
wantBlock($s, $sel, 'click the header of the row headers', [0, 1, 2, 3, 4, 5], new ColumnRange('C', 'C', true), ['A', 'B', 'C']);
[$h, $s, $sel] = cellTable(3, 1, 0, false, $dsSort, true);
$h->clickText('#');
check($s->sort->column === '' && dsChosen($sel) === [0, 1, 2], 'the header of the row headers chooses all, sortable or not, and does not sort');

// A right-click on a cell outside the block chooses it; one inside keeps the block.
[$h, $s, $sel] = cellTable(8, 3, 0);
$h->clickText('r1c1');
$h->rightClick(...dsAt($h, 'r4c2'));
wantBlock($s, $sel, 'right-click r4c2', [4], new ColumnRange('B', 'B'), ['B']);
$h->closeMenu();
$h->clickText('r1c1'); $h->clickOn($h->byText('r3c2'), Key::SHIFT);
$h->rightClick(...dsAt($h, 'r2c1'));
wantBlock($s, $sel, 'right-click r2c1 in the block', [1, 2, 3], new ColumnRange('B', 'A'), ['A', 'B']);
$h->closeMenu();
$h->rightClick(...dsAt($h, 'r2c3'));
wantBlock($s, $sel, 'right-click r2c3, a chosen row out of the block\'s columns', [2], new ColumnRange('C', 'C'), ['C']);
$h->closeMenu();
$s->menuKeepsSelection = true;
$h->rightClick(...dsAt($h, 'r5c1'));
wantBlock($s, $sel, 'right-click r5c1, menuKeepsSelection', [2], new ColumnRange('C', 'C'), ['C']);
$h->closeMenu();

// Dragging cells past the table's right edge scrolls it sideways and goes on choosing columns.
[$h, $s, $sel] = cellTable(8, 10, 100);
[$x, $y] = dsAt($h, 'r1c1');
$h->press($x, $y); $h->drag($x + 5, $y); $h->drag(399, $y);
for ($k = 0; $k < 30; $k++) { $h->frame(1); }
check($s->cells->anchor === 'A' && count($s->selectedColumns()) >= 6 && $s->x > 0, 'held at the right edge: ' . json_encode([$s->cells, $s->selectedColumns(), $s->x]));
$h->release(399, $y);

// A click chooses the column it pressed, though the pointer moves on before the frame that takes it.
[$h, $s, $sel] = cellTable(8, 3, 0);
[$x, $y] = dsAt($h, 'r1c1'); [$x2] = dsAt($h, 'r1c3');
$h->input->mouse($x, $y); $h->input->push(['down', $x, $y, 0, 1, 0]); $h->input->push(['up', $x, $y, 0, 1, 0]); $h->input->mouse($x2, $y); $h->frame();
wantBlock($s, $sel, 'click r1c1, the pointer on r1c3 by the frame', [1], new ColumnRange('A', 'A'), ['A']);

// selectColumns chooses every row and the columns between; cellMenu gives the cells a menu.
[$h, $s, $sel] = cellTable(4, 3, 0);
$s->selectColumns('B', 'C');
wantBlock($s, $sel, 'selectColumns B to C', [0, 1, 2, 3], new ColumnRange('C', 'B'), ['B', 'C']);
$asked = null;
$s = new ListState();
$s->cellMenu = function (\MyPHP\UI\Menu $m, int $row, int $col) use (&$asked): void { $asked = [$row, $col]; $m->item("Cell $row $col"); };
$h = new Headless(fn(Context $c) => $c->table($s, [new TableColumn('Name'), new TableColumn('Kind')], 3, fn(int $row, int $col) => $c->text("r{$row}c{$col}"))->grow = 1, 400, 300);
$h->frame();
$cr = $h->rect(dsCell($h, 'r2c1'));
$h->rightClick($cr[0] + $cr[2] - 2, $cr[1] + 2); // in the cell's padding, past its text
same([[2, 1], ['Cell 2 1']], [$asked, $h->menuLabels()], 'cellMenu: the cell\'s menu over the whole cell');

// A cell sees its row's double click, which chooses the row and submits the list.
$s = new ListState(); $s->selected = -1;
$seen = [];
$h = new Headless(function (Context $c) use ($s, &$seen): void {
    $c->table($s, [new TableColumn('Name'), new TableColumn('Kind')], 3, function (int $row, int $col) use ($c, &$seen): void {
        $cell = $c->text("r{$row}c{$col}");
        if ($c->rowDoubleClicked($cell)) { $seen[] = [$row, $col]; }
    })->grow = 1;
}, 400, 300);
$h->frame();
[$x, $y] = dsAt($h, 'r1c1');
$h->click($x, $y); $h->click($x, $y, 0, 2);
same([[1, 1]], $seen, 'the cell double-clicked sees it, the others not');
check($s->selected === 1 && $s->submitted(), 'the row still takes the click and the double click');

// In a list shorter than its two edges, a drag held below its middle scrolls it down and one held above scrolls it up (mygo's TestDragSelectShortListScrollsTowardThePointer).
$s = new ListState(); $s->selected = -1; $s->selection = new Selection(); $s->dragSelect = true;
$short = null;
$h = new Headless(function (Context $c) use ($s, &$short): void {
    $c->column();
      $short = $c->list($s, 30, fn(int $i) => $c->text("Row $i"))->height(50);
    $c->end();
}, 300, 300);
$h->frame();
foreach ([[30.0, true], [20.0, false]] as [$held, $down]) {
    [$s->row, $s->offset] = [0, 0.0];
    $s->scrollBy(100.0);
    $h->frame();
    [$lx, $ly] = $h->rect($short);
    $x = $lx + 20; $from = $s->extent()[0];
    $h->press($x, $ly + 25); $h->drag($x, $ly + 29); $h->drag($x, $ly + $held);
    $h->frame();
    $moved = $s->extent()[0] - $from;
    check($down ? $moved > 0 : $moved < 0, "held $held down a list 50 tall: scrolled by $moved");
    $h->release($x, $ly + $held);
}
