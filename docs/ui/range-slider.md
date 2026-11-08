# Range slider

A `RangeSlider` has two knobs setting a low and a high number (both bound
by reference) between two bounds, as a price range: each knob takes the
keyboard focus, which Tab moves between, and the arrows, Home and End move
it while it has the focus; a press on the track moves the knob nearest to
it. The knobs do not cross. SwiftUI has none: it is myphp's own.

```php
$app = new class { public float $minPrice = 100; public float $maxPrice = 600; };
$view = fn() => VStack(alignment: HorizontalAlignment::leading)(
    RangeSlider(lower: $app->minPrice, upper: $app->maxPrice, in: [0, 1000], step: 10, title: 'Price'),
    Text(verbatim: "{$app->minPrice} – {$app->maxPrice}")->foregroundStyle(Color::secondary),
)->padding()->frame(width: 300);
```

## Accessibility

Assistive technology sees two sliders, the low and the high value, named by
the title.
