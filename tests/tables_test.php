<?php
// Tables (mygo's table_test.go TestTableSort, TestTableResize,
// TestTableReorder, TestTableReorderFar, TestTableScrollsSideways,
// TestTableSortBy, TestTableSortFollowsSortBy, TestTableHeaderMenu,
// TestTableHeaderMenuPastTheColumns, and
// direction_controls_test.go TestRTLTable): sorting by one column or
// several, resizing and fitting columns, moving them, scrolling sideways,
// right to left.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, ListState, Node, Role, SortOrder, TableColumn};

/** A table of files, name, kind and size, as mygo's tableView. @param list<TableColumn> $cols @param list<string> $names */
function tableView(ListState $s, array $cols, array $names, int $w = 500, int $h = 300): Headless
{
    return new Headless(function (Context $c) use ($s, $cols, $names): void {
        $c->column(10, 0, 1)->align(Node::STRETCH);
          $c->table($s, $cols, count($names), function (int $row, int $col) use ($c, $names): void {
              match ($col) {
                  0 => $c->text($names[$row])->singleLine(),
                  1 => $c->text('Document'),
                  2 => $c->text(($row + 1) . ' KB'),
                  default => $c->text('-'),
              };
          })->grow = 1;
        $c->end();
    }, $w, $h);
}

/** The node heading the column titled $title. */
function header(Headless $h, string $title): Node
{
    $walk = function (Node $n) use (&$walk, $title): ?Node {
        if ($n->role === Role::COLUMN_HEADER && $n->label === $title) { return $n; }
        foreach ($n->kids as $k) { if (($f = $walk($k)) !== null) { return $f; } }
        return null;
    };
    return $walk($h->root ?? $h->frame()) ?? throw new \RuntimeException("no header $title");
}

/** The box of the header of the column titled $title, [x, y, w, h]. @return array{0: float, 1: float, 2: float, 3: float} */
function headerRect(Headless $h, string $title): array
{
    return $h->rect(header($h, $title));
}

/** A press at ($x, $y) moved through $xs and released at the last of them. @param list<float> $xs */
function dragAlong(Headless $h, float $x, float $y, array $xs): void
{
    $h->press($x, $y);
    foreach ($xs as $to) { $h->drag($to, $y); }
    $h->release(end($xs), $y);
}

// A click sorts by a sortable column, a second reverses it; one that does not sort is no click.
$sel = -1;
$s = new ListState();
$s->sort = new SortOrder('Size');
$s->selected = &$sel;
$cols = [new TableColumn('Name', sortable: true), new TableColumn('Kind', 100), new TableColumn('Size', 80, align: Node::END, sortable: true)];
$h = tableView($s, $cols, ['a.txt', 'b.txt']);
$h->frame();
same(1, header($h, 'Size')->sort, 'the column sorted by, ascending');
$h->clickOn(header($h, 'Name'));
check($s->sort == new SortOrder('Name') && $s->sortChanged(), 'a click on Name');
$h->clickOn(header($h, 'Name'));
check($s->sort == new SortOrder('Name', true), 'a second click on Name');
same([2, 0], [header($h, 'Name')->sort, header($h, 'Size')->sort], 'Name descending, Size no longer sorted by');
$h->clickOn(header($h, 'Kind'));
check($s->sort == new SortOrder('Name', true) && $sel === -1, 'a click on Kind neither sorts nor chooses');

// Dragging the end edge of a header resizes its column, a double click there fits it to its cells; a fixed column has none.
$s = new ListState();
$cols = [new TableColumn('Name'), new TableColumn('Kind', 100, 60), new TableColumn('Size', 80, fixed: true)];
$h = tableView($s, $cols, ['a.txt', 'a much longer name.txt']);
$h->frame();
$kind = headerRect($h, 'Kind');
$y = $kind[1] + $kind[3] / 2;
$edge = $kind[0] + $kind[2] - 2;
dragAlong($h, $edge, $y, [$edge + 30]);
$kind = headerRect($h, 'Kind');
same([130.0, 130.0, $edge + 30], [$s->columns->widths['Kind'] ?? null, $kind[2], $kind[0] + $kind[2] - 2], 'dragged 30 wider, the edge under the pointer');
same(300.0, $s->columns->widths['Name'] ?? null, 'the column before it, sharing the room left, kept its width');
$edge += 30;
dragAlong($h, $edge, $y, [$edge - 130]);
same(60.0, headerRect($h, 'Kind')[2], 'no narrower than its least');
$kind = headerRect($h, 'Kind');
$edge = $kind[0] + $kind[2] - 2;
$h->click($edge, $y);
$h->click($edge, $y, clicks: 2);
$doc = $h->rect($h->byText('Document'));
$w = headerRect($h, 'Kind')[2];
check($w >= $doc[2] + 20 && $w <= $doc[2] + 21 && $w >= 70, "fitted to $w for a text of {$doc[2]}");
$size = headerRect($h, 'Size');
dragAlong($h, $size[0] + $size[2] - 1, $y, [$size[0] + $size[2] + 40]);
same(80.0, headerRect($h, 'Size')[2], 'a fixed column is not resized');

