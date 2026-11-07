<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use MyPHP\UI\{Context, Node, Painter};
use MyPHP\UI\Chart\{Axes, Band, Chart as Plot, Curve, Linear, Side, Slice, Stacking, Tick, Time};

/**
 * Chart, as Swift Charts': the marks of its content (BarMark, LineMark,
 * AreaMark, PointMark, RuleMark, SectorMark), laid out on scales it picks
 * from their values — a band of the categories a string names, time for
 * dates, a linear scale for numbers, from 0 for bars and areas — with axes
 * and grid lines, the y axis's labels trailing; marks of several series
 * (foregroundStyle(by:)) take the system's colors in turn and a legend
 * names them; bars of one category, and areas, stack. Sector marks make a
 * pie, or a donut with an innerRadius. It takes the room it is given: give
 * it a frame(height:). Built on UI\Chart.
 *
 *     Chart($sales)(fn(array $s) => BarMark(x: PlottableValue::value('Month', $s['month']), y: PlottableValue::value('Sales', $s['total'])))
 *         ->chartXSelection(value: $this->month)->frame(height: 200)
 */
final class Chart extends View
{
    /** The colors of series, in turn, as Swift Charts' default style. */
    private const PALETTE = [\Color::blue, \Color::green, \Color::orange, \Color::purple, \Color::red, \Color::cyan, \Color::yellow, \Color::pink];

    /** @var list<mixed> */
    private array $content = [];
    private mixed $xSelection = null, $angleSelection = null;
    private bool $selectsX = false, $selectsAngle = false, $legend = true;
    /** @var array{0: float, 1: float}|null */
    private ?array $xDomain = null, $yDomain = null;
    private ?AxisMarks $xAxis = null, $yAxis = null;
    /** @var array<string, ColorValue> */
    private array $styles = [];

    /** @param iterable<mixed>|null $data the items whose marks the content's function makes, as Chart(data) { item in … } */
    public function __construct(private readonly ?iterable $data = null) {}

