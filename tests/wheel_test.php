<?php
// A mouse wheel's notches glide, as browsers scroll them (mygo's wheel_test.go,
// 2549f24 and 9fd99c0): notches add up, stop at the end, an app scrolling the
// container takes over; a touchpad scrolls at once; less motion, at once; a
// container at its end passes the wheel to the one around it.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, ListState, ScrollState};

$s = new ScrollState(); $now = 0;
$h = poseTester(function (Context $c) use ($s) {
    $c->column(0, 0, 1)->scroll($s); $c->box(0, 1000, 0); $c->end();
}, $now, 200, 200);
$h->frame(1);
$advance = function (int $ms) use ($h, &$now) { for ($t = 0; $t < $ms; $t += 16) { $now += 16_000_000; $h->frame(1); } };
$h->wheel(100, 100, 0, -100);
same(0.0, $s->y, 'a notch does not scroll at once');
$advance(50);
check($s->y > 0 && $s->y < 100, "a notch on its way: at {$s->y}");
$h->wheel(100, 100, 0, -100);
$advance(1000);
same(200.0, $s->y, 'two notches add up');
for ($i = 0; $i < 10; $i++) { $h->wheel(100, 100, 0, -100); }
$advance(1000);
same(800.0, $s->y, 'past the end they stop there');
$h->wheel(100, 100, 0, 100);
$advance(50);
$s->y = 10.0;
$advance(1000);
same(10.0, $s->y, 'the app setting the offset takes over');
$h->input->push(['scroll', 100, 100, 0, -30, true]); $h->frame(1);
same(40.0, $s->y, 'a touchpad scrolls at once');

// Less motion: at once.
$h->rt->reduceMotion = true;
$h->wheel(100, 100, 0, -100);
same(140.0, $s->y, 'with less motion, a notch scrolls at once');
$h->rt->reduceMotion = false;

// A container at its end passes the wheel to the one around it.
$outer = new ScrollState(); $inner = new ScrollState();
$h = poseTester(function (Context $c) use ($outer, $inner) {
    $c->column(0, 0, 1)->scroll($outer);
      $c->column()->scroll($inner)->size(0, 100); $c->box(0, 150, 0); $c->end();
      $c->box(0, 1000, 0);
    $c->end();
}, $now, 200, 200);
$h->frame(1);
$advance = function (int $ms) use ($h, &$now) { for ($t = 0; $t < $ms; $t += 16) { $now += 16_000_000; $h->frame(1); } };
$h->wheel(50, 50, 0, -100); $advance(1000);
same([50.0, 0.0], [$inner->y, $outer->y], 'the inner one first, to its end');
$h->wheel(50, 50, 0, -100); $advance(1000);
same([50.0, 100.0], [$inner->y, $outer->y], 'then the one around it');

// A list scrolled by the app during a notch stops there.
$l = new ListState();
$h = poseTester(function (Context $c) use ($l) {
    $c->column(0, 0, 1); $c->list($l, 200, fn(int $i) => $c->text("Row $i")->size(0, 30))->grow = 1; $c->end();
}, $now, 200, 300);
$h->frame(1);
$advance = function (int $ms) use ($h, &$now) { for ($t = 0; $t < $ms; $t += 16) { $now += 16_000_000; $h->frame(1); } };
$h->wheel(100, 100, 0, -300); $advance(50);
check($l->row > 0 || $l->offset > 0, 'the list glides');
$l->scrollTo(0, false);
$advance(1000);
same([0, 0.0], [$l->row, $l->offset], 'scrolled back to its top by the app during a notch: it stays there');

// A pinch reaches the node under the pointer taking input as ZOOM (mygo's InputZoom); a scroll's momentum is told (mygo's 9c69cf8).
$got = [];
$h = new Headless(function (Context $c) use (&$got) {
    $c->box(200, 200, 0)->handleInput(function (\MyPHP\UI\InputEvent $ev) use (&$got): bool { $got[] = [$ev->kind, round($ev->scale, 3), $ev->momentum]; return true; });
}, 200, 200);
$h->frame();
$h->input->push(['magnify', 100, 100, 0.25, 0]);
$h->input->push(['scroll', 100, 100, 0, -40, true, true]);
same([[\MyPHP\UI\InputEvent::ZOOM, 1.25, false], [\MyPHP\UI\InputEvent::SCROLL, 1.0, true]], $got, 'a pinch as ZOOM, the momentum of a scroll');
