<?php
// Confetti in native UI, as canvas-confetti throws it on a web page (mygo's
// examples/confetti): bursts of squares, circles, stars, hearts and emoji
// that fly out, slow down, fall, tumble and fade. Pick one of
// canvas-confetti's demos, or click the stage to throw a burst of your own
// from the pointer. Each piece is three views one inside the other, each
// animated to where it ends (animation(_:value:)) as SwiftUI animates
// offsets, rotations and opacity, as canvas-confetti adds up its motions: a
// burst whose speed decays along an easing of its own, a steady fall that
// fades it, and a spin in a plane tipped away in 3D (rotation3DEffect),
// which makes it tumble. They move in frames that only paint, so hundreds
// of pieces fly without building the view again.
// Run: ./run.sh confetti
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Easing, Path, Pose};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

const WINDOW_W = 980;
const WINDOW_H = 700;
/** How long canvas-confetti moves its pieces once, in seconds. */
const TICK = 1 / 60;
/** How many pieces can be in the air: every piece costs each frame that paints, so a button pressed again and again lets the oldest, most faded pieces go first. */
const MAX_PIECES = 1500;
/** canvas-confetti's colors. */
const DEFAULT_COLORS = [0x26ccff, 0xa25afd, 0xff5e7e, 0x88ff5a, 0xfcff42, 0xffa62d, 0xff36ff];

function rnd(): float { return mt_rand() / mt_getrandmax(); }

/** How a piece of confetti looks: a square, a circle, a star, a heart, or an emoji. */
final class Shape
{
    public const SQUARE = 0, CIRCLE = 1, STAR = 2, HEART = 3, TEXT = 4;

    public function __construct(public readonly int $kind, public readonly string $text = '') {}

    public static function text(string $s): self { return new self(self::TEXT, $s); }
}

/** How a burst flies, as canvas-confetti's options: speeds and distances in points per tick, a sixtieth of a second. */
final class Options
{
    /** How many pieces the burst throws. */
    public int $count = 50;
    /** The direction it throws them, in degrees counterclockwise from the right (90 is up), and how far they stray from it. */
    public float $angle = 90, $spread = 45;
    /** How fast a piece starts, from half of it to one and a half times it, and what its speed keeps every tick. */
    public float $startVelocity = 45, $decay = 0.9;
    /** The pull down, 3 points a tick times it, and the drift sideways. */
    public float $gravity = 1, $drift = 0;
    /** How long the pieces last, fading as they go. */
    public int $ticks = 200;
    /** Where the burst starts, as fractions of the window's width and height. */
    public float $x = 0.5, $y = 0.5;
    /** @var list<int> chosen from at random for each piece */
    public array $colors = DEFAULT_COLORS;
    /** @var list<Shape> */
    public array $shapes;
    /** Scales the pieces. */
    public float $scalar = 1;
    /** Keeps the pieces facing the viewer instead of tumbling. */
    public bool $flat = false;

    /** canvas-confetti's defaults: 50 squares and circles thrown up from the middle of the window. */
    public function __construct() { $this->shapes = [new Shape(Shape::SQUARE), new Shape(Shape::CIRCLE)]; }
}

/** A piece of confetti in a burst. */
final class Piece
{
    public float $w = 0, $h = 0;
    /** How many degrees it turns in its plane, which $tilt turns away from the viewer around an axis turned by $turn degrees. */
    public float $spin = 0, $tilt = 0, $turn = 0;

    public function __construct(public int $id, public int $color, public Shape $shape, public Pose $to) {}
}

/**
 * A burst in the air. canvas-confetti adds up three motions for each piece:
 * a burst that slows down as its speed decays, a fall at a steady speed
 * under gravity and drift, which fades it, and a spin in a tilted plane,
 * which makes it tumble. Each is a pose: the fall, the same for every piece
 * of a burst, moves the whole burst, in which each piece bursts out, tilted,
 * and spins.
 */
final class Burst
{
    /** In seconds. */
    public float $life;
    public Pose $fall;
    public Closure $ease;
    /** @var list<Piece> */
    public array $pieces = [];
    /** Whether a frame showed it where it starts, and whether it heads for where its pieces end then. */
    public bool $shown = false, $launched = false;
    /** When it headed off, in nanoseconds of the frames' time. */
    public int $at = 0;

