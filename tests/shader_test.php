<?php
// Shaders an app draws with (mygo's ui/shader tests, without a GPU): what
// compiling checks, the uniforms' words in their lanes, the quads and the
// runs a draw, a fill and an effect add, and the library Metal compiles.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Canvas, Context, DisplayList, Image, Node, Painter, Path, Theme};
use MyPHP\UI\Shader\{Options, Shader};
use MyPHP\UI\Shader\Compiler\ParseError;

$kindOf = fn(DisplayList $l, int $i): int => (int)round($l->buf[$i * 16 + 9] - DisplayList::KIND_STRIDE * floor($l->buf[$i * 16 + 9] / DisplayList::KIND_STRIDE));
/** The float of the word at $lane of $bytes. */
$floatAt = fn(string $bytes, int $lane): float => unpack('g', $bytes, $lane)[1];
$intAt = fn(string $bytes, int $lane): int => unpack('V', $bytes, $lane)[1];
$throws = function (\Closure $fn, string $class, string $what, string $has = ''): void {
    try {
        $fn();
        check(false, "$what: no exception");
    } catch (\Throwable $e) {
        check($e instanceof $class && ($has === '' || str_contains($e->getMessage(), $has)), "$what: " . get_class($e) . ': ' . $e->getMessage());
    }
};

$waves = Shader::compile(<<<'KAGE'
package main

var Time float
var Tint vec4
var Count int
var Flags uint
var On bool
var Taps [2]vec2

func Fragment(dst vec4, pos vec2) vec4 {
	v := 0.5 + 0.5*sin(pos.x/20+Time)
	if On && Count > 0 && Flags > 0 {
		v += Taps[1].y
	}
	return vec4(v*0.2, v*0.4, v, 1)*Tint
}
KAGE);

// The library Metal compiles: the shader's code, then the renderer's tail.
$src = $waves->code->source;
check(str_contains($src, 'float4 shaderFragment(constant Uniforms& uniforms') && str_contains($src, 'fragment float4 shader_fragment(') && str_contains($src, 'vertex MyphpV shader_vertex('), 'shader: the library, the shader then the tail');
same(count($waves->code->lanes), 23 + 1 + 4 + 1 + 1 + 1 + 4, 'shader: a lane a word, the bridge\'s first');

// Drawing: a quad of kind 16 whose u names the draw, its uniforms in their lanes.
$frame = function (\Closure $draw, int $w = 300, int $h = 200): DisplayList {
    $h = new Headless(function (Context $c) use ($draw): void {
        $c->column()->fill()->draw(function (Painter $p, array $r) use ($draw): void {
            $draw($p, $r);
        });
        $c->end();
    }, $w, $h);
    return $h->paint();
};
$list = $frame(fn(Painter $p) => $waves->draw($p, [10, 20, 100, 50], new Options(uniforms: ['Time' => 1.5, 'Tint' => 0x336699, 'Count' => 3, 'Flags' => -1, 'On' => true, 'Taps' => [1, 2, 3, 4], 'Nope' => 1], radius: 6)));
same(1, count($list->shaders), 'draw: one draw');
same([[$list->n - 1, 1, 0]], $list->shaderRuns, 'draw: its run, the last quad');
$q = ($list->n - 1) * 16;
same([10.0, 20.0, 100.0, 50.0, 6.0, (float)DisplayList::KIND_SHADER, 0.0], [$list->buf[$q], $list->buf[$q + 1], $list->buf[$q + 2], $list->buf[$q + 3], $list->buf[$q + 8], $list->buf[$q + 9], $list->buf[$q + 10]], 'draw: the rect, the radius, kind 16 and its draw');
[$code, $bytes, $wide, $images] = $list->shaders[0];
$l = $code->lanes;
same(strlen($bytes), $code->size, 'draw: the uniforms\' bytes, their buffer\'s size');
same([100.0, 50.0, 1.0], [$floatAt($bytes, $l[8]), $floatAt($bytes, $l[9]), $floatAt($bytes, $l[10])], 'draw: rectSize and pixelScale');
same(1.5, $floatAt($bytes, $l[23]), 'draw: a float');
closeFloat(0x33 / 255, $floatAt($bytes, $l[24]), 'draw: a color\'s red, from 0 to 1');
same(1.0, $floatAt($bytes, $l[27]), 'draw: its alpha');
same([3, 0xffffffff], [$intAt($bytes, $l[28]), $intAt($bytes, $l[29])], 'draw: an int, and a uint by its low 32 bits');
same("\1", $bytes[$l[30]], 'draw: a bool, a byte');
same([1.0, 2.0, 3.0, 4.0], array_map(fn(int $i) => $floatAt($bytes, $l[31 + $i]), [0, 1, 2, 3]), 'draw: an array of vec2, element by element');
same(null, $wide, 'draw: sRGB colors, no wide twin');
same([null, null, null, null], $images, 'draw: no images');
$throws(fn() => $frame(fn(Painter $p) => $waves->draw($p, [0, 0, 10, 10], new Options(uniforms: ['Time' => 'soon']))), \InvalidArgumentException::class, 'draw: a uniform set to a string', 'Time');

