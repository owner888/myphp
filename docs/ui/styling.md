# Styling and themes

A view's look is its modifiers, as SwiftUI's, applied in order:
`->padding()`, `->background()`, `->cornerRadius()`, `->border()`,
`->shadow()`, `->opacity()`, `->foregroundStyle()`, `->font()`. A padding
before a background pads inside it, one after outside.

```php
$view = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
    Text('Card')->font(Font::headline),
    Text('Follows light and dark mode')->foregroundStyle(Color::secondary),
)
    ->padding()
    ->frame(width: 240, alignment: Alignment::leading)
    ->background(Color::secondarySystemBackground)
    ->cornerRadius(12)
    ->shadow(radius: 8, y: 4)
    ->padding();
```

## Colors

The named colors follow the theme, light or dark, the system's accent and
contrast: SwiftUI's `Color::primary`, `::secondary`, `::accentColor`,
`::background`, `::separator`; UIKit's `::systemBackground`,
`::secondarySystemBackground`, `::systemGroupedBackground`,
`::secondarySystemGroupedBackground`, `::tertiarySystemBackground`,
`::label`, `::secondaryLabel`, `::link`, the grays `::systemGray` to
`::systemGray6`; and the system's `::red`, `::blue`, `::green`…
`Color::rgb(0x336699)` is a color of the app's own, for data (a chart's
series, a tag) rather than the interface. `->tint()` colors the controls
inside a view, as the accent.

## Shapes and materials

`Rectangle()`, `RoundedRectangle(cornerRadius:)`, `Circle()`, `Capsule()`
and `Ellipse()` take `->fill()` and `->stroke()`, and are views of their
own or a `->clipShape()`. `->glassEffect($material)` puts a view on glass;
a window's `vibrancy:` (`'sidebar'`, `'under-window'`…) shows the desktop's
material through it.

## State, not selectors

A look that depends on the state is an expression in the view, as SwiftUI's:
`->foregroundStyle($done ? Color::secondary : Color::primary)`,
`->opacity($enabled ? 1 : 0.5)`. The controls look hovered, pressed,
focused and disabled by themselves.

## Themes

The views take their colors and metrics from the theme, which follows the
system's appearance as it changes and the desktop's settings (accent,
contrast, text size). `->preferredColorScheme(ColorScheme::dark)` or
`App::setColorScheme(true)` chooses the appearance for the app (null
follows the system), `->dynamicTypeSize()` or `App::setTextScale()` the
text size. `App::setTheme()` replaces the theme's colors and metrics for
every window, `App::setTheme(null)` going back to the system's:

<!-- no-test -->
```php
$t = Theme::light();
$t->accent = 0x7c3aed;
$t->spacing = 5;     // the unit of the controls' paddings and gaps: 3 compact, 5 roomy
App::setTheme($t);
```

On a phone the theme is `Theme::phone()`: UIKit's system colors, 17-point
text, UIKit's control sizes ([mobile](../mobile.md)).

## The desktop's preferences

The theme follows the desktop's: on macOS the appearance, the accent and
highlight colors, Increase Contrast and Reduce Motion; on Linux GNOME's
color scheme, accent, high contrast, text scaling and animations. A change
while the app runs changes every window.
