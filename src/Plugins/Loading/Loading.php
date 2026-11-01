<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

use MyPHP\UI\{ConicGradient, Context, GradientStop, Node, Painter, Role, Transform};

/**
 * The 29 loading indicators of loading.dev (https://loading.dev, Jakub
 * Krehel's loading-dev for React) for native UI (mygo's plugins/loading):
 * arcs, dots, bars and blocks that MyPHP draws itself, each a node that
 * moves as long as it shows.
 *
 *     Loading::arc($c);
 *     Loading::ring($c, new Options(size: 32, color: $c->theme->accent, easing: Easing::EaseInOut));
 *     $c->row(0, 8);
 *       Loading::bouncingDots($c, new Options(size: 12));
 *       $c->text('Thinking');
 *     $c->end();
 *
 * An indicator is Options::$size points high, DEFAULT_SIZE unless set,
 * and as wide, or wider for a row of dots; the node's own size, as size()
 * or fill sets it, scales it. It draws in the theme's text color unless
 * Options::$color says otherwise, and runs at loading.dev's pace unless
 * Options::$duration says otherwise. Where the desktop asks for less
 * motion (Preferences::$reduceMotion) it stands still, in loading.dev's
 * pose for reduced motion; Options::$paused stops it where it is.
 *
 * Assistive technology sees a progress indicator of unknown length; name
 * it with label().
 *
 * The indicators follow loading.dev's, drawn in their SVG's viewBox or in
 * fractions of their size as its CSS places them, with its keyframes and
 * timing functions (NOTICE.md).
 */
final class Loading
{
    /** An indicator's height when Options::$size is 0. */
    public const DEFAULT_SIZE = 20;

    /** @return list<Spinner> the indicators, by name (mygo's All) */
    public static function all(): array
    {
        $out = [];
        foreach (['Arc' => 'arc', 'Atom' => 'atom', 'Blocks' => 'blocks', 'Bouncing dots' => 'bouncingDots', 'Cascade' => 'cascade', 'Circular dots' => 'circularDots',
            'Classic' => 'classic', 'Classic v2' => 'classicV2', 'Clock' => 'clock', 'Comet' => 'comet', 'Compass' => 'compass', 'Dual' => 'dual', 'Eclipse' => 'eclipse',
            'Flip' => 'flip', 'Gather' => 'gather', 'Leap' => 'leap', 'Linear dots' => 'linearDots', 'Loading' => 'loading', 'Morph' => 'morph', 'Orbit' => 'orbit',
            'Pulse' => 'pulse', 'Radar' => 'radar', 'Ring' => 'ring', 'Ripple' => 'ripple', 'Slide' => 'slide', 'Snake' => 'snake', 'Swirl' => 'swirl', 'Trace' => 'trace',
            'Wave' => 'wave'] as $name => $fn) {
            $out[] = new Spinner($name, self::$fn(...));
        }
        return $out;
    }

