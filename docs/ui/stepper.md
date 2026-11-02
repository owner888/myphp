# Stepper

`$c->stepper()` creates a pair of arrows changing an `int` or a `float` (by
reference) by a step between two bounds, as AppKit's stepper beside a
field: a click on an arrow steps up or down, as do Up and Down while the
stepper has the keyboard focus, and holding an arrow keeps stepping, faster
the longer it is held; Home and End go to the bounds. `$c->changed()`
reports a new value.

```php
$app = new class { public int $copies = 1; };
$view = function (Context $c) use ($app) {
    $c->row(gap: 6);
      $c->text(sprintf('%d copies', $app->copies));
      $c->stepper($app->copies, 1, 99, 1)->label('Copies');
    $c->end();
};
```

For a number the user also types, use a [number input](number-input.md),
which has steppers of its own.

## Accessibility

Assistive technology sees a spin button of the value, named by its
`label()`, which it increments and decrements.
