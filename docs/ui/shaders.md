# Shaders

> This page is about the engine under the views: its builder, `$c` a
> `Context`, which an app reaches inside a `Representable(fn(Context $c) =>
> …)`, as SwiftUI's `NSViewRepresentable` ([custom widgets](custom-widgets.md)). For views, `->colorEffect()`, `->distortionEffect()` and `->layerEffect()`.

A shader computes the color of every pixel of a rectangle, on the GPU,
from its position, values the app passes it each frame and images it
reads: noise and gradients that move, a texture lit pixel by pixel, a
ripple across the window. `MyPHP\UI\Shader\Shader` compiles shaders written
in Go's syntax (Kage), in PHP, and draws them on the GPU renderer that
draws the window (Metal, OpenGL).

```php
use MyPHP\UI\Shader\{Options, Shader};

$waves = Shader::compile(<<<'GO'
package main

var Time float

func Fragment(dst vec4, pos vec2) vec4 {
	v := 0.5 + 0.5*sin(pos.x/20+Time)
	return vec4(v*0.2, v*0.4, v, 1)
}
GO);
$start = hrtime(true);

$view = function (Context $c) use ($waves, $start) {
    $c->column()->fill()->draw(function (Painter $p, array $r) use ($waves, $start) {
        $waves->draw($p, $r, new Options(uniforms: ['Time' => ($p->now() - $start) / 1e9]));
        $p->animationFrame();
    });
    $c->end();
};
```

`draw()` fills a rectangle of the painter, clipped, transformed and faded
with it as anything it paints; `Options::$radius` rounds its corners.

## Text, paths and icons

A shader also fills shapes in place of a color, as a gradient does, and as
SwiftUI fills a shape or text with a shader: `fillPath()` and
`strokePath()` paint a `Path`, `text()` a line of text, and `fill()`
whatever text, paths and icons a function paints, in a box of your own:

```php
use MyPHP\UI\Shader\{Options, Shader};

$waves = Shader::compile("package main\n\nvar Time float\n\nfunc Fragment(dst vec4, pos vec2) vec4 {\n\treturn vec4(0.2, 0.4, 0.5+0.5*sin(pos.x/20+Time), 1)\n}\n");
$circle = (new Path())->circle(60, 60, 50);
$view = function (Context $c) use ($waves, $circle) {
    $c->column()->fill()->draw(function (Painter $p, array $r) use ($waves, $circle) {
        $opts = new Options(uniforms: ['Time' => 1.5]);
        $waves->fillPath($p, $circle, $opts);
        $waves->text($p, 140, 40, 'Hello', 48, $opts);
        $waves->fill($p, [140, 100, 200, 40], $opts, function () use ($p) {
            $p->richText(140, 100, 200, new Span('and more', size: 24, weight: 700));
        });
    });
    $c->end();
};
```

Each pixel shows the shader's color times how much of it the shape covers,
the edges of text corrected as the platform's text is for that color.
`pos` runs across the shape's box (the path's, grown by half the stroke's
width, the text's, or the one `fill()` takes), which `rectSize()` returns;
emoji keep their own colors, and rectangles and images `fill()`'s function
paints paint as they would.

## Effects on nodes

A shader can also draw a node from what it renders, as SwiftUI's
`colorEffect`, `distortionEffect` and `layerEffect` do. Its entry point says
which, in place of `Fragment`:

```go
// The content's colors, changed pixel by pixel.
func ColorEffect(pos vec2, color vec4) vec4

// Where to read the content for the pixel at pos.
func DistortionEffect(pos vec2) vec2

// The pixel's color, from the content read anywhere.
func LayerEffect(pos vec2) vec4
```

`effect()` on a node takes the shader's `effect()`:

```php
use MyPHP\UI\Shader\{Options, Shader};

$wave = Shader::compile("package main\n\nvar Time float\n\nfunc DistortionEffect(pos vec2) vec2 {\n\treturn pos + vec2(0, 6*sin(pos.x/12+Time))\n}\n");
$view = function (Context $c) use ($wave) {
    $c->text('Wavy')->fontSize(24)->effect($wave->effect(new Options(
        uniforms: ['Time' => 0.5],
        maxSampleOffset: [0, 6],
    )));
};
```

