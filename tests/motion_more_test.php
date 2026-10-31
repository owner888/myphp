<?php
// More of mygo's motion_api_test.go and transition_test.go
// (TestScrollToGlides, TestListDividers, TestTransitionResizesAList): a
// scroll to a place gliding as its animation says, by the frame's clock,
// and stopped by the user; a list's dividers between its rows; a list in
// a column whose width animates showing its rows on the way.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Animation, Context, DisplayList, ElementTransition, Easing, ListState, Node, ScrollState};

// A scroll to 500 in 100 ms, linear: halfway at 50 ms, there at 110 and still; to the end, the user scrolling stops it.
$feed = new ScrollState(); $now = 0;
$h = poseTester(function (Context $c) use ($feed): void {
    $c->column()->size(100, 100)->scroll($feed);
      $c->column()->size(100, 1000)->shrink(0); $c->end();
    $c->end();
}, $now, 200, 200);
$h->frame(1);
$feed->scrollTo(500, new Animation(duration: 0.1, ease: Easing::linear(...)));
$h->frame(1);
$now += 50_000_000; $h->frame(1);
check($feed->y >= 240 && $feed->y <= 260 && $feed->moving(), "halfway, at {$feed->y}");
$now += 60_000_000; $h->frame(1);
check($feed->y == 500.0 && !$feed->moving() && !$h->rt->needsFrame, "there, at {$feed->y}");
$feed->scrollTo(INF, Animation::smooth());
$h->frame(1);
$now += 30_000_000; $h->frame(1);
$h->input->push(['scroll', 50, 50, 0, 10]); $h->frame(1);
check(!$feed->moving(), 'the user scrolling stops it');
$y = $feed->y;
$now += 1_000_000_000; $h->frame(1);
same($y, $feed->y, 'stopped, it stays');

// A list's dividers: between its rows, none after the last.
$s = new ListState();
$h = new Headless(function (Context $c) use ($s): void {
    $c->list($s, 3, fn(int $i) => $c->box(0, 20, 0x000000))->height(100)->dividers(1, 0xff0000);
}, 200, 100);
$h->frame();
$list = $h->paint(); $ys = [];
for ($k = 0; $k < $list->n; $k++) {
    $b = $k * 16;
    if ($list->buf[$b + 4] == 1.0 && $list->buf[$b + 5] == 0.0 && $list->buf[$b + 6] == 0.0) { $ys[] = $list->buf[$b + 1]; }
}
same([20.0, 40.0], array_map(fn($y) => round($y + 0.5), $ys), 'dividers on 20 and 40, between the rows');

// A list in a column closing to no width shows its rows on the way, none once closed; opening, it shows them again.
$open = true; $now = 0;
$h = poseTester(function (Context $c) use (&$open): void {
    $c->row(0, 0, 1)->alignItems(Node::STRETCH);
      $col = $c->column()->clipX();
      $open ? $col->width(200) : $col->maxWidth(0); // width(0) leaves the width to the content
      $c->transition($col, new ElementTransition(ease: Easing::linear(...), size: true));
        $s = $c->runtime->local($col->id, fn() => new ListState());
        $c->list($s, 1000, fn(int $i) => $c->text("Row $i")->padding(4))->grow = 1;
      $c->end();
      $c->text('Main')->grow = 1;
    $c->end();
}, $now, 600, 300);
$h->frame(1);
check($h->byText('Row 0') !== null, 'open, the list shows its rows');
$open = false; $h->frame(1);
$shown = [];
for ($i = 1; $i <= 4; $i++) {
    $now += 50_000_000; $h->frame(1);
    $r = ($n = $h->byText('Row 0')) === null ? null : $h->rect($n);
    $shown[] = $r !== null && $r[2] > 8; // closed, a row is as wide as its padding alone, as in CSS, which the column cuts away
}
same([true, true, true, false], $shown, 'closing, its rows show on the way, none once closed');
$open = true; $h->frame(1);
$now += 100_000_000; $h->frame(1);
check(($n = $h->byText('Row 0')) !== null && $h->rect($n)[2] > 0, 'opening, it shows its rows');