    public function __construct(Options $o, public float $x, public float $y, public int $id)
    {
        $ticks = (float)max($o->ticks, 1);
        $this->life = $ticks * TICK;
        $this->fall = new Pose($o->drift * $ticks, 3 * $o->gravity * $ticks, fade: 1);
        $this->ease = self::decayEase($o->decay, $ticks);
        // What the speed adds up to over the ticks, per point per tick of the starting speed.
        $reach = $o->decay == 1 ? $ticks : (1 - $o->decay ** $ticks) / (1 - $o->decay);
        [$angle, $spread] = [deg2rad($o->angle), deg2rad($o->spread)];
        $shapes = $o->shapes ?: [new Shape(Shape::SQUARE), new Shape(Shape::CIRCLE)];
        $colors = $o->colors ?: DEFAULT_COLORS;
        for ($i = 0; $i < $o->count; $i++) {
            $dir = -$angle + $spread / 2 - rnd() * $spread;
            $speed = $o->startVelocity * 0.5 + rnd() * $o->startVelocity;
            $p = new Piece($i, $colors[array_rand($colors)], $shape = $shapes[array_rand($shapes)], new Pose(cos($dir) * $speed * $reach, sin($dir) * $speed * $reach));
            $s = $o->scalar;
            [$p->w, $p->h] = match ($shape->kind) { Shape::CIRCLE => [8 * $s, 8 * $s], Shape::STAR => [16 * $s, 16 * $s], Shape::HEART => [14 * $s, 13 * $s], Shape::TEXT => [12 * $s, 12 * $s], default => [10 * $s, 10 * $s * (0.5 + 0.4 * rnd())] };
            if (!$o->flat) {
                $p->tilt = 45 + 40 * rnd();
                $p->turn = 360 * rnd();
                if ($shape->kind !== Shape::CIRCLE) { // a circle looks the same however it spins
                    // About a turn a second, either way, as canvas-confetti turns its pieces' tilt 0.1 radians a tick.
                    $turns = (0.6 + 0.8 * rnd()) * $this->life;
                    $p->spin = (mt_rand(0, 1) === 0 ? -$turns : $turns) * 360;
                }
            }
            $this->pieces[] = $p;
        }
    }

    /** How far a burst has gone, as a fraction of how far it goes, $t of the way through $ticks ticks, its speed keeping $decay of itself every tick. */
    public static function decayEase(float $decay, float $ticks): Closure
    {
        if ($decay == 1) { return Easing::linear(...); }
        $k = log($decay) * $ticks; // decay^(t ticks), as an exponential, which is cheaper every frame
        $total = 1 - exp($k);
        return fn(float $t): float => (1 - exp($k * $t)) / $total;
    }

    /**
     * The burst: a view that falls and fades, and in it a view for each
     * piece, tipped, which bursts out, with what it shows spinning in it (or
     * on it, as a circle, which does not spin). Each starts where it is
     * until the burst is launched, then animates to where it ends.
     */
    public function view(): View
    {
        $l = $this->launched;
        $linear = Animation::linear(duration: $this->life);
        $out = new Animation($this->life, $this->ease);
        return ZStack(alignment: Alignment::topLeading)(Each($this->pieces, id: fn(Piece $p) => $p->id)(fn(Piece $p) => self::shape($p)
            ->frame(width: $p->w, height: $p->h)
            ->rotationEffect($l ? $p->spin : 0)->animation($linear, value: $l) // it spins in its plane
            ->rotation3DEffect($p->tilt, axis: [cos(deg2rad($p->turn)), sin(deg2rad($p->turn)), 0]) // which tips away: seen edge on as it spins, it tumbles
            ->offset(x: -$p->w / 2 + ($l ? $p->to->x : 0), y: -$p->h / 2 + ($l ? $p->to->y : 0))->animation($out, value: $l))) // and bursts out
            ->frame(width: 1, height: 1, alignment: Alignment::topLeading)
            ->offset(x: $this->x + ($l ? $this->fall->x : 0), y: $this->y + ($l ? $this->fall->y : 0))->opacity($l ? 0 : 1)->animation($linear, value: $l) // falling, fading
            ->allowsHitTesting(false);
    }

    /** What piece $p looks like. */
    private static function shape(Piece $p): View
    {
        $color = Color::rgb($p->color);
        return match ($p->shape->kind) {
            Shape::CIRCLE => Circle()->fill($color),
            Shape::STAR => Canvas(fn(GraphicsContext $g, CGSize $s) => $g->fill(starPath(0, 0, $s->width, $s->height), with: $color)),
            Shape::HEART => Canvas(fn(GraphicsContext $g, CGSize $s) => $g->fill(heartPath(0, 0, $s->width, $s->height), with: $color)),
            Shape::TEXT => Text(verbatim: $p->shape->text)->font(Font::system(size: $p->h)),
            default => Rectangle()->fill($color),
        };
    }
}

/** A star of five points filling the box at ($x, $y). */
function starPath(float $x, float $y, float $w, float $h): Path
{
    [$cx, $cy, $outer] = [$x + $w / 2, $y + $h / 2, $w / 2];
    $path = new Path();
    for ($i = 0; $i < 10; $i++) {
        $rad = $i % 2 === 1 ? $outer * 0.45 : $outer;
        $a = -M_PI / 2 + $i * M_PI / 5;
        $i === 0 ? $path->moveTo($cx + $rad * cos($a), $cy + $rad * sin($a)) : $path->lineTo($cx + $rad * cos($a), $cy + $rad * sin($a));
    }
    return $path->close();
}

