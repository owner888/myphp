<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

use Closure;
use MyPHP\UI\Painter;
use MyPHP\UI\Transform;

/**
 * An indicator's frame being painted (mygo's loading drawing): its square
 * of side $s at ($x, $y), how many cycles it has run, its options; what
 * its parts are drawn with.
 *
 * @internal Loading
 */
final class Drawing
{
    public float $x = 0, $y = 0, $s = 0, $cycles = 0;

    public function __construct(
        public readonly Painter $p,
        public readonly Shape $sh,
        public readonly int $color,
        public readonly Options $o,
        /** Drawn at rest, where the desktop asks for less motion. */
        public readonly bool $still,
    ) {}

    /**
     * How far into its cycle part $step of $count is, 0 to 1, the parts
     * staggered as loading.dev's: each a $count-th of a cycle behind the one
     * before, the first a whole cycle ahead, as a negative animation-delay.
     * A $count of 0 is the indicator's own cycle.
     */
    public function at(int $step, int $count): float
    {
        $t = $this->cycles;
        if ($count > 0) { $t += ($count - $step) / $count; }
        return $t - floor($t);
    }

    /** How much of a turn it has turned at $p of its cycle, the other way if $reverse, as Options::$easing says. */
    public function turn(float $p, bool $reverse): float
    {
        if ($reverse) { $p = 1 - $p; }
        return match ($this->o->easing) {
            Easing::EaseInOut => self::easeInOut($p),
            Easing::Stacked => $p + self::easeInOut($p),
            Easing::Linear => $p,
        };
    }

    /** The angle, radians, an indicator turning once a cycle has turned, as Options::$easing says, the other way if $reverse; at rest, $rest. */
    public function spin(bool $reverse, float $rest): float
    {
        return $this->still ? $rest : $this->turn($this->at(0, 0), $reverse) * 2 * M_PI;
    }

    /** @return array{0: float, 1: float} the middle of the indicator's square */
    public function center(): array
    {
        return [$this->x + $this->s / 2, $this->y + $this->s / 2];
    }

    /** The map from a viewBox of side $n, as loading.dev's SVGs draw in, to the indicator's square. */
    public function view(float $n): Matrix
    {
        $k = $this->s / $n;
        return new Matrix($k, 0, 0, $k, $this->x, $this->y);
    }

    /** The shape emptied, drawing through $m. */
    public function begin(Matrix $m): Shape
    {
        $this->sh->reset();
        $this->sh->m = $m;
        return $this->sh;
    }

    /** Fills the shape in the indicator's color at opacity $a. */
    public function fill(float $a): void
    {
        $this->p->fillPath($this->sh->path, $this->color, $a);
    }

    /** The opacity of part $step of $count of the indicators whose parts fade in turn (loading.dev's fadeCss): from 1 down to $dim through each cycle; $rest at rest. */
    public function fade(int $step, int $count, float $dim, float $rest): float
    {
        return $this->still ? $rest : 1 + ($dim - 1) * $this->at($step, $count);
    }

    /**
     * Draws an arc of the circle of radius $r around ($cx, $cy) in $m's
     * coordinates, $w wide, from angle $a0 to $a1, ended as Options::$cap
     * says, at opacity $a. $m maps without turning: the arc is drawn from
     * angle 0 and turned to $a0 by the renderer, so that an arc of the same
     * length is the same mask from frame to frame.
     */
    public function stroke(Matrix $m, float $cx, float $cy, float $r, float $w, float $a0, float $a1, float $a): void
    {
        if ($a1 <= $a0) { return; }
        $this->turned($m, $cx, $cy, $a0, function (Matrix $m) use ($cx, $cy, $r, $w, $a0, $a1, $a): void {
            $s = $this->begin($m);
            if ($this->o->cap === Cap::FlatCap) {
                $s->arc($cx, $cy, $r + $w / 2, 0, $a1 - $a0, true);
                $s->arc($cx, $cy, $r - $w / 2, $a1 - $a0, 0, false)->close();
                $this->fill($a);
                return;
            }
            $s->arc($cx, $cy, $r, 0, $a1 - $a0, true);
            $this->p->strokePath($s->path, $w * $m->unit(), $this->color, $a);
        });
    }

