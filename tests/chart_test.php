<?php
// Charts (mygo's ui/chart tests): ticks, nice domains and labels as
// d3's (d3-array 3.2.4, d3-scale 4.0.2, d3-shape 3.2.0), bands, time
// ticks, stacks, pies, the nearest point, curves, paths, a plot's layout
// and a selection the pointer and the keys move.
declare(strict_types=1);

namespace MyPHP\Tests;

use DateTimeImmutable;
use DateTimeZone;
use MyPHP\UI\{Context, Key, Node, Path};
use MyPHP\UI\Chart\{Axes, Band, Chart, Curve, Linear, Segment, Slice, Stacking, Tick, Ticks, Time};

// Ticks.
foreach ([
    [0, 1, 10, [0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1]],
    [0, 100, 5, [0, 20, 40, 60, 80, 100]],
    [0, 87, 5, [0, 20, 40, 60, 80]],
    [-3.2, 7.9, 6, [-2, 0, 2, 4, 6]],
    [0.0012, 0.0047, 5, [0.0015, 0.002, 0.0025, 0.003, 0.0035, 0.004, 0.0045]],
    [1e6, 3.4e6, 4, [1e6, 1.5e6, 2e6, 2.5e6, 3e6]],
    [10, 0, 5, [10, 8, 6, 4, 2, 0]],
    [0.1, 0.9, 3, [0.2, 0.4, 0.6, 0.8]],
    [1, 1, 5, [1]],
    [0, 1, 1, [0, 1]],
] as [$lo, $hi, $count, $want]) {
    same(array_map('floatval', $want), Ticks::values($lo, $hi, $count), "ticks($lo, $hi, $count)");
}
// Nice.
foreach ([[0, 87, 5, [0, 100]], [0, 87, 10, [0, 90]], [0, 87, 2, [0, 100]], [-3.2, 7.9, 6, [-4, 8]], [0.0012, 0.0047, 5, [0.001, 0.005]], [13, 1042, 10, [0, 1100]], [90, 3, 5, [100, 0]]] as [$lo, $hi, $count, $want]) {
    $s = (new Linear($lo, $hi))->nice($count);
    same(array_map('floatval', $want), [$s->lo, $s->hi], "Linear($lo, $hi)->nice($count)");
}
// Labels.
$labels = fn(array $ts): string => implode(' ', array_map(fn(Tick $t) => $t->label, $ts));
same('0.0 0.2 0.4 0.6 0.8 1.0', $labels((new Linear(0, 1))->ticks(5)), 'labels of 0 to 1');
same('0 20 40 60 80 100', $labels((new Linear(0, 100))->ticks(5)), 'of 0 to 100');
same('0.0015 0.0020 0.0025 0.0030 0.0035 0.0040 0.0045', $labels((new Linear(0.0012, 0.0047))->ticks(5)), 'of small numbers');
same('-1.0 -0.5 0.0 0.5 1.0', $labels((new Linear(-1, 1))->ticks(4)), 'no -0');
same('x x', $labels((new Linear(0, 1, format: fn(float $v) => 'x'))->ticks(1)), 'a format of its own');
$ts = (new Linear(0, 200))->ticks(2);
check($ts[1]->at == 0.5 && $ts[1]->value == 100, 'ticks of 0 to 200');

// Linear.
$s = new Linear(-10, 30);
check($s->at(0) == 0.25 && $s->invert(0.75) == 20, 'at and invert');
$sp = $s->span(0, 10);
same([0.25, 0.5], [$sp->lo, $sp->hi], 'a span');
$s->clamp = true;
check($s->at(50) == 1 && $s->invert(-1) == -10, 'clamped');
same(0.5, (new Linear(3, 3))->at(3), 'an empty domain maps to the middle');
same([-2.0, 9.0], Chart::extent(4, NAN, -2, 9), 'the extent, without NaN');
same([0.0, 1.0], Chart::extent(), 'of nothing');

// Bands.
$b = (new Band('a', 'b', 'c', 'd'))->padding(0.2, 0.1);
foreach ([0.025, 0.275, 0.525, 0.775] as $i => $w) {
    check(abs($b->band($i)->lo - $w) < 1e-6, "band $i starts at $w");
}
check(abs($b->width() - 0.2) < 1e-6, 'a band\'s width');
foreach ([[0.01, 0], [0.1, 0], [0.249, 0], [0.251, 1], [0.99, 3], [-0.01, null], [1.01, null]] as [$f, $i]) {
    same($i, $b->index($f), "the band at $f");
}
$ts = $b->ticks();
check(count($ts) === 4 && $ts[2]->label === 'c' && $ts[2]->at == $b->at(2), 'a tick a band');

