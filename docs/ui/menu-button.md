# Menu

A `Menu` is a button that opens its content as a menu, as SwiftUI's:
`Button`s, `Toggle`s (checked items), `Picker`s (their choices inline, the
chosen one checked), `Divider`s, `Section`s (a header over their items) and
`Menu`s (submenus).

```php
$app = new class { public string $sort = 'name'; public bool $folders = true; public int $exports = 0; };
$view = fn() => Menu('Options')(
    Picker('Sort by', selection: $app->sort)(Text('Name')->tag('name'), Text('Date')->tag('date')),
    Toggle('Folders first', isOn: $app->folders),
    Divider(),
    Button('Export…', action: fn() => $app->exports++),
)->padding();
```

A click, Enter, Space or Down opens it; the arrows move among the items,
Enter chooses, Escape closes. On a Mac the menu is the system's.

## Accessibility

Assistive technology sees a menu button named by its title, and the menu's
items by theirs.
