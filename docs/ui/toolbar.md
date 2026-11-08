# Toolbar

`->toolbar()` on a page puts buttons in its bar, as SwiftUI's: on the
desktop the window's toolbar, by its title; on a phone the navigation bar's
trailing capsule of glass.

```php
$app = new class { public array $notes = ['Groceries']; public bool $bold = false; };
$view = fn() => NavigationStack()(
    ListView($app->notes)(fn(string $n) => Text(verbatim: $n))
        ->navigationTitle('Notes')
        ->toolbar(
            Toggle('Bold', isOn: $app->bold)->toggleStyle(ToggleStyle::button),
            Button('New', action: fn() => $app->notes[] = 'Untitled', systemImage: 'square.and.pencil')->keyboardShortcut('n'),
        ),
);
```

Buttons take no face of their own until hovered; the items are one stop of
Tab, among which the arrows move the focus. Items that do not fit go into a
menu at the bar's end.

## Accessibility

Assistive technology sees a toolbar of its buttons.
