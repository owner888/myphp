# Charts

The classes of `MyPHP\UI\Chart` build charts without a look of their own,
as headless libraries such as visx do on the web, with the parts of Swift
Charts: scales that map data onto a plot and pick round ticks, a plot with
axes around it, marks that are nodes you style as any other, paths of
lines, areas and pie slices for `draw()` functions, stacking, and a
selection that the pointer and the keyboard move. Every color, size and
font is yours.

```php
use MyPHP\UI\Chart\{Axes, Band, Chart, Linear};

$app = new class { public array $months = ['Jan', 'Feb', 'Mar', 'Apr']; public array $sales = [42, 75, 61, 98]; };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    $x = (new Band(...$app->months))->padding(0.3, 0.15);
    [, $hi] = Chart::extent(...$app->sales);
    $y = (new Linear(0, $hi))->nice(4);
    $ticks = $y->ticks(4);
    Chart::plot($c, new Axes(left: $ticks, bottom: $x->ticks()), function (Node $area) use ($c, $t, $x, $y, $ticks, $app) {
        $area->draw(function (Painter $p, array $r) use ($t, $ticks) {
            foreach ($ticks as $tick) { // grid lines
                $p->line($r[0], Chart::y($r, $tick->at), $r[0] + $r[2], Chart::y($r, $tick->at), 1, $t->border);
            }
        });
        foreach ($app->sales as $i => $v) {
            Chart::rect($c, $x->band($i), $y->span(0, $v))->radius(4)->background($t->accent)
                ->tooltip(sprintf('%s: %.0f', $app->months[$i], $v));
        }
    })->label('Sales by month')->height(200)->gap(6);
};
```

Positions on a plot are fractions of it, from 0 to 1: along x from its left
edge, and along y from its bottom up, the way values grow. Scales turn data
into fractions; marks place themselves in percentages of the plot, so they
are built without waiting for the layout and follow the plot as it
resizes; `draw()` functions get the plot's box, and `Chart::x($r, $f)` and
`Chart::y($r, $f)` turn fractions into points in it.

## Scales

- `new Linear($lo, $hi)` maps numbers. `nice($n)` rounds the domain out to
  the round values of about n ticks, and `ticks($n)` returns them: 1, 2 or
  5 times a power of ten apart, as d3 and Swift Charts pick them, with as
  many decimals as the step needs, or labeled by its `format`. `at($v)` is
  where a value is, `span($a, $b)` the stretch between two, as a bar's from
  0 to its value, and `invert($f)` the value at a fraction, as under the
  pointer. `clamp` keeps values outside the domain at its ends;
  `Chart::extent(...$values)` returns the smallest and the largest.
- `new Band(...$labels)` gives each label a band, as the bars of a bar
  chart: `band($i)` is the i-th's stretch, `at($i)` its middle, `width()`
  their width, and `index($f)` the band at a fraction. `padding($inner,
  $outer)` leaves room between the bands and around them, in fractions of
  a band's step. Its `ticks()` are the labels, in the middle of their
  bands.
- `new Time($from, $to)` maps `DateTimeImmutable`s. Its ticks fall on round
  times in the times' zone, chosen for the span as d3's are: seconds,
  quarters of an hour, midnights, Sundays, the firsts of months or years;
  and each is labeled with what changed since the last round time of the
  coarser unit: "2026", "Mar", "Mar 5", "14:00", ":30".

Ticks are `Tick` values, `at` a fraction, `value` and `label`: make your
own for axes a scale does not mark.

## The plot and its axes

`Chart::plot()` lays the plot's area out in a grid with an axis on each
side that `Axes` gives ticks to: `left` and `right` along the height,
`top` and `bottom` along the width. The labels stay in the layout, so the
axes take the room they need, and the area the rest: give the plot a
height, or `fill()` its parent, and a `gap()` (or `gapX()` and `gapY()`)
between the labels and the area. `Axes`'s `label` builds each label,
centered on its tick; it is plain text by default. Labels sit in boxes of
no width or height that center them, so keep their text on one line with
`noWrap()`:

```php
use MyPHP\UI\Chart\{Axes, Band, Chart, Linear, Side, Tick};

$view = function (Context $c) {
    $t = $c->theme;
    $x = new Band('Mon', 'Tue', 'Wed');
    $y = (new Linear(0, 10))->nice(4);
    $label = function (Side $side, Tick $tick) use ($c, $t) {
        $c->text($tick->label)->noWrap()->fontSize(11)->textColor($t->textMuted);
    };
    Chart::plot($c, new Axes(left: $y->ticks(4), bottom: $x->ticks(), label: $label), function (Node $area) {})->height(160);
};
```

The function of `plot()` gets the area, a node to style, with a
background, a border or a `draw()` function painting grid lines and lines,
and to add marks to.

## Marks

Marks are nodes in the area, placed in percentages of it: style them, name
them for assistive technology, give them tooltips, and handle their clicks,
as any node.

- `Chart::rect($c, $x, $y)` covers a stretch (`Span`) along x and up y: a
  bar, a band of a range, a cell of a heat map.
