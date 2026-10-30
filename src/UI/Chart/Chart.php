<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

use Closure;
use MyPHP\UI\{Context, Key, Node, Path, Track};

/**
 * Charts in native UI without a look of their own (mygo's ui/chart), as
 * headless libraries do on the web: scales that map data onto a plot and
 * pick round ticks (Linear, Band, Time), a plot with axes around it, marks
 * that are nodes you style as any other, paths of lines, areas and pie
 * slices for draw functions, stacking, and a selection that the pointer
 * and the keyboard move.
 *
 *     $x = (new Band(...$months))->padding(0.3, 0.15);
 *     $y = (new Linear(0, 120))->nice(4);
 *     Chart::plot($c, new Axes(left: $y->ticks(4), bottom: $x->ticks()), function (Node $area) use ($c, $x, $y, $sales, $t) {
 *         foreach ($sales as $i => $v) {
 *             Chart::rect($c, $x->band($i), $y->span(0, $v))->background($t->accent)->round(4);
 *         }
 *     })->size(0, 200);
 *
 * Positions along a plot are fractions of it, from 0 to 1: along x from
 * its left edge, and along y from its bottom up, the way values grow.
 * Marks place themselves in percentages of the plot (Node::leftPercent…),
 * so they follow it as it resizes; draw functions get the plot's box, and
 * x() and y() turn fractions into points in it.
 */
final class Chart
{
    /** A size of none, which size()'s 0 would leave to the content: the slot of an axis's label, which overflows it, centered. */
    private const NONE = 1e-6;

    /**
     * Builds a plot with the axes around it: a grid whose middle cell is
     * the plot's area, which $build gets to style, as with a background or
     * a draw function painting grid lines, and to add marks to. Give the
     * plot a height, and a gap to keep the labels away from the area. Name
     * it for assistive technology with label().
     *
     * @param Closure(Node): void $build
     */
    public static function plot(Context $c, Axes $axes, Closure $build): Node
    {
        $label = $axes->label ?? fn(Side $s, Tick $t) => $c->text($t->label)->noWrap();
        $cols = [Track::fr()];
        $rows = [Track::fr()];
        [$col, $row] = [1, 1];
        if ($axes->left !== []) {
            array_unshift($cols, Track::fit());
            $col = 2;
        }
        if ($axes->right !== []) {
            $cols[] = Track::fit();
        }
        if ($axes->top !== []) {
            array_unshift($rows, Track::fit());
            $row = 2;
        }
        if ($axes->bottom !== []) {
            $rows[] = Track::fit();
        }
        $grid = $c->grid($cols, 0, $rows);
          $area = $c->column()->columnStart($col)->rowStart($row);
            $build($area);
          $c->end();
          if ($axes->left !== []) { self::axis($c, Side::Left, $axes->left, $label)->columnStart(1)->rowStart($row); }
          if ($axes->right !== []) { self::axis($c, Side::Right, $axes->right, $label)->columnStart($col + 1)->rowStart($row); }
          if ($axes->top !== []) { self::axis($c, Side::Top, $axes->top, $label)->columnStart($col)->rowStart(1); }
          if ($axes->bottom !== []) { self::axis($c, Side::Bottom, $axes->bottom, $label)->columnStart($col)->rowStart($row + 1); }
        $c->end();
        return $grid;
    }

    /**
     * The labels of ticks along a side of the plot: each in a box of no
     * width (or height) at its tick, which centers it there, with spaces
     * between them that grow by the fractions of the plot between the
     * ticks. The labels stay in the layout, so the axis is as wide (or as
     * high) as they need.
     * @param list<Tick> $ticks
     */
    private static function axis(Context $c, Side $side, array $ticks, Closure $label): Node
    {
        $vertical = $side === Side::Left || $side === Side::Right;
        $pos = fn(Tick $t): float => $vertical ? 1 - $t->at : $t->at; // from the axis's start: the left, or the top
        usort($ticks, fn(Tick $a, Tick $b): int => $pos($a) <=> $pos($b)); // stable, as PHP's sort is
        $e = $vertical ? $c->column() : $c->row();
          $prev = 0.0;
          foreach ($ticks as $t) {
              $p = $pos($t);
              if ($p < -1e-4 || $p > 1 + 1e-4) {
                  continue;
              }
              $p = max($prev, min(1.0, $p));
              $c->column()->basis(0)->grow($p - $prev); $c->end();
              $slot = match ($side) {
                  Side::Left => $c->row()->size(0, self::NONE)->align(Node::CENTER)->justify(Node::END),
                  Side::Right => $c->row()->size(0, self::NONE)->align(Node::CENTER),
                  Side::Top => $c->column()->size(self::NONE, 0)->align(Node::CENTER)->justify(Node::END),
                  Side::Bottom => $c->column()->size(self::NONE, 0)->align(Node::CENTER),
              };
                $label($side, $t);
              $c->end();
              $prev = $p;
          }
          $c->column()->basis(0)->grow(1 - $prev); $c->end();
        $c->end();
        return $e;
    }

