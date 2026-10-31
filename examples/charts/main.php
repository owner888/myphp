<?php
// Charts in native UI (mygo's gallery Charts page), built with UI\Chart:
// scales, axes and marks without a look, the marks nodes styled here, and
// lines, areas and slices paths these cards paint. A bar chart whose bars
// grow to new values and choose a month; a live line whose points the
// pointer and the arrows choose; stacked areas; a scatter plot; a donut.
// Run: ./run.sh charts
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, ElementTransition, Font, Node, Painter};
use MyPHP\UI\Chart\{Axes, Band, Chart, Curve, Linear, Side, Stacking, Tick, Time};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
/** The series' colors, in light and dark windows alike. */
const COLORS = [0x3b82f6, 0x10b981, 0xf59e0b, 0xec4899];
const SOURCES = ['Search', 'Social', 'Direct'];
const VISITS = [
    [30, 34, 38, 36, 42, 47, 45, 52, 58, 55, 61, 66],
    [12, 15, 13, 19, 24, 22, 28, 31, 27, 35, 38, 36],
    [20, 19, 22, 21, 23, 22, 25, 24, 27, 26, 28, 30],
];
const PLATFORMS = ['macOS', 'Windows', 'Linux', 'Browsers'];
const SHARES = [46, 31, 14, 9];

/** The page's state: the sales and the month clicked, the samples of the live line and the point chosen, how the areas stack, the scatter's cars. */
final class Charts
{
    /** @var list<float> */
    public array $sales = [];
    public int $month = -1, $selected = -1, $stacking = 0;
    /** @var list<float> the last minute of samples, one a second */
    public array $samples = [];
    /** @var list<list<array{0: float, 1: float}>> weight and fuel use, by class */
    public array $scatter = [[], [], []];
    public ?Window $window = null;

    public function __construct()
    {
        mt_srand(12);
        foreach (MONTHS as $i => $_) {
            $this->sales[] = 40 + 50 * sin($i / 3.5) + 20 * mt_rand() / mt_getrandmax();
        }
        foreach ($this->scatter as $k => $_) {
            for ($i = 0; $i < 24; $i++) {
                $w = 1100 + 450 * $k + 170 * self::normal();
                $this->scatter[$k][] = [$w, 3 + $w / 420 + 0.9 * self::normal()];
            }
        }
        for ($i = 0; $i < 60; $i++) {
            $this->sample();
        }
    }

    private static function normal(): float
    {
        return sqrt(-2 * log(max(1e-9, mt_rand() / mt_getrandmax()))) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
    }

    /** Takes a sample, which wanders. */
    public function sample(): void
    {
        $last = end($this->samples);
        $this->samples[] = max(0.05, min(0.95, ($last === false ? 0.5 : $last) + 0.12 * (mt_rand() / mt_getrandmax() - 0.5)));
        $this->samples = array_slice($this->samples, -60);
    }
}

/** The look of the axes' labels. */
function axisLabel(Context $c): Closure
{
    return fn(Side $s, Tick $t) => $c->text($t->label, 11, $c->theme->textMuted)->noWrap();
}

/** Paints a line across the plot at each tick of $y, and along it at each of $x. @param list<Tick> $x @param list<Tick> $y */
function gridLines(Node $area, array $x, array $y, int $color): void
{
    $area->draw(function (Painter $p, array $r) use ($x, $y, $color): void {
        foreach ($y as $tick) { $p->line($r[0], Chart::y($r, $tick->at), $r[0] + $r[2], Chart::y($r, $tick->at), 1, $color); }
        foreach ($x as $tick) { $p->line(Chart::x($r, $tick->at), $r[1], Chart::x($r, $tick->at), $r[1] + $r[3], 1, $color); }
    });
}

/** The series' names by their colors. @param list<string> $names */
function legend(Context $c, array $names): void
{
    $c->row(0, 14);
      foreach ($names as $i => $name) {
          $c->row(0, 6)->alignItems(Node::CENTER);
            $c->box(10, 10, COLORS[$i])->radius(3);
            $c->muted($name, 12);
          $c->end();
      }
    $c->end();
}

/** A card: a titled panel. */
function card(Context $c, string $title, Closure $body): void
{
    $t = $c->theme;
    $c->column(16, 10, 1, $t->surface)->radius(10)->border(1, $t->border)->basis(320)->minWidth(0)->minHeight(0);
      $c->text($title, 14, style: Font::BOLD);
      $body();
    $c->end();
}

