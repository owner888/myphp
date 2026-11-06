# Styling and themes

`background()`, `border()`, `radius()`, `shadow()` and `opacity()` style a
node's box, and `cursor()` sets the pointer over it:

```php
$t = $c->theme;
$c->column(18, 12)->radius(10)
    ->background($t->background)->border(1, $t->border)
    ->shadow(0, 1, 3, 0, 0x000000, 0.06);
$c->end();
```

Colors are ints, `0xRRGGBB`; an alpha, from 0 to 1, follows them where a
color can be translucent: `background($rgb, $alpha)`,
`textColor($rgb, $alpha)`, `shadow(..., $rgb, $alpha)`.

- **Borders.** `border(1, $rgb)` draws one inside every edge;
  `borderWidth()` sets the sides apart, CSS style, with `borderColor()`, as
  a line under a header with `borderWidth(0, 0, 1, 0)`, and
  `borderStyle(BorderStyle::Dashed)` dashes it. As with CSS's
  `box-sizing: border-box`, the border takes room within the node's size,
  and the padding and the children are inside it.
- **Shadows.** `shadow($x, $y, $blur, $spread, $rgb, $alpha)` casts a box
  shadow, as CSS's `box-shadow` does: several stack, and each shows only
  outside the box, so a translucent background never shows its own shadow
  through. `insetShadow()` draws an inner one, as `box-shadow: inset`:
  inside the box's padding edge, within its border and rounded corners,
  above the background and below the children; the offset moves it, and a
  positive spread grows it inward. Inner shadows stack too, and go with
  outer ones, as a hairline that edges a field in the dark:

  ```php
  $t = $c->theme;
  $field = $c->column(12);
    $c->text('Field');
  $c->end();
  $field->radius(12)->background($t->surface)
      ->shadow(0, 8, 24, 0, 0x000000, 0.3)
      ->insetShadow(0, 0, 1, 0, 0xffffff, 0.2);
  ```
- **Gradients and stripes.** `gradient($from, $to, $angle)` fills the box
  with a linear gradient; `linearGradient(new LinearGradient(...))` also
  places its colors along the line (`start`, `end`) and mixes them in Oklab
  (`oklab: true`), which keeps their lightness, instead of sRGB, or takes
  as many colors as its `stops`, each a `GradientStop($color, $at)` at its
  place from 0 to 1. `conicGradient(new ConicGradient(...))` turns its
  stops around a point instead, as CSS's `conic-gradient()` (SwiftUI's
  `AngularGradient`): from `angle`, in degrees clockwise from upwards,
  around `centerX`, `centerY`, the middle unless set.
  `radialGradient(new RadialGradient(...))` spreads them out from the
  center in circles, as CSS's `radial-gradient()`: from 0 there to 1 at
  `radius` points away, or at the farthest corner of the box without one,
  as CSS's `farthest-corner`, its first color before the center and its
  last past the radius, for glows, spotlights and vignettes:

  ```php
  $c->column()->size(240, 140)->radius(16)->radialGradient(new RadialGradient(
      centerX: 0.3, centerY: 0.2, oklab: true,
      stops: [new GradientStop(0xa5b4fc, 0), new GradientStop(0x312e81, 1)],
  ));
  $c->end();
  ```

  `borderLinearGradient()` and `borderConicGradient()` paint the border
  with one in place of its color, as SwiftUI's `strokeBorder` with a
  gradient:

  ```php
  $rainbow = [new GradientStop(0xef4444, 0), new GradientStop(0xeab308, 0.33), new GradientStop(0x3b82f6, 0.66), new GradientStop(0xef4444, 1)];
  $c->column()->size(120, 80)->radius(16)->border(4)
      ->borderConicGradient(new ConicGradient(stops: $rainbow, oklab: true));
  $c->end();
  ```

  `stripes($rgb, $width, $gap, $angle)` draws stripes over the background,
  as on what is unavailable. A painter fills rectangles and paths with the
  same gradients (`fillGradient()`, `fillConicGradient()`,
  `fillRadialGradient()`, `fillPathGradient()`, `fillPathConicGradient()`,
  `strokePathConicGradient()`).