    /**
     * Places a mark over the stretch $x along the plot and $y up it, as a
     * bar, a band of a range or a cell of a heat map: a node in the plot's
     * area, which you style as any other, and name for assistive
     * technology. Build it in the function of plot().
     *
     *     Chart::rect($c, $x->band($i), $y->span(0, $v))->background($t->accent)->round(4)->tooltip($label);
     */
    public static function rect(Context $c, Span $x, Span $y): Node
    {
        [$x0, $x1] = [min($x->lo, $x->hi), max($x->lo, $x->hi)];
        [$y0, $y1] = [min($y->lo, $y->hi), max($y->lo, $y->hi)];
        $n = $c->column()->absolute()->leftPercent(100 * $x0)->widthPercent(100 * ($x1 - $x0))->topPercent(100 * (1 - $y1))->heightPercent(100 * ($y1 - $y0));
        $c->end();
        return $n;
    }

    /** Places a mark of $w by $h points centered on the point at $x along the plot and $y up it, as a dot of a scatter plot or the point a selection is on. */
    public static function point(Context $c, float $x, float $y, float $w, float $h): Node
    {
        $n = $c->column()->absolute()->leftPercent(100 * $x)->topPercent(100 * (1 - $y))->size($w, $h)->margin(-$h / 2, 0, 0, -$w / 2);
        $c->end();
        return $n;
    }

    /** Places a vertical line of $width points across the plot at $x along it, as a threshold or the selection of a line chart. Color it with background(). */
    public static function ruleX(Context $c, float $x, float $width): Node
    {
        $n = $c->column()->absolute()->leftPercent(100 * $x)->topPercent(0)->heightPercent(100)->size($width, 0)->margin(0, 0, 0, -$width / 2);
        $c->end();
        return $n;
    }

    /** Places a horizontal line of $height points across the plot at $y up it. */
    public static function ruleY(Context $c, float $y, float $height): Node
    {
        $n = $c->column()->absolute()->topPercent(100 * (1 - $y))->leftPercent(0)->widthPercent(100)->size(0, $height)->margin(-$height / 2, 0, 0, 0);
        $c->end();
        return $n;
    }

    /**
     * Builds what $build adds at the point at $x along the plot and $y up
     * it, of any size: a label over a bar, or a tooltip by the selection.
     * $h and $v place it: Node::CENTER centers it on the point, Node::START
     * puts its left ($h) or top ($v) edge there, Node::END its right or
     * bottom edge, so that END, END shows it above and to the left of the
     * point.
     */
    public static function anchor(Context $c, float $x, float $y, int $h, int $v, Closure $build): Node
    {
        $n = $c->row()->absolute()->leftPercent(100 * $x)->topPercent(100 * (1 - $y))->size(self::NONE, self::NONE)->align($v);
          $c->column()->size(self::NONE, 0)->align($h);
            $build();
          $c->end();
        $c->end();
        return $n;
    }

