# List

`$c->list()` shows rows of a collection of any length: it builds only the
rows in view, and a few beyond (1.5 views of estimated height), so a list
of millions of rows is as fast as one of ten. Rows take the height of their
content, which may differ from row to row: the list measures rows as they
show and estimates the others from them.

```php
$app = new class {
    public ListState $list;
    public array $files;
    public function __construct() { $this->list = new ListState(); $this->files = array_map(fn($i) => "file-$i.txt", range(1, 100_000)); }
};
$view = function (Context $c) use ($app) {
    $c->list($app->list, count($app->files), function (int $i) use ($c, $app) {
        $c->text($app->files[$i])->padding(6, 12);
    })->grow(1);
};
```

Every list takes a `ListState` of its own, made with the app's state: it
keeps the list's place, and says how it behaves. Give a list a size, or
grow it in its parent. A list without a size, or with only a `maxHeight()`,
is as high as its rows.

## Layout

`gap()` spaces the rows, `padding()` pads the content and scrolls with it,
and `justify(Node::END)` puts rows that do not fill the list at its bottom,
as a chat's first messages. The fourth argument builds what the list shows
while it has no rows, as a message that it is empty:

```php
$results = [];
$state = new ListState();
$view = function (Context $c) use (&$results, $state) {
    $c->list($state, count($results), function (int $i) use ($c, &$results) {
        $c->text($results[$i])->padding(6, 12);
    }, function () use ($c) {
        $c->text('No results')->textColor($c->theme->textMuted)->padding(12);
    })->grow(1);
};
```

## Rows wider than the list

`rowWidth($w)` makes the rows at least `$w` points wide, as the columns of
a table ask: where they are wider than the list, it scrolls sideways too,
and where they are not, they grow to its width. A header above the rows
goes with them through `scrollWith()`; clip it, so that what scrolls out of
it does not show. `$c->table()` does this for its columns.

```php
$app = new class {
    public ListState $files;
    public array $names = ['a.txt', 'b.txt'];
    public function __construct() { $this->files = new ListState(); }
};
$view = function (Context $c) use ($app) {
    $head = $c->row()->clip();
      $c->text('Name')->width(300);
      $c->text('Size')->width(300);
    $c->end();
    $list = $c->list($app->files, count($app->names), function (int $i) use ($c, $app) {
        $c->row();
          $c->text($app->names[$i])->width(300);
          $c->text('1 KB')->width(300);
        $c->end();
    })->grow(1)->rowWidth(600);
    $head->scrollWith($list);
};
```

## List state

A list keeps its place by a row rather than by an offset, so the rows in
view stay where they are while the rows around them are measured, grow,
come and go, and its offsets count millions of rows to a fraction of a
point. A `ListState` says how it behaves, and moves it:

- `key` returns an identity for the item of a row, such as its ID: the
  state of a row (focus, text being edited, …) follows its item, and the
  list keeps its place, and its choice, when rows are added or removed
  above them, as when older messages load.
- `keepOffset` keeps a list with a `key` at its offset as its rows move,
  rather than with the item at its top, for rows sorted again as they
  change, as a process monitor's: the rows' state and the choice still
  follow their items.
- `followEnd` starts the list at its end, and keeps the end in view as rows
  come or grow, until the user scrolls away from it, as a chat or a log
  does; scrolling back to the end follows it again. `followAnimation`
  makes it glide there, as a spring (`Animation::smooth()`) or a curve,
  rather than show the end at once: what is added comes up from below, as
  the chat apps scroll, and the user scrolling stops it.
- `header` names the rows heading sections: the header of the section at
  the top stays pinned there while its rows scroll under it, until the
  next header pushes it away. Give headers a background.
- `label` returns the text of a row: typing its first letters while the
  list has the focus chooses it, as in Finder, and assistive technology
  reads it as the row's name.

Give each list a `ListState` of its own, and set its properties before
building the list.

## Choosing rows

- `selected`, set to -1 (or a row), lets a click, or Up, Down, Home and End
  while the list has the keyboard focus, choose a row, which shows in the
  accent color, its text in the theme's `accentText`; the list's `$c->changed()` reports a new choice, and `$c->submitted()` a
  double click or Enter. Null, the default, chooses none.
- `selection` lets the user choose several rows, held in a `Selection` by
  the keys of their items (`key`), or by their indices without one: a
  click chooses one, Cmd-click adds a row or takes it out, and Shift-click
  or Shift with the keys chooses the rows from the one last chosen; Cmd+A
  chooses all. `selected`, set as well, is the row last chosen.

