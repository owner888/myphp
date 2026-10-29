<?php
// Transitions (mygo's transition_test.go and transition_motion_test.go):
// nodes moving and resizing from where the last frame showed them, coming
// in and going out, their colors, springs and delays, matched nodes, and
// what less motion and a resized window do, on a clock the test sets.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Animation, Context, Easing, ElementTransition, Node, TransitionMotion};

/** A harness whose frames happen at $now, nanoseconds, that the test moves on (mygo's clockTester). */
function clockTester(\Closure $view, ?int &$now, float $w, float $h): Headless
{
    $now = 1_000_000 * 1_000_000_000;
    $t = new Headless($view, $w, $h);
    $t->rt->clock = function () use (&$now): int { return $now; };
    $t->frame(1);
    return $t;
}

/** Moves the clock on by $ms and draws the frame that follows. */
function later(Headless $t, int &$now, float $ms): void
{
    $now += (int)round($ms * 1_000_000);
    $t->frame(1);
}

/** The y of the text $s, failing when it does not show. */
function textAt(Headless $t, string $s): array
{
    $n = $t->byText($s);
    check($n !== null, "$s shows");
    return $n === null ? [0.0, 0.0, 0.0, 0.0] : [$n->x, $n->y, $n->w, $n->h];
}

/** The copies leaving under $n. @return list<Node> */
function ghostsOf(Node $n): array
{
    return $n->ghosts;
}

$lin = Easing::linear(...);

// Rows swapping places move to them.
$order = ['A', 'B'];
$t = clockTester(function (Context $c) use (&$order, $lin) {
    $c->column();
      foreach ($order as $s) {
          $c->transition($c->key($s)->row()->size(0, 20), new ElementTransition(0.2, $lin));
            $c->text($s);
          $c->end();
      }
    $c->end();
}, $now, 200, 100);
$off = textAt($t, 'A')[1];
same(20.0, textAt($t, 'B')[1] - $off, 'B starts 20 down');
$order = ['B', 'A']; $t->frame(1);
same(20.0, textAt($t, 'B')[1] - $off, 'B does not jump as the change comes');
later($t, $now, 100);
same([10.0, 10.0], [textAt($t, 'B')[1] - $off, textAt($t, 'A')[1] - $off], 'halfway, both at 10');
later($t, $now, 150);
same([0.0, 20.0], [textAt($t, 'B')[1] - $off, textAt($t, 'A')[1] - $off], 'once over, B at 0 and A at 20');
same(2, count($t->rt->trans), 'two transitions kept for two nodes');

// Resizing lays the content out at the size shown.
$wide = false;
$t = clockTester(function (Context $c) use (&$wide, $lin) {
    $c->transition($c->column()->size($wide ? 300 : 100, 40), new ElementTransition(ease: $lin, size: true));
      $c->text('Right')->alignSelf(Node::END);
    $c->end();
}, $now, 400, 100);
$r = textAt($t, 'Right');
check($r[0] + $r[2] <= 101, 'Right ends at the box');
$wide = true; $t->frame(1);
later($t, $now, 100);
$r = textAt($t, 'Right');
check($r[0] + $r[2] >= 195 && $r[0] + $r[2] <= 205, 'halfway, Right ends near 200: ' . ($r[0] + $r[2]));
later($t, $now, 1000);
$r = textAt($t, 'Right');
check($r[0] + $r[2] >= 299, 'once over, Right ends at 300');