    /**
     * Makes the plot's area choose one of the points at $xs, fractions along
     * it in ascending order: $index is the one nearest the pointer while it
     * is over the area, and -1, none, once it leaves. The area takes the
     * keyboard focus, as one stop of Tab, where the left and right arrows,
     * Home and End move the choice, and Escape clears it. Returns whether
     * the choice changed.
     *
     * $describe tells assistive technology about a point, "March: 42
     * sales", which it reads as the area's value, and as the arrows come to
     * the point. Show the choice with marks, as a rule, a point and a label.
     *
     * @param list<float> $xs
     * @param ?Closure(int): string $describe
     */
    public static function select(Context $c, Node $area, int &$index, array $xs, ?Closure $describe = null): bool
    {
        $area->focusable();
        $rt = $c->runtime;
        $fresh = fn() => (object)['hovering' => false, 'x' => 0.0, 'last' => -1, 'focused' => false];
        $st = $rt?->local($area->id, $fresh) ?? $fresh();
        $old = $index;
        $n = count($xs);
        if ($index >= $n) {
            $index = -1;
        }
        $announce = false;
        $move = function (int $i) use (&$index, &$announce, $n): void {
            $index = max(0, min($n - 1, $i));
            $announce = true;
        };
        $focused = $rt !== null && $rt->focus === $area->id;
        if ($n > 0 && $focused) {
            if ($c->shortcut(Key::RIGHT)) { $move($index < 0 ? 0 : $index + 1); }
            elseif ($c->shortcut(Key::LEFT)) { $move($index < 0 ? $n - 1 : $index - 1); }
            elseif ($c->shortcut(Key::HOME)) { $move(0); }
            elseif ($c->shortcut(Key::END)) { $move($n - 1); }
        }
        if ($index >= 0 && ($focused || $st->focused) && $c->shortcut(Key::ESCAPE)) {
            $index = -1; // Escape lets the focus go too, as of every control's
        }
        $st->focused = $focused;
        // The pointer chooses as it comes and moves, not while it rests where it was as the keys move the choice.
        $r = $rt?->rectOf($area->id);
        if ($r !== null && $c->hovered($area)) {
            $x = $rt->input->mouseX - $r[0];
            if ($r[2] > 0 && $n > 0 && !$announce && (!$st->hovering || $x != $st->x)) {
                $index = self::nearest($xs, $x / $r[2]);
            }
            [$st->hovering, $st->x] = [true, $x];
        } elseif ($st->hovering) {
            $st->hovering = false;
            $index = -1;
        }
        if ($index >= 0 && $describe !== null) {
            $desc = $describe($index);
            $area->value($desc);
            if ($announce && $index !== $st->last) {
                $c->announce($desc);
            }
        }
        $st->last = $index;
        return $index !== $old;
    }

    /** The x of $f, a fraction along a plot's width, in $r, the plot's box a draw function gets. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public static function x(array $r, float $f): float
    {
        return $r[0] + $f * $r[2];
    }

    /** The y of $f, a fraction up a plot's height, in $r: 0 is its bottom and 1 its top. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public static function y(array $r, float $f): float
    {
        return $r[1] + (1 - $f) * $r[3];
    }

    /**
     * The pieces of a line through $pts, after the first point, as the
     * curve goes: [to, c1, c2], cubic when it has control points.
     * @internal
     * @param list<array{0: float, 1: float}> $pts
     * @return list<array{to: array{0: float, 1: float}, c1?: array{0: float, 1: float}, c2?: array{0: float, 1: float}}>
     */
    public static function curve(array $pts, Curve $k): array
    {
        $n = count($pts);
        $segs = [];
        if ($k === Curve::Monotone && $n >= 3) {
            // Each point's slope, as d3's curveMonotoneX: Steffen's inside, and at the ends one from the last segment's slope and the neighbor's.
            $m = array_fill(0, $n, 0.0);
            for ($i = 1; $i < $n - 1; $i++) {
                $m[$i] = self::monotoneSlope($pts[$i - 1], $pts[$i], $pts[$i + 1]);
            }
            $m[0] = self::endSlope($pts[0], $pts[1], $m[1]);
            $m[$n - 1] = self::endSlope($pts[$n - 2], $pts[$n - 1], $m[$n - 2]);
            for ($i = 1; $i < $n; $i++) {
                [$p0, $p1] = [$pts[$i - 1], $pts[$i]];
                $dx = ($p1[0] - $p0[0]) / 3;
                $segs[] = ['to' => $p1, 'c1' => [$p0[0] + $dx, $p0[1] + $dx * $m[$i - 1]], 'c2' => [$p1[0] - $dx, $p1[1] - $dx * $m[$i]]];
            }
            return $segs;
        }
        if ($k === Curve::StepAfter || $k === Curve::StepBefore || $k === Curve::Step) {
            for ($i = 1; $i < $n; $i++) {
                [$p0, $p1] = [$pts[$i - 1], $pts[$i]];
                if ($k === Curve::StepAfter) {
                    $segs[] = ['to' => [$p1[0], $p0[1]]];
                } elseif ($k === Curve::StepBefore) {
                    $segs[] = ['to' => [$p0[0], $p1[1]]];
                } else {
                    $mid = ($p0[0] + $p1[0]) / 2;
                    $segs[] = ['to' => [$mid, $p0[1]]];
                    $segs[] = ['to' => [$mid, $p1[1]]];
                }
                $segs[] = ['to' => $p1];
            }
            return $segs;
        }
        foreach (array_slice($pts, 1) as $p) {
            $segs[] = ['to' => $p];
        }
        return $segs;
    }

