<?php
// Confetti in native UI, as canvas-confetti throws it on a web page (mygo's
// examples/confetti): bursts of squares, circles, stars, hearts and emoji
// that fly out, slow down, fall, tumble and fade. Pick one of
// canvas-confetti's demos, or click the stage to throw a burst of your own
// from the pointer. Each piece is three boxes posed one inside the other
// (animatePose), as canvas-confetti adds up its motions: a burst whose speed
// decays along an easing of its own, a steady fall that fades it, and a
// spin in a plane turned away in 3D (rotationX), which makes it tumble.
// Poses move in frames that only paint, so hundreds of pieces fly without
// building the view again.
// Run: ./run.sh confetti
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Animation, BorderStyle, Content, Context, Cursor, DisplayList, Easing, Font, InputEvent, Node, Painter, Path, Pose, TextPainter, Theme, Transform};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

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
    /** When it headed for its poses, in nanoseconds, 0 while it still shows where it starts. */
    public int $launched = 0;

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
     * The burst: a box that falls and fades, and in it a box for each piece,
     * tilted, which bursts out, with what it shows spinning in it (or on it,
     * as a circle, which does not spin). A pose starts at the first one it
     * gets, so a new burst shows where it starts until a frame showed it
     * there, and then heads off.
     */
    public function build(Context $c, int $now): void
    {
        $group = $c->key($this->id)->column()->absolute()->left($this->x)->top($this->y)->size(1, 1)->passThrough();
        if ($this->launched === 0 && $c->bounds($group)[2] > 0) { $this->launched = $now; }
        $launched = $this->launched !== 0;
        if (!$launched) { $c->animationFrame(); }
        $linear = new Animation($this->life, Easing::linear(...));
        $group->animatePose($launched ? $this->fall : new Pose(), $linear);
          foreach ($this->pieces as $p) {
              [$to, $spin] = $launched ? [$p->to, new Pose(rotate: $p->spin)] : [new Pose(), new Pose()];
              $e = $p->spin == 0 ? shapeBox($c->key($p->id), $p) : $c->key($p->id)->column();
              $e->absolute()->left(-$p->w / 2)->top(-$p->h / 2)->size($p->w, $p->h)->passThrough();
              // The pose spins the piece in its plane, which this turns away: seen edge on as it spins, it tumbles. The burst moves it after.
              if ($p->tilt != 0) { $e->transform(Transform::rotationX($p->tilt)->then(Transform::rotation($p->turn))); }
              $e->animatePose($to, new Animation($this->life, $this->ease));
              if ($p->spin != 0) {
                  shapeBox($c, $p)->size($p->w, $p->h)->passThrough()->animatePose($spin, $linear);
                  $c->end();
              }
              $c->end();
          }
        $c->end();
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

/** One of canvas-confetti's demos: [name, about, run(Confetti, now)]. */
function presets(): array
{
    $fire = function (Confetti $s, float $share, Closure $change): void {
        $o = new Options();
        [$o->count, $o->y] = [(int)(200 * $share), 0.7];
        $change($o);
        $s->burst($o);
    };
    return [
        ['Basic Cannon', 'One burst, up from below', function (Confetti $s) { $o = new Options(); [$o->count, $o->spread, $o->y] = [100, 70, 0.6]; $s->burst($o); }],
        ['Random Direction', 'Another angle every time', function (Confetti $s) {
            $o = new Options();
            [$o->angle, $o->spread, $o->count, $o->y] = [55 + rnd() * 70, 50 + rnd() * 20, 50 + mt_rand(0, 49), 0.6];
            $s->burst($o);
        }],
        ['Realistic Look', 'Five bursts, fast to slow', function (Confetti $s) use ($fire) {
            $fire($s, 0.25, function (Options $o) { [$o->spread, $o->startVelocity] = [26, 55]; });
            $fire($s, 0.2, function (Options $o) { $o->spread = 60; });
            $fire($s, 0.35, function (Options $o) { [$o->spread, $o->decay, $o->scalar] = [100, 0.91, 0.8]; });
            $fire($s, 0.1, function (Options $o) { [$o->spread, $o->startVelocity, $o->decay, $o->scalar] = [120, 25, 0.92, 1.2]; });
            $fire($s, 0.1, function (Options $o) { [$o->spread, $o->startVelocity] = [120, 45]; });
        }],
        ['Fireworks', 'Bursts all around for 5 s', function (Confetti $s, int $now) {
            $s->repeat($now, 0.25, 20, function (int $i, int $n) use ($s) {
                foreach ([[0.1, 0.3], [0.7, 0.9]] as [$a, $b]) {
                    $o = new Options();
                    [$o->count, $o->startVelocity, $o->spread, $o->ticks] = [intdiv(50 * ($n - $i), $n), 30, 360, 60];
                    [$o->x, $o->y] = [$a + rnd() * ($b - $a), rnd() - 0.2];
                    $s->burst($o);
                }
            });
        }],
        ['Stars', 'Golden stars floating out', function (Confetti $s, int $now) {
            $s->repeat($now, 0.1, 3, function () use ($s) {
                $o = new Options();
                [$o->spread, $o->ticks, $o->gravity, $o->decay, $o->startVelocity] = [360, 50, 0, 0.94, 30];
                $o->colors = [0xffe400, 0xffbd00, 0xe89400, 0xffca6c, 0xfdffb8];
                [$o->count, $o->scalar, $o->shapes] = [40, 1.2, [new Shape(Shape::STAR)]];
                $s->burst($o);
                [$o->count, $o->scalar, $o->shapes] = [10, 0.75, [new Shape(Shape::CIRCLE)]];
                $s->burst($o);
            });
        }],
        ['Snow', 'Flakes drifting down for 8 s', function (Confetti $s, int $now) {
            $skew = 1.0;
            $s->repeat($now, 0.03, 270, function (int $i, int $n) use ($s, &$skew) {
                $left = ($n - $i) / $n;
                $skew = max(0.8, $skew - 0.001);
                $o = new Options();
                [$o->count, $o->startVelocity, $o->shapes, $o->ticks] = [1, 0, [new Shape(Shape::CIRCLE)], (int)max(200, 500 * $left)];
                [$o->x, $o->y] = [rnd(), rnd() * $skew - 0.2];
                $o->colors = $s->dark ? [0xffffff, 0xdbeafe, 0xbfdbfe] : [0x93c5fd, 0xbfdbfe, 0xcbd5e1];
                [$o->gravity, $o->scalar, $o->drift] = [0.4 + rnd() * 0.2, 0.4 + rnd() * 0.6, -0.4 + rnd() * 0.8];
                $s->burst($o);
            });
        }],
        ['School Pride', 'Cannons from both sides', function (Confetti $s, int $now) {
            $s->repeat($now, 0.025, 160, function () use ($s) {
                foreach ([[60, 0], [120, 1]] as [$angle, $x]) {
                    $o = new Options();
                    [$o->count, $o->angle, $o->spread, $o->x] = [2, $angle, 55, $x];
                    $o->colors = [0xbb0000, $s->dark ? 0xffffff : 0x1e293b];
                    $s->burst($o);
                }
            });
        }],
        ['Emoji', 'Unicorns and rainbows', function (Confetti $s, int $now) {
            $s->repeat($now, 0.1, 3, function () use ($s) {
                $o = new Options();
                [$o->spread, $o->ticks, $o->gravity, $o->decay, $o->startVelocity] = [360, 60, 0, 0.96, 20];
                [$o->shapes, $o->scalar, $o->count] = [[Shape::text('🦄'), Shape::text('🌈')], 2, 30];
                $s->burst($o);
                [$o->count, $o->flat] = [5, true];
                $s->burst($o);
                [$o->count, $o->flat, $o->scalar, $o->shapes] = [15, false, 1, [new Shape(Shape::CIRCLE)]];
                $s->burst($o);
            });
        }],
        ['Hearts', 'A shape drawn as a path', function (Confetti $s, int $now) {
            $s->repeat($now, 0.1, 3, function () use ($s) {
                $o = new Options();
                [$o->spread, $o->ticks, $o->gravity, $o->decay, $o->startVelocity] = [360, 70, 0.3, 0.95, 25];
                [$o->count, $o->scalar, $o->shapes, $o->flat] = [25, 1.5, [new Shape(Shape::HEART)], true];
                $o->colors = [0xff4d6d, 0xff758f, 0xc9184a, 0xffb3c1];
                $s->burst($o);
            });
        }],
    ];
}

/** The window's state: the bursts in the air, those still to come, and the options of the stage's bursts. */
final class Confetti
{
    /** @var list<Burst> */
    public array $bursts = [];
    /** @var list<array{next: int, every: int, i: int, n: int, fire: Closure}> the demos that throw confetti for a while */
    public array $emitters = [];
    public int $nextId = 0;
    /** The window's size as the view last ran, and whether its theme was dark, for demos whose white pieces would not show on a light background. */
    public float $w = WINDOW_W, $h = WINDOW_H;
    public bool $dark = false;
    /** Where the stage was last pressed. */
    public float $pressX = 0, $pressY = 0;
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

    /** Calls $fire($i, $n) $n times, now and every $every seconds after. */
    public function repeat(int $now, float $every, int $n, Closure $fire): void
    {
        $fire(0, $n);
        if ($n > 1) { $this->emitters[] = ['next' => $now + (int)($every * 1e9), 'every' => (int)($every * 1e9), 'i' => 1, 'n' => $n, 'fire' => $fire]; }
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

/** What a piece looks like: a box opened, which the caller ends. */
function shapeBox(Context $c, Piece $p): Node
{
    $color = $p->color;
    $paint = fn(Closure $path) => function (DisplayList $list, Node $n, Theme $t, TextPainter $text) use ($path, $color) {
        (new Painter($list, $text, $t))->fillPath($path($n->x, $n->y, $n->w, $n->h), $color);
    };
    $n = match ($p->shape->kind) {
        Shape::CIRCLE => $c->column(0, 0, 0, $color)->round($p->w / 2),
        Shape::STAR => $c->column()->draw($paint(starPath(...))),
        Shape::HEART => $c->column()->draw($paint(heartPath(...))),
        Shape::TEXT => $c->column()->center(),
        default => $c->column(0, 0, 0, $color),
    };
    if ($p->shape->kind === Shape::TEXT) { $c->text($p->shape->text, $p->h)->lineHeight(1); }
    return $n;
}

function view(Context $c, Confetti $s): void
{
    $t = $c->theme;
    $now = $c->now();
    [$s->w, $s->h] = $c->size();
    $s->dark = $t->dark;
    // While the desktop asks for less motion, nothing flies: the pieces would only show where they land, fading.
    $still = $c->preferences()->reduceMotion;
    foreach ($s->emitters as $k => &$e) {
        for (; $e['i'] < $e['n'] && $now >= $e['next']; $e['i']++, $e['next'] += $e['every']) {
            if (!$still) { ($e['fire'])($e['i'], $e['n']); }
        }
        if ($e['i'] >= $e['n']) { unset($s->emitters[$k]); }
    }
    unset($e);
    $s->emitters = array_values($s->emitters);

    $c->column(24, 20, 1);
      $c->row(0, 12)->align(Node::END);
        $c->column(0, 4, 1);
          $c->text('Confetti', 28, style: Font::BOLD);
          $c->muted('canvas-confetti\'s demos in native UI, every piece moved by poses that only paint.');
        $c->end();
        $c->text($s->inAir() . ' in the air', 12, $t->textMuted);
      $c->end();
      if ($still) { $c->text('Confetti rests while the desktop asks for less motion.', color: $t->warning); }
      $c->row(0, 16, 1)->align(Node::STRETCH);
        $c->column(0, 16, 1);
          presetTiles($c, $s, $now, $still);
          $stage = $c->key('stage')->column(0, 6, 1)->align(Node::CENTER)->justify(Node::CENTER)->round(12)->border($t->border)->borderStyle(BorderStyle::Dashed)->cursor(Cursor::Pointer);
            $c->text('Click anywhere here', style: Font::BOLD);
            $c->text('to throw your burst from the pointer', 12, $t->textMuted);
          $c->end();
          // Where the stage was pressed, noted as the press comes, for the click after.
          $stage->handleInput(function (InputEvent $ev) use ($s) {
              if ($ev->kind === InputEvent::POINTER_DOWN) { [$s->pressX, $s->pressY] = [$ev->x, $ev->y]; }
              return false;
          });
          if ($c->clicked($stage) && !$still) { $s->burst($s->custom(...$c->localToWindow($stage, $s->pressX, $s->pressY))); }
        $c->end();
        controls($c, $s);
      $c->end();
    $c->end();

    // The pieces fly over everything, from the window's (0, 0), and let the pointer through. Bursts that faded away go.
    $s->bursts = array_values(array_filter($s->bursts, fn(Burst $b) => $b->launched === 0 || ($now - $b->launched) / 1e9 < $b->life));
    $c->key('confetti')->column()->absolute()->top(0)->left(0)->right(0)->bottom(0)->passThrough();
      foreach ($s->bursts as $b) { $b->build($c, $now); }
    $c->end();
    // The view runs again once a burst faded, to let it go, and when a demo throws more.
    $gone = null;
    foreach ($s->bursts as $b) {
        if ($b->launched !== 0) { $d = $b->life - ($now - $b->launched) / 1e9; $gone = $gone === null ? $d : min($gone, $d); }
    }
    if ($gone !== null && $gone > 0) { $c->after($gone); }
    foreach ($s->emitters as $e) { $c->after(($e['next'] - $now) / 1e9); }
}

/** A tile for each of canvas-confetti's demos, which lifts under the pointer and sinks as it presses: a pose, which moves in frames that only paint. */
function presetTiles(Context $c, Confetti $s, int $now, bool $still): void
{
    $t = $c->theme;
    $c->grid(3, 12);
      foreach (presets() as $i => [$name, $about, $run]) {
          $tile = $c->key($i)->buttonBase()->label($name)->padding(12, 14)->round(10)->border($t->border)->cursor(Cursor::Pointer);
          [$bg, $lift] = $c->pressed($tile) ? [$t->surfacePressed, new Pose(scale: 0.97)] : ($c->hovered($tile) ? [$t->surfaceHover, new Pose(y: -2)] : [$t->surface, new Pose()]);
          $tile->background($bg)->animatePose($lift, Animation::snappy());
            $c->column(0, 4, 1);
              $c->text($name, style: Font::BOLD);
              $c->text($about, 12, $t->textMuted);
            $c->end();
          $c->end();
          if ($c->clicked($tile) && !$still) { $run($s, $now); }
      }
    $c->end();
}

/** The sliders of the stage's bursts. */
function controls(Context $c, Confetti $s): void
{
    $t = $c->theme;
    $c->column(16, 10, 0, $t->surface)->size(280, 0)->round(12)->border($t->border);
      $c->text('Your burst', style: Font::BOLD);
      $slider = function (string $label, float &$v, float $lo, float $hi, string $format) use ($c, $t) {
          $c->row(0, 8)->align(Node::CENTER);
            $c->text($label, 12)->size(62, 0);
            $c->key($label)->slider($v, $lo, $hi, 0)->label($label)->grow(1);
            $c->text(sprintf($format, $v), 12, $t->textMuted)->size(40, 0)->textAlign(Node::END);
          $c->end();
      };
      $slider('Count', $s->count, 1, 400, '%.0f');
      $slider('Angle', $s->angle, 0, 360, '%.0f°');
      $slider('Spread', $s->spread, 0, 360, '%.0f°');
      $slider('Velocity', $s->velocity, 0, 90, '%.0f');
      $slider('Decay', $s->decay, 0.8, 0.99, '%.2f');
      $slider('Gravity', $s->gravity, -1, 3, '%.1f');
      $slider('Drift', $s->drift, -2, 2, '%.1f');
      $slider('Size', $s->scalar, 0.4, 3, '%.1f×');
      $c->spacer();
      if ($c->clicked($c->button('Reset'))) { $s->resetOptions(); }
    $c->end();
}

$s = new Confetti();
App::setName('Confetti');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Confetti', width: WINDOW_W, height: WINDOW_H, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