    /** A single open stroke turning in a circle. */
    public static function arc(Context $c, ?Options $o = null): Node { return self::show($c, 'arc', $o); }
    /** Three rings tumbling inside a circle. */
    public static function atom(Context $c, ?Options $o = null): Node { return self::show($c, 'atom', $o); }
    /** Nine blocks shrinking and growing in a sweep across a grid, in the order Options::$sweep says. */
    public static function blocks(Context $c, ?Options $o = null): Node { return self::show($c, 'blocks', $o); }
    /** Three staggered dots bouncing up and down, in a row 1.15 times as wide as it is high. */
    public static function bouncingDots(Context $c, ?Options $o = null): Node { return self::show($c, 'bouncingDots', $o); }
    /** Three nested arcs fanning into a spiral, then snapping back in line. */
    public static function cascade(Context $c, ?Options $o = null): Node { return self::show($c, 'cascade', $o); }
    /** Eight dots in a ring, the brightest going around. */
    public static function circularDots(Context $c, ?Options $o = null): Node { return self::show($c, 'circularDots', $o); }
    /** Twelve fading bars in a radial pattern. */
    public static function classic(Context $c, ?Options $o = null): Node { return self::show($c, 'classic', $o); }
    /** Eight ticks in a ring, lit in turn. */
    public static function classicV2(Context $c, ?Options $o = null): Node { return self::show($c, 'classicV2', $o); }
    /** A clock hand sweeping around a faint face. */
    public static function clock(Context $c, ?Options $o = null): Node { return self::show($c, 'clock', $o); }
    /** A full ring fading into its tail. */
    public static function comet(Context $c, ?Options $o = null): Node { return self::show($c, 'comet', $o); }
    /** Four ticks turning a quarter turn at a time. */
    public static function compass(Context $c, ?Options $o = null): Node { return self::show($c, 'compass', $o); }
    /** Two arcs turning in opposite directions. */
    public static function dual(Context $c, ?Options $o = null): Node { return self::show($c, 'dual', $o); }
    /** Two dots trading places, one passing behind the other. */
    public static function eclipse(Context $c, ?Options $o = null): Node { return self::show($c, 'eclipse', $o); }
    /** A square flipping over one axis, then the other. */
    public static function flip(Context $c, ?Options $o = null): Node { return self::show($c, 'flip', $o); }
    /** Four blocks pulling together, turning, and pushing apart. */
    public static function gather(Context $c, ?Options $o = null): Node { return self::show($c, 'gather', $o); }
    /** Three dots in a row, the last one leaping to the front. */
    public static function leap(Context $c, ?Options $o = null): Node { return self::show($c, 'leap', $o); }
    /** Three dots lighting up in turn from left to right, in a row nearly as wide as it is high. */
    public static function linearDots(Context $c, ?Options $o = null): Node { return self::show($c, 'linearDots', $o); }
    /** The loading.dev mark, its brightest segment circling the ring. */
    public static function loading(Context $c, ?Options $o = null): Node { return self::show($c, 'loading', $o); }
    /** A square rounding into a circle and back as it turns. */
    public static function morph(Context $c, ?Options $o = null): Node { return self::show($c, 'morph', $o); }
    /** A fading half ring turning around a dot. */
    public static function orbit(Context $c, ?Options $o = null): Node { return self::show($c, 'orbit', $o); }
    /** A ring rippling outward from a dot. */
    public static function pulse(Context $c, ?Options $o = null): Node { return self::show($c, 'pulse', $o); }
    /** A fading beam sweeping around a faint dish. */
    public static function radar(Context $c, ?Options $o = null): Node { return self::show($c, 'radar', $o); }
    /** An arc turning in a faint circle. */
    public static function ring(Context $c, ?Options $o = null): Node { return self::show($c, 'ring', $o); }
    /** Three rings spreading out from the center, or drawing in to it, as Options::$direction says. */
    public static function ripple(Context $c, ?Options $o = null): Node { return self::show($c, 'ripple', $o); }
    /** Three dots sliding into the empty corner of a square. */
    public static function slide(Context $c, ?Options $o = null): Node { return self::show($c, 'slide', $o); }
    /** An arc stretching and shrinking as it circles. */
    public static function snake(Context $c, ?Options $o = null): Node { return self::show($c, 'snake', $o); }
    /** A bright cell chasing its trail around a square. */
    public static function swirl(Context $c, ?Options $o = null): Node { return self::show($c, 'swirl', $o); }
    /** A dash tracing the outline of a rounded square. */
    public static function trace(Context $c, ?Options $o = null): Node { return self::show($c, 'trace', $o); }
    /** Five bars rising and falling in a wave, from their middle or their bottom as Options::$origin says. */
    public static function wave(Context $c, ?Options $o = null): Node { return self::show($c, 'wave', $o); }

    /** An indicator of spec $name with options $o. */
    private static function show(Context $c, string $name, ?Options $o): Node
    {
        $o ??= new Options();
        $s = self::specs()[$name];
        $size = $o->size > 0 ? $o->size : self::DEFAULT_SIZE;
        $color = $o->color ?? $c->theme->text;
        $cycle = (int)round(($o->duration > 0 ? $o->duration : $s->cycle) * 1e9);
        $still = $c->preferences()->reduceMotion;
        $e = $c->column()->size($size * $s->aspect, $size)->shrink(0)->role(Role::PROGRESS)->range(0, 1, -1); // a progress indicator of unknown length, as Context::spinner
        $c->end();
        $now = $c->now();
        $k = $c->runtime?->local($e->id, fn() => new Timer($now)) ?? new Timer($now);
        if ($o->paused && !$k->paused) {
            [$k->held, $k->paused] = [$now - $k->start, true];
        } elseif (!$o->paused && $k->paused) {
            [$k->start, $k->paused] = [$now - $k->held, false];
        }
        $e->draw(static function (Painter $p, array $r) use ($k, $color, $o, $still, $s, $cycle): void {
            $d = new Drawing($p, $k->sh, $color, $o, $still);
            $d->s = min($r[3], $r[2] / $s->aspect);
            [$d->x, $d->y] = [$r[0] + ($r[2] - $d->s * $s->aspect) / 2, $r[1] + ($r[3] - $d->s) / 2];
            if (!$still) {
                $ran = $k->held;
                if (!$k->paused) {
                    $ran = $p->now() - $k->start;
                    $p->animationFrame();
                }
                $d->cycles = $ran / $cycle;
            }
            ($s->draw)($d);
        });
        return $e;
    }

