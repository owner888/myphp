# Decimal field

A `DecimalField` is a [number field](number-input.md) for amounts of money:
it edits a string (bound by reference) holding an exact decimal between two
bounds, which Up and Down, and the arrows beside it, change by a step. The
value is written with exactly the decimals of the step, as `"1234.50"`; the
bounds are written the same way, `''` for no limit. Inside, it counts in
integers of units of the step's last decimal, so no amount is ever held in
binary floating point.

```php
$app = new class { public string $price = '19.99'; public string $total = '0'; };
$view = fn() => Form()(
    DecimalField('Price', value: $app->price, in: ['0', '100000'], step: '0.01'),
    LabeledContent('Price with tax', bcadd($app->price, bcmul($app->price, '0.09', 4), 2)),
);
```

Compute with the value as a string (`bcadd`, `bcmul`), never with `float`.

## Accessibility

Assistive technology sees a spin button of the value, named by its title.
