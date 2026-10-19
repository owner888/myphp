<?php
// Draggable nodes (mygo's draggable_test.go): following the pointer past a
// few points, no click then; thrown to the nearest snap point at the
// pointer's velocity; held by bounds, rubber-banded or rigid; caught
// moving; at rest at once with less motion; in a transformed parent; with
// a pose.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Animation, Context, DraggableOptions, Easing, Motion, Node, Pose};

/** Presses at ($x, $y) and moves the pointer by ($dx, $dy) in $steps steps of 10 ms, without letting go. */
function throwBy(Headless $t, int &$now, float $x, float $y, float $dx, float $dy, int $steps): void
{
    $t->hover($x, $y);
    $t->press($x, $y);
    for ($i = 1; $i <= $steps; $i++) {
        $now += 10_000_000;
        $k = $i / $steps;
        $t->drag($x + $dx * $k, $y + $dy * $k);
    }
}

// It follows the pointer once it moved a few points; a drag is no click; a press that does not move is one.
$off = [0.0, 0.0]; $card = null; $seen = null; $dragging = false; $clicks = 0;
$t = poseTester(function (Context $c) use (&$off, &$card, &$seen, &$dragging, &$clicks) {
    $seen = $off; // read before draggable changes it: the frame builds again
    $card = $c->draggable($c->box(40, 40, 0xff0000), $off);
    $dragging = $c->dragging($card);
    if ($c->clicked($card)) { $clicks++; }
}, $now, 300, 150);
$t->hover(20, 20); $t->press(20, 20);
$now += 10_000_000; $t->drag(22, 21);
check($off == [0, 0] && !$dragging, 'a move of a few points does not drag it');
$now += 10_000_000; $t->drag(50, 30);
check($off == [30, 10] && $dragging && $seen == $off, 'dragged by 30, 10: the offset follows, and the view saw it');
check($card->world->x == 30 && $card->world->y == 10, 'dragged by 30, 10, it shows there');
$now += 5_000_000; $t->release(50, 30);
check($clicks === 0 && !$dragging, 'let go: no click');
$now += 1_000_000_000; $t->frame();
check($off == [30, 10] && $card->world->x == 30 && !$c = $t->rt->transitioning($card->id), 'without a snap, it rests where it was let go');
$t->click(50, 30);
check($clicks === 1 && $off == [30, 10], 'a press that does not move is a click');

// Thrown to snap points.
$off = [0.0, 0.0];
$t = poseTester(function (Context $c) use (&$off, &$card) {
    $card = $c->draggable($c->box(40, 40, 0), $off, new DraggableOptions(Node::HORIZONTAL, bounds: [0, 0, 200, 0], snap: Motion::nearest([0, 0], [200, 0])));
}, $now, 300, 150);
throwBy($t, $now, 20, 20, 60, 0, 6);
$now += 200_000_000; $t->release(80, 20);
check($off == [0, 0], 'let go slowly at 60, the nearest is 0');
check($t->rt->transitioning($card->id), 'let go at 60, it goes back');
$now += 2_000_000_000; $t->frame();
check($card->world->x == 0 && !$t->rt->transitioning($card->id), 'back at rest');
throwBy($t, $now, 20, 20, 60, 0, 6);
$now += 5_000_000; $t->release(80, 20);
check($off == [200, 0], 'thrown at 1000 points a second from 60, it goes on to 200');
$v = $t->rt->dragRecs[$card->id]->ch[0]->velocity;
check($v >= 990 && $v <= 1010, "the spring starts at the pointer's velocity: $v");
$now += 2_000_000_000; $t->frame();
throwBy($t, $now, 220, 20, 0, 50, 5);
$t->release(220, 70);
check($off == [200, 0] && $card->world->y == 0, 'vertical moves do nothing');