    /** @return array<string, Spec> */
    private static function specs(): array
    {
        static $specs = null;
        return $specs ??= self::make();
    }

    /** @return array<string, Spec> the indicators, as loading.dev's */
    private static function make(): array
    {
        $id = new Matrix();
        $ease = Drawing::easeInOut(...);
        $easeOut = Drawing::easeOut(...);
        $linear = static fn(float $t): float => $t;
        $f = Drawing::frames(...);
        return [
            'arc' => new Spec(0.8, 1, static function (Drawing $d): void {
                $a = $d->spin(false, 0);
                $d->stroke($d->view(24), 12, 12, 10, 2.5, $a, $a + 18.0 / 10, 1);
            }),
            'atom' => new Spec(1.0, 1, static function (Drawing $d) use ($id): void {
                [$cx, $cy] = $d->center();
                $r = $d->s / 2;
                $d->hoop($id, $cx, $cy, $r, $r - 0.055 * $d->s, 1);
                // Each orbit, tilted a third of a half turn more than the one before, is a ring turning in 3D around its diameter, seen from the front: an ellipse growing and shrinking across it.
                for ($i = 0; $i < 3; $i++) {
                    $a = $d->still ? deg2rad(60) : $d->turn($d->at($i, 3), false) * 2 * M_PI;
                    $k = cos($a);
                    if (abs($k) < 1e-4) { continue; }
                    $d->hoop($id->rotate($i * 60, $cx, $cy)->scale($k, 1, $cx, $cy), $cx, $cy, $r, $r - 0.045 * $d->s, 1);
                }
            }),
            'blocks' => new Spec(1.3, 1, static function (Drawing $d) use ($id, $ease, $f): void {
                [$cell, $gap] = [$d->s * 0.8 / 3, $d->s * 0.1];
                for ($row = 0; $row < 3; $row++) {
                    for ($col = 0; $col < 3; $col++) {
                        [$place, $count] = match ($d->o->sweep) { Sweep::Rows => [$row, 3], Sweep::Columns => [$col, 3], Sweep::Diagonal => [$row + $col, 5] };
                        $k = $d->still ? 0.8 : $f($d->at($place, $count), $ease, [0, 1], [0.35, 0], [0.7, 1], [1, 1]);
                        if ($k <= 0) { continue; }
                        [$x, $y] = [$d->x + $col * ($cell + $gap), $d->y + $row * ($cell + $gap)];
                        $d->rounded($id->scale($k, $k, $x + $cell / 2, $y + $cell / 2), $x, $y, $cell, $cell, 0.0625 * $d->s, 0, 1);
                    }
                }
            }),
            'bouncingDots' => new Spec(0.5, 1.15, static function (Drawing $d) use ($id): void {
                $dot = 0.25 * $d->s;
                for ($i = 0; $i < 3; $i++) {
                    $y = 0.0;
                    if (!$d->still) { // up and down on alternate cycles
                        $t = $d->cycles + (3 - $i) / 3;
                        $n = floor($t);
                        $p = $t - $n;
                        if ((int)$n % 2 === 1) { $p = 1 - $p; }
                        $y = 0.28 - Drawing::easeInOut($p);
                    }
                    $d->dot($id, $d->x + $dot / 2 + $i * 0.45 * $d->s, $d->y + $d->s / 2 + $y * $dot, $dot / 2, 1);
                }
            }),
            'cascade' => new Spec(1.5, 1, static function (Drawing $d): void {
                foreach ([10.5, 7, 3.5] as $i => $r) {
                    $a = $d->still ? 0.0 : Drawing::overshoot($d->at($i, 24)) * 2 * M_PI;
                    $d->stroke($d->view(24), 12, 12, $r, 2, $a, $a + M_PI / 2, 1);
                }
            }),
            'circularDots' => new Spec(0.8, 1, static function (Drawing $d): void {
                foreach ([[8, 1.5], [12.5962, 3.4038], [14.5, 8], [12.5962, 12.5962], [8, 14.5], [3.4038, 12.5962], [1.5, 8], [3.4038, 3.4038]] as $i => [$x, $y]) {
                    $d->dot($d->view(16), $x, $y, 1.5, $d->fade($i, 8, 0.2, 0.6));
                }
            }),
            'classic' => new Spec(1.2, 1, static function (Drawing $d) use ($id): void {
                // The bars are placed as loading.dev's CSS places them: around a point a little right of the middle.
                [$cx, $cy] = [$d->x + 0.52 * $d->s, $d->y + 0.501 * $d->s];
                [$w, $h] = [0.24 * $d->s, 0.08 * $d->s];
                for ($i = 0; $i < 12; $i++) {
                    $m = $id->rotate($i * 30, $cx, $cy)->move(1.46 * $w, 0);
                    $d->rounded($m, $cx - $w / 2, $cy - $h / 2, $w, $h, min(6, $h / 2), 0, $d->fade($i, 12, 0.15, 0.5));
                }
            }),
            'classicV2' => new Spec(0.8, 1, static function (Drawing $d): void {
                for ($i = 0; $i < 8; $i++) {
                    $d->capsule($d->view(16)->rotate($i * 45, 8, 8), 8, 2.0 / 3, 8, 10.0 / 3, 4.0 / 3, $d->fade($i, 8, 0.4, 0.5));
                }
            }),
            'clock' => new Spec(1.2, 1, static function (Drawing $d): void {
                $v = $d->view(16);
                $d->dot($v, 8, 8, 8, 0.1);
                $d->turned($v, 8, 8, $d->spin(false, 0), static function (Matrix $v) use ($d): void { // the hand, in its viewBox of 16, pointing up
                    $d->begin($v)->move(11.1937, 2.92061)
                        ->cube(10.5206, 2.49739, 9.77304, 2.21397, 8.9954, 2.08314)
                        ->cube(8.45076, 1.99151, 8, 2.44772, 8, 3)
                        ->line(8, 7.20324)
                        ->cube(8, 7.49211, 8.12492, 7.76686, 8.34259, 7.95677)
                        ->line(11.8999, 11.0603)
                        ->cube(12.3287, 11.4344, 12.9913, 11.378, 13.2643, 10.8787)
                        ->cube(13.6356, 10.1998, 13.8736, 9.45248, 13.9617, 8.67713)
                        ->cube(14.0892, 7.55433, 13.8971, 6.41834, 13.4074, 5.39993)
                        ->cube(12.9177, 4.38152, 12.1503, 3.5221, 11.1937, 2.92061)
                        ->close();
                    $d->fill(1);
                });
            }),
            'comet' => new Spec(0.7, 1, static function (Drawing $d) use ($id): void {
                [$cx, $cy] = $d->center();
                [$r, $w] = [$d->s / 2, 0.12 * $d->s];
                $a = rad2deg($d->spin(false, 0));
                // The tail, a ring fading in from transparent around a turn, and the head at its end.
                $s = $d->begin($id)->ring($cx, $cy, $r, $r - $w);
                $d->p->fillPathConicGradient($s->path, new ConicGradient([new GradientStop($d->color, 0, 0), new GradientStop($d->color, 1)], angle: $a));
                $d->dot($id->rotate($a, $cx, $cy), $cx, $d->y + $w / 2, $w / 2, 1);
            }),
            'compass' => new Spec(0.5, 1, static function (Drawing $d): void {
                $a = $d->still ? 0.0 : 90 * Drawing::easeInOut($d->at(0, 0));
                for ($i = 0; $i < 4; $i++) {
                    $d->capsule($d->view(16)->rotate($a + $i * 90, 8, 8), 8, 2.0 / 3, 8, 10.0 / 3, 4.0 / 3, 1);
                }
            }),
            'dual' => new Spec(1.0, 1, static function (Drawing $d): void {
                $v = $d->view(24);
                $a = $d->spin(false, 0);
                $d->stroke($v, 12, 12, 10, 2.5, $a, $a + 18.0 / 10, 1);
                $b = $d->spin(true, M_PI);
                $d->stroke($v, 12, 12, 5.5, 2.5, $b, $b + 10 / 5.5, 1);
            }),
            'eclipse' => new Spec(1.2, 1, static function (Drawing $d) use ($id, $ease, $f): void {
                [$cx, $cy] = $d->center();
                $dot = 0.4 * $d->s;
                for ($i = 0; $i < 2; $i++) {
                    // Across and back, nearer (larger) on the way there and farther on the way back.
                    [$x, $a, $k] = [$i * 1.5 - 0.75, 1.0, 1.0];
                    if (!$d->still) {
                        $p = $d->at($i, 2);
                        $x = $f($p, $ease, [0, 0.75], [0.5, -0.75], [1, 0.75]);
                        $a = $f($p, $ease, [0, 0.75], [0.25, 1], [0.5, 0.75], [0.75, 0.5], [1, 0.75]);
                        $k = $f($p, $ease, [0, 1], [0.25, 1.3], [0.5, 1], [0.75, 0.7], [1, 1]);
                    }
                    $d->dot($id, $cx + $x * $dot, $cy, $dot / 2 * $k, $a);
                }
            }),
            'flip' => new Spec(1.2, 1, static function (Drawing $d) use ($ease, $f): void {
                [$cx, $cy] = $d->center();
                [$half, $r] = [0.32 * $d->s, 0.06 * $d->s];
                [$ax, $ay] = [0.0, 0.0];
                if (!$d->still) {
                    $p = $d->at(0, 0);
                    $ax = $f($p, $ease, [0, 0], [0.5, -180], [1, -180]);
                    $ay = $f($p, $ease, [0, 0], [0.5, 0], [1, -180]);
                }
                // The square turned in 3D, around its horizontal axis and then its vertical one (CSS's rotateX(ax) rotateY(ay)), seen in perspective from three times the indicator's size.
                $t = Transform::translation(-$half, -$half)->then(Transform::rotationY($ay))->then(Transform::rotationX($ax))
                    ->then(Transform::perspective(3 * $d->s))->then(Transform::translation($cx, $cy));
                $d->roundedIn($t, 2 * $half, 2 * $half, $r, 0, 1);
            }),
            'gather' => new Spec(1.6, 1, static function (Drawing $d) use ($id, $ease, $f): void {
                [$cx, $cy] = $d->center();
                [$b, $gap] = [0.38 * $d->s, 0.24 * $d->s];
                [$pull, $turn] = [0.0, 0.0];
                if (!$d->still) {
                    $p = $d->at(0, 0);
                    $pull = $f($p, $ease, [0, 0], [0.3, 1], [0.6, 1], [1, 0]);
                    $turn = $f($p, $ease, [0, 0], [0.3, 0], [0.6, 90], [1, 90]);
                }
                $in = $pull * 0.211 * $b; // each block pulls in toward the middle by a fifth of its size
                $m = $id->rotate($turn, $cx, $cy);
                for ($i = 0; $i < 4; $i++) {
                    [$col, $row] = [$i % 2, intdiv($i, 2)];
                    $x = $d->x + $col * ($b + $gap) + (1 - 2 * $col) * $in;
                    $y = $d->y + $row * ($b + $gap) + (1 - 2 * $row) * $in;
                    $d->rounded($m, $x, $y, $b, $b, 0.14 * $d->s, 0, 1);
                }
            }),
            'leap' => new Spec(1.8, 1, static function (Drawing $d) use ($id, $ease, $f): void {
                // Whole points, as loading.dev rounds them to pixels.
                $dot = round(0.22 * $d->s);
                $gap = floor(($d->s - $dot) / 2);
                $top = round(($d->s - $dot) / 2);
                $left = $d->s - $dot - 2 * $gap;
                [$mx, $my] = [$d->x + $left + $gap + $dot / 2, $d->y + $top + $dot / 2]; // the middle of the stretch a dot leaps over
                for ($i = 0; $i < 3; $i++) {
                    // The last dot leaps over the others to the front, as they slide back a place each.
                    [$x, $a] = [$i * $gap, 0.0];
                    if (!$d->still) {
                        $p = $d->at($i, 3);
                        $x = $f($p, $ease, [0, 0], [0.3333, 0], [0.6666, -$gap], [1, -2 * $gap]);
                        $a = $f($p, $ease, [0, 0], [0.3333, 180], [0.6666, 180], [1, 180]);
                    }
                    [$px, $py] = Drawing::polar($mx + $x, $my, $gap, deg2rad($a + 180));
                    $d->dot($id, $px, $py, $dot / 2, 1);
                }
            }),
            'linearDots' => new Spec(0.9, 0.9375, static function (Drawing $d) use ($id, $linear, $f): void {
                $dot = 0.1875 * $d->s;
                for ($i = 0; $i < 3; $i++) {
                    $a = $d->still ? 0.75 : $f($d->at($i, 3), $linear, [0, 1], [0.6667, 0.5], [1, 1]);
                    $d->dot($id, $d->x + $dot / 2 + $i * 2 * $dot, $d->y + $d->s / 2, $dot / 2, $a);
                }
            }),
            'loading' => new Spec(1.0, 1, static function (Drawing $d): void {
                $v = $d->view(15);
                foreach ([[12, 6], [10, 10], [6, 12], [2, 10], [0, 6], [2, 2], [6, 0], [10, 2]] as $i => [$x, $y]) { // the mark's segments, in a viewBox of 15
                    $a = $d->fade($i, 8, 0.2, 0.6);
                    foreach ([[0, 0], [2, 0], [0, 2], [2, 2]] as [$bx, $by]) {
                        $d->rounded($v, $x + $bx, $y + $by, 1, 1, 0, 0, $a);
                    }
                }
            }),
            'morph' => new Spec(1.2, 1, static function (Drawing $d) use ($id, $ease, $f): void {
                [$cx, $cy] = $d->center();
                $side = 0.64 * $d->s;
                [$r, $a] = [0.08 * $d->s, 0.0];
                if (!$d->still) {
                    $p = $d->at(0, 0);
                    $r = $f($p, $ease, [0, $r], [0.5, $side / 2], [1, $r]);
                    $a = $f($p, $ease, [0, 0], [0.5, 45], [1, 90]);
                }
                $d->rounded($id->rotate($a, $cx, $cy), $cx - $side / 2, $cy - $side / 2, $side, $side, $r, 0, 1);
            }),
            'orbit' => new Spec(0.75, 1, static function (Drawing $d) use ($id): void {
                [$cx, $cy] = $d->center();
                $d->dot($id, $cx, $cy, 0.125 * $d->s, 1);
                // Half a ring, from transparent at its start to opaque at its end.
                [$r, $w] = [0.42 * $d->s, 0.0625 * $d->s];
                $d->turned($id, $cx, $cy, $d->spin(false, 0), static function (Matrix $m) use ($d, $cx, $cy, $r, $w): void {
                    [$a0, $a1] = [M_PI / 2, 3 * M_PI / 2];
                    $s = $d->begin($m)->arc($cx, $cy, $r, $a0, $a1, true)->arc($cx, $cy, $r - $w, $a1, $a0, false)->close();
                    // Empty subpaths at the corners of the whole ring's box, which the gradient turns around the middle of.
                    $s->move($cx - $r, $cy - $r)->line($cx - $r, $cy - $r)->close()->move($cx + $r, $cy + $r)->line($cx + $r, $cy + $r)->close();
                    $d->p->fillPathConicGradient($s->path, new ConicGradient([new GradientStop($d->color, 0, 0), new GradientStop($d->color, 0.5), new GradientStop($d->color, 1)], angle: 180));
                });
            }),
            'pulse' => new Spec(1.2, 1, static function (Drawing $d): void {
                $v = $d->view(16);
                [$k, $a] = [1.0, 0.2];
                if (!$d->still) {
                    $e = Drawing::easeOut($d->at(0, 0));
                    [$k, $a] = [0.25 + 0.75 * $e, 0.4 * (1 - $e)];
                }
                $d->dot($v, 8, 8, 8 * $k, $a);
                $d->dot($v, 8, 8, 2, 1);
            }),
            'radar' => new Spec(1.5, 1, static function (Drawing $d): void {
                $v = $d->view(16);
                $d->dot($v, 8, 8, 8, 0.2);
                $d->hoop($v, 8, 8, 6, 5, 0.2);
                $d->turned($v, 8, 8, $d->spin(false, 0), static fn(Matrix $m) => self::beam($d, $m));
                $d->dot($v, 8, 8, 2, 1);
            }),
            'ring' => new Spec(0.8, 1, static function (Drawing $d): void {
                $v = $d->view(24);
                $d->hoop($v, 12, 12, 11.25, 8.75, 0.2);
                $a = $d->spin(false, 0);
                $d->stroke($v, 12, 12, 10, 2.5, $a, $a + 16.0 / 10, 1);
            }),
            'ripple' => new Spec(1.2, 1, static function (Drawing $d) use ($id): void {
                [$cx, $cy] = $d->center();
                for ($i = 0; $i < 3; $i++) {
                    [$k, $a] = [($i + 1) / 3, 0.4];
                    if (!$d->still) {
                        $p = $d->at($i, 3);
                        if ($d->o->direction === RippleDirection::RippleIn) { $p = 1 - $p; }
                        $e = Drawing::easeOut($p);
                        [$k, $a] = [$e, 1 - $e];
                    }
                    if ($k <= 0) { continue; }
                    $r = $d->s / 2 * $k;
                    $d->hoop($id, $cx, $cy, $r, $r - 0.08 * $d->s * $k, $a);
                }
            }),
            'slide' => new Spec(2.4, 1, static function (Drawing $d) use ($id, $ease, $f): void {
                $dot = 0.34 * $d->s;
                $far = 1.588 * $dot;
                $rest = [[$far, 0], [0, 0], [0, $far]];
                for ($i = 0; $i < 3; $i++) {
                    // Around the square's corners, to the one empty, and waiting there for the others.
                    [$x, $y] = $rest[$i];
                    if (!$d->still) {
                        $p = $d->at($i, 3);
                        $x = $f($p, $ease, [0, $far], [0.0833, $far], [0.25, $far], [0.3333, 0], [0.5, 0], [0.5833, 0], [0.75, 0], [0.8333, $far], [1, $far]);
                        $y = $f($p, $ease, [0, 0], [0.0833, $far], [0.25, $far], [0.3333, $far], [0.5, $far], [0.5833, 0], [0.75, 0], [0.8333, 0], [1, 0]);
                    }
                    $d->dot($id, $d->x + 0.06 * $d->s + $dot / 2 + $x, $d->y + 0.06 * $d->s + $dot / 2 + $y, $dot / 2, 1);
                }
            }),
            'snake' => new Spec(1.4, 1, static function (Drawing $d) use ($ease, $f): void {
                $v = $d->view(24);
                $a = $d->spin(false, 0);
                // A dash stretching to 45 long, then going on at that length, as its start catches up with its end.
                [$dash, $offset] = [18.0, 0.0];
                if (!$d->still) {
                    $p = $d->at(0, 0);
                    $dash = $f($p, $ease, [0, 1], [0.5, 45], [1, 45]);
                    $offset = $f($p, $ease, [0, 0], [0.5, -17], [1, -62]);
                }
                Drawing::dashes(2 * M_PI * 10, $dash, 100, $offset, static function (float $from, float $to) use ($d, $v, $a): void {
                    $d->stroke($v, 12, 12, 10, 2.5, $a + $from / 10, $a + $to / 10, 1);
                });
            }),
            'swirl' => new Spec(1.2, 1, static function (Drawing $d) use ($id): void {
                [$pad, $gap] = [0.0625 * $d->s, 0.15625 * $d->s];
                $cell = ($d->s - 2 * $pad - 2 * $gap) / 3;
                foreach ([0, 1, 2, 7, -1, 3, 6, 5, 4] as $i => $step) { // the cells' order, by rows, around the grid; -1 its empty middle
                    if ($step < 0) { continue; }
                    [$x, $y] = [$d->x + $pad + ($i % 3) * ($cell + $gap), $d->y + $pad + intdiv($i, 3) * ($cell + $gap)];
                    $d->rounded($id, $x, $y, $cell, $cell, 0.0625 * $d->s, 0, $d->fade($step, 8, 0.2, 0.6));
                }
            }),
            'trace' => new Spec(1.2, 1, static function (Drawing $d): void {
                $v = $d->view(20);
                $out = self::TRACE_WIDTH / 2;
                $d->rounded($v, self::TRACE_AT - $out, self::TRACE_AT - $out, self::TRACE_SIDE + 2 * $out, self::TRACE_SIDE + 2 * $out, self::TRACE_RADIUS + $out, self::TRACE_WIDTH, 0.2);
                $perimeter = self::tracePerimeter();
                $at = $d->still ? 0.0 : $d->turn($d->at(0, 0), false) * $perimeter;
                Drawing::dashes($perimeter, self::TRACE_DASH, $perimeter - self::TRACE_DASH, -$at, static fn(float $from, float $to) => self::traceDash($d, $v, $from, $to));
            }),
            'wave' => new Spec(0.9, 1, static function (Drawing $d) use ($id, $ease, $f): void {
                $w = 0.12 * $d->s;
                for ($i = 0; $i < 5; $i++) {
                    $h = $d->still ? 0.4 + 0.15 * $i : $f($d->at($i, 5), $ease, [0, 0.3], [0.5, 1], [1, 0.3]);
                    $bh = $h * $d->s;
                    $y = $d->o->origin === WaveOrigin::WaveBottom ? $d->y + $d->s - $bh : $d->y + ($d->s - $bh) / 2;
                    $d->rounded($id, $d->x + $i * ($d->s - $w) / 4, $y, $w, $bh, $w / 2, 0, 1);
                }
            }),
        ];
    }

