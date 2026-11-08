# Popover

`->popover()` shows a small panel next to a view while a `bool` (bound by
reference) is true, as SwiftUI's: on a Mac outside the window, as an
NSPopover, at its content's ideal size; Escape or a click outside closes
it. `arrowEdge:` chooses the side.

```php
$app = new class { public bool $info = true; };
$view = fn() => Button('Info', action: fn() => $app->info = true, systemImage: 'info.circle')->padding()
    ->popover(isPresented: $app->info, content: fn() => Text('Shown next to the button.')->padding()->frame(width: 220));
```

Give the content a `frame(width:)` to wrap long text. In a view of no
window (a test's), it shows inside it.

## Accessibility

Assistive technology sees a dialog next to its view.