// Bounds: rubber-banded past them, as long as the parent (the window) is; rigid ones stop it; an offset set goes within them.
$off = [0.0, 0.0]; $rigid = false;
$t = poseTester(function (Context $c) use (&$off, &$card, &$rigid) {
    $card = $c->draggable($c->box(40, 40, 0), $off, new DraggableOptions(bounds: [0, 0, 100, 50], rigid: $rigid));
}, $now, 300, 150);
throwBy($t, $now, 20, 20, 200, -40, 4);
closeFloat(Motion::rubberband(200, 0, 100, 300), $off[0], 'past its bounds across, less and less far');
closeFloat(Motion::rubberband(-40, 0, 50, 150), $off[1], 'and down');
$now += 200_000_000; $t->release(220, -20);
check($off == [100, 0], 'let go past its bounds, it rests within them');
$now += 2_000_000_000; $t->frame();
check($card->world->x == 100 && $card->world->y == 0, 'back within its bounds');
$rigid = true;
throwBy($t, $now, 120, 20, 100, 100, 4);
check($off == [100, 50], 'rigid bounds stop it');
$t->release(220, 120);
$off = [-50, 20]; $t->frame();
check($off == [0, 20], 'an offset the app sets goes within the bounds');

// A press catches it moving, where it shows, and takes it from there.
$off = [0.0, 0.0]; $clicks = 0;
$t = poseTester(function (Context $c) use (&$off, &$card, &$clicks) {
    $card = $c->draggable($c->box(40, 40, 0), $off, new DraggableOptions(animation: new Animation(0.1, Easing::linear(...))));
    if ($c->clicked($card)) { $clicks++; }
}, $now, 300, 150);
$off = [200, 0]; $t->frame(1);
$now += 50_000_000; $t->frame(1);
check($card->world->x == 100 && $t->rt->transitioning($card->id), 'halfway, it shows at 100');
$t->hover(120, 20); $t->press(120, 20);
check($off == [100, 0] && !$t->rt->transitioning($card->id), 'caught, it rests where it showed');
$now += 10_000_000; $t->drag(130, 20);
same([110.0, 0.0], $off, 'caught and dragged by 10');
$t->release(130, 20);
same(0, $clicks, 'a drag is no click');
$now += 1_000_000_000; $t->frame();
same(110.0, $card->world->x, 'let go, it shows there');

// With less motion, it goes to rest at once; without an offset of its own, it keeps one.
$shown = null;
$t = poseTester(function (Context $c) use (&$card, &$shown) {
    $card = $c->draggable($c->box(40, 40, 0), o: new DraggableOptions(snap: Motion::nearest([0, 0])));
    $shown = $c->dragOffset($card);
}, $now, 300, 150);
throwBy($t, $now, 20, 20, 50, 30, 5);
same([50.0, 30.0], $shown, 'dragged, it shows at 50, 30');
$t->rt->reduceMotion = true;
$t->release(70, 50);
check($shown == [0, 0] && $card->world->x == 0 && !$t->rt->transitioning($card->id), 'let go with less motion, it is at rest at once');

// In a parent twice as large, 40 points of the window are 20 of the parent.
$off = [0.0, 0.0];
$t = poseTester(function (Context $c) use (&$off) {
    $c->column()->size(100, 60)->transformOrigin(0, 0)->scale(2, 2);
      $c->draggable($c->box(20, 20, 0), $off);
    $c->end();
}, $now, 300, 150);
throwBy($t, $now, 10, 10, 40, 20, 4);
same([20.0, 10.0], $off, 'dragged in a transformed parent, in its axes');

// Lifted as it is dragged, it stays under the pointer: the offset moves it in its parent's axes.
$off = [0.0, 0.0];
$t = poseTester(function (Context $c) use (&$off, &$card) {
    $card = $c->draggable($c->box(40, 40, 0), $off);
    $card->animatePose($c->dragging($card) ? new Pose(scale: 2) : new Pose(), new Animation(0.05, Easing::linear(...)));
}, $now, 300, 150);
throwBy($t, $now, 20, 20, 60, 30, 6);
same([60.0, 30.0], $off, 'lifted and dragged by 60, 30');
$w = $card->world;
check($w->a == 2 && $w->x == 40 && $w->y == 10, "scaled twice around its center, moved by 60, 30: {$w->a}, {$w->x}, {$w->y}");

// The nearest snap point; throws no faster than MAX_FLING.
$snap = Motion::nearest([0, 0], [100, 0], [100, 100]);
same([0.0, 0.0], $snap([40, 0]), 'nearest to 40');
same([100.0, 0.0], $snap([60, 0]), 'nearest to 60');
same([100.0, 100.0], $snap([90, 70]), 'nearest to 90, 70');
same([300.0, 400.0], Context::fling([300.0, 400.0]), 'a throw of 500 a second');
$v = Context::fling([60000, 80000]);
closeFloat(4800, $v[0], 'too fast a throw, across'); closeFloat(6400, $v[1], 'and down');