// A wide color: the bytes a wide gamut reads, in extended sRGB, beside the sRGB ones.
$p3 = \MyPHP\UI\Oklch::rgb(0.7, 0.3, 145);
$list = $frame(fn(Painter $p) => $waves->draw($p, [0, 0, 50, 50], new Options(uniforms: ['Tint' => $p3])));
[, $bytes, $wide] = $list->shaders[0];
check($wide !== null && $floatAt($wide, $l[24]) < 0.0 && $floatAt($bytes, $l[24]) >= 0.0, 'draw: a wide color\'s red below 0 for a wide gamut, its sRGB twin\'s else');

// Values: set by variables, checked by type, copied by a draw.
$v = $waves->newValues()->float($waves->uniform('Time'), 2.0)->ints($waves->uniform('Count'), 7)->color($waves->uniform('Tint'), 0xff0000, 0.5);
$list = $frame(fn(Painter $p) => $waves->draw($p, [0, 0, 40, 40], new Options(uniforms: ['Count' => 9], values: $v)));
[, $bytes] = $list->shaders[0];
same([2.0, 9, 1.0, 0.5], [$floatAt($bytes, $l[23]), $intAt($bytes, $l[28]), $floatAt($bytes, $l[24]), $floatAt($bytes, $l[27])], 'values: theirs, the map\'s over them, a color and its alpha');
$throws(fn() => $waves->uniform('Nope'), \InvalidArgumentException::class, 'uniform: a name the shader has not', 'no uniform variable Nope');
$throws(fn() => $waves->uniform('__scale'), \InvalidArgumentException::class, 'uniform: the bridge\'s', 'no uniform variable');
$throws(fn() => $waves->newValues()->float($waves->uniform('Tint'), 1), \InvalidArgumentException::class, 'values: a float for a vec4', 'Tint is a vec4, not a float');
$throws(fn() => $waves->newValues()->floats($waves->uniform('Taps'), 1, 2, 3, 4, 5), \InvalidArgumentException::class, 'values: too many numbers', 'holds 4');
$other = Shader::compile("package main\n\nvar Time float\n\nfunc Fragment(dst vec4, pos vec2) vec4 {\n\treturn vec4(Time)\n}\n");
$throws(fn() => $waves->newValues()->float($other->uniform('Time'), 1), \InvalidArgumentException::class, 'values: another shader\'s variable', 'not a uniform variable of the values\' shader');
$throws(fn() => $frame(fn(Painter $p) => $waves->draw($p, [0, 0, 9, 9], new Options(values: $other->newValues()))), \InvalidArgumentException::class, 'draw: values of another shader');

