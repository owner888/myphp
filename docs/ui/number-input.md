# Number field

A `NumberField` edits an `int` or a `float` (bound by reference) as a
number between two bounds, which Up and Down, and the arrows beside it,
change by a step. What is typed applies as soon as it is a number in range,
and shows rounded to the decimals of the step once the field loses the
focus. SwiftUI writes it `TextField(value:format:)`; this is myphp's own,
SwiftUI-shaped.

```php
$app = new class { public int $copies = 1; public float $opacity = 0.5; };
$view = fn() => Form()(
    NumberField('Copies', value: $app->copies, in: [1, 99]),
    NumberField('Opacity', value: $app->opacity, in: [0, 1], step: 0.05),
);
```

For amounts of money, a [decimal field](decimal-input.md); for a number
without typing, a [stepper](stepper.md) or a [slider](slider.md).

## Accessibility

Assistive technology sees a spin button of the value, named by its title.