// Dragging a header past the middle of the next moves its column, and is no click; a fixed column neither moves nor lets others past it.
$s = new ListState();
$s->sort = null;
$cols = [new TableColumn('Name', fixed: true), new TableColumn('Kind', 100, sortable: true), new TableColumn('Size', 80)];
$h = tableView($s, $cols, ['a.txt']);
$h->frame();
$kind = headerRect($h, 'Kind');
$y = $kind[1] + $kind[3] / 2;
$x = $kind[0] + 20;
dragAlong($h, $x, $y, [$x + 5, $x + 20, $x + 50, $x + 60]);
same(['Name', 'Size', 'Kind'], $s->columns->order, 'Kind moved past Size');
same(null, $s->sort, 'dragging the header did not sort');
$kind = headerRect($h, 'Kind'); $size = headerRect($h, 'Size');
$doc = $h->rect($h->byText('Document'));
check($size[0] < $kind[0] && $doc[0] >= $kind[0], "Size at {$size[0]}, Kind at {$kind[0]}, its cell at {$doc[0]}");
$x = $size[0] + 10;
dragAlong($h, $x, $y, [$x - 5, $x - 40, $x - 100, $x - 200]);
same('Name', $s->columns->order[0], 'nothing moved past a fixed column');

// A column dragged past several others stays the one dragged.
$s = new ListState();
$cols = [new TableColumn('Name'), new TableColumn('Kind', 80), new TableColumn('Size', 80), new TableColumn('Date', 80)];
$h = tableView($s, $cols, ['a.txt'], 600);
$h->frame();
$kind = headerRect($h, 'Kind');
$y = $kind[1] + $kind[3] / 2;
$x = $kind[0] + 20;
dragAlong($h, $x, $y, array_map(fn(int $d) => $x + $d, range(5, 180, 15)));
same(['Name', 'Size', 'Date', 'Kind'], $s->columns->order, 'Kind dragged past Size and Date');

// Columns wider than the table scroll sideways, the header with them; down still moves the rows under the header.
$s = new ListState();
$cols = [new TableColumn('Name', 200), new TableColumn('Kind', 200), new TableColumn('Size', 200)];
$h = tableView($s, $cols, array_fill(0, 50, 'file'), 400);
$h->frame();
check(headerRect($h, 'Size')[0] >= 390, 'the third column of 200 starts past a table 380 wide');
$h->input->mouse(200, 150);
$h->input->push(['scroll', 200, 150, -300, 0]);
$h->frame();
$size = headerRect($h, 'Size');
$cell = $h->rect($h->byText('1 KB'));
check($size[0] <= 300 && $cell[0] == $size[0] + 10, "scrolled sideways: the header at {$size[0]}, the text of its cell at {$cell[0]}");
$h->input->push(['scroll', 200, 150, 0, -300]);
$h->frame();
check(headerRect($h, 'Size')[1] == $size[1] && $s->first > 0, 'scrolled down: the header stays, the rows moved');

// With sortBy, a Cmd-click adds a column after those sorted by, a second makes it descending, a third takes it out; a click sorts by one again.
$s = new ListState();
$s->sortBy = [];
$cols = [new TableColumn('Name', sortable: true), new TableColumn('Kind', 100, sortable: true), new TableColumn('Size', 80, sortable: true)];
$h = tableView($s, $cols, ['a.txt', 'b.txt']);
$h->frame();
$by = function (string $step, SortOrder ...$want) use ($s): void { check($s->sortBy == $want, $step . ': ' . json_encode(array_map(fn($o) => [$o->column, $o->descending], $s->sortBy))); };
$click = function (string $title, int $mods = 0) use ($h): void { $h->clickOn(header($h, $title), $mods); };
$click('Name');
$by('a click on Name', new SortOrder('Name'));
$click('Kind', Key::COMMAND);
$by('a Cmd-click on Kind', new SortOrder('Name'), new SortOrder('Kind'));
$kept = $s->sortBy;
$click('Size', Key::COMMAND);
$by('a Cmd-click on Size', new SortOrder('Name'), new SortOrder('Kind'), new SortOrder('Size'));
same(2, count($kept), 'a click left the list the app kept as it was');
same([1, 'Size'], [header($h, 'Size')->sort, header($h, 'Size')->label], 'Size, sorted by third, named by its title alone');
check($h->byText('3') !== null, 'the place of Size among the columns sorted by');
$click('Kind', Key::COMMAND);
$by('a second Cmd-click on Kind', new SortOrder('Name'), new SortOrder('Kind', true), new SortOrder('Size'));
$click('Kind', Key::COMMAND);
$by('a third Cmd-click on Kind', new SortOrder('Name'), new SortOrder('Size'));
$click('Name', Key::COMMAND);
$by('a Cmd-click on Name', new SortOrder('Name', true), new SortOrder('Size'));
$click('Name', Key::COMMAND);
$by('a second Cmd-click on Name', new SortOrder('Size'));
$click('Kind', Key::COMMAND);
$click('Kind');
$by('a click on Kind', new SortOrder('Kind'));
$click('Name', Key::COMMAND);
$click('Kind');
$by('a click on Kind, first', new SortOrder('Kind', true));