    /**
     * Draws what $fn draws through $m turned by $angle radians clockwise
     * around ($cx, $cy) in $m's coordinates. The renderer turns it: what $fn
     * draws at rest is the same path from frame to frame, whose mask it
     * rasterizes once. $fn draws through the map it is given, which
     * rasterizes the mask twice as fine, for the renderer to sample it
     * turned as sharp.
     * @param Closure(Matrix): void $fn
     */
    public function turned(Matrix $m, float $cx, float $cy, float $angle, Closure $fn): void
    {
        [$px, $py] = $m->apply($cx, $cy);
        $this->p->transform(Transform::scaling(0.5, 0.5)->then(Transform::rotation(rad2deg($angle))), $px, $py, function () use ($fn, $m, $px, $py): void {
            $fn(new Matrix()->scale(2, 2, $px, $py)->then($m));
        });
    }

    /**
     * Draws the rectangle ($x, $y, $w, $h) with corners of radius $r in $m's
     * coordinates, filled, or its border $bw wide if $bw > 0, at opacity
     * $a: the renderer draws it, through any transform, as a node's
     * background, no path to rasterize into a mask every frame.
     */
    public function rounded(Matrix $m, float $x, float $y, float $w, float $h, float $r, float $bw, float $a): void
    {
        $t = $m->move($x, $y)->transform();
        if ($t !== null) { $this->roundedIn($t, $w, $h, $r, $bw, $a); }
    }

    /** rounded() through $t, which maps the rectangle $w × $h at the origin to the indicator. */
    public function roundedIn(Transform $t, float $w, float $h, float $r, float $bw, float $a): void
    {
        if ($w <= 0 || $h <= 0 || $a <= 0) { return; }
        // fill and stroke put a rectangle and its border on whole pixels: drawn ZOOM times as large and scaled back, they keep their sizes to a ZOOM-th of a pixel, as they move and grow.
        $zoom = 8;
        $box = [0.0, 0.0, $w * $zoom, $h * $zoom];
        $this->p->transform(Transform::scaling(1 / $zoom, 1 / $zoom)->then($t), 0, 0, function () use ($box, $r, $bw, $a, $zoom): void {
            if ($bw > 0) { $this->p->stroke($box, $this->color, $r * $zoom, $bw * $zoom, $a); } else { $this->p->fill($box, $this->color, $r * $zoom, $a); }
        });
    }

    /** Draws the circle of radius $r around ($cx, $cy) in $m's coordinates at opacity $a. */
    public function dot(Matrix $m, float $cx, float $cy, float $r, float $a): void
    {
        $this->rounded($m, $cx - $r, $cy - $r, 2 * $r, 2 * $r, $r, 0, $a);
    }

    /** Draws the ring between radii $out and $in around ($cx, $cy) in $m's coordinates at opacity $a, as a circle's border. */
    public function hoop(Matrix $m, float $cx, float $cy, float $out, float $in, float $a): void
    {
        $this->rounded($m, $cx - $out, $cy - $out, 2 * $out, 2 * $out, $out, max(0, $out - $in), $a);
    }

    /** Draws the line from ($x0, $y0) to ($x1, $y1) in $m's coordinates, $w wide, with round ends, at opacity $a. */
    public function capsule(Matrix $m, float $x0, float $y0, float $x1, float $y1, float $w, float $a): void
    {
        [$dx, $dy] = [$x1 - $x0, $y1 - $y0];
        $m = $m->rotate(rad2deg(atan2($dy, $dx)), $x0, $y0);
        $this->rounded($m, $x0 - $w / 2, $y0 - $w / 2, hypot($dx, $dy) + $w, $w, $w / 2, 0, $a);
    }

