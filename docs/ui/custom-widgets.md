# Custom widgets (`Representable`)

When an application needs to wrap a custom low-level engine node or a third-party plugin surface inside a declarative SwiftUI view tree, use `Representable(fn(Context $c): Node => …, fills: $bool)`, which corresponds to SwiftUI's `NSViewRepresentable` / `UIViewRepresentable`.

```php
$app = new class { public bool $revealed = false; };
$view = fn() => VStack(spacing: 12)(
    Text('Click the spoiler below:'),
    Representable(function (Context $c) use ($app): Node {
        $t = $c->theme;
        $box = $c->column()->padding(6, 12)->radius(6)->focusable()
            ->background($app->revealed ? $t->surface : $t->text);
          $c->text('The butler did it.');
        $c->end();
        if ($c->clicked($box)) {
            $app->revealed = !$app->revealed;
        }
        return $box;
    }),
)->padding();
```

Standard modifiers chained on `Representable(...)` (such as `->padding()`, `->frame()`, `->font()`, `->help()`, `->accessibilityLabel()`) apply to the outer `Node` returned by the closure. Passing `fills: true` makes the node expand to fill the available space along its parent stack.

## Headless widget bases on `Context`

Inside a `Representable`, `Context` provides unstyled behavioral primitives ("bases") that handle pointer hit-testing, keyboard navigation, focus rings, and accessibility roles while leaving visual styling to you:

| Base | What it does |
|---|---|
| `buttonBase()` | Focusable container that reports `clicked()` for pointer, Enter, and Space |
| `checkboxBase($on)`, `switchBase($on)`, `toggleBase($on)` | Toggles a `bool` reference |
| `radioBase($selected, $value)` | Selects `$value` into `$selected` |
| `sliderBase($value, $min, $max, $step, $vertical)` | Tracks pointer drags and arrow/Page/Home/End keys across its content box |
| `tabsBase($selected, $n)` / `segmentedBase($selected, $n)` | Tab list or radio segment group with arrow-key navigation |
| `selectBase($selected)` / `comboboxBase($text)` | Trigger + popup menu with keyboard highlighting |
| `textInputBase()` / `textAreaBase()` | Unstyled single-line or multi-line text inputs |

For custom text surfaces with their own backing store or multi-caret model, see [`TextBuffer` and `TextInputClient`](text-input-client.md).
