# Range slider

`$c->rangeSlider()` creates a slider of two knobs setting a low and a high
`float` (both by reference) between two bounds, as a price range: each knob
takes the keyboard focus, which Tab moves between, and the arrows, Home and
End move it while it has the focus; a press on the track moves the knob
nearest to it. The knobs do not cross. `$c->changed()` reports a new value.

```php
$app = new class { public float $minPrice = 100; public float $maxPrice = 600; };
$view = function (Context $c) use ($app) {
    $c->rangeSlider($app->minPrice, $app->maxPrice, 0, 1000, 10)->label('Price');
    $c->text(sprintf('$%.0f – $%.0f', $app->minPrice, $app->maxPrice));
};
```

A step above 0 snaps the values to the low bound and multiples of the step
from it, with tick marks; 0 leaves them free, the arrows moving them by a
hundredth of the range. The slider is `$t->space(5)` high and at least
`$t->space(25)` wide; the last argument gives it a width.

## Accessibility

Assistive technology sees two sliders, named by the range slider's
`label()` and "minimum" and "maximum".