// Time ticks.
$utc = new DateTimeZone('UTC');
$parse = fn(string $s): DateTimeImmutable => new DateTimeImmutable($s, $utc);
foreach ([
    ['2026-01-01T00:00:00Z', '2026-12-31T00:00:00Z', 6, ['2026-01-01T00:00:00.000Z', '2026-04-01T00:00:00.000Z', '2026-07-01T00:00:00.000Z', '2026-10-01T00:00:00.000Z'], '2026 Apr Jul Oct'],
    ['2026-03-01T00:00:00Z', '2026-03-08T00:00:00Z', 7, ['2026-03-01T00:00:00.000Z', '2026-03-02T00:00:00.000Z', '2026-03-03T00:00:00.000Z', '2026-03-04T00:00:00.000Z', '2026-03-05T00:00:00.000Z', '2026-03-06T00:00:00.000Z', '2026-03-07T00:00:00.000Z', '2026-03-08T00:00:00.000Z'], 'Mar Mar 2 Mar 3 Mar 4 Mar 5 Mar 6 Mar 7 Mar 8'],
    ['2026-03-05T09:13:00Z', '2026-03-05T17:40:00Z', 5, ['2026-03-05T10:00:00.000Z', '2026-03-05T11:00:00.000Z', '2026-03-05T12:00:00.000Z', '2026-03-05T13:00:00.000Z', '2026-03-05T14:00:00.000Z', '2026-03-05T15:00:00.000Z', '2026-03-05T16:00:00.000Z', '2026-03-05T17:00:00.000Z'], '10:00 11:00 12:00 13:00 14:00 15:00 16:00 17:00'],
    ['2026-03-05T09:13:07Z', '2026-03-05T09:14:51Z', 6, ['2026-03-05T09:13:15.000Z', '2026-03-05T09:13:30.000Z', '2026-03-05T09:13:45.000Z', '2026-03-05T09:14:00.000Z', '2026-03-05T09:14:15.000Z', '2026-03-05T09:14:30.000Z', '2026-03-05T09:14:45.000Z'], ':15 :30 :45 09:14 :15 :30 :45'],
    ['2010-06-01T00:00:00Z', '2026-06-01T00:00:00Z', 5, ['2015-01-01T00:00:00.000Z', '2020-01-01T00:00:00.000Z', '2025-01-01T00:00:00.000Z'], '2015 2020 2025'],
    ['2026-01-29T00:00:00Z', '2026-02-12T00:00:00Z', 8, ['2026-01-29T00:00:00.000Z', '2026-01-31T00:00:00.000Z', '2026-02-01T00:00:00.000Z', '2026-02-03T00:00:00.000Z', '2026-02-05T00:00:00.000Z', '2026-02-07T00:00:00.000Z', '2026-02-09T00:00:00.000Z', '2026-02-11T00:00:00.000Z'], 'Jan 29 Jan 31 Feb Feb 3 Feb 5 Feb 7 Feb 9 Feb 11'],
    ['2026-03-01T00:00:00Z', '2026-03-29T00:00:00Z', 4, ['2026-03-01T00:00:00.000Z', '2026-03-08T00:00:00.000Z', '2026-03-15T00:00:00.000Z', '2026-03-22T00:00:00.000Z', '2026-03-29T00:00:00.000Z'], 'Mar Mar 8 Mar 15 Mar 22 Mar 29'], // Sundays
    ['2026-01-01T00:00:00.120Z', '2026-01-01T00:00:00.870Z', 5, ['2026-01-01T00:00:00.200Z', '2026-01-01T00:00:00.400Z', '2026-01-01T00:00:00.600Z', '2026-01-01T00:00:00.800Z'], '.200 .400 .600 .800'],
] as [$from, $to, $count, $want, $wantLabels]) {
    $got = array_map(fn(Tick $t) => DateTimeImmutable::createFromFormat('U.u', sprintf('%.3F', $t->value / 1000))->format('Y-m-d\TH:i:s.v\Z'), (new Time($parse($from), $parse($to)))->ticks($count));
    same($want, $got, "ticks of $from to $to");
    same($wantLabels, $labels((new Time($parse($from), $parse($to)))->ticks($count)), "labels of $from to $to");
}
$tokyo = new DateTimeZone('+09:00');
$ts = (new Time(new DateTimeImmutable('2026-03-01 00:00', $tokyo), new DateTimeImmutable('2026-03-04 00:00', $tokyo)))->ticks(3);
check(count($ts) === 4 && $ts[1]->label === 'Mar 2', 'ticks fall on round times of the times\' zone: ' . $labels($ts));

