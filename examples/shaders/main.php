<?php
// Shaders written in PHP (UI\Shader, compiled in PHP as mygo's ui/shader
// compiles them), on the GPU: waves a uniform moves, text and a path
// filled with a shader, an element drawn through an effect, and a frosted
// pane reading what moves behind it.
// Run: myphp run -d macos examples/shaders
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Path};
use MyPHP\UI\Shader\Shader;

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

const WAVES = <<<'KAGE'
package main

var Time float
var Tint vec4

func Fragment(dst vec4, pos vec2) vec4 {
	size := rectSize()
	v := 0.5 + 0.5*sin(pos.x/18+Time*2)*cos(pos.y/23-Time)
	c := mix(vec3(0.05, 0.1, 0.3), Tint.rgb, v)
	return vec4(c*(0.6+0.4*pos.y/size.y), 1)
}
KAGE;

const RAINBOW = <<<'KAGE'
package main

var Time float

func Fragment(dst vec4, pos vec2) vec4 {
	t := pos.x/rectSize().x + Time*0.3
	return vec4(0.5+0.5*cos(6.2831*(t+vec3(0, 0.33, 0.67))), 1)
}
KAGE;

const WAVY = <<<'KAGE'
package main

var Time float

func DistortionEffect(pos vec2) vec2 {
	return pos + vec2(0, 6*sin(pos.x/12+Time*3))
}
KAGE;

const FROST = <<<'KAGE'
package main

var Tint vec4

func Fragment(dst vec4, pos vec2) vec4 {
	b := backdropSample(pos)
	return mix(b, vec4(Tint.rgb*Tint.a, Tint.a), 0.35)
}
KAGE;

/** The shaders, compiled once, and the values the waves draw with. */
final class Shaders
{
    public Shader $waves, $rainbow, $wavy, $frost;
    public \MyPHP\UI\Shader\Values $values;
    public \MyPHP\UI\Shader\Uniform $time, $tint;
    public Path $ring;
    /** When the app started: the shaders' time is the seconds since, small enough for a float of 32 bits to keep its fractions. */
    public int $start;

    public function __construct()
    {
        $this->waves = Shader::compile(WAVES);
        $this->rainbow = Shader::compile(RAINBOW);
        $this->wavy = Shader::compile(WAVY);
        $this->frost = Shader::compile(FROST);
        $this->values = $this->waves->newValues();
        [$this->time, $this->tint] = [$this->waves->uniform('Time'), $this->waves->uniform('Tint')];
        $this->ring = (new Path)->circle(60, 60, 44);
        $this->start = hrtime(true);
    }
}

$s = new Shaders;
App::setName('Shaders');
App::whenReady(function () use ($s): void {
    new Window(new WindowOptions(title: 'Shaders', width: 720, height: 520, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
