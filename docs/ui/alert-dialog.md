# Alert

`->alert()` asks a question while a `bool` (bound by reference) is true, as
SwiftUI's: a title, a message, and its actions, each a `Button`, which
closes it as it runs. A `ButtonRole::cancel` button is the alert's Cancel,
Escape's; a `ButtonRole::destructive` one is red.

```php
$app = new class { public bool $asking = true; public int $deleted = 0; };
$view = fn() => Text('A note')->padding()->alert('Delete the note?', isPresented: $app->asking, actions: [
    Button('Delete', role: ButtonRole::destructive, action: fn() => $app->deleted++),
    Button('Cancel', role: ButtonRole::cancel),
], message: 'This cannot be undone.');
```

What is behind it takes no input while it shows; the focus goes to its
default button and comes back as it closes. On a phone it is UIKit's alert,
its buttons across or stacked; for choices from the bottom of a phone, a
`->confirmationDialog()` ([mobile](../mobile.md)).

## Accessibility

Assistive technology sees an alert dialog named by its title, described by
its message.
