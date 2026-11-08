# Slider

A `Slider` sets a number (bound by reference) between a low and a high
value: dragging the knob, or a press on the track, sets it, as do the
arrows (a hundredth of the range), Page Up and Page Down (a tenth), Home
and End while it has the keyboard focus.

```php
$app = new class { public float $volume = 40; };
$view = fn() => VStack(alignment: HorizontalAlignment::leading)(
    Text('Volume'),
    Slider(value: $app->volume, in: [0, 100]),
    Text(verbatim: sprintf('%.0f%%', $app->volume))->foregroundStyle(Color::secondary),
)->padding()->frame(width: 260);
```

## Steps

With `step:` the value snaps to the low value and multiples of the step from
it, with a tick mark at each (none past 61), as AppKit's sliders with tick
marks: dragging snaps to them, and the arrows step by step.

```php
$app = new class { public float $rating = 3; };
$view = fn() => Slider(value: $app->rating, in: [1, 5], step: 1)->frame(width: 200)->padding();
```

`onEditingChanged:` is called with true as a drag starts and false as it
ends, as SwiftUI's. For a low and a high value at once, as a price range,
use a [range slider](range-slider.md); for exact numbers, a
[stepper](stepper.md).

## Accessibility

Assistive technology sees a slider of the value, named by its `title:` or
`accessibilityLabel`, which it increments and decrements as the arrows do.
