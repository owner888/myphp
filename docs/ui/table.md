# Table

`$c->table()` puts a [list](list.md)'s rows in columns, under a header of
`TableColumn`s, each with a title, a width (or 0 to share the room the
others leave) and an alignment; its last argument builds the content of a
row's column, usually a text. It builds only the rows in view, as a list
does, and rows are as high as their tallest cell, and at least
`$t->space(8)`, so text that wraps makes its row taller. Cells pad
`$t->space(1.5)` by `$t->space(2.5)`.

```php
$files = [['name' => 'report.pdf', 'size' => '2 MB'], ['name' => 'notes.txt', 'size' => '4 KB']];
$app = new class {
    public ListState $files;
    public array $opened = [];
    public function __construct() { $this->files = new ListState(); $this->files->selected = -1; }
    public function open(array $f): void { $this->opened[] = $f; }
};
$cols = [new TableColumn('Name'), new TableColumn('Size', width: 90, align: Node::END)];
$view = function (Context $c) use ($app, $cols, $files) {
    $c->table($app->files, $cols, count($files), function (int $row, int $col) use ($c, $files) {
        switch ($col) {
            case 0: $c->text($files[$row]['name'])->singleLine(); break;
            case 1: $c->text($files[$row]['size']); break;
        }
    })->grow(1);
    if ($app->files->submitted()) {
        $app->open($files[$app->files->selected]);
    }
};
```

## Choosing rows

The table takes the same `ListState` as a list, and takes the keyboard
focus for it: with `selected`, a click or the keys choose a row, with
`selection` several, and the state's `submitted()` reports a double click
or Enter; `key`, `label`, `reorder` and the rest work as for a list. A row
`header` names spans every column, which the cell builder builds as column
0, and stays at the top while its section's rows scroll under it.

Chosen and hovered rows are square from edge to edge, as in Finder's list
view; `roundRows` rounds their corners at the theme's `radius`, as a list's
rows are, for an app whose other lists are rounded.

## Choosing cells

With `selection`, `cellSelect` makes the table choose a block of cells, as
a spreadsheet does: the rows chosen, times the columns from
`cells->anchor` to `cells->cursor` in the order shown (`cells->all` for
every column). It is one block: separate ranges of cells, as Excel's
Cmd-drag adds, are not chosen.

- **Clicks.** A click on a cell chooses its row and its column; a
  Shift-click extends the rows and the columns to it; a Cmd-click adds its
  row or takes it out and keeps the columns. A press on a cell outside the
  block chooses as the pointer goes down; a press inside the block waits
  for the release.
- **Dragging** over cells chooses those it goes over, and scrolls the table
  near its edges, down and sideways.
- **Keys.** Left and Right move `cells->cursor`, Shift with them extends
  the columns; the keys that move the rows leave one column unless with
  Shift; Cmd+A chooses every row and every column. Right to left, Left goes
  to the column on the left of the screen. In an `outlineTable()`, Left and
  Right still open and close the rows, and the columns are chosen with the
  pointer.
- **Right-click.** On a cell outside the block, it chooses that cell
  before the context menu opens; inside, the block stays.
  `menuKeepsSelection` leaves the block as it is.
- **Row headers.** A `rowHeader` column, as a spreadsheet's row numbers,
  chooses whole rows: a click or a drag on its cells chooses rows with
  every column, and a click on its header every row. It does not sort.

The cells of the block show as a chosen row does, in the accent color with
the accent's text color, as do the row headers of whole rows chosen, and
the cell at `cells->cursor` in the row the keys move from has a border
while the table has the focus. A cell holding the focus, as an editor in
it, shows as it is. Chosen rows are not tinted whole. `selectedColumns()`
returns the block's column IDs in the order shown, and
`selectColumns($from, $to)` chooses every row and a range of columns, for
the app's own commands. `cellSelected($row, $col)` tells a cell builder
whether its cell is chosen, for text of its own color to show on the
accent. A cell that reads its row's double click with
`$c->rowDoubleClicked()` leaves the click to the row, so the block follows
it.

```php
$rows = [['1', 'Sample', '36'], ['2', '', '41']];
$app = new class {
    public ListState $grid;
    public Selection $chosen;
    public function __construct() { $this->grid = new ListState(); $this->chosen = new Selection(); }
};
$app->grid->selection = $app->chosen;
$app->grid->selected = -1;
$app->grid->cellSelect = true;
$cols = [new TableColumn('#', width: 48, fixed: true, rowHeader: true), new TableColumn('Name'), new TableColumn('Age', width: 90)];
$view = function (Context $c) use ($app, $cols, $rows) {
    $t = $c->theme;
    $c->table($app->grid, $cols, count($rows), function (int $row, int $col) use ($c, $t, $app, $rows) {
        [$text, $color] = [$rows[$row][$col], $t->text];
        if ($text === '') {
            [$text, $color] = ['empty', $t->textMuted];
        }
        if ($app->grid->cellSelected($row, $col)) {
            $color = $t->accentText; // muted does not show on the accent
        }
        $c->text($text)->textColor($color)->singleLine();
    })->grow(1);
};
```

