# Drawing and animation

`draw()` paints on a node after its background, and `drawOver()` after its
children, with a `Painter` in points of the window and the node's box,
`[x, y, w, h]`: rectangles with `fill()`, `fillGradient()`,
`fillConicGradient()` and `fillRadialGradient()`, `stroke()` and
`strokeDashed()`, `shadow()`, `line()`, `image()`, `clip()`, paths of
lines and curves with `fillPath()` and `strokePath()`, or in a gradient
with `fillPathGradient()` and `strokePathGradient()`, polylines with
`strokePolyline()`, and text with `text()`, or `richText()` in spans of any
style, which `measureText()` measures first, to center or align it:

```php
$view = function (Context $c) {
    $accent = $c->theme->accent;
    $c->column()->height(120)->draw(function (Painter $p, array $r) use ($accent) {
        [$x0, $y0, $w, $h] = $r;
        $wave = new Path();
        for ($i = 0; $i <= 100; $i++) {
            $x = $x0 + $w * $i / 100;
            $y = $y0 + $h / 2 + 40 * sin($i / 8);
            $i === 0 ? $wave->moveTo($x, $y) : $wave->lineTo($x, $y);
        }
        $p->strokePath($wave, 2, $accent);
    });
    $c->end();
};
```

```php
$view = function (Context $c) {
    $muted = $c->theme->textMuted;
    $c->column()->size(200, 40)->draw(function (Painter $p, array $r) use ($muted) {
        [$x, $y, $w, $h] = $r;
        $label = new Span('42%', weight: 600, color: $muted);
        [$lw, $lh] = $p->measureText(0, $label);
        $p->richText($x + ($w - $lw) / 2, $y + ($h - $lh) / 2, 0, $label);
    });
    $c->end();
};
```