// Rows come in and go out, collapsing.
$items = ['One', 'Two'];
$motion = new ElementTransition(ease: $lin, enter: new TransitionMotion(collapse: true), exit: new TransitionMotion(collapse: true));
$col = null;
$t = clockTester(function (Context $c) use (&$items, &$col, $motion) {
    $col = $c->column();
      foreach ($items as $s) {
          $c->transition($c->key($s)->row()->size(0, 30), $motion);
            $c->text($s);
          $c->end();
      }
    $c->end();
}, $now, 200, 200);
check($t->byText('One') !== null, 'the first frame shows its rows');
same(2, count($t->rt->trans), 'two transitions');
check(array_sum(array_map(fn($r) => $r->moving, $t->rt->trans)) === 0, 'the first frame\'s rows appear at once: their parent is new too');
$row = function (string $s) use (&$col): ?Node { foreach ($col->kids as $k) { if (($k->kids[0]->text ?? null) === $s) { return $k; } } return null; };
$items = ['New', 'One', 'Two']; $t->frame(1);
check(($e = $row('New')) !== null && $e->h == 0 && $e->clip !== 0, 'the new row starts collapsed and cut off');
later($t, $now, 100);
check(($h = $row('New')->h) >= 14 && $h <= 16, "halfway, the new row is $h high");
later($t, $now, 150);
same(30.0, $row('New')->h, 'once in, the new row is 30 high');
$items = ['New', 'Two']; $t->frame(1);
$g = ghostsOf($col);
check(count($g) === 1 && ($g[0]->kids[0]->text ?? null) === 'One' && $g[0]->h == 30, 'the row gone leaves as a copy, 30 high');
check($t->byText('One') === null, 'the row gone is not found');
later($t, $now, 100);
check(count(ghostsOf($col)) === 1 && ($h = ghostsOf($col)[0]->h) >= 14 && $h <= 16, 'halfway, the copy is half high');
later($t, $now, 150);
same(0, count(ghostsOf($col)), 'the copy is gone once its exit ended');
same(2, count($t->rt->trans), 'two transitions kept for two nodes');
// Back while its copy was leaving, a row comes back from there.
$items = ['New', 'Two', 'Three']; $t->frame(1);
later($t, $now, 1000);
$items = ['New', 'Two']; $t->frame(1);
later($t, $now, 100);
$items = ['New', 'Two', 'Three']; $t->frame(1);
same(0, count(ghostsOf($col)), 'no copy leaves as the row comes back');
check(($e = $row('Three')) !== null && $e->h >= 14 && $e->h <= 16, 'the row back starts from where its copy was: ' . ($e?->h));

// Colors fade.
$bg = 0x0000ff; $box = null;
$t = clockTester(function (Context $c) use (&$bg, &$box, $lin) {
    $box = $c->transition($c->column(0, 0, 0, $bg)->size(50, 50), new ElementTransition(ease: $lin, colors: true));
    $c->end();
}, $now, 100, 100);
same(0x0000ff, $box->color, 'the box is blue');
$bg = 0xff0000; $t->frame(1);
later($t, $now, 100);
$red = $box->color >> 16; $blue = $box->color & 255;
check($red >= 110 && $red <= 145 && $blue >= 110 && $blue <= 145, sprintf('halfway, the box is #%06x', $box->color));
later($t, $now, 1000);
same(0xff0000, $box->color, 'once over, the box is red');

// Without motion, and as the window resizes, it shows where it is.
$x = 0.0;
$t = clockTester(function (Context $c) use (&$x) {
    $c->column(0, 0)->size(100, 100);
      $c->transition($c->text('Moving')->at($x, 0), new ElementTransition());
    $c->end();
}, $now, 300, 200);
$t->rt->reduceMotion = true;
$x = 50.0; $t->frame(1);
same(50.0, textAt($t, 'Moving')[0], 'without motion, it shows where it is');
$t->rt->reduceMotion = false;
$x = 0.0; $t->frame(1);
check(textAt($t, 'Moving')[0] != 0, 'with motion, it does not jump');
later($t, $now, 1000);
$x = 20.0; $t->w = 400; $t->frame(1);
same(20.0, textAt($t, 'Moving')[0], 'as the window resizes, it shows where it is');

// A spring keeps going: sent back, it goes on for a moment, then turns.
$x = 0.0; $boxes = [];
$t = clockTester(function (Context $c) use (&$x, &$boxes) {
    $c->column(0, 0)->size(300, 100);
      $boxes['box'] = $c->transition($c->key('box')->box(20, 20, 0)->at($x, 0), new ElementTransition(animation: Animation::snappy()));
    $c->end();
}, $now, 300, 100);
$x = 200.0; $t->frame(1);
later($t, $now, 100);
$was = $boxes['box']->x; $v = $t->rt->trans[$boxes['box']->id]->ch[0]->velocity;
check($was > 0 && $was < 200 && $v > 0, "on its way, the box is at $was going $v/s");
$x = 0.0; $t->frame(1);
later($t, $now, 16);
check($boxes['box']->x > $was, 'sent back, the box does not turn at once: ' . $boxes['box']->x);
check($t->rt->transitioning($boxes['box']->id), 'the box moving is transitioning');
later($t, $now, 2000);
check($boxes['box']->x == 0 && count($t->rt->trans) === 1 && $t->rt->trans[$boxes['box']->id]->moving === 0, 'once over, the box is at 0');
$t->frame(1);
check(!$t->rt->transitioning($boxes['box']->id), 'the box at rest is not transitioning');