- `Chart::point($c, $x, $y, $w, $h)` is w by h points, centered on a
  point: a dot of a scatter plot, the point a selection is on.
- `Chart::ruleX($c, $x, $width)` is a vertical line across the plot, and
  `Chart::ruleY($c, $y, $height)` a horizontal one: a threshold, an
  average.
- `Chart::anchor($c, $x, $y, $h, $v, $build)` places what `$build` adds at
  a point, of any size: `Node::CENTER` centers it there, `Node::START` puts
  its left (h) or top (v) edge there, and `Node::END` its right or bottom
  edge, so that `Node::CENTER, Node::END` shows a label above a bar.

```php
use MyPHP\UI\Chart\{Axes, Band, Chart, Linear};

$app = new class { public array $sales = [3, 7, 5]; public int $month = -1; };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    $x = new Band('Jan', 'Feb', 'Mar');
    $y = (new Linear(0, 8))->nice(4);
    Chart::plot($c, new Axes(), function (Node $area) use ($c, $t, $x, $y, $app) {
        foreach ($app->sales as $i => $v) {
            $bar = Chart::rect($c, $x->band($i), $y->span(0, $v))->background($t->accent);
            if ($c->clicked($bar)) {
                $app->month = $i;
            }
            Chart::anchor($c, $x->at($i), $y->at($v), Node::CENTER, Node::END, function () use ($c, $v) {
                $c->text((string)$v)->noWrap()->fontSize(11)->margin(0, 0, 4, 0);
            });
        }
    })->height(160);
};
```

A mark with a [transition](transitions.md) moves to its new place as the
data changes, as bars growing to new values:

<!-- no-test -->
```php
$c->transition(Chart::rect($c, $x->band($i), $y->span(0, $v))->background($t->accent), new ElementTransition(duration: 0.3));
```

## Lines and areas

`Chart::line($r, $xs, $ys, $curve)` returns the path of a line through
points, and `Chart::area($r, $xs, $lo, $hi, $curve)` the path of the area
between two lines, for a `draw()` function of the area to stroke and fill.
`$xs` and `$ys` are fractions, as scales return; a null `$lo` is the
bottom of the plot, and a NaN breaks the line or the area there, for
missing values. The curve joins the points: `Curve::Linear` with straight
lines, `Curve::Monotone` smoothly, without going past the values of the
points it joins, and `Curve::StepAfter`, `StepBefore` and `Step` in steps.

```php
use MyPHP\UI\Chart\{Axes, Chart, Curve};

$xs = [0, 0.25, 0.5, 0.75, 1];
$ys = [0.2, 0.6, 0.4, 0.9, 0.7];
$view = function (Context $c) use ($xs, $ys) {
    $t = $c->theme;
    Chart::plot($c, new Axes(), function (Node $area) use ($t, $xs, $ys) {
        $area->draw(function (Painter $p, array $r) use ($t, $xs, $ys) {
            $p->fillPath(Chart::area($r, $xs, null, $ys, Curve::Monotone), $t->accent, 0.15);
            $p->strokePath(Chart::line($r, $xs, $ys, Curve::Monotone), 2, $t->accent);
        });
    })->height(160);
};
```

### Live data

A line of thousands of samples, redrawn every frame as new ones arrive,
needs fewer points than it has samples: `Chart::minMax($xs, $ys,
$pixelWidth)` keeps the lowest and highest sample of each pixel column, in
their order, and the ends of each run, so that the line looks the same and
narrow spikes still show. Its `$xs` are fractions that never decrease; a
NaN still breaks the line. `Chart::lineInto()` writes into a path you
pass, which a view keeps from frame to frame. Keep the samples themselves
for selections and readings.

`Painter::strokePolyline()` strokes points in points with round joins and
caps, as `strokePath()` would, faster for lines that change every frame: on
a GPU it draws each segment as a rounded rectangle, rather than rasterizing
the whole line into a mask on the CPU. Where segments overlap, their
antialiased edges can look a little bolder than `strokePath()`'s.
Translucent lines take `strokePath()`'s way, since overlapping segments
would show darker there.

```php
use MyPHP\UI\Chart\{Axes, Chart};

$xs = array_map(fn($i) => $i / 999, range(0, 999));
$ys = array_map(fn($i) => 0.5 + 0.4 * sin($i / 30), range(0, 999));
$view = function (Context $c) use ($xs, $ys) {
    $t = $c->theme;
    Chart::plot($c, new Axes(), function (Node $area) use ($t, $xs, $ys) {
        $area->draw(function (Painter $p, array $r) use ($t, $xs, $ys) {
            [$bx, $by] = Chart::minMax($xs, $ys, (int)ceil($r[2] * $p->scale()));
            $points = [];
            foreach ($bx as $i => $x) {
                $points[] = [Chart::x($r, $x), Chart::y($r, $by[$i])];
            }
            $p->strokePolyline($points, 2, $t->accent);
        });
    })->height(120);
};
```