The node and its children, with their shadows and what their `draw()`
callbacks paint, render into a bitmap on the GPU every frame the node
paints, and the effect draws over it in place of the node. `pos` runs from
the corner of the node's box, whose size `rectSize()` returns. A
`LayerEffect` reads the content with `layerAt(p)`, its pixel at `p`, or
`layerSample(p)`, blended between pixels, `p` in points as `pos` is; a
`DistortionEffect` reads it with `layerSample`. Content reads as
transparent beyond what renders: `Options::$maxSampleOffset` renders that
much more around the box, for an effect that reads or moves pixels that
far, and the effect draws over it all. The node stays where it is for
clicks, focus and accessibility: only what shows changes. An effect's
shader reads images 0 to 2 of its own; image 3 is the content.

## The backdrop

A shader can read what the window drew behind it, as Flutter's
`BackdropFilter` with a shader does: frosted panes, lenses, a tint that
follows what scrolls under it. `backdropAt(p)` returns its pixel at `p`,
and `backdropSample(p)` blends the pixels around `p`, `p` in points as
`pos` is; `Options::$backdropBlur` blurs it first, its standard deviation
in points:

```php
use MyPHP\UI\Shader\{Options, Shader, Values};

$frost = Shader::compile("package main\n\nvar Tint vec4\n\nfunc Fragment(dst vec4, pos vec2) vec4 {\n\tb := backdropSample(pos)\n\treturn mix(b, Tint, Tint.a)\n}\n");
$tint = $frost->uniform('Tint');
$values = $frost->newValues()->color($tint, 0xffffff, 0.3);
$view = function (Context $c) use ($frost, $values) {
    $c->column()->fill()->draw(function (Painter $p, array $r) use ($frost, $values) {
        $frost->draw($p, $r, new Options(backdropBlur: 12, values: $values, radius: 16));
    });
    $c->end();
};
```

The renderers capture the backdrop as they do for the glass plugin's
panes, every frame, so it follows whatever moves behind. A node's effect
reads it too. A shader reading its backdrop draws rectangles (`draw()`,
`effect()`), not shapes (`fill()`), and reads images 0, 1 and 3 of its own;
image 2 is the backdrop.

## The language

A shader is a Go file of package main. Its function `Fragment` returns the
pixel's color, premultiplied (each color component multiplied by the
alpha):

```go
func Fragment(dst vec4, pos vec2) vec4
```

`dst` is the pixel's center in pixels of the window, or of the canvas
drawn into, and `pos` the same point in points from the rectangle's top
left corner. `Fragment` may take `dst` alone, or nothing.