// Delays stagger the changes.
$y = 0.0;
$t = clockTester(function (Context $c) use (&$y, &$boxes, $lin) {
    $c->column(0, 0)->size(300, 100);
      for ($i = 0; $i < 3; $i++) {
          $boxes[$i] = $c->transition($c->key($i)->box(20, 20, 0)->at($i * 30, $y), new ElementTransition(animation: new Animation(0.1, $lin, delay: $i * 0.05)));
      }
    $c->end();
}, $now, 300, 100);
$y = 60.0; $t->frame(1);
later($t, $now, 75);
same([45.0, 15.0, 0.0], [round($boxes[0]->y, 6), round($boxes[1]->y, 6), round($boxes[2]->y, 6)], 'staggered, the boxes on their way');

// Entering scaled and turned; going, grown.
$show = false; $holder = null;
$t = clockTester(function (Context $c) use (&$show, &$boxes, &$holder, $lin) {
    $holder = $c->column(0, 0)->size(200, 200);
      if ($show) {
          $boxes['pop'] = $c->transition($c->key('pop')->box(40, 40, 0), new ElementTransition(0.1, $lin, enter: new TransitionMotion(opacity: 1, scale: 0.5, rotate: 90), exit: new TransitionMotion(scale: 2)));
      }
    $c->end();
}, $now, 200, 200);
$show = true; $t->frame(1);
$w = $boxes['pop']->world;
check($w->set && $w->a <= 0.01 && $w->b >= 0.49 && $w->b <= 0.51, 'entering, the box shows half as large, turned a quarter: ' . json_encode([$w->a, $w->b]));
later($t, $now, 50);
$b = $t->rt->worldOf($boxes['pop']->id)->bounds($boxes['pop']->x, $boxes['pop']->y, 40, 40);
check($b[2] > 30 && $b[2] < 60, "halfway in, the box is {$b[2]} wide");
later($t, $now, 1000);
check(!$boxes['pop']->world->set, 'in, the box is no longer transformed');
$show = false; $t->frame(1);
later($t, $now, 50);
$g = ghostsOf($holder);
check(count($g) === 1 && $g[0]->world->set && $g[0]->world->a >= 1.4 && $g[0]->world->a <= 1.6, 'halfway out, the copy grows: ' . json_encode($g ? [$g[0]->world->a] : []));

// A node of a match comes from where its match showed, in its colors, laid out at the sizes on the way.
$detail = false;
$t = clockTester(function (Context $c) use (&$detail, &$boxes, $lin) {
    $tr = new ElementTransition(0.1, $lin, match: 'photo-7');
    $c->column(0, 0)->size(400, 300);
      if (!$detail) {
          $c->key('grid')->column(0, 0)->at(20, 30)->size(100, 100);
            $boxes['thumb'] = $c->transition($c->key('thumb')->column(0, 0, 0, 0xff0000)->size(40, 20), $tr); $c->end();
          $c->end();
      } else {
          $c->key('detail')->column(0, 0)->at(100, 100)->size(200, 100);
            $boxes['hero'] = $c->transition($c->key('hero')->column(0, 0, 0, 0x0000ff)->size(200, 100), $tr); $c->end();
          $c->end();
      }
    $c->end();
}, $now, 400, 300);
$near = fn(Node $n, array $want) => abs($n->x - $want[0]) < 0.5 && abs($n->y - $want[1]) < 0.5 && abs($n->w - $want[2]) < 0.5 && abs($n->h - $want[3]) < 0.5;
$detail = true; $t->frame(1);
check($near($boxes['hero'], [20, 30, 40, 20]), 'appearing, the hero shows where the thumbnail was');
same(0xff0000, $boxes['hero']->color, "appearing, the hero is the thumbnail's red");
later($t, $now, 50);
check($near($boxes['hero'], [60, 65, 120, 60]), 'halfway, the hero is between: ' . json_encode([$boxes['hero']->x, $boxes['hero']->y, $boxes['hero']->w, $boxes['hero']->h]));
check(!$boxes['hero']->world->set, 'halfway, the hero is laid out at its size, not scaled');
later($t, $now, 1000);
check($near($boxes['hero'], [100, 100, 200, 100]), 'once over, the hero is in its place');
same(0x0000ff, $boxes['hero']->color, 'once over, the hero is blue');
$detail = false; $t->frame(1);
check($near($boxes['thumb'], [100, 100, 200, 100]), 'back, the thumbnail comes from the hero');
later($t, $now, 1000);
$t->rt->reduceMotion = true;
$detail = true; $t->frame(1);
check($near($boxes['hero'], [100, 100, 200, 100]), 'without motion, the hero shows where it is');

