# Select

A `Picker` (`PickerStyle::automatic` or `::menu`) is a pop-up button
choosing one of its choices, each a `Text` tagged with its value, into the
selection (bound by reference): a click on it, or Enter, Space, Up or Down
while it has the keyboard focus, opens its options below it, where the
arrows, Home and End move among them and Enter, Space or a click chooses
one; Escape closes them.

```php
$app = new class { public string $size = 'Medium'; };
$view = fn() => Form()(
    Picker('Size', selection: $app->size)(
        Text('Small')->tag('Small'),
        Text('Medium')->tag('Medium'),
        Text('Large')->tag('Large'),
    ),
);
```

The options show above it where there is no room below, and give the focus
back as they close; with more options than the window has room for, they
scroll. In a phone's `Form` row the choice shows muted at the row's end with
its arrows, as UIKit's menu button in a cell. In a `Menu`, a `Picker` shows
its choices inline, the chosen one checked.

For a long list that typing filters, use a [combobox](combobox.md); for a
few options shown at once, [radio buttons](radio.md).

## Accessibility

Assistive technology sees a pop-up button named by its title, its value
the choice.
