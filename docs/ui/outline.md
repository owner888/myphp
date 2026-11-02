# Outline

`$c->outline()` shows a tree of items, as AppKit's outline view and
Finder's list view: items at the top, and below each item open its
children, indented, with an arrow that opens and closes it. The app gives
the items at the top and a function returning an item's children, null for
one without any; the outline builds only the rows in view, as a
[list](list.md) does, so trees of any size are as fast as small ones.
Items are strings or ints.

```php
$app = new class {
    /** @var array<string, list<string>> the paths in each directory */
    public array $children = ['/' => ['/src', '/README.md'], '/src' => ['/src/App.php']];
    public OutlineState $files;
    public function __construct() { $this->files = new OutlineState(); $this->files->list->selected = -1; }
};
$view = function (Context $c) use ($app) {
    $c->outline($app->files, ['/'], function (string $dir) use ($app): ?array {
        return $app->children[$dir] ?? null; // null for a file
    }, function (string $path) use ($c) {
        $c->text(basename($path) ?: '/');
    })->grow(1);
    $row = $app->files->list->selected;
    $chosen = $row >= 0 ? $app->files->item($row) : null;
    // …
};
```

## Outline state

Its state, an `OutlineState`, holds the rows' `ListState`, `list`, which
chooses rows as a list's does (`selected`, `selection`), and the items
`open`, a `Selection` of the items showing their children. The rows' keys
are the items, so their choice and state follow them as items open and
close above them. `rows()`, `item($row)` and `depth($row)` read the rows
shown. `indent` is the width each level adds before a row's arrow, the
theme's `space(4)` when zero; it must not be negative.

## Opening and closing

- A click on an item's arrow opens or closes it; Option-click opens or
  closes all inside it too.
- While the outline has the focus, Right opens the item chosen or goes to
  its first child, and Left closes it or goes to its parent; with Option
  they open and close all inside.
- Closing an item whose child was chosen chooses the item.

## In columns

`$c->outlineTable()` is the same in a [table](table.md)'s columns, the
first of which shows the arrows, indented as the items are deep: the
columns are a table's, which the user sorts, resizes and moves.

```php
$children = ['/' => ['/src', '/README.md'], '/src' => ['/src/App.php']];
$files = new OutlineState();
$cols = [new TableColumn('Name'), new TableColumn('Size', width: 80, align: Node::END)];
$view = function (Context $c) use ($files, $cols, $children) {
    $c->outlineTable($files, $cols, ['/'], fn(string $p) => $children[$p] ?? null, function (string $path, int $col) use ($c, $children) {
        switch ($col) {
            case 0: $c->text(basename($path) ?: '/')->singleLine(); break;
            case 1: $c->text(isset($children[$path]) ? '—' : '1 KB'); break;
        }
    });
};
```

## Accessibility

Assistive technology sees a tree, or a table with rows that open and
close, whose rows say their level, whether they open, and whether they are
open, and which of all the rows they are; it opens and closes them as the
arrows do.