- **Blur.** `blur($sigma)` blurs the node and its children, as CSS's
  `filter: blur()`: what they draw spreads about 3 sigma points around,
  transparent beyond, on the GPU. They render into a bitmap first, as an
  [effect](shaders.md#effects-on-nodes) does, every frame they paint;
  clicks, focus and assistive technology take the node where it is. Glows
  are borders blurred, a sharp one and blurred ones stacked:

  ```php
  $glow = new ConicGradient(stops: [new GradientStop(0x22d3ee, 0), new GradientStop(0xa855f7, 0.5), new GradientStop(0x22d3ee, 1)]);
  $c->column()->size(200, 120);
    foreach ([[6, 0], [9, 4], [15, 15]] as [$width, $blur]) {
        $c->column()->absolute()->top(0)->left(0)->right(0)->bottom(0)->passThrough()
            ->radius(16)->border($width)->borderConicGradient($glow)->blur($blur);
        $c->end();
    }
  $c->end();
  ```
- **Caching.** `cache()` keeps what the node and its children paint in a
  bitmap of the GPU, as Flutter's `RepaintBoundary` and SwiftUI's
  `drawingGroup()` do, drawn again only when what they paint changes:
  other content, another size, the window's scale or theme. A still card
  of gradients, blurred images and large shadows around a drawing that
  moves then costs one image wherever a frame redraws over it, and turning
  or scaling the node with a transform moves the bitmap without drawing it
  again. The bitmap holds the box, its shadows and children beyond it that
  it does not clip; text over no opaque background of the subtree's own is
  smoothed in grayscale, as in a blur. With `blur()` or an effect, their
  bitmap is the one kept.

  ```php
  $c->column()->size(900, 212)->radius(16)->gradient(0x6366f1, 0xec4899, 30)
      ->shadow(0, 10, 40, 0, 0x000000, 0.25)->cache();
    $c->text('A still card');
  $c->end();
  ```
- **Materials.** `material($m)` fills the box with a material a plugin
  paints, in place of a background, as the [glass
  plugin](../plugins/glass.md)'s Liquid Glass, which what is under it shows
  through, frosted and bent along its edges, its scroll edges and its
  backdrop blur, which may fade along a gradient. `systemMaterial()` uses
  one of the system's own.
- **Pointer.** `cursor()` takes the shapes of the platforms:
  `Cursor::Pointer`, `Cursor::Text`, `Cursor::Move`, `Cursor::Grab` and
  `Cursor::Grabbing`, the resize cursors (both ways, toward one side as
  `Cursor::ResizeE`, and of columns and rows), `Cursor::Copy` and
  `Cursor::Alias` for drops, `Cursor::ContextMenu`,
  `Cursor::VerticalText`, `Cursor::NotAllowed`, `Cursor::Crosshair`, and
  `Cursor::None`, which hides it. A node's cursor shows over its children
  too, except over those that take clicks (a button, or anything with
  `clicked()` or `onClick()`): those show their own, the default arrow
  unless they set one, so the buttons of a field with `Cursor::Text` show
  no I-beam.

`Oklch::rgb($l, $c, $h)` is CSS's `oklch()`. It returns a color like any
other, so fills, borders, gradients, stripes, shadows, dividers, text,
decorations, text backgrounds, the glass's tint and the theme
(`$t->accent = Oklch::rgb(0.6, 0.2, 240)`) all take it. It can name
colors outside sRGB, more vivid than most screens show. Such a color keeps
both: its real value, drawn when the window is on a screen that shows a
wider range (Display P3 on recent Macs), and the nearest sRGB color,
chosen as CSS does (less saturated, same lightness and hue). That fallback
is its low 24 bits, `0xRRGGBB`, and is what sRGB screens, Linux and SVG
pictures draw; `WideColor::srgb()` returns it without the real value.
`Theme::mix()` and transitions mix the real values: a mix back inside sRGB
is an ordinary color. Don't change the bits of such a color, as the real
value would no longer match: make a new one.

`Theme::mix($a, $b, $k)` blends two colors.

## State, not selectors

There are no style sheets and no state selectors: the view is code, so a
node's look follows the state in the view itself, as
`if ($c->hovered($row)) { $row->background($t->surfaceHover); }`, and one
node's state can style another, as a group's hover does in CSS.

A widget returns its node, so a call after it styles it differently from
the rest: `$c->button('Save')->padding(10, 20)->radius(999)`. For a look
of your own, build on the widgets' bases, which have none: see
[custom widgets](custom-widgets.md).

## Themes

Widgets take their colors and metrics from the theme, `$c->theme`: the
light or the dark theme, following the system's appearance as it changes,
and the settings of the desktop that the system's own controls follow. Use
its colors in your own nodes so they follow too. To change it, set a copy
for the app with `App::setTheme()`, which `App::setTheme(null)` undoes to
follow the system again:

<!-- no-test -->
```php
$t = clone App::theme(); // the default, which follows the system
$t->accent = 0x7c3aed;
$t->radius = 8;
$t->spacing = 3; // compact
App::setTheme($t);
```

`spacing` is the unit of the room widgets leave: their paddings and gaps,
and the sizes of check boxes, switches, sliders and the rows of tables and
trees, are multiples of it. It is 4 by default; 3 makes every widget
compact, 5 roomy. `fontSize` sizes their text, `radius` rounds their
corners, and `scrollbarWidth` sets the width of scroll bars. On macOS,
rounded corners, the widgets' and your nodes', curve continuously as
AppKit's and SwiftUI's do, and elsewhere as quarter circles, as GTK draws
them. Size your own nodes with the theme too, and they follow it:
`$t->space(3)` is three units of its spacing, and `$t->rem(2)` twice its
font size, as CSS's rem.

Tooltips and toasts are the theme turned over, so that they stand out from
what they are over: filled with `inverse`, with `inverseText` on it, which
left null are the theme's `text` and `background`. A dark app that would
rather keep them dark sets both:

<!-- no-test -->
```php
$t->inverse = 0x3f3f46;
$t->inverseText = 0xfafafa;
```

## A theme for part of the window

`App::setTheme()` themes every window: the root, its background and its
text, and the widgets. `$c->themed($t)` themes a part of one instead: a
column whose children build with `$t`, as a theme picker previewing light
beside dark, or a light preview pane inside dark chrome:

```php
$c->row()->fill()->alignItems(Node::STRETCH);
  foreach ([Theme::light(), Theme::dark()] as $t) {
      $c->themed($t, 16, 8, 1);
        $c->text('Preview');
        $c->button('OK');
      $c->end();
  }
$c->end();
```

Inside, everything is as in a window of `$t`: `$c->theme` is it while the
children build, and `Painter::theme()` while they paint, so widgets of
your own follow too; the widgets take its colors and its metrics
(`spacing`, `radius`, `fontSize`, `scrollbarWidth`, so `$t->space()` and
`$t->rem()`); and the popovers, menus, tooltips and dialogs opened from
inside take it, though they show above the window. The column paints
`$t`'s `background`, and its text takes `$t`'s `text` color and
`fontSize`, as the root does the window's theme's; `background()` of
another color, or a transparent one, leaves what is behind it showing
instead. The root, and what is built after the column, keep the window's
theme; toasts are the window's, and keep it too. Themes nest, and a null
theme makes it a plain column.

## The desktop's preferences

`$c->preferences()` returns the settings of the desktop, which the default
theme follows and a frame follows the changes of:

- **`accent`.** The accent color the user chose (macOS, and desktops whose
  portal gives one, as GNOME 47 and KDE do), which colors primary buttons,
  the choice and the focus ring, with text on it that stands out.
- **`highContrast`.** macOS's Increase Contrast, the portal's higher
  contrast: borders and secondary text are darker (lighter in the dark),
  and the focus ring opaque.
- **`textScale`.** GNOME's text size, iOS's Text Size and Android's font
  size: `fontSize` is that many times larger than the platform's default
  (`Theme::defaultFontSize()`: 13 points on macOS, 14 on Linux, 17 on a
  phone or tablet), which `Theme::textScale()` reports and the text styles
  (`preferredFont()`) scale by.
- **`reduceMotion`.** macOS's Reduce Motion and GNOME's animations turned
  off: `$c->animate()` goes to its target at once.
