# Slider

`$c->slider()` creates a slider setting a `float` (by reference) between a
low and a high value: dragging the knob, or a press on the track, sets it,
as do the arrows (a hundredth of the range), Page Up and Page Down (a
tenth), Home and End while it has the keyboard focus. `$c->changed()`
reports a new value. It is 160 points wide unless the last argument says
otherwise.

```php
$app = new class { public float $volume = 50; };
$view = function (Context $c) use ($app) {
    $c->slider($app->volume, 0, 100)->label('Volume');
};
```

## Steps

`$c->stepSlider()` snaps the value to the low value and multiples of a
step from it, with a tick mark at each (none past 61), as AppKit's sliders
with tick marks: dragging snaps to them, and the arrows step by step.

```php
$app = new class { public float $quality = 50; };
$view = function (Context $c) use ($app) {
    $c->stepSlider($app->quality, 0, 100, 25)->label('Quality');
};
```

For a low and a high value at once, as a price range, use a
[range slider](range-slider.md); for exact numbers, a
[number input](number-input.md) or a [stepper](stepper.md).

## Without a look

`$c->sliderBase()` is a slider without a look: dragging across its content
box, within its padding, sets the value. Draw its track and thumb with
`draw()` where `($value - $lo) / ($hi - $lo)` puts them, and pad it by half
the thumb's width to keep the thumb inside it. [Right to
left](right-to-left.md), the low value is at the right, as a slider draws
it, and the arrows move the thumb the way they point: draw it mirrored when
`$c->direction()` is `Direction::RightToLeft`:

```php
$app = new class { public float $volume = 30; };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    $at = $app->volume / 100;
    if ($c->direction() === Direction::RightToLeft) {
        $at = 1 - $at;
    }
    $c->sliderBase($app->volume, 0, 100)->height(24)->paddingX(12)->label('Volume')
        ->draw(function (Painter $p, array $r) use ($at, $t) {
            [$x0, $y, $w] = $r;
            $x = $x0 + 12 + ($w - 24) * $at;
            $p->fill([$x0 + 12, $y + 10, $w - 24, 4], $t->border, 2);
            $p->fill([$x - 12, $y, 24, 24], $t->accent, 12);
        });
};
```

The fourth argument snaps its values to the low value and multiples of a
step, which the arrows move between, rather than by a hundredth of the
range, and `vertical: true` makes it go up, from the low value at the
bottom of its content box; pad it by half the thumb's height then:

```php
$app = new class { public float $level = 4; };
$view = function (Context $c) use ($app) {
    $accent = $c->theme->accent;
    $c->sliderBase($app->level, 0, 10, 1, vertical: true)->size(24, 120)->paddingY(12)
        ->draw(fn(Painter $p, array $r) => $p->fill([$r[0], $r[1] + 12 + ($r[3] - 24) * (1 - $app->level / 10) - 4, 24, 8], $accent, 4));
};
```

See [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a slider of its range and value, named by its
`label()`, horizontal or vertical, which it increments and decrements as
the arrows do.