    /**
     * Radar's beam through $m, in its viewBox of 16: a sector of the ring
     * between radii 3 and 8, from the top to its leading edge, opaque inside
     * a third of the radius and fading to transparent at its rim, as
     * loading.dev's radial gradient.
     */
    private static function beam(Drawing $d, Matrix $m): void
    {
        [$in, $out, $solid] = [3.0, 8.0, 8 * 0.3334];
        [$ix, $iy, $ox, $oy] = [10.4707 - 8, 9.69922 - 8, 14.249 - 8, 12.9951 - 8]; // the leading edge, from the inner circle to the outer one
        $opacity = fn(float $r): float => max(0.0, min(1.0, 1 - ($r - $solid) / ($out - $solid)));
        // Sectors of growing radii over each other, each as opaque as makes the ring between its radius and the one before the gradient's opacity there: their edges inside the beam meet no other edge, as bands side by side would.
        $n = (int)max(6, min(40, ceil(($out - $in) * $m->unit() / 1.5)));
        $band = fn(int $k): float => $in + ($out - $in) * ($k - 0.5) / $n;
        for ($k = 1; $k <= $n; $k++) {
            [$inside, $beyond] = [$opacity($band($k)), $k < $n ? $opacity($band($k + 1)) : 0.0];
            $a = 1 - (1 - $inside) / (1 - $beyond);
            $r = $in + ($out - $in) * $k / $n;
            // Where the leading edge crosses the circle of radius r.
            [$dx, $dy] = [$ox - $ix, $oy - $iy];
            [$qa, $qb, $qc] = [$dx * $dx + $dy * $dy, 2 * ($ix * $dx + $iy * $dy), $ix * $ix + $iy * $iy - $r * $r];
            $u = max(0.0, min(1.0, (-$qb + sqrt(max(0.0, $qb * $qb - 4 * $qa * $qc))) / (2 * $qa)));
            $end = atan2($iy + $u * $dy, $ix + $u * $dx);
            $d->begin($m)->move(8, 8 - $in)->line(8, 8 - $r)
                ->arc(8, 8, $r, -M_PI / 2, $end, false)
                ->line(8 + $ix, 8 + $iy)
                ->arc(8, 8, $in, atan2($iy, $ix), -M_PI / 2, false)->close();
            $d->fill($a);
        }
    }