// Without motion, what fades fades in place, what collapses appears at once, and colors still change smoothly.
$show = false; $bg = 0x0000ff;
$t = clockTester(function (Context $c) use (&$show, &$bg, &$boxes, &$holder, $lin) {
    $holder = $c->column(0, 0)->size(200, 200);
      $boxes['color'] = $c->transition($c->key('color')->column(0, 0, 0, $bg)->size(50, 50), new ElementTransition(0.1, $lin)); $c->end();
      if ($show) {
          $boxes['fade'] = $c->transition($c->key('fade')->box(20, 20, 0), new ElementTransition(0.1, $lin, enter: new TransitionMotion(y: 30), exit: new TransitionMotion(y: 30)));
          $boxes['fold'] = $c->transition($c->key('fold')->box(20, 20, 0), new ElementTransition(0.1, $lin, enter: new TransitionMotion(collapse: true)));
      }
    $c->end();
}, $now, 200, 200);
$t->rt->reduceMotion = true;
$show = true; $bg = 0xff0000; $t->frame(1);
later($t, $now, 50);
check($boxes['fade']->y == 50 && $boxes['fade']->opacity >= 0.4 && $boxes['fade']->opacity <= 0.6, "without motion, the box entering is at {$boxes['fade']->y}, {$boxes['fade']->opacity} opaque");
check($boxes['fold']->h == 20 && $boxes['fold']->opacity == 1, 'without motion, the box collapsing is there at once');
check(($boxes['color']->color >> 16) >= 100 && ($boxes['color']->color & 255) >= 100, sprintf('without motion, the colors change smoothly: #%06x', $boxes['color']->color));
later($t, $now, 1000);
$show = false; $t->frame(1);
later($t, $now, 50);
$g = ghostsOf($holder);
check(count($g) === 1 && $g[0]->y == 50 && $g[0]->opacity >= 0.4 && $g[0]->opacity <= 0.6, 'without motion, the copy leaving fades in place: ' . json_encode($g ? [$g[0]->y, $g[0]->opacity] : []));

// Coming in, a node moves with the layout at once where its transition does not animate.
$shown = false; $above = 20.0;
$t = clockTester(function (Context $c) use (&$shown, &$above, &$boxes) {
    $c->column(0, 0)->size(300, 200);
      $c->box(20, $above, 0);
      if ($shown) { $boxes['panel'] = $c->transition($c->key('panel')->box(40, 20, 0), new ElementTransition(0.1, colors: true, enter: new TransitionMotion(x: 8))); }
    $c->end();
}, $now, 300, 200);
$shown = true; $t->frame(1);
later($t, $now, 30);
$above = 50.0; $t->frame(1);
same(50.0, $boxes['panel']->y, 'on its way in, the panel goes down with the box above at once');
check($boxes['panel']->x > 0 && $boxes['panel']->x < 8, "on its way in, the panel slides in: {$boxes['panel']->x}");
later($t, $now, 1000);
same([0.0, 50.0], [$boxes['panel']->x, $boxes['panel']->y], 'in, the panel is in its place');