function sales(Context $c, Charts $ch): void
{
    $t = $c->theme;
    $c->row(0, 8)->alignItems(Node::CENTER);
      $c->muted($ch->month >= 0 ? sprintf('%s: $%.1fk', MONTHS[$ch->month], $ch->sales[$ch->month]) : 'Click a bar to choose its month.');
      $c->spacer();
      if ($c->clicked($c->button('Shuffle'))) {
          foreach ($ch->sales as $i => $_) { $ch->sales[$i] = 10 + 110 * mt_rand() / mt_getrandmax(); }
      }
    $c->end();
    $x = (new Band(...MONTHS))->padding(0.3, 0.1);
    [, $hi] = Chart::extent(...$ch->sales);
    $y = (new Linear(0, $hi))->nice(4);
    $y->format = fn(float $v) => $v == 0 ? '$0' : sprintf('$%.0fk', $v);
    $ticks = $y->ticks(4);
    $months = array_values(array_filter($x->ticks(), fn(Tick $tk) => (int)$tk->value % 2 === 0)); // every other month, so the labels keep apart
    Chart::plot($c, new Axes(left: $ticks, bottom: $months, label: axisLabel($c)), function (Node $area) use ($c, $ch, $x, $y, $ticks, $t): void {
        gridLines($area, [], $ticks, $t->border);
        foreach ($ch->sales as $i => $v) {
            $c->key("bar$i");
            $bar = Chart::rect($c, $x->band($i), $y->span(0, $v))->radius(3)->background($t->accent, $ch->month >= 0 && $i !== $ch->month ? 0.35 : 1.0)
                ->tooltip(sprintf('%s: $%.1fk', MONTHS[$i], $v))->label(MONTHS[$i])->cursor(\MyPHP\UI\Cursor::Pointer);
            $c->transition($bar, new ElementTransition(0.35)); // bars grow and shrink to new values
            if ($c->clicked($bar)) {
                $ch->month = $ch->month === $i ? -1 : $i;
            }
        }
        if (($i = $ch->month) >= 0) {
            Chart::anchor($c, $x->at($i), $y->at($ch->sales[$i]), Node::CENTER, Node::END, function () use ($c, $ch, $i): void {
                $c->text(sprintf('%.0f', $ch->sales[$i]), 11, style: Font::BOLD)->noWrap()->margin(0, 0, 4, 0);
            });
        }
    })->label('Sales by month')->size(0, 200);
}

function live(Context $c, Charts $ch): void
{
    $t = $c->theme;
    $n = count($ch->samples);
    $end = new DateTimeImmutable('@' . time());
    $x = new Time($end->modify('-59 seconds'), $end);
    $y = new Linear(0, 1, format: fn(float $v) => sprintf('%.0f%%', 100 * $v));
    [$xt, $yt] = [$x->ticks(4), $y->ticks(4)];
    $at = fn(int $i): DateTimeImmutable => $end->modify(($i - $n + 1) . ' seconds');
    $xs = $ys = [];
    foreach ($ch->samples as $i => $v) { [$xs[], $ys[]] = [$x->at($at($i)), $y->at($v)]; }
    $describe = fn(int $i) => sprintf('%s: %.0f%%', $at($i)->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('H:i:s'), 100 * $ch->samples[$i]);
    $c->muted($ch->selected >= 0 && $ch->selected < $n ? $describe($ch->selected) : 'Point at the line, or focus it and use the arrows.');
    Chart::plot($c, new Axes(left: $yt, bottom: $xt, label: axisLabel($c)), function (Node $area) use ($c, $ch, $xs, $ys, $yt, $describe, $n, $t): void {
        $area->draw(function (Painter $p, array $r) use ($xs, $ys, $yt, $t): void {
            foreach ($yt as $tick) { $p->line($r[0], Chart::y($r, $tick->at), $r[0] + $r[2], Chart::y($r, $tick->at), 1, $t->border); }
            $p->fillPath(Chart::area($r, $xs, null, $ys, Curve::Monotone), $t->accent, 0.12);
            $p->strokePath(Chart::line($r, $xs, $ys, Curve::Monotone), 2, $t->accent);
        });
        Chart::select($c, $area, $ch->selected, $xs, $describe);
        if (($i = $ch->selected) >= 0 && $i < $n) {
            Chart::ruleX($c, $xs[$i], 1)->background($t->textMuted);
            Chart::point($c, $xs[$i], $ys[$i], 10, 10)->radius(5)->background($t->accent)->border(2, $t->background);
        }
    })->label('Live samples')->size(0, 200);
}

function stacked(Context $c, Charts $ch): void
{
    $t = $c->theme;
    $c->segmented(['Values', 'Shares'], $ch->stacking)->label('Stacking');
    $how = $ch->stacking === 1 ? Stacking::Normalized : Stacking::Standard;
    $stack = Chart::stack(VISITS, $how);
    $hi = max(array_map(fn($s) => $s->hi, end($stack)));
    $y = $how === Stacking::Normalized ? new Linear(0, 1, format: fn(float $v) => sprintf('%.0f%%', 100 * $v)) : (new Linear(0, $hi))->nice(4);
    $weeks = count(VISITS[0]);
    $x = new Linear(1, $weeks, format: fn(float $v) => sprintf('W%.0f', $v));
    [$xt, $yt] = [$x->ticks(6), $y->ticks(4)];
    $xs = array_map(fn(int $i) => $x->at($i + 1), range(0, $weeks - 1));
    Chart::plot($c, new Axes(left: $yt, bottom: $xt, label: axisLabel($c)), function (Node $area) use ($stack, $xs, $y, $yt, $t): void {
        $area->draw(function (Painter $p, array $r) use ($stack, $xs, $y, $yt, $t): void {
            foreach ($yt as $tick) { $p->line($r[0], Chart::y($r, $tick->at), $r[0] + $r[2], Chart::y($r, $tick->at), 1, $t->border); }
            foreach ($stack as $s => $series) {
                $lo = array_map(fn($g) => $y->at($g->lo), $series);
                $hi = array_map(fn($g) => $y->at($g->hi), $series);
                $p->fillPath(Chart::area($r, $xs, $lo, $hi, Curve::Monotone), COLORS[$s], 0.75);
                $p->strokePath(Chart::line($r, $xs, $hi, Curve::Monotone), 1.5, COLORS[$s]);
            }
        });
    })->label('Visits by source')->size(0, 180);
    legend($c, SOURCES);
}