    /** The slope at $b between $a and $c, as d3's slope3. */
    private static function monotoneSlope(array $a, array $b, array $c): float
    {
        [$h0, $h1] = [$b[0] - $a[0], $c[0] - $b[0]];
        if ($h0 == 0 || $h1 == 0) {
            return 0.0;
        }
        [$s0, $s1] = [($b[1] - $a[1]) / $h0, ($c[1] - $b[1]) / $h1];
        $p = ($s0 * $h1 + $s1 * $h0) / ($h0 + $h1);
        if (($s0 < 0) !== ($s1 < 0) || $s0 == 0 || $s1 == 0) {
            return 0.0;
        }
        $m = min(abs($s0), abs($s1), 0.5 * abs($p));
        return $s0 < 0 ? -2 * $m : 2 * $m;
    }

    /** The slope at an end of a line, from the slope $t at the point next to it, as d3's slope2. */
    private static function endSlope(array $a, array $b, float $t): float
    {
        $h = $b[0] - $a[0];
        return $h == 0 ? $t : (3 * ($b[1] - $a[1]) / $h - $t) / 2;
    }

    /** Calls $fn(from, to) with each run of indexes below $n for which $ok holds. */
    private static function runs(int $n, Closure $ok, Closure $fn): void
    {
        $from = -1;
        for ($i = 0; $i <= $n; $i++) {
            if ($i < $n && $ok($i)) {
                if ($from < 0) { $from = $i; }
                continue;
            }
            if ($from >= 0) {
                $fn($from, $i);
                $from = -1;
            }
        }
    }

    /** @param list<array{to: array, c1?: array, c2?: array}> $segs */
    private static function addSegments(Path $path, array $segs): void
    {
        foreach ($segs as $s) {
            isset($s['c1']) ? $path->cubeTo($s['c1'][0], $s['c1'][1], $s['c2'][0], $s['c2'][1], $s['to'][0], $s['to'][1]) : $path->lineTo($s['to'][0], $s['to'][1]);
        }
    }

    /**
     * The path of a line through the points at $xs and $ys, fractions along
     * and up the plot whose box is $r, joined as $k says. A NaN value
     * breaks the line there. Stroke it in a draw function of the plot.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     * @param list<float> $xs
     * @param list<float> $ys
     */
    public static function line(array $r, array $xs, array $ys, Curve $k = Curve::Linear): Path
    {
        return self::lineInto(new Path, $r, $xs, $ys, $k);
    }

    /**
     * Replaces $path with line()'s geometry and returns it, clearing its
     * trim (mygo's LineInto): one path kept per series of a live chart.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     * @param list<float> $xs
     * @param list<float> $ys
     */
    public static function lineInto(Path $path, array $r, array $xs, array $ys, Curve $k = Curve::Linear): Path
    {
        $path->reset();
        $n = min(count($xs), count($ys));
        self::runs($n, fn(int $i): bool => !is_nan($xs[$i]) && !is_nan($ys[$i]), function (int $from, int $to) use ($path, $r, $xs, $ys, $k): void {
            if ($k === Curve::Linear) { // straight to the path, without the points of a curve
                [$x0, $y0, $w, $h] = $r; // as x() and y()
                $path->moveTo($x0 + $xs[$from] * $w, $y0 + (1 - $ys[$from]) * $h);
                for ($i = $from + 1; $i < $to; $i++) {
                    $path->lineTo($x0 + $xs[$i] * $w, $y0 + (1 - $ys[$i]) * $h);
                }
                return;
            }
            $pts = [];
            for ($i = $from; $i < $to; $i++) {
                $pts[] = [self::x($r, $xs[$i]), self::y($r, $ys[$i])];
            }
            $path->moveTo($pts[0][0], $pts[0][1]);
            self::addSegments($path, self::curve($pts, $k));
        });
        return $path;
    }