Paragraphs a view lays out itself, as the pages of a book reader, are
shaped once with `$c->shapeRichText()`, justified and indented if need be,
and drawn with `Painter::textLayout()`; their lines tell where each line
is, to break pages between them (see [retained text
geometry](text-input-client.md#retained-text-geometry)).

Draw functions only paint: MyPHP may call them more than once a frame.
`Painter::icon()` and `Painter::image()` draw SVGs and images,
`Painter::imageRegion()` parts of images (see [Parts of
images](#parts-of-images)), and `Painter::focusRing()` the ring of the
keyboard focus, for widgets that draw their own. `Painter::theme()` is the
theme the node is painted in.

`Path::trim($from, $to)` keeps the part of a path's length from `$from` to
`$to`, as fractions of it, which draws a path in as `$to` animates from 0
to 1, as SwiftUI's `trim` and Motion's `pathLength`:

```php
$app = new class { public bool $done = true; };
$view = function (Context $c) use ($app) {
    $accent = $c->theme->accent;
    $box = $c->column()->size(24, 24);
    $c->end();
    $drawn = $c->animateTo($box, 'check', $app->done ? 1.0 : 0.0, Animation::smooth());
    $box->draw(function (Painter $p, array $r) use ($drawn, $accent) {
        [$x, $y] = $r;
        $check = (new Path())->moveTo($x + 4, $y + 12)->lineTo($x + 10, $y + 18)->lineTo($x + 20, $y + 6);
        $p->strokePath($check->trim(0, $drawn), 2, $accent);
    });
};
```

A stroke has round ends and corners unless its path sets them, as SVG's
`stroke-linecap`, `stroke-linejoin` and `stroke-miterlimit` do:
`cap()` ends it flat (`Path::CAP_BUTT`) or square (`Path::CAP_SQUARE`), and
`join()` turns its corners in points (`Path::JOIN_MITER`, bevelled past
`miterLimit()`) or cuts them (`Path::JOIN_BEVEL`). `dash($offset,
...$lengths)` strokes it in dashes, as `stroke-dasharray` and
`stroke-dashoffset` do, and they stay where they are as `trim()` draws the
path in:

```php
$view = function (Context $c) {
    $muted = $c->theme->textMuted;
    $c->column()->height(20)->draw(function (Painter $p, array $r) use ($muted) {
        [$x, $y, $w, $h] = $r;
        $edge = (new Path())->moveTo($x, $y + $h / 2)->lineTo($x + $w, $y + $h / 2);
        $p->strokePath($edge->cap(Path::CAP_BUTT)->dash(0, 6, 4), 1.5, $muted);
    });
    $c->end();
};
```

## Parts of images

An image can hold many pictures: the icons of a sheet, the frames of an
animation, the cells of a contact sheet. `Painter::imageRegion()` draws one
part of it, `$src` in the image's pixels, into a rectangle; nothing of the
image around the part shows, smooth or pixelated. Its `ImageOptions`
mirror the part (`flipX`, `flipY`) and tint it (`tint` multiplies its
colors, `tintAlpha` its alpha):

```php
$icons = Image::fromPixels(64, 16, str_repeat("\xff\xff\xff\xff", 64 * 16)); // 16×16 icons in a row
$icon = fn(int $i) => [$i * 16, 0, 16, 16];
$view = function (Context $c) use ($icons, $icon) {
    $muted = $c->theme->textMuted;
    $c->column()->size(24, 24)->draw(function (Painter $p, array $r) use ($icons, $icon, $muted) {
        [$x, $y] = $r;
        $p->imageRegion($icons, $icon(3), [$x, $y, 24, 24], new ImageOptions(tint: $muted));
    });
    $c->end();
};
```

A part outside what the painter shows draws nothing, so a view of many can
draw them all without culling them itself, as games draw their sprites and
tiles.

## Canvases

A `Canvas` is an image a painter draws, on the GPU: render to texture, as
Skia's `SkSurface` and LÖVE's `Canvas`. `draw()` draws it anew during a
frame, with a painter in its pixels, and it keeps what it holds, showing
anywhere an image does through `bitmap()`:

```php
$grid = new Canvas(800, 400);
$drawn = false;
$view = function (Context $c) use ($grid, &$drawn) {
    $c->column()->fill()->draw(function (Painter $p, array $r) use ($grid, &$drawn) {
        if (!$drawn) { // once, then every frame for the price of an image
            $drawn = true;
            $grid->draw($p, function (Painter $cp) {
                for ($x = 0; $x < 800; $x += 40) { $cp->line($x, 0, $x, 400, 1, 0x888888, 0.3); }
            });
        }
        $p->image($grid->bitmap(), $r, Node::FILL_BOX);
    });
    $c->end();
};
```

A canvas drawn with a tint or an opacity fades or colors a whole layer at
once. Canvases show other canvases, and text. A canvas's pixels are its
units: draw it at the window's scale (`Painter::scale()`) for one as sharp
as the window, or smaller and make its bitmap `pixelated()` to scale pixel
art up crisp (the [game plugin](../plugins/game.md) shows one at whole
multiples). A [shader](shaders.md) reads canvases as images, and draws
into them.

## Transforms

`Painter::transform($t, $ox, $oy, $fn)` transforms what `$fn` draws by
`$t`, a `Transform` such as `Transform::translation()`, `scaling()` or
`rotation()`, around the point `($ox, $oy)`, which stays where it is.
Transforms nest, after the painter's own, so a view that pans and zooms,
as a map or a diagram, draws its content through one, and turns a part of
it inside:

```php
$doc = new class {
    public float $panX = 0, $panY = 0, $zoom = 1.5;
    public array $nodes = [[[20, 20, 80, 40], 0x60a5fa]];
    public array $dial = ['x' => 200, 'y' => 120, 'angle' => 30];
};
$view = function (Context $c) use ($doc) {
    $accent = $c->theme->accent;
    $c->column()->fill()->draw(function (Painter $p, array $r) use ($doc, $accent) {
        $view = Transform::translation(-$doc->panX, -$doc->panY)->then(Transform::scaling($doc->zoom, $doc->zoom));
        $p->transform($view, $r[0], $r[1], function () use ($p, $doc, $accent) {
            foreach ($doc->nodes as [$box, $color]) {
                $p->fill($box, $color, 6); // culled where the view does not look
            }
            ['x' => $cx, 'y' => $cy] = $doc->dial;
            $p->transform(Transform::rotation($doc->dial['angle']), $cx, $cy, function () use ($p, $cx, $cy, $accent) {
                $p->line($cx, $cy, $cx, $cy - 40, 2, $accent);
            });
        });
    });
    $c->end();
};
```

Clips made inside turn with what they clip. Input is not transformed: the
pointer comes in the node's coordinates, which the app maps to its content
with the inverse of its view. Under a transform, images and their parts
are placed where they are rather than on whole pixels, so that one moving
through a zoomed view glides.

## Shaping text

Text that a widget lays out itself, as in the cells of a grid, is shaped
once with `$c->shape()`, which returns glyphs placed along a line by the
system's text engine, with ligatures, kerning and fallback fonts, and the
runes each comes from (`cluster`). Move their `x` and draw them with
`Painter::glyphs()`; `$c->fontMetrics()` returns the font's ascent,
descent and line gap, and `Painter::scale()` the device pixels of a point,
to line things up with the display's pixels. `shape()` caches nothing,
unlike the text of nodes: keep the glyphs of text drawn in many frames. A
`TextStyle`'s `features` turns OpenType features on and off.

```php
$view = function (Context $c) {
    $font = new TextStyle();
    $font->family = 'monospace';
    $font->size = 13;
    $cellWidth = 8.0;
    $glyphs = $c->shape('grid', $font);
    foreach ($glyphs as $g) {
        $g->x = $g->cluster * $cellWidth; // one per cell
    }
    $ascent = $c->fontMetrics($font)['ascent'];
    $text = $c->theme->text;
    $c->column()->height(20)->draw(function (Painter $p, array $r) use ($glyphs, $ascent, $text) {
        $p->glyphs($glyphs, $r[0], $r[1] + $ascent, $text);
    });
    $c->end();
};
```

## Animation

For motion, `$c->animate($node, $key, $target, $seconds)` returns a value
that eases to a target and draws frames until it gets there:

```php
$app = new class { public bool $sidebar = true; };
$view = function (Context $c) use ($app) {
    $panel = $c->column()->clip();
      $c->text('Sidebar');
    $c->end();
    $panel->width($c->animate($panel, 'width', $app->sidebar ? 280 : 0, 0.2));
};
```

`$c->animateWith()` takes the easing: `Easing::linear(...)`,
`Easing::easeIn(...)`, `Easing::easeOut(...)` (as `animate()`),
`Easing::easeInOut(...)`, `Easing::cubicBezier($x1, $y1, $x2, $y2)`, any
`Closure(float): float`, or `Easing::bounce($e)`, which goes along `$e`
and comes back. `$c->animateTo()` takes springs as well (see
[Motion](motion.md)). `$c->loop()` returns the progress of an animation
that starts over every period, for spinners and pulses; `rotate()` turns a
node, as an icon:

```php
$loader = SVG::fromFile('icons/loader.svg');
$view = function (Context $c) use ($loader) {
    $t = $c->theme;
    $spin = $c->icon($loader);
    $spin->rotate($c->loop($spin, 'spin', 1.0, Easing::linear(...)) * 360);

    $skeleton = $c->column()->height(14)->radius(7)->background($t->border);
    $c->end();
    $skeleton->opacity(0.4 + 0.6 * $c->loop($skeleton, 'pulse', 1.6, Easing::bounce(Easing::easeInOut(...))));
};
```

To animate in other ways, compute from `$c->now()` (nanoseconds) and call
`$c->animationFrame()` in every frame that moves: MyPHP draws the next
frame when the display can show it, and draws nothing while nothing
changes.

A drawing that moves while the layout stays, as a spinner or a chart
scrolling by, animates from its `draw()` instead: compute it from
`$p->now()` and call `$p->animationFrame()` while it moves, or
`$p->after($seconds)` when it changes in steps. Unless something else
changed, those frames paint the nodes of the last frame again without
running the view, so they cost only the painting; and a node out of view is
not painted, so it asks for none. While the window is out of sight (hidden,
minimized, or covered by other windows), MyPHP draws no frames at all, from
`draw()` or the view, until some of it shows again: compute what moves from
the time, as here, and it shows where the time puts it (see
[Motion](motion.md#out-of-sight)):

```php
$view = function (Context $c) {
    $accent = $c->theme->accent;
    $c->column()->height(4)->draw(function (Painter $p, array $r) use ($accent) {
        [$x0, $y, $w, $h] = $r;
        $p->animationFrame();
        $x = $x0 + ($w - 20) * (intdiv($p->now(), 1_000_000) % 1000) / 1000;
        $p->fill([$x, $y, 20, $h], $accent, 2);
    });
    $c->end();
};
```

A drawing that looks as smooth at fewer frames, as a record turning, a
slow wave or a level meter, can ask for them with
`$p->animationFrameRate($fps)` in place of `$p->animationFrame()`: the
window paints it again that often, rather than at each of the display's
refreshes (120 a second on ProMotion displays), and on macOS has the
display refresh as often, which saves power. The rate rounds up to one the
display shows evenly, a whole number of refreshes a frame: 60, 40 or 30
frames a second on a 120 Hz display, 60 or 30 on a 60 Hz one. The window
draws at the fastest rate anything in it asks for, so a drawing asking for
`animationFrame()` or a view that animates still draws at the display's.
Pacing with `$p->after(1 / 60)` instead draws unevenly: a frame asked for
later than a refresh waits for the next.

Such a `draw()` sees the state as the view last ran: what it reads that
changes otherwise is the view's to build anew, after `Window::update()`.

When the desktop asks for less motion (`$c->preferences()->reduceMotion`),
`animate()` and `animateWith()` go to their target at once. `loop()` goes
on, as the system's spinners do: it shows that something is going on.
Motion you compute yourself should read the preference too.

[Motion](motion.md) has springs that keep their velocity, delays, values
following the pointer, keyframes, and how to tell when an animation is
done; [Transforms](transforms.md) move, scale and rotate any node.
