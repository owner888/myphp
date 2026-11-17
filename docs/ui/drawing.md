# Drawing and Canvas

`Canvas(fn(GraphicsContext $g, CGSize $size) => …)` draws custom 2D vector graphics in a declarative view, as SwiftUI's `Canvas`: paths filled and stroked with colors, at the context's opacity. Wrap a `Canvas` inside a `TimelineView` to animate it at the display's refresh rate.

```php
$view = fn() => Canvas(function (GraphicsContext $g, CGSize $size) {
    $wave = new Path();
    for ($i = 0; $i <= 100; $i++) {
        $x = $size->width * $i / 100;
        $y = $size->height / 2 + 40 * sin($i / 8);
        $i === 0 ? $wave->moveTo($x, $y) : $wave->lineTo($x, $y);
    }
    $g->stroke($wave, with: Color::accentColor, lineWidth: 2);
})->frame(height: 120)->padding();
```

## Paths, caps, joins and dashes

Build paths with `(new Path())->moveTo($x, $y)->lineTo(…)->quadTo(…)->cubicTo(…)->close()`, or the helpers `Path::rect()`, `Path::roundedRect()`, `Path::circle()`, and `Path::ellipse()`.

- **`trim($from, $to)`** keeps the fraction `[$from, $to]` of a path's length, animating a stroke drawing itself in as `$to` goes from `0` to `1`.
- **`cap()`** sets `Path::CAP_ROUND` (default), `Path::CAP_BUTT`, or `Path::CAP_SQUARE`.
- **`join()`** sets `Path::JOIN_ROUND` (default), `Path::JOIN_MITER` (with `miterLimit()`), or `Path::JOIN_BEVEL`.
- **`dash($offset, ...$lengths)`** strokes the path with a dash pattern that stays anchored as `trim()` grows.

```php
$view = fn() => Canvas(function (GraphicsContext $g, CGSize $size) {
    $edge = (new Path())->moveTo(0, $size->height / 2)->lineTo($size->width, $size->height / 2);
    $g->stroke($edge->cap(Path::CAP_BUTT)->dash(0, 8, 4), with: Color::secondary, lineWidth: 2);
})->frame(height: 24)->padding();
```

## Direct Painter access (`withPainter`)

For rich text measurement, multi-stop or conic/radial gradients, image regions, or custom GPU shaders inside a `Canvas`, call `$g->withPainter(function (Painter $p, array $r) { … })`, where `$r` is `[$x, $y, $w, $h]` in window points:

```php
$view = fn() => Canvas(function (GraphicsContext $g, CGSize $size) {
    $g->withPainter(function (Painter $p, array $r) {
        [$x, $y, $w, $h] = $r;
        $label = new Span('42%', weight: 600, color: $p->theme()->textMuted);
        [$lw, $lh] = $p->measureText(0, $label);
        $p->richText($x + ($w - $lw) / 2, $y + ($h - $lh) / 2, 0, $label);
    });
})->frame(width: 200, height: 40);
```

## Offscreen textures (`MyPHP\UI\Canvas`)

When you need a persistent pixel canvas or a fixed-resolution framebuffer (up to `8192×8192`, for pixel art, paint tools, or 3D scene composition with `$canvas->renderScene($scene)`), create a `new \MyPHP\UI\Canvas($width, $height)` in your state object and display it with `Image($canvas->image())` (see [`examples/game`](../../examples/game) and [`examples/model-viewer`](../../examples/model-viewer)).