    /**
     * The path of the area between two lines through the points at $xs,
     * $lo below and $hi above, joined as $k says, for areas and bands and
     * the layers of a stack; a null $lo puts the bottom line at the bottom
     * of the plot. A NaN value breaks the area there.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     * @param list<float> $xs
     * @param ?list<float> $lo
     * @param list<float> $hi
     */
    public static function area(array $r, array $xs, ?array $lo, array $hi, Curve $k = Curve::Linear): Path
    {
        $path = new Path;
        $n = min(count($xs), count($hi), $lo === null ? PHP_INT_MAX : count($lo));
        $low = fn(int $i): float => $lo === null ? 0.0 : $lo[$i];
        self::runs($n, fn(int $i): bool => !is_nan($xs[$i]) && !is_nan($hi[$i]) && !is_nan($low($i)), function (int $from, int $to) use ($path, $r, $xs, $hi, $low, $k): void {
            $top = $bottom = [];
            for ($i = $from; $i < $to; $i++) {
                $top[] = [self::x($r, $xs[$i]), self::y($r, $hi[$i])];
                $bottom[] = [self::x($r, $xs[$i]), self::y($r, $low($i))];
            }
            $path->moveTo($top[0][0], $top[0][1]);
            self::addSegments($path, self::curve($top, $k));
            // Back along the bottom line, the same curve the other way.
            $segs = self::curve($bottom, $k);
            $last = $bottom[count($bottom) - 1];
            $path->lineTo($last[0], $last[1]);
            for ($i = count($segs) - 1; $i >= 0; $i--) {
                $at = $i > 0 ? $segs[$i - 1]['to'] : $bottom[0];
                $s = $segs[$i];
                isset($s['c1']) ? $path->cubeTo($s['c2'][0], $s['c2'][1], $s['c1'][0], $s['c1'][1], $at[0], $at[1]) : $path->lineTo($at[0], $at[1]);
            }
            $path->close();
        });
        return $path;
    }

    /**
     * The lowest and highest of $xs and $ys in each pixel column of the
     * plot, in their order, and the ends of each run of defined points, so
     * that a line through them looks the same and narrow spikes still show
     * (mygo's MinMax): [xs, ys]. $xs and $ys are fractions, as for line();
     * $xs must not decrease, or the columns come out wrong, and a NaN or
     * infinite value breaks the line, where a NaN is between the runs.
     * Points left or right of the plot go, but for the nearest one on each
     * side. $pixelWidth is the plot's width in pixels, ceil(w * scale).
     * Keep the points themselves for selections and readings.
     *
     * @param list<float> $xs
     * @param list<float> $ys
     * @return array{0: list<float>, 1: list<float>}
     */
    public static function minMax(array $xs, array $ys, int $pixelWidth): array
    {
        $ox = $oy = [];
        if ($pixelWidth <= 0) {
            return [$ox, $oy];
        }
        $n = min(count($xs), count($ys));
        // The runs of defined points, without runs()' closures: a live chart reduces tens of thousands a frame.
        for ($from = 0; $from < $n;) {
            if (!is_finite($xs[$from]) || !is_finite($ys[$from])) { $from++; continue; }
            for ($to = $from + 1; $to < $n && is_finite($xs[$to]) && is_finite($ys[$to]); $to++);
            self::extremes($xs, $ys, $from, $to, $pixelWidth, $ox, $oy);
            $from = $to;
        }
        return [$ox, $oy];
    }

