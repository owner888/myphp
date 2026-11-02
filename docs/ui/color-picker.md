# Color picker

`$c->colorWell()` creates a swatch of a color, an `int` 0xRRGGBB and a
`float` alpha (both by reference), that opens a color picker below it, as
AppKit's color well: a click, Enter or Space opens it, and Escape or a
click outside closes it. `$c->changed()` reports a new color.

```php
$app = new class { public int $tint = 0x2563eb; public float $tintAlpha = 1.0; };
$view = function (Context $c) use ($app) {
    $c->field('Tint', function () use ($c, $app) {
        $c->colorWell($app->tint, $app->tintAlpha);
    });
};
```

`$c->colorPicker()` is the picker itself, to show in a panel of your own,
as AppKit's color panel: a square choosing the saturation, across, and the
brightness, up, of the hue its slider chooses below it, a slider of
opacity, the color in hex, which the user edits, and swatches of common
colors. The arrows move across the square while it has the focus, Up and
Down changing the brightness and Left and Right the saturation.

```php
$app = new class { public int $background = 0xffffff; public float $alpha = 1.0; };
$view = function (Context $c) use ($app) {
    $c->colorPicker($app->background, $app->alpha);
};
```

## Accessibility

Assistive technology sees a color well whose value is the color in hex,
named by its `label()` or its [field](form.md). In the picker, it sees a
slider of the saturation and brightness, sliders of the hue and the
opacity, a text field named "Hex", and buttons named by the swatches'
colors, in the window's language
([MyPHP's own strings](../i18n.md#myphps-own-strings)).