// Back as its copy goes, a node comes from where the copy was, and not past its place.
$shown = true; $above = 20.0;
$t = clockTester(function (Context $c) use (&$shown, &$above, &$boxes, $lin) {
    $c->column(0, 0)->size(300, 200);
      $c->box(20, $above, 0);
      if ($shown) { $boxes['panel'] = $c->transition($c->key('panel')->box(40, 20, 0), new ElementTransition(0.1, $lin, colors: true, exit: new TransitionMotion(x: 8))); }
    $c->end();
}, $now, 300, 200);
$shown = false; $t->frame(1);
later($t, $now, 50);
$shown = true; $above = 50.0; $t->frame(1);
same(20.0, $boxes['panel']->y, 'back, the panel starts where its copy was');
later($t, $now, 25);
check($boxes['panel']->y > 20 && $boxes['panel']->y < 50, "on its way back, the panel is between: {$boxes['panel']->y}");
later($t, $now, 1000);
same([0.0, 50.0], [$boxes['panel']->x, $boxes['panel']->y], 'back, the panel is in its place');

// Collapsed coming in, a node keeps opening as the layout makes it smaller.
$shown = false; $hh = 40.0;
$t = clockTester(function (Context $c) use (&$shown, &$hh, &$boxes, $lin) {
    $c->column(0, 0)->size(300, 200);
      if ($shown) { $boxes['row'] = $c->transition($c->key('row')->box(40, $hh, 0), new ElementTransition(0.1, $lin, colors: true, enter: new TransitionMotion(opacity: 1, collapse: true))); }
    $c->end();
}, $now, 300, 200);
$shown = true; $t->frame(1);
later($t, $now, 50);
same(20.0, $boxes['row']->h, 'halfway in, the row is half high');
$hh = 10.0;
foreach ([[0, 5.0], [25, 7.5]] as [$ms, $want]) { later($t, $now, $ms); closeFloat($want, $boxes['row']->h, (50 + $ms) . ' ms in, the row'); }
later($t, $now, 1000);
same(10.0, $boxes['row']->h, 'in, the row is its height');

// Animations disabled, nothing moves nor leaves.
$target = 0.0; $instant = false; $show = true; $value = 0.0;
$t = clockTester(function (Context $c) use (&$target, &$instant, &$show, &$value, &$boxes, &$holder) {
    if ($instant) { $c->disableAnimations(); }
    $value = $c->animateTo($c->box(1, 1, 0), 'x', $target, Animation::smooth());
    $holder = $c->column(0, 0)->size(100, 100);
      $boxes['moving'] = $c->transition($c->key('moving')->box(10, 10, 0)->at($target, 0), new ElementTransition());
      if ($show) { $c->transition($c->key('going')->box(10, 10, 0), new ElementTransition(exit: new TransitionMotion())); }
    $c->end();
}, $now, 200, 200);
$target = 50.0; $instant = true; $show = false; $t->frame(1);
check($value == 50 && !$t->rt->needsFrame, "without animations, the value is $value");
same(0, count(ghostsOf($holder)), 'without animations, no copy leaves');
same(50.0, $boxes['moving']->x, 'without animations, the box is in its place');
$instant = false; $target = 0.0;
later($t, $now, 10);
check($value != 0, 'with animations again, the value moves');

// A route change shows the colors of what comes at once (mygo's 1549ce9): a node coming back is not eased from the color of the one that held its place.
$color = 0x3478f6;
$t = clockTester(function (Context $c) use (&$color, $lin) {
    $c->transition($c->column()->size(100, 40)->background($color)->label('btn'), new ElementTransition(1.0, $lin, colors: true));
    $c->end();
}, $now, 200, 100);
$color = 0xeeeeee; later($t, $now, 100);
check($t->byLabel('btn')->color !== 0xeeeeee, 'a color change eases');
$t->rt->forgetColors(); // as a page comes
$color = 0x222222; later($t, $now, 16);
same(0x222222, $t->byLabel('btn')->color, 'after a route change: its color at once');

// Siblings leaving together keep their order (mygo's de135d4): C came first, A and B before it later; all three go, their copies A, B, C.
$rows = ['C'];
$t = clockTester(function (Context $c) use (&$rows, $lin) {
    $c->column()->label('list');
      foreach ($rows as $s) {
          $c->transition($c->key($s)->row()->size(0, 20), new ElementTransition(0.2, $lin, exit: new TransitionMotion(opacity: 0.0)));
            $c->text($s);
          $c->end();
      }
    $c->end();
}, $now, 200, 100);
$rows = ['A', 'B', 'C']; later($t, $now, 300);
$rows = []; later($t, $now, 16);
same(['A', 'B', 'C'], array_map(fn(Node $g) => $g->kids[0]->text, ghostsOf($t->byLabel('list'))), 'the copies going in the order the rows had');
