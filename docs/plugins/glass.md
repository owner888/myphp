# Glass

In a view, `->glassEffect(new Glass())` puts a view on myphp's glass, as
SwiftUI's `glassEffect()`; `Glass(Style::Clear)` the clear kind:

```php
use MyPHP\Plugins\Glass\Glass;

$view = fn() => Text('On glass')->padding()->glassEffect(new Glass())->padding();
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](../ui/custom-widgets.md)).

The glass plugin draws Liquid Glass in native UI, the material of macOS 26
and later: what is under a node shows through it, frosted, bent near its
edges as through the rim of a lens, and lit along its hairline rim. It
also draws macOS's scroll edges, which content scrolling under a bar fades
or frosts under ([Scroll edges](#scroll-edges)), and blurs what is under a
node without the glass, evenly or fading along a gradient ([Blur](#blur)).
MyPHP draws them with the renderer's shaders, on the GPU or the CPU, so
they look the same on macOS and Linux. It is all PHP: no JavaScript
package, and nothing to `App::use()`.

```php
use MyPHP\Plugins\Glass\Glass;

$c->row(8, 8)->padding(8, 16)->radius(22)->material(new Glass());
  $c->text('On glass');
$c->end();
```

`Glass` is a material (`MyPHP\UI\Material`): `material()` fills the node
with it in place of a background, and its `radius()` is its shape; its
children draw on it. What shows through is what was painted under it in
the same window: the nodes before it, as content that scrolls under a
toolbar placed over it with `absolute()`, and other glass.

```php
use MyPHP\Plugins\Glass\Glass;

$app = new class { public ScrollState $scroll; public function __construct() { $this->scroll = new ScrollState(); } };

$view = function (Context $c) use ($app) {
    $c->column(0, 0, 1);
      $c->column(0, 12, 1)->padding(64, 16, 16)->scroll($app->scroll)->scrollbarInsets(64, 0, 0);
        for ($i = 0; $i < 30; $i++) { $c->text("Row $i"); }
      $c->end();
      // The toolbar floats over the content, which scrolls under it.
      $c->row(6, 8)->absolute()->top(12)->left(12)->right(12)->radius(26)->material(new Glass());
        $c->text('Toolbar');
      $c->end();
    $c->end();
};
```

## Styles

- **`Style::Regular`**, the default, frosts what shows through and
  lightens it, or darkens it in dark mode, so that what is on the glass
  reads over anything: bars, buttons and panels. Larger panes are
  frostier, as on macOS.
- **`Style::Clear`** barely blurs or tones what shows through: glass over
  photos and video, where what is on it brings its own contrast.

`tint` colors the glass toward a color, by `tintAlpha`, as a prominent
button: `new Glass(tint: $t->accent)`, with text in `$t->accentText`.
`interactive` makes the glass grow a little while it is pressed, as
AppKit's interactive glass does on macOS 27: about 1.1 points left and
right and 0.45 above and below, whatever its size, within 150 ms. AppKit
stretches the content too, by a pixel or so, which the children here are
not. MyPHP's interactive glass also lights up a little while the pointer
is over it.

```php
use MyPHP\Plugins\Glass\Glass;

$t = $c->theme;
$done = $c->row(8, 0)->padding(8, 16)->radius(20)->material(new Glass(tint: $t->accent, interactive: true))->focusable();
  $c->text('Done', style: Font::BOLD, color: $t->accentText);
$c->end();
if ($c->clicked($done)) {
    // ...
}
```

`clarity`, from 0 to 1, makes panes as large as sidebars and inspectors
clearer, the content under them reading through: 0, the default, is
macOS's material, unchanged; 0.5 blurs up to 5 points rather than 10, maps
black to 35% in light mode and white to 60% in dark mode, lights the rim
more and curves the last 48 points; 1 is nearly clear, blurring up to 2
points and mapping black to 20% in light mode, and black to 10% and white
to 85% in dark mode. Each half of the range is a straight step between
those. Panes up to 100 points keep their material whatever the clarity,
which eases in up to 200 points: `new Glass(clarity: 0.5)`. Clear glass
barely tones what shows through already; on it, clarity only lights the
rim and curves the bezel.

In a drawing, `Glass::paint($p, $r, $radius, $g)` paints a pane of glass
over what the painter painted before it.

## Scroll edges

`ScrollEdge` is the scroll edge effect of macOS 26 and later, a material
for a node over the edge of content that scrolls under a bar of controls,
so that they stand out from it, as SwiftUI's `safeAreaBar` with
`scrollEdgeEffectStyle` draws it:

```php
use MyPHP\Plugins\Glass\{Glass, ScrollEdge};

$app = new class { public ScrollState $scroll; public function __construct() { $this->scroll = new ScrollState(); } };

