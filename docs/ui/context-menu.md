# Context menu

`->contextMenu()` gives a view a menu of `Button`s, `Toggle`s, `Picker`s,
`Divider`s, `Section`s and `Menu`s, as SwiftUI's: a right click (or a
Control-click) opens it on the desktop, by the pointer; a long press on a
phone, the view lifted over the blurred window and the menu by it, as
UIKit's.

```php
$app = new class { public array $notes = ['Groceries', 'Ideas']; public array $copied = []; };
$view = fn() => ListView($app->notes)(fn(string $n, int $i) => Text(verbatim: $n)->contextMenu(
    Button('Copy', action: fn() => $app->copied[] = $n, systemImage: 'doc.on.doc'),
    Divider(),
    Button('Delete', role: ButtonRole::destructive, action: fn() => array_splice($app->notes, $i, 1), systemImage: 'trash'),
));
```

A `ButtonRole::destructive` item shows in red, its `systemImage:` at its
start on a phone. On a phone the finger held on slides over the items and
lifted on one chooses it; the menu lines up with the side of the window
the view is on. A `ListView` with a `selection:` takes
`->contextMenu(menu: fn(array $ids) => [...], primaryAction: fn(array $ids)
=> …)` for its chosen rows, as SwiftUI's `contextMenu(forSelectionType:)`
([list](list.md)).

## Accessibility

Assistive technology opens the menu with the view's "show menu" action, and
sees the menu's items by their titles.