Files to choose several of, by their paths:

```php
$app = new class {
    public array $files = [['path' => '/a.txt', 'name' => 'a.txt'], ['path' => '/b.txt', 'name' => 'b.txt']];
    public Selection $chosen;
    public ListState $list;
    public function __construct() { $this->chosen = new Selection(); $this->list = new ListState(); }
    public function delete(string $path): void { $this->files = array_values(array_filter($this->files, fn($f) => $f['path'] !== $path)); }
};
$app->list->key = fn(int $i) => $app->files[$i]['path'];
$app->list->label = fn(int $i) => $app->files[$i]['name'];
$app->list->selection = $app->chosen;
$app->list->selected = -1;
$view = function (Context $c) use ($app) {
    $c->list($app->list, count($app->files), function (int $i) use ($c, $app) {
        $c->text($app->files[$i]['name'])->padding(6, 12);
    })->grow(1);
    if ($c->clicked($c->button(sprintf('Delete %d', $app->chosen->count())))) {
        foreach ($app->chosen->all() as $path) {
            $app->delete($path);
        }
        $app->chosen->clear();
    }
};
```

The keys of items gone from the list stay in the selection until you take
them out. `$c->clickModifiers()` gives the modifier keys of any click, for
widgets of your own that do the same.

In a list with `selection`, a right-click on a row outside the choice
chooses it before its context menu opens, as Finder and Qt do, so the menu
acts on the row it was opened on; on a chosen row it leaves the choice as
it is. `menuKeepsSelection` turns this off: the menu opens on the row
clicked, whose builder knows its row, and the choice stays. A list with
`selected` alone never chooses on a right-click.

<!-- no-test -->
```php
$app->files->selection = $app->chosen;
$app->files->menuKeepsSelection = true; // the menu acts on the row clicked alone
```

`dragSelect`, with `selection`, chooses rows as the pointer drags over
them from the row it pressed, as a Shift-click on the row under the
pointer would (with Cmd held as the press began, besides the rows chosen),
scrolling the list as the pointer nears or passes its top or bottom. A
press on a row not chosen chooses it as the pointer goes down, as a
spreadsheet does, rather than at the release; a press on a row chosen
waits for the release, so a drag of the choice keeps it.
`dragSelecting()` reports a drag choosing rows while the pointer holds it,
for work that follows the choice, as a sum of the rows chosen, to wait
until it ends. A list cannot both `dragSelect` and `reorder`.

To choose a row from your code, set `selected` (mygo's way: the list
shows it as it is next built, whatever rows it had before), or call
`choose($row)`, which also brings the row into view and, with a
`selection`, chooses it alone. `choose()` clamps `$row` to the rows the
list had when it was last built, so right after you give it new rows (the
results of a search that just came) it would drop a row past the old
count: set `selected` then, and `scrollTo()` it if it must show.

<!-- no-test -->
```php
$app->results = $found;          // new rows; the list was built with none
$app->list->selected = 0;        // chosen as the list is next built
```

`extendTo($row)` chooses the rows from the one Shift extends from to
`$row`, as a Shift-click on it does, for keys of your own, as one that
extends the choice to the last row:

<!-- no-test -->
```php
if ($app->extendToEnd) {
    $app->list->extendTo(count($app->files) - 1); // after the list is built
}
```

What a row holds can tell which part of it a double click landed on
without taking the click from the row, which goes on choosing:
`$c->rowDoubleClicked()` reports a double click on the row with the
pointer over the node. `$c->doubleClicked()` on the node would take the
click, and the row would not choose.

```php
$app = new class {
    public ListState $list;
    public array $files = ['a.txt', 'b.txt'];
    public function __construct() { $this->list = new ListState(); $this->list->selected = -1; }
    public function rename(int $i): void {}
};
$view = function (Context $c) use ($app) {
    $c->list($app->list, count($app->files), function (int $i) use ($c, $app) {
        $name = $c->text($app->files[$i])->padding(6, 12);
        if ($c->rowDoubleClicked($name)) {
            $app->rename($i);
        }
    });
};
```

## Scrolling

`scrollTo($row, align: Node::START)` shows a row at the `Node::START`,
`CENTER` or `END` of the list, gliding there unless `smooth: false`;
`scrollIntoView($row)` scrolls as little as shows it, and `scrollToEnd()`
scrolls to the end, rows not built yet included: the list scrolls as the
frame is laid out, to where the row is once measured. `visible()` returns
the first and last rows in view and `atEnd()` whether the end shows, to
load more as the list nears the end of what was loaded.

A chat:

```php
$app = new class {
    public ListState $chat;
    public array $messages = [];
    public bool $more = true;
    public function __construct() {
        $this->chat = new ListState();
        foreach (range(1, 50) as $i) { $this->messages[] = ['id' => $i, 'text' => "Message $i"]; }
    }
    public function loadOlder(): void { $this->more = false; }
};
$app->chat->key = fn(int $i) => $app->messages[$i]['id'];
$app->chat->followEnd = true;
$app->chat->followAnimation = Animation::smooth();
$view = function (Context $c) use ($app) {
    [$first] = $app->chat->visible();
    if ($first < 5 && $app->more) {
        $app->loadOlder(); // above the messages in view, which stay put
    }
    if (!$app->chat->atEnd() && $c->clicked($c->button('Jump to latest'))) {
        $app->chat->scrollToEnd();
    }
    $c->list($app->chat, count($app->messages), function (int $i) use ($c, $app) {
        $c->text($app->messages[$i]['text'])->padding(6, 12);
    })->grow(1)->justify(Node::END);
};
```

## Reordering

`reorder` lets the user drag rows to another place in the list: the rows
chosen, when the row dragged is one of them, else that row, which the list
passes in order, and the row they go before, the number of rows for the
end. Move them there: the list shows where they would go as they are
dragged, and scrolls near its edges.

```php
$app = new class {
    public ListState $list;
    public array $items = ['One', 'Two', 'Three'];
    public function __construct() { $this->list = new ListState(); }
};
$app->list->reorder = function (array $rows, int $to) use ($app) {
    $moved = array_map(fn($r) => $app->items[$r], $rows);
    $before = count(array_filter($rows, fn($r) => $r < $to));
    $rest = array_values(array_diff_key($app->items, array_flip($rows)));
    array_splice($rest, $to - $before, 0, $moved);
    $app->items = $rest;
};
$view = function (Context $c) use ($app) {
    $c->list($app->list, count($app->items), fn(int $i) => $c->text($app->items[$i])->padding(6, 12));
};
```

Rows that are buttons, which open what they show as they are clicked,
reorder too: a press on a row's button drags the row once the pointer
moves, and clicks the button if it does not.

See [drag and drop](drag-and-drop.md).

## How the chosen row looks

A chosen row is filled with the theme's `accent`, whether the list has the
focus or not, and what it builds inherits the theme's `accentText`, as the
text of a primary button does; a color the row's content gives itself
stays. Chosen cells of a table that chooses blocks of cells show the same
way.

## The focus

A `ListState` answers for its list's focus: `focused($c)` reports focus on
the list and `focusWithin($c)` includes its descendants, `focus($c)` gives
it the focus, and `shortcut($c, $key, $mods)` reports a key pressed while
it or a row has the focus. They work after building the list, or while
building its rows. A `Handle` bound to the list (`bind()`) keeps its
identity for a focus asked before the list is built, which waits while the
list is hidden:

```php
$app = new class {
    public ListState $list;
    public Handle $files;
    public array $names = ['a.txt', 'b.txt'];
    public int $opened = 0;
    public function __construct() { $this->list = new ListState(); $this->list->selected = 0; $this->files = new Handle(); }
    public function openCurrentFile(): void { $this->opened++; }
};
$view = function (Context $c) use ($app) {
    $app->files->onShortcut($c, Key::K, Key::COMMAND, fn() => $app->openCurrentFile());
    $c->key('files')->list($app->list, count($app->names), function (int $i) use ($c, $app) {
        $text = $c->text($app->names[$i])->padding(6, 12);
        if ($app->list->selected === $i && $app->list->focusWithin($c)) {
            $text->textColor($c->theme->accent);
        }
    })->grow(1)->bind($app->files);
};
```

Call `$app->files->focus()` on the main thread to request focus, or
`focus($c)` to select a window explicitly. A handle's shortcut action runs
after construction only if its enabled control is present. A table shares
this identity through its `ListState`, and an outline through its state's.

The row holding keyboard focus stays built when it scrolls out of view, so
editing continues and Tab can scroll the next focused row into view.

## Accessibility

Assistive technology sees a list, named by its `label()`, of list items
named by the text inside them or the state's `label`, each saying which of
all the rows it is, "5 of 10,000", although the list builds only those in
view. A list choosing its rows gives assistive technology the focus on the
row chosen: Up and Down are read as they move the choice, and focusing a
row chooses it. A screen reader moving out of view asks the list to scroll
there, which builds the rows it reaches.

For rows in columns, see [Table](table.md); for a tree, [Outline](outline.md).