// Images: their sizes in the bridge's words, the images with the draw; a canvas has its place.
$img = Image::fromPixels(3, 2, str_repeat("\xff\x00\x00\xff", 6));
$cv = new Canvas(16, 8);
$reader = Shader::compile("package main\n\nfunc Fragment(dst vec4, pos vec2) vec4 {\n\treturn image0At(pos) + image1Sample(pos)\n}\n");
$list = $frame(fn(Painter $p) => $reader->draw($p, [0, 0, 20, 20], new Options(images: [$img, $cv->bitmap()])));
[$rc, $bytes, , $images] = $list->shaders[0];
same([3.0, 2.0, 16.0, 8.0], array_map(fn(int $i) => $floatAt($bytes, $rc->lanes[$i]), [0, 1, 2, 3]), 'images: their sizes, in pixels');
check($images[0] === $img && $images[1] === $cv->bitmap() && $images[2] === null, 'images: with the draw');
check($cv->slot !== null, 'images: a canvas read has its place');

// Fill: the glyphs' and paths' masks become the shader's, in runs; the rest paints as it would.
$list = $frame(function (Painter $p) use ($waves): void {
    $waves->fill($p, [5, 6, 100, 40], new Options, function () use ($p): void {
        $p->text(5, 6, 'Hi', 16, 0x000000);
        $p->fill([0, 0, 4, 4], 0x00ff00);
        $p->fillPath((new Path)->circle(50, 20, 10), 0x000000);
    });
});
$kinds = array_map(fn(int $i) => $kindOf($list, $i), range(0, $list->n - 1));
$glyphs = array_keys(array_filter($kinds, fn(int $k) => $k === DisplayList::KIND_SHADER_GLYPH));
check(count($glyphs) >= 3 && in_array(0, $kinds, true), 'fill: glyphs and the path filled, the rectangle as it is: ' . json_encode($kinds));
$g = $glyphs[0] * 16;
same([0.0, 5.0, 6.0], [$list->buf[$g + 4], $list->buf[$g + 5], $list->buf[$g + 6]], 'fill: a mask names its draw and the box\'s corner');
same(1, count($list->shaders), 'fill: one draw');
same(count($glyphs), array_sum(array_map(fn(array $r) => $r[1], $list->shaderRuns)), 'fill: the masks in runs');
$list = $frame(fn(Painter $p) => $waves->fill($p, [0, 0, 9, 9], new Options, fn() => $p->fill([0, 0, 4, 4], 0)));
same([[], []], [$list->shaders, $list->shaderRuns], 'fill: no mask, no draw');

// Effects: the node renders into a canvas, the effect draws over it, the content image3.
$wavy = Shader::compile("package main\n\nvar Time float\n\nfunc DistortionEffect(pos vec2) vec2 {\n\treturn pos + vec2(0, 6*sin(pos.x/12+Time))\n}\n");
$h = new Headless(function (Context $c) use ($wavy): void {
    $c->column(0, 0, 1)->fill()->alignItems(Node::CENTER)->justify(Node::CENTER);
      $c->text('Wavy', 20)->label('wavy')->effect($wavy->effect(new Options(uniforms: ['Time' => 1.0], maxSampleOffset: [0, 6])));
    $c->end();
}, 300, 200);
$list = $h->paint();
same(1, count($list->shaders), 'effect: one draw');
[$ec, $bytes, , $images] = $list->shaders[0];
$r = $h->rect($h->byLabel('wavy'));
check($images[3] instanceof Image && $images[3]->canvas !== null, 'effect: its content, a canvas\'s bitmap, image3');
same([$images[3]->w, $images[3]->h], [(int)(ceil($r[0] + $r[2]) - floor($r[0])), (int)(ceil($r[1] + $r[3] + 6) - floor($r[1] - 6))], 'effect: the content, the box and the margin on the pixel grid');
same([$r[2], $r[3]], [$floatAt($bytes, $ec->lanes[8]), $floatAt($bytes, $ec->lanes[9])], 'effect: rectSize the box\'s');
same([$r[0] - floor($r[0]), $r[1] - floor($r[1] - 6)], [$floatAt($bytes, $ec->lanes[11]), $floatAt($bytes, $ec->lanes[12])], 'effect: the box\'s corner in the content');
check(!in_array(1, array_map(fn(int $i) => $kindOf($list, $i), range(0, $list->n - 1)), true), 'effect: the text drawn in the canvas, not the window');
$throws(fn() => $wavy->draw(new Painter($list, new Metrics, new Theme), [0, 0, 9, 9]), \LogicException::class, 'effect: drawn as a fill', 'draws through effect()');
$throws(fn() => $waves->effect(), \LogicException::class, 'effect: of a Fragment', 'ColorEffect, DistortionEffect or LayerEffect');