/** A heart filling the box at ($x, $y). */
function heartPath(float $x, float $y, float $w, float $h): Path
{
    return (new Path())->moveTo($x + $w / 2, $y + $h)
        ->cubeTo($x - $w * 0.15, $y + $h * 0.55, $x + $w * 0.05, $y - $h * 0.1, $x + $w / 2, $y + $h * 0.22)
        ->cubeTo($x + $w * 0.95, $y - $h * 0.1, $x + $w * 1.15, $y + $h * 0.55, $x + $w / 2, $y + $h)->close();
}

/** The window's state: the bursts in the air, those still to come, and the options of the stage's bursts. */
final class Confetti
{
    /** @var list<Burst> */
    public array $bursts = [];
    public ?Window $win = null;
    /** @var list<array{next: int, every: int, i: int, n: int, fire: Closure}> the demos that throw confetti for a while */
    public array $emitters = [];
    /** The demo's tile under the pointer, which lifts. */
    public ?int $hover = null;
    public int $nextId = 0;
    /** The window's size as the view last ran, and whether its theme was dark, for demos whose white pieces would not show on a light background. */
    public float $w = WINDOW_W, $h = WINDOW_H;
    public bool $dark = false;
    /** The stage's options, as its sliders set them. */
    public float $count, $angle, $spread, $velocity, $decay, $gravity, $drift, $scalar;

    public function __construct() { $this->resetOptions(); }

    /** The stage's options as they start: a ring of canvas-confetti's default pieces. */
    public function resetOptions(): void
    {
        $d = new Options();
        [$this->count, $this->angle, $this->spread, $this->velocity] = [150, $d->angle, 360, 30];
        [$this->decay, $this->gravity, $this->drift, $this->scalar] = [$d->decay, $d->gravity, $d->drift, $d->scalar];
    }

    /** Throws a burst as $o says; past MAX_PIECES the oldest pieces go. */
    public function burst(Options $o): void
    {
        $this->bursts[] = new Burst($o, $o->x * $this->w, $o->y * $this->h, $this->nextId++);
        for ($over = $this->inAir() - MAX_PIECES; $over > 0;) {
            $b = $this->bursts[0];
            if (count($b->pieces) > $over) { $b->pieces = array_slice($b->pieces, $over); break; }
            $over -= count($b->pieces);
            array_shift($this->bursts);
        }
    }

    public function inAir(): int
    {
        return array_sum(array_map(fn(Burst $b) => count($b->pieces), $this->bursts));
    }

    /** Calls $fire($i, $n) $n times, now and every $every seconds after, as the frames come (tick). */
    public function repeat(float $every, int $n, Closure $fire): void
    {
        $this->emitters[] = ['next' => 0, 'every' => (int)($every * 1e9), 'i' => 0, 'n' => $n, 'fire' => $fire];
    }

    /** Moves things on to the frame at $now: the demos throw what is due; the bursts a frame showed where they start head off; those that faded go. */
    public function tick(int $now): void
    {
        foreach ($this->emitters as $k => &$e) {
            if ($e['next'] === 0) { $e['next'] = $now; }
            for (; $e['i'] < $e['n'] && $now >= $e['next']; $e['i']++, $e['next'] += $e['every']) { ($e['fire'])($e['i'], $e['n']); }
            if ($e['i'] >= $e['n']) { unset($this->emitters[$k]); }
        }
        unset($e);
        $this->emitters = array_values($this->emitters);
        $this->bursts = array_values(array_filter($this->bursts, fn(Burst $b) => !$b->launched || ($now - $b->at) / 1e9 < $b->life));
        foreach ($this->bursts as $b) {
            if (!$b->shown) { $b->shown = true; } // shown where it starts: the next frame launches it
            elseif (!$b->launched) { [$b->launched, $b->at] = [true, $now]; }
        }
    }

    /** The times of the frames it needs next, in nanoseconds: the demos' next bursts, a frame to launch the bursts shown, one to let each go once it faded. @return list<int> */
    public function due(): array
    {
        $times = array_map(fn(array $e) => $e['next'], $this->emitters);
        foreach ($this->bursts as $b) { $times[] = $b->launched ? $b->at + (int)($b->life * 1e9) + 1 : 0; } // 0: at once
        return $times;
    }

    /** The stage's options for a burst from ($x, $y) in window points. */
    public function custom(float $x, float $y): Options
    {
        $o = new Options();
        $o->count = (int)$this->count;
        [$o->angle, $o->spread, $o->startVelocity, $o->decay] = [$this->angle, $this->spread, $this->velocity, $this->decay];
        [$o->gravity, $o->drift, $o->scalar] = [$this->gravity, $this->drift, $this->scalar];
        [$o->x, $o->y] = [$x / $this->w, $y / $this->h];
        return $o;
    }
}

$s = new Confetti();
App::setName('Confetti');
App::whenReady(function () use ($s) {
    $s->win = new Window(new WindowOptions(title: 'Confetti', width: WINDOW_W, height: WINDOW_H, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
