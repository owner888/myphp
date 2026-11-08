# List

A `ListView` (SwiftUI's `List`; `list` is a PHP keyword) shows rows one
under the other, scrolled, filling the room it is given. Of data, only the
rows in view are built, so it holds a hundred thousand, each keeping its
identity by its id:

```php
$app = new class {
    public array $files;
    public function __construct() { $this->files = array_map(fn($i) => ['id' => $i, 'name' => "file-$i.txt", 'size' => $i * 3], range(1, 100_000)); }
};
$view = fn() => ListView($app->files, id: 'id')(fn(array $f) => HStack()(
    Image(systemName: 'doc'),
    Text(verbatim: $f['name']),
    Spacer(),
    Text(verbatim: "{$f['size']} KB")->foregroundStyle(Color::secondary),
));
```

The row closure gets the item and its index. `ListView(3)(fn(int $i) => …)`
is rows 0 to 2, as SwiftUI's `List(0..<3)`. Of views, `ListView()(Section('A')
( … ), …)` is an inset grouped list, as iOS's Settings, and
`NavigationLink`s in it are rows with a chevron.

## Choosing rows

`selection:`, bound, is the id of the row chosen, or, an array, the ids of
the rows chosen (⌘-click, Shift-click, ⌘A), as SwiftUI's `List(selection:)`
of an optional id or a set. A click or the arrows choose; the chosen row
fills with the accent.

```php
$app = new class { public ?int $chosen = 2; public array $notes = [['id' => 1, 'title' => 'Groceries'], ['id' => 2, 'title' => 'Ideas']]; };
$view = fn() => ListView($app->notes, id: 'id', selection: $app->chosen)(fn(array $n) => Text(verbatim: $n['title']))
    ->contextMenu(menu: fn(array $ids) => [Button('Delete', role: ButtonRole::destructive, action: fn() => null)], primaryAction: fn(array $ids) => null);
```

`->contextMenu(menu:, primaryAction:)` is the menu of the rows chosen and
what a double click or Enter on them does, as SwiftUI's
`contextMenu(forSelectionType:)`.

## Styles and rows

`->listStyle(ListStyle::plain)` is a list of rows with separators, a
phone's plain table; `automatic` is the platform's (an inset grouped list
of sections on a phone). `->alternatingRowBackgrounds()` stripes the rows,
as a Mac's. A row of one button, or of a view with a context menu or swipe
actions, is that control across the whole row.

## Reordering

`->onMove(fn(array $from, int $to) => …)` lets the user drag rows to
reorder them, the app moving its items, as SwiftUI's.

## On a phone

`->swipeActions(Button(…), …)` on a row shows buttons as it is swiped
toward its start, a full swipe running the first; `->refreshable(fn() =>
…)` on the list pulls down to refresh; `->searchable()` puts a search
field over it ([mobile](../mobile.md)).

## Accessibility

Assistive technology sees a list of its rows, the chosen ones selected,
named by their text.