// The backdrop: its draw splits the pass, as glass does, blurred in pixels.
$frost = Shader::compile("package main\n\nfunc Fragment(dst vec4, pos vec2) vec4 {\n\treturn backdropSample(pos)\n}\n");
check($frost->code->backdrop, 'backdrop: read');
$list = $frame(fn(Painter $p) => $frost->draw($p, [10, 10, 50, 50], new Options(backdropBlur: 4)));
same([[$list->n - 1, 4.0]], $list->glass, 'backdrop: a glass entry at its quad, its blur in pixels');
[$fc, $bytes] = $list->shaders[0];
same([1.0, 0.0, 10.0], array_map(fn(int $i) => $floatAt($bytes, $fc->lanes[13 + $i]), [0, 1, 2]), 'backdrop: its map to the window\'s pixels');
$throws(fn() => $frame(fn(Painter $p) => $frost->fill($p, [0, 0, 9, 9], new Options, fn() => null)), \LogicException::class, 'backdrop: filling shapes', 'fills rectangles');

// What compiling checks.
$throws(fn() => Shader::compile("package main\n\nfunc Fragment() vec4 { return vec4(1) }\nfunc ColorEffect(pos vec2, c vec4) vec4 { return c }\n"), \InvalidArgumentException::class, 'compile: two entry points', 'Fragment and ColorEffect');
$throws(fn() => Shader::compile("package main\n\nfunc LayerEffect(pos vec2) vec4 { return image3At(pos) }\n"), \InvalidArgumentException::class, 'compile: an effect reading image3', 'image3 is its content');
$throws(fn() => Shader::compile("package main\n\nfunc Fragment(dst vec4, pos vec2) vec4 { return backdropAt(pos) + image2At(pos) }\n"), \InvalidArgumentException::class, 'compile: a backdrop reader reading image2', 'image2');
$throws(fn() => Shader::compile("package main\n\nfunc Fragment(dst vec4, pos vec2) vec4 {\n\treturn vec4(x)\n}\n"), ParseError::class, 'compile: an undefined name, by its line', '4:');
$throws(fn() => Shader::compile("package main\n\nvar X float\n"), \Throwable::class, 'compile: no entry point');
// The entry point found without parsing: not in comments or strings; a broken effect's error is the source's, no entry wrapped after it.
check(Shader::compile("package main\n\n// func ColorEffect(pos vec2, c vec4) vec4\n/* func LayerEffect( */\nfunc Fragment(dst vec4, pos vec2) vec4 { return vec4(1) }\n") instanceof Shader, 'compile: entry points in comments are none');
$throws(fn() => Shader::compile("package main\n\nfunc ColorEffect(pos vec2, c vec4) vec4 {\n\treturn c\n"), ParseError::class, 'compile: a broken effect', "expected '(', found rectSize"); // the bridge's next, as mygo's
$throws(fn() => Shader::compile("package main\n\nfunc Fragment() vec4 { return vec4(1) }\nfunc ColorEffect(pos vec2, c vec4) vec4 { return c\n"), ParseError::class, 'compile: two entry points that do not parse', 'expected');
