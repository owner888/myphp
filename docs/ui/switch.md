# Switch

A `Toggle` is a switch turning a `bool` (bound by reference) on and off,
for a setting that applies at once: a click or a tap turns it over, as does
Space while it has the keyboard focus, and its knob slides across. It is a
switch on a phone and a checkbox on a Mac, as SwiftUI's; `->toggleStyle(
ToggleStyle::switch)` makes it a switch everywhere.

```php
$app = new class { public bool $wifi = true; };
$view = fn() => Toggle('Wi-Fi', isOn: $app->wifi)->toggleStyle(ToggleStyle::switch)->padding();
```

In a `Form` it is a row, its title at the start and the switch at the end,
as a phone's Settings:

```php
$app = new class { public bool $sound = false; public bool $haptics = true; };
$view = fn() => Form()(
    Section('Sounds')(
        Toggle('Sound', isOn: $app->sound),
        Toggle('Haptics', isOn: $app->haptics),
    ),
);
```

`->onChange(of: $app->sound, action: fn() => …)` runs what follows a change.
For a choice that applies when a form is saved, a [check box](checkbox.md).

## Accessibility

Assistive technology sees a switch, on or off, named by its title (or its
`accessibilityLabel`).
