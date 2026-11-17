# Overlays, sheets, alerts and popovers

Declarative views present overlays above the window's content with `->sheet()`, `->fullScreenCover()`, `->popover()`, `->alert()`, `->confirmationDialog()`, `->contextMenu()`, `->help()`, and `->toast()`.

```php
$app = new class {
    public bool $showPopover = false;
    public bool $showSheet = false;
    public string $name = 'Untitled';
};
$view = fn() => HStack(spacing: 12)(
    Button('More ▾', action: fn() => $app->showPopover = !$app->showPopover)
        ->popover(isPresented: $app->showPopover, content: fn() => VStack(spacing: 8)(
            Button('Rename…', action: function () use ($app) {
                $app->showPopover = false;
                $app->showSheet = true;
            }),
        )->padding(12)),
)->padding()->sheet(isPresented: $app->showSheet, content: fn() => VStack(spacing: 12)(
    Text('Rename')->font(Font::headline),
    TextField('Name', text: $app->name)->defaultFocus(),
    Button('Done', action: fn() => $app->showSheet = false)->buttonStyle(ButtonStyle::borderedProminent),
)->padding(20)->frame(width: 280));
```

## Keyboard and focus behavior

- **Modal focus trap**: A `sheet`, `fullScreenCover`, or `alert` keeps keyboard focus inside itself while open, starting on its first focusable control (or the one with `->defaultFocus()`). Everything behind it is inert to pointer, keyboard, and screen readers.
- **Escape stack**: Pressing `Escape` (or the system Back button on Android) dismisses the topmost open menu, popover, sheet, or alert in lifo order, and restores keyboard focus to the control that opened it. Unless `->interactiveDismissDisabled(true)` is set on a sheet's content, clicking the dimmed backdrop or swiping down on a phone also dismisses it.
- **Layered views**: Use `->overlay($view, alignment: Alignment::topTrailing)` or `ZStack` to place badges and floating bars directly over a view.
