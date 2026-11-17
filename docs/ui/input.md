# Input, gestures and focus

Declarative views handle pointer taps, hovers, drags, magnification, rotation, keyboard shortcuts, and focus through modifiers, as SwiftUI's:

```php
$app = new class {
    public int $taps = 0;
    public bool $hovered = false;
};
$view = fn() => Text($app->hovered ? 'Click me!' : "Tapped {$app->taps}")
    ->padding(12)
    ->background($app->hovered ? Color::accentColor->opacity(0.15) : Color::secondarySystemBackground)
    ->cornerRadius(8)
    ->onHover(fn(bool $over) => $app->hovered = $over)
    ->onTapGesture(fn() => $app->taps++)
    ->padding();
```

## Tap, long-press and pointer location

- **`->onTapGesture(fn() => …, count: 1)`** runs on a click or tap (`count: 2` for a double-click). If the closure accepts a `CGPoint $location` parameter, it receives the tap's coordinates in `CoordinateSpace::local` (relative to the view) or `CoordinateSpace::global` (the window).
- **`->onLongPressGesture(fn() => …)`** runs on a long press or secondary click.
- **`->onHover(fn(bool $isHovered) => …)`** runs as the pointer enters and leaves the view.
- **`->allowsHitTesting(false)`** lets pointer events pass through the view to what is behind it.

## Drag, magnify and rotate gestures

Attach a `DragGesture`, `MagnifyGesture`, or `RotateGesture` with `->gesture()`:

```php
$app = new class { public float $x = 0.0, $y = 0.0; };
$view = fn() => Circle()
    ->fill(Color::accentColor)
    ->frame(width: 48, height: 48)
    ->offset(x: $app->x, y: $app->y)
    ->gesture(
        DragGesture(minimumDistance: 0)
            ->onChanged(function (DragGestureValue $v) use ($app) {
                $app->x = $v->translation->width;
                $app->y = $v->translation->height;
            })
            ->onEnded(function (DragGestureValue $v) use ($app) {
                withAnimation(Animation::spring(), function () use ($app) {
                    $app->x = $app->y = 0.0;
                });
            })
    );
```

`DragGestureValue` provides `location`, `startLocation`, `translation`, `velocity`, `predictedEndLocation`, and `predictedEndTranslation` (for momentum flings; see [`examples/drag`](../../examples/drag)).

## Keyboard shortcuts and key presses

- **`->keyboardShortcut('s', modifiers: [EventModifiers::command])`** triggers a `Button` when the shortcut is pressed (`KeyEquivalent::return`, `escape`, `delete`, `space`, or a character). `EventModifiers::command` maps to `Cmd` on macOS/iOS and `Ctrl` on Windows/Linux/Android.
- **`->onKeyPress(KeyEquivalent::upArrow, fn(KeyPress $p) => …)`** handles key presses when the view or its subtree has focus.

## Focus management

- **`->defaultFocus()`** gives a control the initial keyboard focus when the window or sheet opens.
- **`->focused($app->isFieldFocused)`** binds a `bool` property to whether the control currently holds keyboard focus; setting the property to `true` moves focus to it.