// With sortBy, sort follows its first column; a Shift-click adds a column as a Cmd-click does.
$s = new ListState();
$s->sortBy = [];
$cols = [new TableColumn('Name', sortable: true), new TableColumn('Kind', 100, sortable: true)];
$h = tableView($s, $cols, ['a.txt', 'b.txt']);
$h->frame();
$click = function (string $title, int $mods = 0) use ($h): void { $h->clickOn(header($h, $title), $mods); };
$follows = function (string $step, ?SortOrder $first, SortOrder ...$want) use ($s): void { check($s->sortBy == $want && $s->sort == $first, $step); };
$click('Name');
$follows('a click on Name', new SortOrder('Name'), new SortOrder('Name'));
$click('Kind', Key::SHIFT);
$follows('a Shift-click on Kind', new SortOrder('Name'), new SortOrder('Name'), new SortOrder('Kind'));
$click('Name', Key::COMMAND);
$click('Name', Key::SHIFT);
$follows('Name taken out', new SortOrder('Kind'), new SortOrder('Kind'));
$click('Kind', Key::SHIFT);
$click('Kind', Key::SHIFT);
$follows('sorted by none', null);
$s->sortBy = [new SortOrder('Kind', true)];
$h->frame();
$follows('sortBy set by the app', new SortOrder('Kind', true), new SortOrder('Kind', true));

// The header menu is told the column in the columns given, wherever the layout shows it.
$built = null;
$s = new ListState();
$s->columns->order = ['Size', 'Name', 'Kind'];
$s->headerMenu = function (\MyPHP\UI\Menu $m, int $col) use (&$built): void { $built = $col; $m->item('Hide Column'); };
$cols = [new TableColumn('Name'), new TableColumn('Kind', 100), new TableColumn('Size', 80)];
$h = tableView($s, $cols, ['a.txt']);
$h->frame();
$kind = headerRect($h, 'Kind'); $size = headerRect($h, 'Size');
check($size[0] < $kind[0], 'Size shows first, Kind last');
[$x, $y] = $h->middle(header($h, 'Kind'));
$h->rightClick($x, $y);
same([['Hide Column'], 1], [$h->menuLabels(), $built], 'Kind, shown third, is the second of the columns');
$h->closeMenu();
$s->headerMenu = function (\MyPHP\UI\Menu $m, int $col) use (&$built): void { $built = $col; $m->item('Reset Columns'); };
$s->columns->order = [];
$cols = [new TableColumn('Name', 120), new TableColumn('Kind', 100)];
$h = tableView($s, $cols, ['a.txt']);
$h->frame();
$h->rightClick(450, $y);
same([['Reset Columns'], -1], [$h->menuLabels(), $built], 'past the columns, the menu for column -1');

// Right to left, the columns go from the right, and the edge that resizes a column is its left.
$s = new ListState();
$cols = [new TableColumn('Name', 100), new TableColumn('Size', 80)];
$h = new Headless(function (Context $c) use ($s, $cols): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->table($s, $cols, 3, function (int $row, int $col) use ($c): void { $c->text(['file', '1 KB'][$col]); })->grow = 1;
    $c->end();
}, 400, 200);
$h->rt->rightToLeft = true;
$h->frame();
$name = headerRect($h, 'Name'); $size = headerRect($h, 'Size');
check($name[0] > $size[0] && $name[0] >= 300, "right to left, Name at {$name[0]}, Size at {$size[0]}");
dragAlong($h, 301, 10, [281]);
same(120.0, $s->columns->width($cols[0]), "dragging Name's edge 20 to the left widens it");