    /** CSS's ease-in-out. */
    public static function easeInOut(float $t): float
    {
        static $f = null;
        return ($f ??= self::bezier(0.42, 0, 0.58, 1))($t);
    }

    /** CSS's ease-out. */
    public static function easeOut(float $t): float
    {
        static $f = null;
        return ($f ??= self::bezier(0, 0, 0.58, 1))($t);
    }

    /** Cascade's overshooting timing, cubic-bezier(0.68, -0.75, 0.265, 1.75). */
    public static function overshoot(float $t): float
    {
        static $f = null;
        return ($f ??= self::bezier(0.68, -0.75, 0.265, 1.75))($t);
    }

    /** CSS's cubic-bezier($x1, $y1, $x2, $y2). @return Closure(float): float */
    public static function bezier(float $x1, float $y1, float $x2, float $y2): Closure
    {
        $cx = 3 * $x1; $bx = 3 * ($x2 - $x1) - $cx; $ax = 1 - $cx - $bx;
        $cy = 3 * $y1; $by = 3 * ($y2 - $y1) - $cy; $ay = 1 - $cy - $by;
        $x = fn(float $s): float => (($ax * $s + $bx) * $s + $cx) * $s;
        $y = fn(float $s): float => (($ay * $s + $by) * $s + $cy) * $s;
        return static function (float $t) use ($x, $y, $ax, $bx, $cx): float {
            if ($t <= 0 || $t >= 1) { return $t; }
            // Newton's method, then bisection where the slope is flat.
            $s = $t;
            for ($i = 0; $i < 8; $i++) {
                $e = $x($s) - $t;
                if (abs($e) < 1e-7) { return $y($s); }
                $dx = (3 * $ax * $s + 2 * $bx) * $s + $cx;
                if (abs($dx) < 1e-6) { break; }
                $s -= $e / $dx;
            }
            [$lo, $hi, $s] = [0.0, 1.0, $t];
            for ($i = 0; $i < 40; $i++) {
                if ($x($s) < $t) { $lo = $s; } else { $hi = $s; }
                $s = ($lo + $hi) / 2;
            }
            return $y($s);
        };
    }

    /**
     * The value of keyframes $ks ([at, value] each) at $p of a cycle, each
     * stretch between two eased by $ease, as CSS's timing functions are.
     * @param Closure(float): float $ease
     * @param array{0: float, 1: float} ...$ks
     */
    public static function frames(float $p, Closure $ease, array ...$ks): float
    {
        for ($i = 1; $i < count($ks); $i++) {
            [$a, $b] = [$ks[$i - 1], $ks[$i]];
            if ($p <= $b[0]) {
                return $b[0] <= $a[0] ? $b[1] : $a[1] + ($b[1] - $a[1]) * $ease(($p - $a[0]) / ($b[0] - $a[0]));
            }
        }
        return $ks[count($ks) - 1][1];
    }

    /**
     * Calls $fn with each dash of a pattern of dashes $dash long and gaps
     * $gap long, shifted by $offset as SVG's stroke-dashoffset shifts it, on
     * a path $length long: from and to along it.
     * @param Closure(float, float): void $fn
     */
    public static function dashes(float $length, float $dash, float $gap, float $offset, Closure $fn): void
    {
        $period = $dash + $gap;
        if ($dash <= 0 || $period <= 0) { return; }
        for ($at = fmod(-$offset, $period) - $period; $at < $length; $at += $period) { // from the last dash starting before the path
            [$from, $to] = [max($at, 0.0), min($at + $dash, $length)];
            if ($to > $from) { $fn($from, $to); }
        }
    }

    /** @return array{0: float, 1: float} the point at radius $r and angle $a (radians clockwise from the right) around ($cx, $cy) */
    public static function polar(float $cx, float $cy, float $r, float $a): array
    {
        return [$cx + $r * cos($a), $cy + $r * sin($a)];
    }
}
