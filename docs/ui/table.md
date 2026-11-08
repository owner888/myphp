# Table

A `Table` puts rows in columns under a header, as SwiftUI's and Finder's
list view: each `TableColumn` a title and the view of each row's cell, or,
with `value:`, the row's value as text. Only the rows in view are built, as
a [list](list.md)'s; the user resizes and moves the columns by their
headers.

```php
$app = new class {
    public ?int $chosen = null;
    public array $files = [['id' => 1, 'name' => 'notes.txt', 'size' => 12], ['id' => 2, 'name' => 'photo.jpg', 'size' => 2048]];
};
$view = fn() => Table($app->files, id: 'id', selection: $app->chosen)(
    TableColumn('Name', value: 'name'),
    TableColumn('Size', fn(array $f) => Text(verbatim: "{$f['size']} KB")->foregroundStyle(Color::secondary))->width(min: 60, ideal: 80)->alignment(HorizontalAlignment::trailing),
);
```

## Choosing and sorting

`selection:`, bound, is the id of the row chosen, or an array of the ids
chosen (⌘-click, Shift-click), as a list's; `->contextMenu(menu:,
primaryAction:)` is the menu of the rows chosen and what a double click or
Enter on them does. `sortOrder:`, bound, is the header the user clicked, a
`SortOrder` of a column with a `value:`: the app sorts its rows by it, in
`->onChange(of: $app->sortOrder, action: …)`.

`->defaultScrollAnchor(UnitPoint::bottom)` keeps the newest rows of a log
in view as they come.

## Accessibility

Assistive technology sees a table of rows and columns, its headers naming
the cells, the chosen rows selected.