The language is [Ebitengine's Kage](https://ebitengine.org/en/documents/shader.html),
whose compiler MyPHP's is ported from, by way of mygo's: Go's syntax, with
these types:

| Types | |
| --- | --- |
| `bool`, `int`, `uint`, `float` | scalars of 32 bits |
| `vec2`, `vec3`, `vec4` | vectors of floats, with swizzles (`v.xy`, `c.rgb`) |
| `ivec2` to `ivec4`, `uvec2` to `uvec4` | vectors of ints and uints |
| `mat2`, `mat3`, `mat4` | matrices of floats, by columns |
| `[N]T` | arrays of any of them |

Functions of your own, `if`, `for` loops (to bounds that need not be
constants, and `range` over an int), constants, and GLSL's built-in
functions: `sin`, `cos`, `atan2`, `pow`, `exp`, `sqrt`, `abs`, `floor`,
`fract`, `mod`, `min`, `max`, `clamp`, `mix`, `step`, `smoothstep`,
`length`, `distance`, `dot`, `cross`, `normalize`, and the others. ints and
uints are two kinds of integers that mix only in shifts, as in Go; uints
wrap around, which hashes need:

```go
func pcg(v uint) uint {
	s := v*747796405 + 2891336453
	w := ((s >> ((s >> 28) + 4)) ^ s) * 277803737
	return (w >> 22) ^ w
}
```

`discard()` leaves the pixel as it was. `dfdx`, `dfdy` and `fwidth` give
how a value changes to the next pixel.

## Uniforms

Exported variables at the package level are uniforms: values the app sets
for each draw in `Options::$uniforms`, by name. A number sets a scalar; an
array of numbers sets a vector, a matrix (by columns) or an array (elements
in order). A uniform the options leave out is zero.

```go
var Taps [6]vec4 // x, y, when, how hard
var Count int
```

```php
use MyPHP\UI\Shader\{Options, Shader};

$fx = Shader::compile("package main\n\nvar Taps [6]vec4\nvar Count int\n\nfunc Fragment(dst vec4, pos vec2) vec4 {\n\treturn vec4(float(Count)/6, 0, 0, 1)\n}\n");
$ripples = [['x' => 10, 'y' => 20, 'at' => 0.5, 'force' => 1]];
$view = function (Context $c) use ($fx, $ripples) {
    $c->column()->fill()->draw(function (Painter $p, array $r) use ($fx, $ripples) {
        $taps = [];
        foreach ($ripples as $t) {
            array_push($taps, $t['x'], $t['y'], $t['at'], $t['force']);
        }
        $fx->draw($p, $r, new Options(uniforms: ['Taps' => $taps, 'Count' => count($ripples)]));
    });
    $c->end();
};
```

For values set every frame, `Values` sets them by variables looked up once,
with no array and no conversions each draw, as Flutter's `FragmentShader`
and Skia's `SkRuntimeShaderBuilder` hold them:

```php
use MyPHP\UI\Shader\{Options, Shader};

$waves = Shader::compile("package main\n\nvar Time float\nvar Tint vec4\n\nfunc Fragment(dst vec4, pos vec2) vec4 {\n\treturn Tint * (0.5 + 0.5*sin(Time))\n}\n");
$timeVar = $waves->uniform('Time'); // throws for a name the shader lacks
$tintVar = $waves->uniform('Tint');
$values = $waves->newValues();
$view = function (Context $c) use ($waves, $timeVar, $tintVar, $values) {
    $accent = $c->theme->accent;
    $c->column()->fill()->draw(function (Painter $p, array $r) use ($waves, $timeVar, $tintVar, $values, $accent) {
        $values->float($timeVar, $p->now() / 1e9)->color($tintVar, $accent);
        $waves->draw($p, $r, new Options(values: $values));
    });
    $c->end();
};
```

Each setter checks the variable's type (`float()`, `vec2()` to `vec4()`,
`int()`, `uint()`, `bool()`, and `floats()`, `ints()` and `uints()` for
vectors, matrices and arrays) and throws for another, as for too many
numbers; drawing copies the values, so one `Values` serves every draw.
`Options::$uniforms` then sets the names it holds over them.

A color (`Values::color()`) sets a `vec3` or `vec4` to its components from
0 to 1, not premultiplied, in sRGB; where the window draws a wide gamut, a
color outside sRGB, as `Oklch::rgb()` makes, reaches the shader in extended
sRGB (components below 0 or above 1), as the window's own colors do.

## Images

A shader reads up to four images, `Options::$images`: images, or
canvases' (`Canvas::bitmap()`), in their pixels from their top left
corners:

| Function | |
| --- | --- |
| `image0At(p vec2) vec4` | the pixel at `p`, transparent outside the image |
| `image0Sample(p vec2) vec4` | blended between the four pixels around `p`, clamped to the edges |
| `image0Size() vec2` | the image's size in pixels |

and `image1At` to `image3At`, and so on. `rectSize()` returns the size of
the rectangle the shader fills, in points, and `pixelScale()` the pixels a
point.

A shader drawing into a [canvas](drawing.md#canvases) makes the input of
another, as the passes of an effect do: one computes a field at a few
samples into a small canvas, the next reads it, sampled, at every pixel of
the window.

<!-- no-test -->
```php
$field = new Canvas($cols, $rows);

$box->draw(function (Painter $p, array $r) use ($field, $windShader, $glyphShader, $wind, $atlas, $cols, $rows) {
    $field->draw($p, function (Painter $cp) use ($windShader, $wind, $cols, $rows) {
        $windShader->draw($cp, [0, 0, $cols, $rows], new Options(uniforms: $wind));
    });
    $glyphShader->draw($p, $r, new Options(images: [$field->bitmap(), $atlas, null, null]));
});
```

## Errors and cost

`Shader::compile()` throws for what is wrong with a shader, with its
lines; compile shaders once, as the app starts. It checks the shader and
translates it into the GPU's language (Metal's or GLSL), which the GPU's
driver then compiles. Metal keeps its libraries on disk, so later launches
have them at once; Linux's Mesa drivers keep theirs too (in
`~/.cache/mesa_shader_cache`). A shader costs little to draw on a GPU,
whatever it computes per pixel.

`draw()` throws on a uniform set to a value that is not a number.