    /** The marks: of each item, fn($item) => mark (or a list of them); or the marks themselves, and lists of them. */
    public function __invoke(Closure|ChartMark|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    /** The x of the marks the pointer is over, null once it leaves, as Swift Charts' chartXSelection(value:); the arrows move it while the chart has the focus. */
    public function chartXSelection(mixed &$value): static { $this->xSelection = &$value; $this->selectsX = true; return $this; }

    /** The value along a pie's sectors the pointer is over (their sum's share at its angle), null once it leaves, as Swift Charts' chartAngleSelection(value:). */
    public function chartAngleSelection(mixed &$value): static { $this->angleSelection = &$value; $this->selectsAngle = true; return $this; }

    /** @param array{0: float, 1: float} $domain the ends of the y scale */
    public function chartYScale(array $domain): static { $this->yDomain = [(float)$domain[0], (float)$domain[1]]; return $this; }

    /** @param array{0: float, 1: float} $domain the ends of a numeric x scale */
    public function chartXScale(array $domain): static { $this->xDomain = [(float)$domain[0], (float)$domain[1]]; return $this; }

    public function chartXAxis(AxisMarks $marks): static { $this->xAxis = $marks; return $this; }

    public function chartYAxis(AxisMarks $marks): static { $this->yAxis = $marks; return $this; }

    /** Whether the legend of the series shows, below the plot. */
    public function chartLegend(\Visibility $visibility): static { $this->legend = $visibility !== \Visibility::hidden; return $this; }

    /** The color of each series, by its name, as Swift Charts' chartForegroundStyleScale. @param array<string, \Color|ColorValue> $styles */
    public function chartForegroundStyleScale(array $styles): static
    {
        foreach ($styles as $name => $style) { $this->styles[(string)$name] = ColorValue::of($style); }
        return $this;
    }

    /** The marks of the content, flat. @return list<ChartMark> */
    private function marks(): array
    {
        $out = [];
        $add = function (mixed $m) use (&$out, &$add): void {
            if ($m === null) { return; }
            if ($m instanceof ChartMark) { $out[] = $m; return; }
            if (is_array($m)) { foreach ($m as $x) { $add($x); } return; }
            throw new \LogicException('a Chart holds marks (BarMark, LineMark, AreaMark, PointMark, RuleMark, SectorMark), not ' . get_debug_type($m));
        };
        foreach ($this->content as $item) {
            if ($item instanceof Closure) {
                if ($this->data === null) { throw new \LogicException('Chart()(fn($item) => …) takes the items: Chart($data)(fn($item) => BarMark(…))'); }
                foreach ($this->data as $key => $d) { $add($item($d, $key)); }
                continue;
            }
            $add($item);
        }
        return $out;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $marks = $this->marks();
        // The series, in the order they come, and their colors.
        $series = [];
        foreach ($marks as $m) {
            $name = self::seriesOf($m);
            if ($name !== null && !isset($series[$name])) { $series[$name] = count($series); }
        }
        $colorOf = function (ChartMark $m) use ($series, $env): int {
            if ($m->color !== null) { return $m->color->rgb($env); }
            $name = self::seriesOf($m);
            if ($name !== null && isset($this->styles[$name])) { return $this->styles[$name]->rgb($env); }
            return ColorValue::of(self::PALETTE[($name === null ? 0 : $series[$name]) % count(self::PALETTE)])->rgb($env);
        };
        $box = $c->column(0, 10)->alignItems(Node::STRETCH);
          $sectors = array_values(array_filter($marks, fn(ChartMark $m) => $m->kind === ChartMark::SECTOR));
          if ($sectors !== []) { $this->pie($c, $env, $sectors, $colorOf); } else { $this->cartesian($c, $env, $marks, $colorOf); }
          if ($this->legend && count($series) > 0 && array_filter(array_keys($series), fn($s) => $s !== '') !== []) {
              $c->row(0, 14)->wrap(INF);
                foreach ($series as $name => $_) {
                    $sample = null;
                    foreach ($marks as $m) { if (self::seriesOf($m) === $name) { $sample = $m; break; } }
                    $c->row(0, 6)->alignItems(Node::CENTER);
                      $c->box(8, 8, $colorOf($sample))->radius(4);
                      $c->text((string)$name, 11, $env->theme->textMuted);
                    $c->end();
                }
              $c->end();
          }
        $c->end();
        View::takeRoom($box, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $box;
    }

    /** The series of mark $m: the value of its foregroundStyle(by:), or of a LineMark's series:. */
    private static function seriesOf(ChartMark $m): ?string
    {
        $v = $m->by ?? $m->series;
        return $v === null ? null : self::text($v->value);
    }

    private static function text(mixed $v): string
    {
        return $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : (is_float($v) ? rtrim(rtrim(sprintf('%.4f', $v), '0'), '.') : (string)$v);
    }

    /** The axis label of $v. */
    private static function label(mixed $v, ?AxisMarks $axis): string
    {
        if ($axis?->format !== null) { return (string)($axis->format)($v); }
        if (is_float($v) || is_int($v)) { return abs($v) >= 1000 ? number_format($v) : rtrim(rtrim(sprintf('%.2f', $v), '0'), '.'); }
        return $v instanceof DateTimeInterface ? Time::label(DateTimeImmutable::createFromInterface($v)) : (string)$v;
    }

    /**
     * The marks on x and y scales: the plot with its axes and grid lines, the
     * areas and lines painted, the bars, points and rules nodes over them.
     * @param list<ChartMark> $marks @param Closure(ChartMark): int $colorOf
     */
    private function cartesian(Context $c, Env $env, array $marks, Closure $colorOf): void
    {
        $t = $env->theme;
        // The x scale, of the marks' x values: categories, times or numbers.
        $xs = [];
        foreach ($marks as $m) {
            if ($m->x !== null) { $xs[] = $m->x->value; }
            if ($m->kind === ChartMark::PLOT && $m->xValues !== []) { array_push($xs, min($m->xValues), max($m->xValues)); } // a LinePlot's ends, not its every point
        }
        $kind = $xs === [] ? 'linear' : (is_string($xs[0]) ? 'band' : ($xs[0] instanceof DateTimeInterface ? 'time' : 'linear'));
        $xLabel = '';
        foreach ($marks as $m) { if ($m->x !== null && $m->x->label !== '') { $xLabel = $m->x->label; break; } }
        if ($kind === 'band') {
            $cats = array_values(array_unique(array_map('strval', $xs)));
            $band = (new Band(...$cats))->padding(0.3, 0.15);
            $xAt = fn(mixed $v): float => $band->at((int)array_search((string)$v, $cats, true));
            $xTicks = $band->ticks();
            if (count($xTicks) > 8) { $k = (int)ceil(count($xTicks) / 7); $xTicks = array_values(array_filter($xTicks, fn(Tick $tk) => (int)$tk->value % $k === 0)); } // every k-th, so the labels keep apart
            if ($this->xAxis?->format !== null) { $xTicks = array_map(fn(Tick $tk) => new Tick($tk->at, $tk->value, self::label($cats[(int)$tk->value], $this->xAxis)), $xTicks); }
        } elseif ($kind === 'time') {
            $ts = array_map(fn($v) => DateTimeImmutable::createFromInterface($v), $xs);
            $time = new Time(min($ts), max($ts), $this->xAxis?->format);
            $xAt = fn(mixed $v): float => $time->at(DateTimeImmutable::createFromInterface($v));
            $xTicks = $time->ticks($this->xAxis?->desiredCount ?? 4);
        } else {
            [$lo, $hi] = $this->xDomain ?? ($xs === [] ? [0.0, 1.0] : Plot::extent(...array_map('floatval', $xs)));
            $lin = $this->xDomain !== null ? new Linear($lo, $hi) : (new Linear($lo, $hi))->nice($this->xAxis?->desiredCount ?? 5);
            if ($this->xAxis?->format !== null) { $lin->format = $this->xAxis->format; }
            $xAt = fn(mixed $v): float => $lin->at((float)$v);
            $xTicks = $lin->ticks($this->xAxis?->desiredCount ?? 5);
        }
        // The y values, stacked: bars of a category on one another, areas of a series on those before it.
        $stackBars = []; $barTop = []; $areaSeries = [];
        foreach ($marks as $i => $m) {
            if ($m->kind === ChartMark::BAR && $m->y !== null && $m->yStart === null && $m->x !== null) {
                $k = self::text($m->x->value);
                $base = $barTop[$k] ?? 0.0;
                $stackBars[$i] = [$base, $base + (float)$m->y->value];
                $barTop[$k] = $base + (float)$m->y->value;
            }
            if ($m->kind === ChartMark::AREA && $m->y !== null && $m->yStart === null) { $areaSeries[self::seriesOf($m) ?? ''][] = $i; }
        }
        $stackAreas = []; $how = Stacking::Standard;
        if ($areaSeries !== []) {
            $first = $marks[reset($areaSeries)[0]];
            $how = match ($first->stacking) { \MarkStackingMethod::normalized => Stacking::Normalized, \MarkStackingMethod::center => Stacking::Center, default => Stacking::Standard };
            if ($first->stacking === \MarkStackingMethod::unstacked) {
                foreach ($areaSeries as $ids) { foreach ($ids as $i) { $stackAreas[$i] = [0.0, (float)$marks[$i]->y->value]; } }
            } else {
                $at = []; // the x values of all the series, each its column in the stack (keyed with a prefix: PHP makes "3" the key 3)
                foreach ($areaSeries as $ids) { foreach ($ids as $i) { $at['x' . self::text($marks[$i]->x->value)] ??= count($at); } }
                $table = [];
                foreach (array_values($areaSeries) as $s => $ids) {
                    $table[$s] = array_fill(0, count($at), NAN);
                    foreach ($ids as $i) { $table[$s][$at['x' . self::text($marks[$i]->x->value)]] = (float)$marks[$i]->y->value; }
                }
                $stacked = Plot::stack($table, $how);
                foreach (array_values($areaSeries) as $s => $ids) {
                    foreach ($ids as $i) { $seg = $stacked[$s][$at['x' . self::text($marks[$i]->x->value)]]; $stackAreas[$i] = [$seg->lo, $seg->hi]; }
                }
            }
        }
        $ys = []; $fromZero = false;
        foreach ($marks as $i => $m) {
            if ($m->kind === ChartMark::PLOT) { if ($m->yValues !== []) { array_push($ys, min($m->yValues), max($m->yValues)); } continue; }
            if (isset($stackBars[$i])) { array_push($ys, ...$stackBars[$i]); $fromZero = true; continue; }
            if (isset($stackAreas[$i])) { array_push($ys, ...$stackAreas[$i]); $fromZero = true; continue; }
            foreach ([$m->y, $m->yStart, $m->yEnd] as $v) { if ($v !== null && !$v->value instanceof DateTimeInterface) { $ys[] = (float)$v->value; } }
            if ($m->kind === ChartMark::BAR || $m->kind === ChartMark::AREA) { $fromZero = true; }
        }
        if ($fromZero) { $ys[] = 0.0; }
        $count = $this->yAxis?->desiredCount ?? 4;
        [$lo, $hi] = $this->yDomain ?? ($ys === [] ? [0.0, 1.0] : Plot::extent(...$ys));
        $y = $this->yDomain !== null ? new Linear($lo, $hi) : (new Linear($lo, $hi == $lo ? $lo + 1 : $hi))->nice($count);
        if ($this->yAxis?->format !== null) { $y->format = $this->yAxis->format; }
        elseif ($how === Stacking::Normalized && $stackAreas !== []) { $y->format = fn(float $v) => sprintf('%.0f%%', 100 * $v); }
        $yTicks = $this->yAxis?->values !== null ? array_map(fn($v) => new Tick($y->at((float)$v), (float)$v, self::label($v, $this->yAxis)), $this->yAxis->values) : $y->ticks($count);
        $label = fn(Side $s, Tick $tk) => $c->text($tk->label, 11, $t->textMuted)->noWrap()->margin($s === Side::Bottom ? 4 : 0, $s === Side::Right ? 0 : 6, 0, $s === Side::Right ? 6 : 0);
        $leading = $this->yAxis?->position === \AxisMarkPosition::leading;
        $axes = new Axes(left: $leading ? $yTicks : [], right: $leading ? [] : $yTicks, bottom: $xTicks, label: $label);
        $curve = fn(ChartMark $m): Curve => match ($m->interpolation) { \InterpolationMethod::monotone, \InterpolationMethod::catmullRom, \InterpolationMethod::cardinal => Curve::Monotone, \InterpolationMethod::stepStart => Curve::StepAfter, \InterpolationMethod::stepEnd => Curve::StepBefore, \InterpolationMethod::stepCenter => Curve::Step, default => Curve::Linear };
        // The x fractions a selection chooses among: the marks' x values, each once, in order.
        $sel = [];
        foreach ($marks as $m) { if ($m->x !== null) { $sel[self::text($m->x->value)] = [$xAt($m->x->value), $m->x->value]; } }
        uasort($sel, fn($a, $b) => $a[0] <=> $b[0]);
        $sel = array_values($sel);
        $band ??= null;
        $plot = Plot::plot($c, $axes, function (Node $area) use ($c, $env, $marks, $xAt, $y, $yTicks, $xTicks, $kind, $stackBars, $stackAreas, $areaSeries, $colorOf, $curve, $band, $sel, $xLabel, $t): void {
            $grid = $t->border;
            // The grid, the areas and the lines, painted under the marks that are nodes.
            $paths = [];
            foreach ($areaSeries as $ids) {
                $pts = array_map(fn($i) => [$xAt($marks[$i]->x->value), $y->at($stackAreas[$i][0]), $y->at($stackAreas[$i][1])], $ids);
                usort($pts, fn($a, $b) => $a[0] <=> $b[0]);
                $m = $marks[$ids[0]];
                $paths[] = ['area', array_column($pts, 0), array_column($pts, 1), array_column($pts, 2), $colorOf($m), self::alpha($m), $curve($m)];
            }
            $lines = [];
            foreach ($marks as $m) { if ($m->kind === ChartMark::LINE && $m->x !== null && $m->y !== null) { $lines[self::seriesOf($m) ?? ''][] = $m; } }
            foreach ($lines as $ms) {
                $pts = array_map(fn(ChartMark $m) => [$xAt($m->x->value), $y->at((float)$m->y->value)], $ms);
                usort($pts, fn($a, $b) => $a[0] <=> $b[0]);
                $paths[] = ['line', array_column($pts, 0), array_column($pts, 1), null, $colorOf($ms[0]), self::alpha($ms[0]), $curve($ms[0]), $ms[0]->lineWidth];
            }
            foreach ($marks as $m) { // a LinePlot's points as fractions of the plot, which its painting reduces to the extremes of each pixel column
                if ($m->kind !== ChartMark::PLOT || $kind !== 'linear') { continue; }
                [$x0, $x1] = [$xAt(0.0), $xAt(1.0)]; [$y0, $y1] = [$y->at(0.0), $y->at(1.0)];
                [$kx, $ky] = [$x1 - $x0, $y1 - $y0];
                $fx = $fy = [];
                foreach ($m->xValues as $j => $v) { $fx[] = $x0 + $kx * $v; $fy[] = $y0 + $ky * $m->yValues[$j]; }
                $paths[] = ['plot', $fx, $fy, null, $colorOf($m), self::alpha($m), null, $m->lineWidth];
            }
            $area->draw(static function (Painter $p, array $r) use ($yTicks, $xTicks, $kind, $grid, $paths): void {
                foreach ($yTicks as $tk) { $p->line($r[0], Plot::y($r, $tk->at), $r[0] + $r[2], Plot::y($r, $tk->at), 1, $grid); }
                if ($kind !== 'band') { foreach ($xTicks as $tk) { $p->line(Plot::x($r, $tk->at), $r[1], Plot::x($r, $tk->at), $r[1] + $r[3], 1, $grid); } }
                foreach ($paths as $path) {
                    if ($path[0] === 'plot') {
                        $p->clip($r, static function () use ($p, $r, $path): void {
                            [$xs, $ys] = Plot::minMax($path[1], $path[2], (int)ceil($r[2] * $p->scale()));
                            $points = [];
                            foreach ($xs as $j => $f) { $points[] = [Plot::x($r, $f), Plot::y($r, $ys[$j])]; }
                            $p->strokePolyline($points, $path[7], $path[4], $path[5]);
                        });
                        continue;
                    }
                    if ($path[0] === 'area') { $p->fillPath(Plot::area($r, $path[1], $path[2], $path[3], $path[6]), $path[4], $path[5]); }
                    else { $p->strokePath(Plot::line($r, $path[1], $path[2], $path[6]), $path[7], $path[4], $path[5]); }
                }
            });
            // The bars, points and rules, and the marks' annotations.
            foreach ($marks as $i => $m) {
                $rgb = $colorOf($m);
                $top = null;
                switch ($m->kind) {
                    case ChartMark::BAR:
                        [$a, $b] = $stackBars[$i] ?? [(float)($m->yStart?->value ?? 0), (float)($m->yEnd?->value ?? $m->y?->value ?? 0)];
                        $xs = $kind === 'band' ? $band->band((int)array_search(self::text($m->x->value), array_map(fn(Tick $tk) => $tk->label, $band->ticks()), true)) : null;
                        if ($xs === null) { // along numbers or time: as wide as $width points, or a share of the room between them
                            $f = $xAt($m->x->value);
                            $aw = $c->runtime?->rectOf($area->id)[2] ?? 0.0;
                            $w = $m->width !== null && $aw > 0 ? $m->width / $aw : 0.6 / max(1, count($sel));
                            $xs = new \MyPHP\UI\Chart\Span($f - $w / 2, $f + $w / 2);
                        }
                        $c->key("mark$i");
                        $n = Plot::rect($c, $xs, $y->span($a, $b))->background($rgb, self::alpha($m));
                        if ($m->radius > 0) { $n->radius($m->radius); }
                        $n->label(self::describe($m));
                        $top = [$xs->mid(), $y->at(max($a, $b))];
                        break;
                    case ChartMark::POINT:
                        if ($m->x === null || $m->y === null) { break; }
                        $d = sqrt($m->symbolSize ?? 50) ;
                        $f = [$xAt($m->x->value), $y->at((float)$m->y->value)];
                        $c->key("mark$i");
                        Plot::point($c, $f[0], $f[1], $d, $d)->radius($d / 2)->background($rgb, self::alpha($m))->label(self::describe($m));
                        $top = $f;
                        break;
                    case ChartMark::RULE:
                        $w = $m->lineWidth === 2.0 ? 1.0 : $m->lineWidth;
                        $color = $m->color === null ? $t->textMuted : $rgb;
                        if ($m->x !== null) { Plot::ruleX($c, $xAt($m->x->value), $w)->background($color, self::alpha($m)); $top = [$xAt($m->x->value), 1.0]; }
                        elseif ($m->y !== null) { Plot::ruleY($c, $y->at((float)$m->y->value), $w)->background($color, self::alpha($m)); $top = [1.0, $y->at((float)$m->y->value)]; }
                        break;
                    case ChartMark::LINE: case ChartMark::AREA:
                        if ($m->x !== null && $m->y !== null) { $top = [$xAt($m->x->value), $y->at(isset($stackAreas[$i]) ? $stackAreas[$i][1] : (float)$m->y->value)]; }
                        break;
                }
                if ($m->annotation !== null && $top !== null) {
                    [$pos, $content] = $m->annotation;
                    [$h, $v] = match ($pos) { \AnnotationPosition::bottom => [Node::CENTER, Node::START], \AnnotationPosition::leading => [Node::END, Node::CENTER], \AnnotationPosition::trailing => [Node::START, Node::CENTER], default => [Node::CENTER, Node::END] };
                    $c->key("note$i");
                    Plot::anchor($c, min(max($top[0], 0.0), 1.0), $top[1], $h, $v, function () use ($c, $env, $content): void {
                        $c->column()->padding(2, 4);
                          View::renderAll($c, $env->along(Node::COLUMN), $content());
                        $c->end();
                    });
                }
            }
            if ($this->selectsX && $sel !== []) {
                $index = -1;
                foreach ($sel as $k => [, $v]) { if ($this->xSelection !== null && self::text($v) === self::text($this->xSelection)) { $index = $k; } }
                $was = $index;
                Plot::select($c, $area, $index, array_column($sel, 0), fn(int $k) => ($xLabel !== '' ? "$xLabel " : '') . self::text($sel[$k][1]));
                if ($index !== $was) { $this->xSelection = $index < 0 ? null : $sel[$index][1]; }
            }
        });
        View::takeRoom($plot, Node::COLUMN, View::FLEX_X | View::FLEX_Y);
    }

    /** How opaque mark $m is: its opacity, by its color's (Color::accentColor->opacity(0.12)). */
    private static function alpha(ChartMark $m): float
    {
        return $m->alpha * ($m->color?->alpha ?? 1.0);
    }

    /** What assistive technology reads of mark $m: its values, by their labels. */
    private static function describe(ChartMark $m): string
    {
        $parts = [];
        foreach ([$m->x, $m->y, $m->by] as $v) { if ($v !== null) { $parts[] = ($v->label !== '' ? "{$v->label}: " : '') . self::text($v->value); } }
        return implode(', ', $parts);
    }

    /**
     * The sector marks as a pie, or a donut: each a share of their angles'
     * sum, from twelve o'clock clockwise, as large as the chart's room allows.
     * @param list<ChartMark> $sectors @param Closure(ChartMark): int $colorOf
     */
    private function pie(Context $c, Env $env, array $sectors, Closure $colorOf): void
    {
        $values = array_map(fn(ChartMark $m) => max(0.0, (float)($m->angle?->value ?? 0)), $sectors);
        $total = array_sum($values);
        $n = $c->column()->label(implode(', ', array_map(fn(ChartMark $m) => self::describe($m), $sectors)));
        View::takeRoom($n, Node::COLUMN, View::FLEX_X | View::FLEX_Y);
        $rt = $c->runtime;
        $rect = $rt?->rectOf($n->id);
        if ($this->selectsAngle && $rect !== null) {
            if ($c->hovered($n)) {
                $dx = $rt->input->mouseX - $rect[0] - $rect[2] / 2;
                $dy = $rt->input->mouseY - $rect[1] - $rect[3] / 2;
                $a = atan2($dx, -$dy);
                if ($a < 0) { $a += 2 * M_PI; }
                $r = min($rect[2], $rect[3]) / 2;
                $this->angleSelection = sqrt($dx * $dx + $dy * $dy) <= $r && $total > 0 ? $a / (2 * M_PI) * $total : null;
            } elseif ($this->angleSelection !== null) { $this->angleSelection = null; }
        }
        $colors = array_map($colorOf, $sectors);
        $n->draw(static function (Painter $p, array $r) use ($sectors, $values, $colors): void {
            $outer = min($r[2], $r[3]) / 2;
            $cx = $r[0] + $r[2] / 2; $cy = $r[1] + $r[3] / 2;
            $slices = Plot::pie($values, $outer > 0 ? ($sectors[0]->angularInset / $outer) : 0.0);
            foreach ($slices as $i => $s) {
                $m = $sectors[$i];
                $ro = $m->outerRadius?->of($outer) ?? $outer;
                $ri = $m->innerRadius?->of($outer) ?? 0.0;
                $p->fillPath(Plot::sector($cx, $cy, $ri, $ro, $s), $colors[$i], self::alpha($m));
            }
        });
        $c->end();
    }
}
