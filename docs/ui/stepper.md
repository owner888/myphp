# Stepper

A `Stepper` changes a number (bound by reference) by a step between two
bounds, as AppKit's stepper beside a field: a click on an arrow steps up or
down, as do Up and Down while the stepper has the keyboard focus, and
holding an arrow keeps stepping, faster the longer it is held; Home and End
go to the bounds. Its title shows beside it.

```php
$app = new class { public int $copies = 1; };
$view = fn() => Form()(
    Stepper("Copies: {$app->copies}", value: $app->copies, in: [1, 99]),
);
```

`step:` sets the step, 1 by default; a `float` steps by fractions.

## Accessibility

Assistive technology sees a spin button of the value, named by its title,
which it increments and decrements.
