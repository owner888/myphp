<?php
// Shaders written in PHP (UI\Shader, compiled in PHP as mygo's ui/shader
// compiles them), on the GPU: waves a uniform moves, text and a path
// filled with a shader, an element drawn through an effect, and a frosted
// pane reading what moves behind it.
// Run: ./run.sh shaders
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Node, Painter, Path};
use MyPHP\UI\Shader\{Options, Shader};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

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

/** A card of $title, $h tall, what $body builds in it. */
function card(Context $c, string $title, float $h, Closure $body): void
{
    $c->column(12, 8)->size(320, 0)->background($c->theme->surface)->radius(12);
      $c->text($title, 13, $c->theme->textMuted);
      $c->column()->size(296, $h)->radius(8)->clip();
        $body();
      $c->end();
    $c->end();
}

function view(Context $c, Shaders $s): void
{
    $t = ($c->now() - $s->start) / 1e9;
    $c->animationFrame();
    $c->column(20, 16, 1, $c->theme->background)->fill();
      $c->text('Shaders', 22);
      $c->row(0, 16)->wrap($c->width - 40);
        card($c, 'draw(): waves, Values set each frame', 160, function () use ($c, $s, $t): void {
            $c->column()->fill()->draw(function (Painter $p, array $r) use ($s, $t): void {
                $s->values->float($s->time, $t)->color($s->tint, $p->theme()->accent);
                $s->waves->draw($p, $r, new Options(values: $s->values));
            });
            $c->end();
        });
        card($c, 'fill(): text and a path', 160, function () use ($c, $s, $t): void {
            $c->column()->fill()->draw(function (Painter $p, array $r) use ($s, $t): void {
                $o = new Options(uniforms: ['Time' => $t]);
                $s->rainbow->text($p, $r[0] + 130, $r[1] + 40, 'Hello', 44, $o);
                $p->transform(\MyPHP\UI\Transform::translation($r[0], $r[1] + 20), 0, 0, fn() => $s->rainbow->strokePath($p, $s->ring, 10, $o));
            });
            $c->end();
        });
        card($c, 'effect(): a DistortionEffect', 160, function () use ($c, $s, $t): void {
            $c->column(0, 0, 1)->fill()->alignItems(Node::CENTER)->justify(Node::CENTER);
              $c->text('Wavy text', 34)->effect($s->wavy->effect(new Options(uniforms: ['Time' => $t], maxSampleOffset: [0, 8])));
            $c->end();
        });
        card($c, 'backdropSample(): a frosted pane', 160, function () use ($c, $s, $t): void {
            $c->column()->fill()->draw(function (Painter $p, array $r) use ($s, $t): void {
                foreach ([0xff5a5a, 0x5ac8ff, 0xffd25a] as $i => $rgb) {
                    $x = $r[0] + 148 + 110 * sin($t + $i * 2.1) - 30;
                    $p->fill([$x, $r[1] + 30 + 40 * $i, 60, 40], $rgb, 10);
                }
                $s->frost->draw($p, [$r[0] + 60, $r[1] + 30, 176, 100], new Options(uniforms: ['Tint' => 0xffffff], radius: 14, backdropBlur: 8));
            });
            $c->end();
        });
      $c->end();
    $c->end();
}

$s = new Shaders;
App::setName('Shaders');
App::whenReady(function () use ($s): void {
    new Window(new WindowOptions(title: 'Shaders', width: 720, height: 520, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