## Arranging columns

The user arranges the columns, as in Finder:

- **Resizing.** Dragging the right edge of a column's header resizes the
  column, between its `minWidth` and `maxWidth`, and a double click there
  fits it to its cells. The columns before it that share the room left
  keep their widths, for the edge to follow the pointer.
- **Moving.** Dragging a header moves its column among the others.
- **Scrolling sideways.** Columns wider than the table scroll sideways,
  the header with them.
- **Fixed columns.** A `fixed` column stays where it is, as wide as it is.

The state's `columns`, a `TableLayout`, keeps the order and the widths, by
the columns' `id`s (their titles by default): set it from saved settings
to restore them, and save it as it changes.

## Sorting

A click on the header of a `sortable` column sorts the rows by it,
ascending, and a second click reverses the order: the header shows an
arrow, assistive technology reads the order, the state's `sort` holds a
`SortOrder` (its `column` and whether `descending`), and `sortChanged()`
reports the click. The table shows the rows in the order the cell builder
gets them, so sort them as it says, keyed by `key` for the choice to follow
its rows:

```php
$files = [['id' => 1, 'name' => 'b.txt', 'size' => 20], ['id' => 2, 'name' => 'a.txt', 'size' => 10]];
$state = new ListState();
$cols = [new TableColumn('Name', sortable: true), new TableColumn('Size', width: 90, align: Node::END, sortable: true)];
$view = function (Context $c) use ($state, $cols, $files) {
    $sorted = $files;
    if (($s = $state->sort) !== null) {
        $by = $s->column === 'Size' ? 'size' : 'name';
        usort($sorted, fn($a, $b) => ($s->descending ? -1 : 1) * ($a[$by] <=> $b[$by]));
    }
    $state->key = fn(int $i) => $sorted[$i]['id'];
    $c->table($state, $cols, count($sorted), function (int $row, int $col) use ($c, $sorted) {
        $c->text($col === 0 ? $sorted[$row]['name'] : (string)$sorted[$row]['size']);
    })->grow(1);
};
```

To sort by several columns, set the state's `sortBy` to an array. A click
still sorts by one column, and reverses it when it was first; a Cmd-click
or a Shift-click, as in web data grids, adds the column after those sorted
by, ascending; another makes it descending, and a third takes it out. The
headers show their direction and, when there are several, their place.
`sortBy` gets a new array as it changes. With `sort` read too, the table
keeps it the first of `sortBy`, null when it sorts by none, so code that
reads the column sorting first goes on reading `sort`.

## Header menu

The state's `headerMenu` gives the header a context menu, built as
[contextMenu()](context-menu.md)'s is. `$col` is the index of the column
clicked in the columns given to `table()`, whatever order the user moved
them into, or -1 for the part of the header past the columns, as AppKit's
`clickedColumn`. A menu built without items does not open:

```php
$app = new class {
    public ListState $files;
    public array $hidden = [];
    public function __construct() { $this->files = new ListState(); }
    public function hide(string $id): void { $this->hidden[] = $id; }
    public function showAll(): void { $this->hidden = []; }
};
$cols = [new TableColumn('Name'), new TableColumn('Size', width: 90)];
$app->files->headerMenu = function (Menu $m, int $col) use ($app, $cols) {
    if ($col >= 0 && $m->item('Hide Column')->chosen()) {
        $app->hide($cols[$col]->id());
    }
    if ($m->item('Show All Columns')->chosen()) {
        $app->showAll();
    }
};
$view = function (Context $c) use ($app, $cols) {
    $c->table($app->files, $cols, 1, fn(int $row, int $col) => $c->text("$row:$col"));
};
```

## Cell menu

The state's `cellMenu` gives the cells a context menu over the whole cell,
padding included, built as [contextMenu()](context-menu.md)'s is. `$row`
and `$col` are as the cell builder receives them. A menu built without
items does not open, and a context menu of what a cell holds takes the
right-click over it instead:

```php
$grid = new ListState();
$copied = [];
$grid->cellMenu = function (Menu $m, int $row, int $col) use (&$copied) {
    if ($m->item('Copy')->chosen()) {
        $copied[] = [$row, $col];
    }
};
$view = function (Context $c) use ($grid) {
    $c->table($grid, [new TableColumn('A'), new TableColumn('B')], 3, fn(int $row, int $col) => $c->text("$row:$col"));
};
```

## Renaming in place

An [editable text](editable-text.md) in a cell is renamed in place, as
Finder's file names: Enter and F2 edit the chosen row's text, as does a
click on it once the row is chosen. See
[editable text](editable-text.md#in-lists-and-tables).

## Accessibility

Assistive technology sees a table, named by its `label()`, of rows named by
the text inside them, each saying which of all the rows it is, with column
headers that say the order the rows are sorted in. With `cellSelect`, the
cells of the block say they are chosen.

For a tree of rows in columns, see [Outline](outline.md#in-columns).
