# Color picker

A `ColorPicker` is a swatch of a color, an `int` 0xRRGGBB (bound by
reference), that opens a color picker below it, as AppKit's color well and
SwiftUI's color picker: a click, Enter or Space opens it, and Escape or a
click outside closes it. `supportsOpacity: true` with `opacity:` adds a
slider of opacity, a `float` from 0 to 1.

```php
$app = new class { public int $tint = 0x0a84ff; public float $alpha = 1.0; };
$view = fn() => Form()(
    ColorPicker('Tint', selection: $app->tint),
    ColorPicker('Highlight', selection: $app->tint, supportsOpacity: true, opacity: $app->alpha),
);
```

The picker is a square choosing the saturation, across, and the brightness,
up, of the hue its slider chooses below it, the color in hex, which the
user edits, and swatches of common colors.

## Accessibility

Assistive technology sees a color well whose value is the color in hex,
named by its title.
