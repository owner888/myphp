# GPU Shaders

MyPHP includes a pure-PHP shader compiler (`MyPHP\UI\Shader\Shader`) that compiles shaders written in Kage (Go-flavored shader syntax) directly into the native shading language of each platform:
- **Metal Shading Language (MSL)** on macOS and iOS
- **HLSL (Shader Model 5.0)** on Windows Direct3D 11
- **GLSL 330 / GLSL ES 300** on Linux and Android

## View shader modifiers (`colorEffect`, `distortionEffect`, `layerEffect`)

Apply compiled shaders directly to any declarative `View` using SwiftUI's shader modifiers:

- **`->colorEffect($shader, uniforms: [...])`** — transforms each pixel's color (`func ColorEffect(pos vec2, color vec4) vec4`).
- **`->distortionEffect($shader, maxSampleOffset: new CGSize($w, $h), uniforms: [...])`** — warps pixel coordinates (`func DistortionEffect(pos vec2) vec2`).
- **`->layerEffect($shader, maxSampleOffset: new CGSize($w, $h), uniforms: [...])`** — samples arbitrary neighbors from the rendered layer (`func LayerEffect(pos vec2) vec4`).

```php
use MyPHP\UI\Shader\Shader;

$wave = Shader::compile(<<<'GO'
package main

var Time float

func DistortionEffect(pos vec2) vec2 {
	return vec2(pos.x, pos.y + 3*sin(pos.x/16 + Time))
}
GO);

$view = fn() => Text('Wavy text')
    ->font(Font::title)
    ->bold()
    ->distortionEffect($wave, maxSampleOffset: new CGSize(0, 6), uniforms: ['Time' => 1.0])
    ->padding();
```

## Drawing shaders in a `Canvas`

Inside a `Canvas`, use `$g->withPainter()` to fill rectangles, paths, or text with a fragment shader (`func Fragment(dst vec4, pos vec2) vec4`):

```php
use MyPHP\UI\Shader\{Options, Shader};

$waves = Shader::compile(<<<'GO'
package main

var Time float

func Fragment(dst vec4, pos vec2) vec4 {
	v := 0.5 + 0.5*sin(pos.x/20 + Time)
	return vec4(v*0.2, v*0.4, v, 1)
}
GO);

$view = fn() => TimelineView()(fn(TimelineViewContext $ctx) =>
    Canvas(fn(GraphicsContext $g) => $g->withPainter(function (Painter $p, array $r) use ($waves, $ctx) {
        $waves->draw($p, $r, new Options(uniforms: ['Time' => $ctx->nanoseconds / 1e9]));
    }))->frame(height: 120)->cornerRadius(8)
);
```

See [`examples/shaders`](../../examples/shaders), [`examples/chat-glow`](../../examples/chat-glow), and [`examples/blackhole`](../../examples/blackhole) for complete shader examples.