Ask for frames with `$c->animationFrame()` and take in the samples that
arrived since the last one, rather than invalidating per sample.
`examples/chart-streaming` scrolls waveforms of thousands of samples.

## Stacks

`Chart::stack($series, $how)` piles series up, as stacked bars and areas
show them: `$series[$s][$i]` is the i-th value of the s-th series, and the
result's `[$s][$i]` the `Segment` it covers in the i-th stack.
`Stacking::Standard` piles values up from 0, and negative values down from
it; `Stacking::Normalized` in shares of the stack's sum, from 0 to 1; and
`Stacking::Center` around 0, as a streamgraph.

```php
use MyPHP\UI\Chart\{Axes, Band, Chart, Linear, Stacking};

$app = new class { public array $search = [3, 4, 2]; public array $direct = [1, 2, 3]; };
$colors = [0x3b82f6, 0xf59e0b];
$view = function (Context $c) use ($app, $colors) {
    $x = new Band('Jan', 'Feb', 'Mar');
    $y = new Linear(0, 8);
    Chart::plot($c, new Axes(), function (Node $area) use ($c, $app, $colors, $x, $y) {
        $stacked = Chart::stack([$app->search, $app->direct], Stacking::Standard);
        foreach ($stacked as $s => $series) {
            foreach ($series as $i => $seg) {
                Chart::rect($c, $x->band($i), $y->span($seg->lo, $seg->hi))->background($colors[$s]);
            }
        }
    })->height(160);
};
```

## Pies and donuts

`Chart::pie($values, $pad)` divides a circle among values, in their order
from twelve o'clock, with `$pad` radians between them, and
`Chart::sector($cx, $cy, $inner, $outer, $slice)` returns the path of a
slice: a wedge with `$inner` 0, a piece of a donut otherwise.
`Chart::sliceAt()` finds the slice at a point, as under the pointer, and
`Chart::polar()` the point at an angle, where a slice's label goes:

```php
use MyPHP\UI\Chart\Chart;

$app = new class { public array $shares = [55, 30, 15]; };
$colors = [0x3b82f6, 0x22c55e, 0xf59e0b];
$view = function (Context $c) use ($app, $colors) {
    $slices = Chart::pie($app->shares, 0.03);
    $ring = $c->column()->size(160, 160)->label('Users by platform');
    $c->end();
    $hover = -1;
    [$x, $y, $over] = $c->pointerPosition($ring);
    if ($over && ($i = Chart::sliceAt($slices, $x - 80, $y - 80, 50, 80)) !== null) {
        $hover = $i;
    }
    $ring->draw(function (Painter $p, array $r) use ($slices, $hover, $colors) {
        foreach ($slices as $i => $s) {
            $p->fillPath(Chart::sector($r[0] + 80, $r[1] + 80, 50, $i === $hover ? 80 : 76, $s), $colors[$i]);
        }
    });
};
```

## Selection

`Chart::select($c, $area, $index, $xs, $describe)` makes the area choose
one of the points at `$xs`, in ascending order, as Swift Charts'
`chartXSelection`: the one nearest the pointer while it is over the area,
and none (-1) once it leaves. The area takes the keyboard focus, as one
stop of Tab, and the left and right arrows, Home and End move the choice
while it has it, and Escape clears it. It reports a new choice. Show the
choice with marks:

```php
use MyPHP\UI\Chart\{Axes, Chart};

$hours = ['06:00', '12:00', '18:00'];
$temps = [12.5, 21.0, 17.2];
$xs = [0.0, 0.5, 1.0];
$ys = [0.25, 0.8, 0.6];
$app = new class { public int $selected = 1; };
$view = function (Context $c) use ($app, $hours, $temps, $xs, $ys) {
    $t = $c->theme;
    $describe = fn(int $i) => sprintf('%s: %.1f °C', $hours[$i], $temps[$i]);
    Chart::plot($c, new Axes(), function (Node $area) use ($c, $t, $app, $xs, $ys, $describe) {
        Chart::select($c, $area, $app->selected, $xs, $describe);
        if (($i = $app->selected) >= 0) {
            Chart::ruleX($c, $xs[$i], 1)->background($t->textMuted);
            Chart::point($c, $xs[$i], $ys[$i], 10, 10)->radius(5)->background($t->accent);
            Chart::anchor($c, $xs[$i], 1, Node::CENTER, Node::START, function () use ($c, $t, $describe, $i) {
                $c->text($describe($i))->noWrap()->padding(3, 8)->radius(6)->background($t->surface);
            });
        }
    })->height(160);
};
```

For the points of a band scale, `$xs` are their middles;
`Chart::nearest($xs, $f)` finds the point nearest a fraction for
selections of your own, and a band's `index()` the band.

## Accessibility

Name the plot with `label()`. Marks are nodes: name each one assistive
technology should read, as a bar's month and value. `select()` tells it
about the chosen point: what `$describe` returns is the area's value,
which screen readers read out as the arrows come to a point.

What the classes leave out: logarithmic scales, zooming and panning,
legends (rows of your own, as above) and labels that avoid each other.