    /**
     * Adds the extremes of each column of the run of points $from to $to
     * (not included) to $ox and $oy, after a break when they have some.
     * @param list<float> $xs
     * @param list<float> $ys
     * @param list<float> $ox
     * @param list<float> $oy
     */
    private static function extremes(array $xs, array $ys, int $from, int $to, int $pixelWidth, array &$ox, array &$oy): void
    {
        // The first at or past each edge, found by halves: at most one point beyond either is kept.
        [$a, $b] = [$from, $to];
        while ($a < $b) { $m = ($a + $b) >> 1; if ($xs[$m] >= 0) { $b = $m; } else { $a = $m + 1; } }
        $lo = $a;
        if ($lo === $to) {
            return;
        }
        [$a, $b] = [$lo, $to];
        while ($a < $b) { $m = ($a + $b) >> 1; if ($xs[$m] > 1) { $b = $m; } else { $a = $m + 1; } }
        $hi = $a;
        if ($lo === $from && $lo === $hi) {
            return;
        }
        if ($lo > $from) { $lo--; }
        if ($hi < $to) { $hi++; }
        if ($ox !== []) {
            $ox[] = NAN; $oy[] = NAN;
        }
        $ox[] = $xs[$lo]; $oy[] = $ys[$lo];
        $last = $lo;
        for ($i = $lo; $i < $hi;) {
            $bucket = floor($xs[$i] * $pixelWidth); // a float: a point beyond the plot may be far beyond an int
            $low = $high = $i;
            $yl = $yh = $ys[$i];
            for ($i++; $i < $hi; $i++) {
                $x = $xs[$i];
                if ($x < 0 || $x > 1 || floor($x * $pixelWidth) != $bucket) { break; }
                $y = $ys[$i];
                if ($y < $yl) { $low = $i; $yl = $y; } elseif ($y > $yh) { $high = $i; $yh = $y; }
            }
            [$p, $q] = $low < $high ? [$low, $high] : [$high, $low];
            if ($p !== $last) { $ox[] = $xs[$p]; $oy[] = $ys[$p]; $last = $p; }
            if ($q !== $last) { $ox[] = $xs[$q]; $oy[] = $ys[$q]; $last = $q; }
        }
        if ($hi - 1 !== $last) { $ox[] = $xs[$hi - 1]; $oy[] = $ys[$hi - 1]; }
    }

    /** The index of the value of $xs, in ascending order, nearest $f, or -1 for none: the point of a line under the pointer. @param list<float> $xs */
    public static function nearest(array $xs, float $f): int
    {
        $n = count($xs);
        if ($n === 0) {
            return -1;
        }
        [$lo, $hi] = [0, $n];
        while ($lo < $hi) { // the first at or after $f
            $m = intdiv($lo + $hi, 2);
            if ($xs[$m] < $f) { $lo = $m + 1; } else { $hi = $m; }
        }
        if ($lo === $n) {
            return $lo - 1;
        }
        return $lo > 0 && $f - $xs[$lo - 1] <= $xs[$lo] - $f ? $lo - 1 : $lo;
    }

    /** The smallest and the largest of $values, the domain of a scale showing them, and 0, 1 for none; NaN is left out. Include 0 for bars and areas. @return array{0: float, 1: float} */
    public static function extent(float ...$values): array
    {
        [$lo, $hi] = [INF, -INF];
        foreach ($values as $v) {
            if (!is_nan($v)) {
                [$lo, $hi] = [min($lo, $v), max($hi, $v)];
            }
        }
        return $lo > $hi ? [0.0, 1.0] : [$lo, $hi];
    }

    /**
     * Piles series up, as stacked bars and areas show them: $series[$s][$i]
     * is the $i-th value of the $s-th series, and the result's [$s][$i]
     * where it goes in the stack of the $i-th values, the $s-th from the
     * bottom. NaN values take no room.
     * @param list<list<float>> $series
     * @return list<list<Segment>>
     */
    public static function stack(array $series, Stacking $how = Stacking::Standard): array
    {
        $n = 0;
        foreach ($series as $s) { $n = max($n, count($s)); }
        $out = array_fill(0, count($series), []);
        $value = fn(int $s, int $i): float => $i >= count($series[$s]) || is_nan($series[$s][$i]) ? 0.0 : $series[$s][$i];
        for ($i = 0; $i < $n; $i++) {
            [$up, $down, $total] = [0.0, 0.0, 0.0];
            $segs = [];
            foreach (array_keys($series) as $s) {
                $v = $value($s, $i);
                $total += abs($v);
                if ($how === Stacking::Center || $v >= 0) {
                    $segs[$s] = [$up, $up + $v];
                    $up += $v;
                } else {
                    $segs[$s] = [$down + $v, $down];
                    $down += $v;
                }
            }
            [$shift, $scale] = match ($how) {
                Stacking::Center => [-$up / 2, 1.0],
                Stacking::Normalized => [0.0, $total > 0 ? 1 / $total : 1.0],
                Stacking::Standard => [0.0, 1.0],
            };
            foreach ($segs as $s => [$lo, $hi]) {
                $out[$s][$i] = new Segment(($lo + $shift) * $scale, ($hi + $shift) * $scale);
            }
        }
        return $out;
    }