$view = function (Context $c) use ($app) {
    $c->column(0, 0, 1);
      $c->column(0, 12, 1)->padding(64, 16, 16)->scroll($app->scroll)->scrollbarInsets(64, 0, 0);
        for ($i = 0; $i < 30; $i++) { $c->text("Row $i"); }
      $c->end();
      // The content fades into the background under the bar.
      $c->column()->absolute()->top(0)->left(0)->right(0)->height(74)->passThrough()->material(new ScrollEdge());
      $c->end();
      // Buttons of glass float over it.
      $c->row(0, 6)->absolute()->top(12)->left(12)->right(12)->passThrough();
        $c->row(6, 0)->radius(14)->material(new Glass(interactive: true));
          $c->text('Back');
        $c->end();
      $c->end();
    $c->end();
};
```

- **Soft**, the default, fades what the content scrolls over into it: the
  background at 85% at the bar's edge, falling to nothing at the node's
  other edge, with no blur. Make the node the bar's height and 10 points
  more, as macOS does.
- **`hard`** frosts the content under the bar evenly: blurred by about 6
  points, its colors saturated by a quarter, under 82% of the background,
  with a hairline along the node's other edge, black at 10% in light mode
  and white at 7% in dark mode. Make the node the bar's height.

`bottom` puts the bar at the node's bottom, and `background` sets what the
content scrolls over, the theme's `background` unless set. Paint the edge
before what floats on it, and let `passThrough()` take the pointer to the
content.

They are macOS 27's, measured from SwiftUI's over test patterns: in its
layers, a soft edge replays the window's background under a gradient mask
from 85% to nothing, 10 points past the bar, and a hard edge blurs the
content (a Gaussian of radius 6, saturation 1.25 and 0.03 more), then
replays the background at 82.45% over it. macOS's window toolbars draw an
edge of their own, an even blur under 60% of the background, which a bar
of native UI does not have.

## Blur

`Blur` is a backdrop blur, a material too: what is under the node shows
through it blurred by `radius` points (the standard deviation, as CSS's
`blur()` takes), with no tint, rim or shadow.

`mask` makes it a progressive blur: a `LinearGradient` whose alpha says
how much of `radius` each place blurs by, as CSS's `mask-image` does.
Where the gradient is opaque, what shows through blurs by `radius`, where
it is translucent by less, and where it is transparent not at all; its
colors do not show. Under a bar, content then blurs the most at its edge,
and less and less further from it.

```php
use MyPHP\Plugins\Glass\{Blur, Glass};

$app = new class { public ScrollState $scroll; public function __construct() { $this->scroll = new ScrollState(); } };

$view = function (Context $c) use ($app) {
    $c->column(0, 0, 1);
      $c->column(0, 12, 1)->padding(64, 16, 16)->scroll($app->scroll)->scrollbarInsets(88, 0, 0);
        for ($i = 0; $i < 30; $i++) { $c->text("Row $i"); }
      $c->end();
      // What scrolls under the toolbar blurs, the more the nearer the top.
      $c->column()->absolute()->top(0)->left(0)->right(0)->height(88)->passThrough()
        ->material(new Blur(6, new LinearGradient(0x000000, 1, 0x000000, 0, angle: 180, start: 0.3, end: 1)));
      $c->end();
      // Buttons of glass float over it.
      $c->row(0, 6)->absolute()->top(12)->left(12)->right(12)->passThrough();
        $c->row(6, 0)->radius(14)->material(new Glass(interactive: true));
          $c->text('Share');
        $c->end();
      $c->end();
    $c->end();
};
```

Here the blur is whole over the top 30% of the strip and fades out below,
`passThrough()` lets the pointer reach the content under the strip and
around the buttons, and `scrollbarInsets()` starts the scroll bar below
the strip, as AppKit starts a scroller below its toolbar's edge effect.
Paint the blur before what floats on it: glass over it shows the blurred
content through. `start` and `end` place the gradient's colors along it,
as everywhere; leaving `end` 0 puts both at `start`, a hard edge.

A blur that varies is drawn in steps, each blurring what the step before
drew a little more, shown where the blur wanted is more than the step's
own, mixed with the next between them: one step for each doubling of the
blur from two pixels, five for 12 points on a Retina display. A pixel
between two steps shows both blurs mixed, as Core Animation's variable
blur does between the levels of its pyramid.

## How it looks like macOS

The glass follows macOS 27's, measured from AppKit's
`NSGlassEffectView`, with the optics of the open-source reproductions of
Liquid Glass:

- **The bezel.** The surface is flat in the middle and curves down to its
  edges along Apple's squircle profile, over the last 36 points, at most
  half of the pane. Light coming straight down refracts entering it, as
  through glass of refractive index 1.5, so what is near the edge comes
  from further inside: the rim mirrors what is just inside it, as a
  lens's does.
- **The material.** The regular glass blurs what shows through by up to 10
  points, more for larger panes, and maps its lightness, black to 54% and
  white to 100% in light mode, and to 15% and 51% in dark mode, keeping
  its colors. The clear glass adds an eighth to it.
- **The light.** The rim is lit where it faces up or down and shaded where
  it faces the sides, within a pixel of the edge: a hairline, as AppKit's.
- **No shadow.** Neither `NSGlassEffectView` nor a button of the glass
  bezel casts one: the pane's edge is its rim alone.

## Performance

Each pane reads what is under it, averages and blurs it, then draws with
its own shader: a few small passes on the GPU, and a pane changes when
anything under it does, as content scrolling under a bar. When a window
draws on the CPU, without a GPU, as [Rendering](../ui/rendering.md)
describes, a change under a pane redraws the pane and what its blur
reaches around it. A progressive blur does this for each of its steps,
where each shows.

Panes are rounded rectangles. They do not merge into each other when
close, as AppKit's `NSGlassEffectContainerView` does.