function scatter(Context $c, Charts $ch): void
{
    $t = $c->theme;
    legend($c, ['Small', 'Midsize', 'Large']);
    $weights = $uses = [];
    foreach ($ch->scatter as $class) { foreach ($class as [$w, $u]) { [$weights[], $uses[]] = [$w, $u]; } }
    $x = (new Linear(...Chart::extent(...$weights)))->nice(4);
    $y = (new Linear(...Chart::extent(...$uses)))->nice(4);
    $x->format = fn(float $v) => sprintf('%.1f t', $v / 1000);
    $y->format = fn(float $v) => sprintf('%.0f L', $v);
    [$xt, $yt] = [$x->ticks(4), $y->ticks(4)];
    Chart::plot($c, new Axes(left: $yt, bottom: $xt, label: axisLabel($c)), function (Node $area) use ($c, $ch, $x, $y, $xt, $yt, $t): void {
        gridLines($area, $xt, $yt, $t->border);
        foreach ($ch->scatter as $k => $class) {
            foreach ($class as [$w, $u]) {
                Chart::point($c, $x->at($w), $y->at($u), 9, 9)->radius(4.5)->background(COLORS[$k], 0.8)->border(1, COLORS[$k])
                    ->tooltip(sprintf('%.0f kg, %.1f L/100 km', $w, $u));
            }
        }
    })->label('Weight and fuel use of cars')->size(0, 200);
}

function donut(Context $c): void
{
    $t = $c->theme;
    $slices = Chart::pie(SHARES, 0.025);
    [$size, $inner, $outer] = [180.0, 56.0, 86.0];
    $c->row(0, 28)->alignItems(Node::CENTER);
      $ring = $c->column()->size($size, $size)->label('Users by platform')->alignItems(Node::CENTER)->justify(Node::CENTER);
        $hover = null;
        $rt = $c->runtime;
        if ($rt !== null && $c->hovered($ring) && ($rr = $rt->rectOf($ring->id)) !== null) {
            $hover = Chart::sliceAt($slices, $rt->input->mouseX - $rr[0] - $size / 2, $rt->input->mouseY - $rr[1] - $size / 2, $inner, $outer);
        }
        $ring->draw(function (Painter $p, array $r) use ($slices, $hover, $size, $inner, $outer): void {
            foreach ($slices as $i => $s) {
                $p->fillPath(Chart::sector($r[0] + $size / 2, $r[1] + $size / 2, $inner, $i === $hover ? $outer : $outer - 4, $s), COLORS[$i]);
            }
        });
        $c->text($hover === null ? '4.2M' : sprintf('%.0f%%', SHARES[$hover]), 24, style: Font::BOLD);
        $c->muted($hover === null ? 'users' : PLATFORMS[$hover], 12);
      $c->end();
      $c->column(0, 8);
        foreach (PLATFORMS as $i => $name) {
            $c->row(0, 8)->alignItems(Node::CENTER);
              $c->box(10, 10, COLORS[$i])->radius(3);
              $c->text($name, 13)->size(70, 0);
              $c->muted(sprintf('%.0f%%', SHARES[$i]), 13);
            $c->end();
        }
      $c->end();
    $c->end();
}

$charts = new Charts;
App::whenReady(function () use ($charts): void {
    $charts->window = new Window(new WindowOptions(title: 'Charts', width: 1080, height: 760, content: Content::view(function (Context $c) use ($charts): void {
        $c->column(20, 16, 1, $c->theme->background)->scroll(new \MyPHP\UI\ScrollState());
          $c->muted('UI\\Chart has scales, axes and marks without a look: the marks are nodes styled here, and lines, areas and slices paths these cards paint.');
          $c->row(0, 16)->wrap($c->width - 40)->alignItems(Node::START);
            card($c, 'Sales', fn() => sales($c, $charts));
            card($c, 'Live data', fn() => live($c, $charts));
            card($c, 'Visits by source', fn() => stacked($c, $charts));
            card($c, 'Weight and fuel use', fn() => scatter($c, $charts));
            card($c, 'Users by platform', fn() => donut($c));
          $c->end();
        $c->end();
    })));
    $tick = function () use (&$tick, $charts): void { // a sample a second
        $charts->sample();
        $charts->window?->invalidate();
        App::after(1, $tick);
    };
    App::after(1, $tick);
});
App::run();