    /** Trace's rounded square, in its viewBox of 20, and its dash. */
    private const TRACE_AT = 1.25, TRACE_SIDE = 17.5, TRACE_RADIUS = 4.0, TRACE_WIDTH = 2.5, TRACE_DASH = 16.0;

    /** The length of Trace's rounded square. */
    private static function tracePerimeter(): float
    {
        return 4 * (self::TRACE_SIDE - 2 * self::TRACE_RADIUS) + 2 * M_PI * self::TRACE_RADIUS;
    }

    /** Trace's dash from $from to $to along its rounded square, through $v. */
    private static function traceDash(Drawing $d, Matrix $v, float $from, float $to): void
    {
        [$at, $side, $radius, $width] = [self::TRACE_AT, self::TRACE_SIDE, self::TRACE_RADIUS, self::TRACE_WIDTH];
        $straight = $side - 2 * $radius;
        $corner = M_PI / 2 * $radius;
        // The point at length u along the square, from the start of its top side, as SVG's rect starts, and the direction it goes there, in radians.
        $along = static function (float $u) use ($at, $side, $radius, $straight, $corner): array {
            $s = min(3, (int)($u / ($straight + $corner)));
            $u -= $s * ($straight + $corner);
            $dir = $s * M_PI / 2;
            [$sin, $cos] = [sin($dir), cos($dir)];
            $starts = [[$at + $radius, $at], [$at + $side, $at + $radius], [$at + $side - $radius, $at + $side], [$at, $at + $side - $radius]]; // where each side starts: the top's after the top left corner
            [$x, $y] = $starts[$s];
            if ($u <= $straight) { return [$x + $u * $cos, $y + $u * $sin, $dir]; }
            // Around the corner, whose center is inward of the side's end.
            [$x, $y] = [$x + $straight * $cos - $radius * $sin, $y + $straight * $sin + $radius * $cos];
            $a = $dir - M_PI / 2 + ($u - $straight) / $radius;
            return [$x + $radius * cos($a), $y + $radius * sin($a), $a + M_PI / 2];
        };
        $n = (int)ceil(($to - $from) / 0.4) + 1;
        $s = $d->begin($v);
        if ($d->o->cap === Cap::FlatCap) { // the dash's outline: one edge there, the other back
            for ($i = 0; $i < 2 * $n; $i++) {
                [$j, $edge] = $i >= $n ? [2 * $n - 1 - $i, 1.0] : [$i, -1.0];
                [$x, $y, $dir] = $along($from + ($to - $from) * $j / ($n - 1));
                [$px, $py] = [$x - $edge * $width / 2 * sin($dir), $y + $edge * $width / 2 * cos($dir)];
                $i === 0 ? $s->move($px, $py) : $s->line($px, $py);
            }
            $s->close();
            $d->fill(1);
            return;
        }
        for ($i = 0; $i < $n; $i++) {
            [$x, $y] = $along($from + ($to - $from) * $i / ($n - 1));
            $i === 0 ? $s->move($x, $y) : $s->line($x, $y);
        }
        $d->p->strokePath($s->path, $width * $v->unit(), $d->color);
    }
}