// Stacks, as d3's stackOffsetDiverging, stackOffsetExpand and stackOffsetSilhouette.
$segs = fn(array $stack): array => array_map(fn(array $s) => array_map(fn(Segment $g) => [round($g->lo, 9), round($g->hi, 9)], $s), $stack);
$f = fn(array $v): array => array_map(fn(array $s) => array_map(fn(array $p) => array_map('floatval', $p), $s), $v);
same($f([[[0, 3], [-1, 0], [0, 2]], [[3, 7], [0, 2], [-5, 0]], [[7, 8], [2, 3], [2, 3]]]), $segs(Chart::stack([[3, -1, 2], [4, 2, -5], [1, 1, 1]])), 'standard');
$pos = [[3, 1, 2], [4, 2, 5], [1, 1, 1]];
same($f([[[0, 0.375], [0, 0.25], [0, 0.25]], [[0.375, 0.875], [0.25, 0.75], [0.25, 0.875]], [[0.875, 1], [0.75, 1], [0.875, 1]]]), $segs(Chart::stack($pos, Stacking::Normalized)), 'normalized');
same($f([[[-4, -1], [-2, -1], [-4, -2]], [[-1, 3], [-1, 1], [-2, 3]], [[3, 4], [1, 2], [3, 4]]]), $segs(Chart::stack($pos, Stacking::Center)), 'center');
$got = $segs(Chart::stack([[1, NAN], [2]]));
same([[[0.0, 1.0], [0.0, 0.0]], [[1.0, 3.0], [0.0, 0.0]]], $got, 'shorter series and NaN take no room');

// Pies.
$got = Chart::pie([1, 2, 3, 0, 4], 0.02);
foreach ([[0, 0.6383185307179586], [0.6383185307179586, 1.8949555921538759], [1.8949555921538759, 3.7699111843077517], [3.7699111843077517, 3.7899111843077518], [3.7899111843077518, 6.283185307179586]] as $i => [$a, $z]) {
    check(abs($got[$i]->start - $a) < 1e-9 && abs($got[$i]->end - $z) < 1e-9, "slice $i");
}
$quarters = Chart::pie([1, 1, 1, 1]);
foreach ([[1, -10], [10, 1], [-1, 10], [-10, -1]] as $i => [$x, $y]) {
    same($i, Chart::sliceAt($quarters, $x, $y, 5, 20), "twelve, three, six and nine: $i");
}
same(null, Chart::sliceAt($quarters, 1, 1, 5, 20), 'the hole of a donut has no slice');
[$x, $y] = Chart::polar(0, 0, 10, M_PI / 2);
check(abs($x - 10) < 1e-9 && abs($y) < 1e-9, 'polar at a quarter turn');

// The nearest point.
foreach ([[-1, 0], [0.1, 0], [0.125, 0], [0.2, 1], [0.4, 2], [0.8, 3], [2, 3]] as [$at, $i]) {
    same($i, Chart::nearest([0, 0.25, 0.5, 1], $at), "nearest $at");
}
same(-1, Chart::nearest([], 0.5), 'no points, no nearest');

// Curves.
$pts = [[0.0, 0.0], [10.0, 10.0], [20.0, 10.0], [30.0, 0.0]];
$m = Chart::curve($pts, Curve::Monotone);
check(count($m) === 3 && isset($m[1]['c1']) && $m[1]['c1'][1] == 10 && $m[1]['c2'][1] == 10, 'monotone keeps to the flat stretch');
$sa = Chart::curve($pts, Curve::StepAfter);
check(count($sa) === 6 && $sa[0]['to'] === [10.0, 0.0], 'step after');
same([0.0, 10.0], Chart::curve($pts, Curve::StepBefore)[0]['to'], 'step before');
$st = Chart::curve($pts, Curve::Step);
check(count($st) === 9 && $st[0]['to'] === [5.0, 0.0] && $st[1]['to'] === [5.0, 10.0], 'step');
$two = Chart::curve(array_slice($pts, 0, 2), Curve::Monotone);
check(count($two) === 1 && !isset($two[0]['c1']), 'two points join straight');

// Paths: what they go through.
$cmds = fn(Path $p): array => array_map(fn(array $c) => array_map(fn($v) => is_float($v) ? round($v, 6) : $v, $c), (fn() => $this->cmds)->call($p));
$r = [0.0, 0.0, 100.0, 100.0];
$line = $cmds(Chart::line($r, [0, 0.25, 0.5, 0.75], [0, 1, NAN, 1]));
same(2, count(array_filter($line, fn($c) => $c[0] === $line[0][0])), 'a NaN breaks a line in two');
$area = $cmds(Chart::area($r, [0, 0.5, 1], null, [0.5, 0.75, 1]));
check($area[0][1] == 0 && $area[0][2] == 50 && end($area)[0] !== $area[0][0], 'an area from its top line, closed');
check(Chart::sector(50, 50, 20, 40, new Slice(0, M_PI))->bounds() !== null, 'a donut\'s half');
same(null, Chart::sector(50, 50, 20, 40, new Slice(1, 1))->bounds(), 'an empty slice is no path');

