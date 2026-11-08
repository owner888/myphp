# Toast

`->toast($message)` shows a message near the bottom of the window for a
few seconds, as the outcome of what the user just did, while the property
it binds is set; it sets it back to null as it goes. SwiftUI has none: it
is myphp's own.

```php
$app = new class {
    public ?string $message = null;
    public array $notes = ['Groceries', 'Ideas'];
    public function trash(string $n): void { $this->notes = array_values(array_diff($this->notes, [$n])); $this->message = "Moved “{$n}” to the Trash"; }
};
$view = fn() => ListView($app->notes)(fn(string $n) => HStack()(
    Text(verbatim: $n),
    Spacer(),
    Button('Delete', role: ButtonRole::destructive, action: fn() => $app->trash($n))->buttonStyle(ButtonStyle::borderless),
))->toast($app->message);
```

A message already showing shows anew; others stack above it, the newest
three showing. The time of the toasts stops while the pointer rests on
them or the window is in the background. Toasts fade in and out, in the
theme's inverse colors (see [themes](styling.md#themes)).

## Accessibility

Assistive technology announces the message as it shows.
