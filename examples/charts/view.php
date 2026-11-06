<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, ElementTransition, Font, Node, Painter};
use MyPHP\UI\Chart\{Axes, Band, Chart, Curve, Linear, Side, Stacking, Tick, Time};

/** The look of the axes' labels. */
$axisLabel = function (Context $c): Closure {
    return fn(Side $s, Tick $t) => $c->text($t->label, 11, $c->theme->textMuted)->noWrap();
};

/** Paints a line across the plot at each tick of $y, and along it at each of $x. @param list<Tick> $x @param list<Tick> $y */
$gridLines = function (Node $area, array $x, array $y, int $color): void {
    $area->draw(function (Painter $p, array $r) use ($x, $y, $color): void {
        foreach ($y as $tick) { $p->line($r[0], Chart::y($r, $tick->at), $r[0] + $r[2], Chart::y($r, $tick->at), 1, $color); }
        foreach ($x as $tick) { $p->line(Chart::x($r, $tick->at), $r[1], Chart::x($r, $tick->at), $r[1] + $r[3], 1, $color); }
    });
};

/** The series' names by their colors. @param list<string> $names */
$legend = function (Context $c, array $names): void {
    $c->row(0, 14);
      foreach ($names as $i => $name) {
          $c->row(0, 6)->alignItems(Node::CENTER);
            $c->box(10, 10, COLORS[$i])->radius(3);
            $c->muted($name, 12);
          $c->end();
      }
    $c->end();
};

/** A card: a titled panel. */
$card = function (Context $c, string $title, Closure $body): void {
    $t = $c->theme;
    $c->column(16, 10, 1, $t->surface)->radius(10)->border(1, $t->border)->basis(320)->minWidth(0)->minHeight(0);
      $c->text($title, 14, style: Font::BOLD);
      $body();
    $c->end();
};

$sales = function (Context $c, Charts $ch) use (&$axisLabel, &$gridLines): void {
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
    Chart::plot($c, new Axes(left: $ticks, bottom: $months, label: $axisLabel($c)), function (Node $area) use ($c, $ch, $x, $y, $ticks, $t, $gridLines): void {
        $gridLines($area, [], $ticks, $t->border);
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
};

$live = function (Context $c, Charts $ch) use (&$axisLabel): void {
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
    Chart::plot($c, new Axes(left: $yt, bottom: $xt, label: $axisLabel($c)), function (Node $area) use ($c, $ch, $xs, $ys, $yt, $describe, $n, $t): void {
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
};

$stacked = function (Context $c, Charts $ch) use (&$axisLabel, &$legend): void {
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
    Chart::plot($c, new Axes(left: $yt, bottom: $xt, label: $axisLabel($c)), function (Node $area) use ($stack, $xs, $y, $yt, $t): void {
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
    $legend($c, SOURCES);
};

$scatter = function (Context $c, Charts $ch) use (&$legend, &$axisLabel, &$gridLines): void {
    $t = $c->theme;
    $legend($c, ['Small', 'Midsize', 'Large']);
    $weights = $uses = [];
    foreach ($ch->scatter as $class) { foreach ($class as [$w, $u]) { [$weights[], $uses[]] = [$w, $u]; } }
    $x = (new Linear(...Chart::extent(...$weights)))->nice(4);
    $y = (new Linear(...Chart::extent(...$uses)))->nice(4);
    $x->format = fn(float $v) => sprintf('%.1f t', $v / 1000);
    $y->format = fn(float $v) => sprintf('%.0f L', $v);
    [$xt, $yt] = [$x->ticks(4), $y->ticks(4)];
    Chart::plot($c, new Axes(left: $yt, bottom: $xt, label: $axisLabel($c)), function (Node $area) use ($c, $ch, $x, $y, $xt, $yt, $t, $gridLines): void {
        $gridLines($area, $xt, $yt, $t->border);
        foreach ($ch->scatter as $k => $class) {
            foreach ($class as [$w, $u]) {
                Chart::point($c, $x->at($w), $y->at($u), 9, 9)->radius(4.5)->background(COLORS[$k], 0.8)->border(1, COLORS[$k])
                    ->tooltip(sprintf('%.0f kg, %.1f L/100 km', $w, $u));
            }
        }
    })->label('Weight and fuel use of cars')->size(0, 200);
};

$donut = function (Context $c): void {
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
};

/** The cards, a page of charts. */
return function (Context $c) use (&$card, &$sales, &$live, &$stacked, &$scatter, &$donut): void {
    $c->column(20, 16, 1, $c->theme->background)->scroll(new \MyPHP\UI\ScrollState());
      $c->muted('UI\\Chart has scales, axes and marks without a look: the marks are nodes styled here, and lines, areas and slices paths these cards paint.');
      $c->row(0, 16)->wrap($c->width - 40)->alignItems(Node::START);
        $card($c, 'Sales', fn() => $sales($c, $this));
        $card($c, 'Live data', fn() => $live($c, $this));
        $card($c, 'Visits by source', fn() => $stacked($c, $this));
        $card($c, 'Weight and fuel use', fn() => $scatter($c, $this));
        $card($c, 'Users by platform', fn() => $donut($c));
      $c->end();
    $c->end();
};
