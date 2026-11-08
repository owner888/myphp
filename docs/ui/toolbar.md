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

On a `NavigationSplitView`'s detail the items go at the end of the
window's toolbar, as SwiftUI's on the Mac. On a view outside a stack and a
split view they are a bar of their own over it, a divider under the bar.

A button with a `systemImage` shows its icon alone there, its title on
hover and to assistive technology, as SwiftUI's toolbar; its title alone
where the system has no such symbol. `->labelStyle(LabelStyle::titleAndIcon)`
shows both, `LabelStyle::titleOnly` the title.

Buttons take no face of their own until hovered; the items are one stop of
Tab, among which the arrows move the focus. Items that do not fit go into a
menu at the bar's end.

## Accessibility

Assistive technology sees a toolbar of its buttons.