    /** Divides a circle among $values, in their order from twelve o'clock, each slice as large as its share of their sum; $pad is the angle between slices, in radians; values below 0, and NaN, take no room. @param list<float> $values @return list<Slice> */
    public static function pie(array $values, float $pad = 0.0): array
    {
        $sum = 0.0;
        foreach ($values as $v) { if ($v > 0) { $sum += $v; } }
        $n = count($values);
        $pad = max(0.0, min($pad, 2 * M_PI / max($n, 1)));
        $k = $sum > 0 ? (2 * M_PI - $n * $pad) / $sum : 0.0;
        $out = [];
        $a = 0.0;
        foreach ($values as $v) {
            $end = $a + $pad + ($v > 0 ? $v * $k : 0.0);
            $out[] = new Slice($a, $end, $pad);
            $a = $end;
        }
        return $out;
    }

    /** The point at the angle $a, $r from the center ($cx, $cy), as where a slice's label goes. @return array{0: float, 1: float} */
    public static function polar(float $cx, float $cy, float $r, float $a): array
    {
        return [$cx + $r * sin($a), $cy - $r * cos($a)];
    }

    /** The path of a slice between the radii $inner and $outer around ($cx, $cy): a wedge of a pie with $inner 0, of a donut otherwise; the slice's pad keeps a gap of the same width along it, as d3's arcs do. */
    public static function sector(float $cx, float $cy, float $inner, float $outer, Slice $s): Path
    {
        $path = new Path;
        if ($outer <= 0 || $s->end <= $s->start) {
            return $path;
        }
        $inner = max(0.0, min($inner, $outer));
        $gap = $s->pad * sqrt($inner * $inner + $outer * $outer); // at the radius between the two, as d3's padRadius
        $angles = function (float $r) use ($s, $gap): array {
            [$a0, $a1] = [$s->start, $s->end];
            if ($gap > 0 && $r > 0) {
                $d = asin(min(1.0, $gap / 2 / $r));
                if ($a1 - $a0 > 2 * $d) {
                    return [$a0 + $d, $a1 - $d];
                }
                $m = ($a0 + $a1) / 2;
                return [$m, $m];
            }
            return [$a0, $a1];
        };
        [$a0, $a1] = $angles($outer);
        [$x, $y] = self::polar($cx, $cy, $outer, $a0);
        $path->moveTo($x, $y);
        self::arc($path, $cx, $cy, $outer, $a0, $a1);
        if ($inner > 0) {
            [$b0, $b1] = $angles($inner);
            [$x, $y] = self::polar($cx, $cy, $inner, $b1);
            $path->lineTo($x, $y);
            self::arc($path, $cx, $cy, $inner, $b1, $b0);
        } else {
            $path->lineTo($cx, $cy);
        }
        return $path->close();
    }

    /** Adds an arc of radius $r around ($cx, $cy) from the angle $a0 to $a1, in cubic curves of up to a quarter turn. */
    private static function arc(Path $path, float $cx, float $cy, float $r, float $a0, float $a1): void
    {
        $n = (int)ceil(abs($a1 - $a0) / (M_PI / 2));
        if ($n === 0) {
            return;
        }
        $step = ($a1 - $a0) / $n;
        $k = 4.0 / 3 * tan($step / 4);
        for ($i = 0; $i < $n; $i++) {
            [$b0, $b1] = [$a0 + $i * $step, $a0 + ($i + 1) * $step];
            [$x0, $y0] = self::polar($cx, $cy, $r, $b0);
            [$x1, $y1] = self::polar($cx, $cy, $r, $b1);
            // The tangent at angle a, going clockwise, is (cos a, sin a).
            $path->cubeTo($x0 + $k * $r * cos($b0), $y0 + $k * $r * sin($b0), $x1 - $k * $r * cos($b1), $y1 - $k * $r * sin($b1), $x1, $y1);
        }
    }

    /** The slice at ($x, $y), relative to the center of a pie whose slices go from $inner to $outer, or null: the slice under the pointer. @param list<Slice> $slices */
    public static function sliceAt(array $slices, float $x, float $y, float $inner, float $outer): ?int
    {
        $d = hypot($x, $y);
        if ($d < $inner || $d > $outer) {
            return null;
        }
        $a = atan2($x, -$y);
        if ($a < 0) {
            $a += 2 * M_PI;
        }
        foreach ($slices as $i => $s) {
            if ($a >= $s->start && $a < $s->end) {
                return $i;
            }
        }
        return null;
    }
}
