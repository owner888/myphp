# Number input

`$c->numberInput()` creates a text input editing an `int` or a `float` (by
reference) as a number between two bounds, which Up and Down, and the
buttons beside it, change by a step. What is typed applies as soon as it is
a number in range, and shows rounded to the decimals of the step once the
input loses the focus. `$c->changed()` reports a new value.

```php
$app = new class { public int $copies = 1; public float $opacity = 0.5; };
$view = function (Context $c) use ($app) {
    $c->numberInput($app->copies, 1, 99, 1)->label('Copies');
    $c->numberInput($app->opacity, 0, 1, 0.05)->label('Opacity');
};
```

For a value the user only steps, use a [stepper](stepper.md); for one
chosen roughly, a [slider](slider.md); for an amount of money, which a
`float` cannot hold exactly, a [decimal input](decimal-input.md).

## Accessibility

Assistive technology sees a spin button of the value, named by its
`label()` or its [field](form.md), which it increments and decrements by
the step.