// A plot's layout: the area after the widest label, labels centered on their ticks, marks in the area.
$chartFind = function (?Node $n, string $label) use (&$chartFind): ?Node {
    if ($n === null) { return null; }
    if ($n->label === $label) { return $n; }
    foreach ($n->kids as $k) { if (($f = $chartFind($k, $label)) !== null) { return $f; } }
    return null;
};
$x = (new Band('Mon', 'Tue', 'Wed', 'Thu'))->padding(0.2, 0);
$yl = new Linear(0, 100);
$h = new Headless(function (Context $c) use ($x, $yl): void {
    $c->column(20);
      Chart::plot($c, new Axes(left: $yl->ticks(2), bottom: $x->ticks()), function (Node $area) use ($c, $x, $yl): void {
          $area->label('area');
          Chart::rect($c, $x->band(1), $yl->span(0, 50))->label('bar');
          Chart::point($c, 0.5, 0.5, 10, 10)->label('dot');
          Chart::ruleY($c, 1, 2)->label('top rule');
          Chart::anchor($c, 1, 0, Node::END, Node::END, fn() => $c->text('corner')->noWrap());
      })->size(400, 200);
    $c->end();
}, 440, 240);
$root = $h->frame();
$ra = $h->rect($chartFind($root, 'area'));
$r100 = $h->rect($h->byText('100'));
$mon = $h->rect($h->byText('Mon'));
check($ra[0] == 20 + $r100[2] && $r100[0] == 20, 'the area starts after the widest label: ' . json_encode([$ra, $r100]));
check(abs($r100[1] + $r100[3] / 2 - $ra[1]) <= 0.5, 'the label 100 is centered on the top of the area');
check($ra[1] + $ra[3] == $mon[1] && $ra[1] + $ra[3] + $mon[3] == 220, 'the labels below it: ' . json_encode([$ra, $mon]));
check(abs($mon[0] + $mon[2] / 2 - ($ra[0] + $ra[2] * $x->band(0)->mid())) <= 0.5, 'Mon centered on its band');
$bar = $h->rect($chartFind($root, 'bar'));
$band = $x->band(1);
$want = [$ra[0] + $ra[2] * $band->lo, $ra[1] + $ra[3] / 2, $ra[2] * ($band->hi - $band->lo), $ra[3] / 2];
check(max(array_map(fn($a, $b) => abs($a - $b), $bar, $want)) <= 0.5, 'the bar: ' . json_encode([$bar, $want]));
$dot = $h->rect($chartFind($root, 'dot'));
check($dot[0] + 5 == $ra[0] + $ra[2] / 2 && $dot[1] + 5 == $ra[1] + $ra[3] / 2 && $dot[2] == 10, 'the dot centered: ' . json_encode($dot));
$rule = $h->rect($chartFind($root, 'top rule'));
check($rule[1] == $ra[1] - 1 && $rule[2] == $ra[2] && $rule[3] == 2, 'the rule: ' . json_encode($rule));
$corner = $h->rect($h->byText('corner'));
check(abs($corner[0] + $corner[2] - ($ra[0] + $ra[2])) <= 0.5 && abs($corner[1] + $corner[3] - ($ra[1] + $ra[3])) <= 0.5, 'the corner ends at the bottom right: ' . json_encode([$corner, $ra]));

// Choosing a point with the pointer and the keys.
$selected = -1;
$changes = 0;
$h = new Headless(function (Context $c) use (&$selected, &$changes): void {
    Chart::plot($c, new Axes, function (Node $area) use ($c, &$selected, &$changes): void {
        $area->label('plot');
        if (Chart::select($c, $area, $selected, [0.1, 0.5, 0.9], fn(int $i) => ['one', 'two', 'three'][$i])) { $changes++; }
    })->size(200, 100);
}, 300, 100);
$h->frame();
$h->input->push(['accessOn']);
$h->frame();
$h->hover(95, 50);
same(1, $selected, 'the pointer at the middle');
$h->hover(190, 50);
same(2, $selected, 'at the right');
$h->hover(250, 50);
same(-1, $selected, 'gone from the plot');
same(3, $changes, 'three changes');
$h->tab();
check($h->focused()?->label === 'plot', 'Tab focuses the plot');
$h->right(); $h->right();
same(1, $selected, 'two rights');
$h->end(); $h->right();
same(2, $selected, 'End, and no further');
$h->home();
same(['one', 'two', 'three', 'one'], $h->told, 'announced as the keys come to them');
$h->escape();
same(-1, $selected, 'Escape clears it, and lets the focus go');
$h->tab();
$h->left();
same(2, $selected, 'left from none goes to the last');
