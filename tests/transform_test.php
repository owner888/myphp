<?php
// Transforms (mygo's transform_test.go and painter_transform_test.go): what a
// node's transform does to its drawing, the pointer, the coordinates of its
// layout and the window, and the bounds assistive technology sees; in 2D and
// in 3D.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessTree, Affine, Context, DisplayList, InputEvent, Node, Role, Theme, Transform};

/** The quads of $list: x, y, w, h, alpha, kind, and the transform entry each is drawn through. @return list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: int}> */
function quadsOf(DisplayList $list): array
{
    $q = [];
    for ($i = 0; $i < $list->n; $i++) {
        $b = $list->buf; $k = $i * DisplayList::FLOATS_PER_RECT;
        $entry = (int)floor($b[$k + 9] / DisplayList::KIND_STRIDE);
        $q[] = [$b[$k], $b[$k + 1], $b[$k + 2], $b[$k + 3], $b[$k + 7], $b[$k + 9] - DisplayList::KIND_STRIDE * $entry, $entry];
    }
    return $q;
}

/** Entry $i of the transforms of $list, as an Affine's a b c d x y g h. @return list<float> */
function entryOf(DisplayList $list, int $i): array
{
    $v = [];
    for ($k = 0; $k < DisplayList::FLOATS_PER_TRANSFORM; $k++) { $v[] = $list->xforms[$i * DisplayList::FLOATS_PER_TRANSFORM + $k]; }
    return $v;
}

/** The point of a quad drawn through entry $e of $list. @return array{0: float, 1: float} */
function through(DisplayList $list, int $e, float $x, float $y): array
{
    [$a, $b, $c, $d, $x0, $y0, $g, $h] = entryOf($list, $e);
    $w = $g * $x + $h * $y + 1;
    return [($a * $x + $c * $y + $x0) / $w, ($b * $x + $d * $y + $y0) / $w];
}

// A rotated box draws turned, takes the pointer within its turned shape, and gives its input local coordinates.
$clicks = 0; $events = []; $box = null; $c0 = null;
$t = new Headless(function (Context $c) use (&$clicks, &$events, &$box, &$c0) {
    $c0 = $c;
    $box = $c->key('target')->box(80, 30, 0xdc141e)->at(60, 50)->rotate(45);
    if ($c->clicked($box)) { $clicks++; }
    $box->handleInput(function (InputEvent $ev) use (&$events) { $events[] = $ev; return false; });
}, 220, 180);
$t->frame();
[$x, $y] = $c0->localToWindow($box, 15, 10);
$list = $t->paint();
$mine = array_values(array_filter(quadsOf($list), fn($q) => $q[2] == 80 && $q[3] == 30));
check(count($mine) === 1 && $mine[0][6] > 0, 'the rotated box is drawn through a transform');
[$px, $py] = through($list, $mine[0][6], 60 + 15, 50 + 10);
closeFloat($x, $px, 'drawn, the point the box maps to, x'); closeFloat($y, $py, 'drawn, the point the box maps to, y');
$t->click($x, $y);
same(1, $clicks, 'a press on the turned box clicks it');
check($events !== [], 'the turned box hears the pointer');
closeFloat(15, $events[0]->x, 'its input, x in its layout'); closeFloat(10, $events[0]->y, 'its input, y in its layout');
[$bx, $by] = $c0->bounds($box);
$t->click($bx + 1, $by + 1);
same(1, $clicks, 'the bounds outside the turned shape take no click');
[$lx, $ly] = $c0->windowToLocal($box, $x, $y);
closeFloat(15, $lx, 'back in its layout, x'); closeFloat(10, $ly, 'back in its layout, y');
same([60.0, 50.0, 80.0, 30.0], $c0->layoutBounds($box), 'the transform leaves the layout');

// Nested transforms compose, and reflect.
$child = null; $clicks = 0;
$t = new Headless(function (Context $c) use (&$child, &$clicks, &$c0) {
    $c0 = $c;
    $c->column(0, 0)->size(80, 80)->at(40, 30)->transformOrigin(0, 0)->scale(2, 2)->translate(10, 5);
      $child = $c->box(20, 10, 0x0a78dc)->at(10, 15)->transformOrigin(0, 0)->scale(-1, 1)->rotate(90);
      if ($c->clicked($child)) { $clicks++; }
    $c->end();
}, 250, 220);
$t->frame();
// The child's (5, 4) reflects to (-5, 4), turns to (-4, -5), then the parent's scale and move take (46, 40) to (62, 55).
[$x, $y] = $c0->localToWindow($child, 5, 4);
closeFloat(62, $x, 'nested, x'); closeFloat(55, $y, 'nested, y');
$t->click($x, $y);
same(1, $clicks, 'a nested, reflected transform takes the click');

// A singular transform shows nothing and takes no pointer.
$e = null; $clicks = 0;
$t = new Headless(function (Context $c) use (&$e, &$clicks, &$c0) {
    $c0 = $c;
    $e = $c->box(100, 50, 0xff0000)->scale(0, 1);
    if ($c->clicked($e)) { $clicks++; }
}, 200, 100);
$t->click(50, 25);
same(0, $clicks, 'a collapsed transform takes no click');
check($c0->windowToLocal($e, 50, 25) === null, 'a singular transform has no inverse');
$q = array_values(array_filter(quadsOf($t->paint()), fn($q) => $q[2] == 100 && $q[3] == 50));
check($q === [] || entryOf($t->paint(), $q[0][6])[0] == 0, 'a collapsed transform draws nothing wide');

// In 3D: a card turned in perspective is narrower, its far edge shorter; turned over, its back shows and takes the pointer.
$turn = 40.0; $card = $back = null; $clicks = 0;
$t = new Headless(function (Context $c) use (&$turn, &$card, &$back, &$clicks, &$c0) {
    $c0 = $c;
    $c->column(0, 0)->size(400, 300);
      $card = $c->key('card')->box(200, 100, 0xff0000)->at(100, 100)->rotateY($turn)->perspective(400)->backfaceHidden();
      $back = $c->key('back')->box(200, 100, 0x0000ff)->at(100, 100)->rotateY($turn + 180)->perspective(400)->backfaceHidden();
      if ($c->clicked($card)) { $clicks++; }
    $c->end();
}, 400, 300);
$t->frame();
[, , $bw, $bh] = $c0->bounds($card);
check($bw < 200 && $bw >= 100 && $bh > 100, "turned, the card shows within $bw × $bh");
[$lx, $ly] = $c0->windowToLocal($card, ...$c0->localToWindow($card, 150, 60));
check(abs($lx - 150) < 1e-2 && abs($ly - 60) < 1e-2, "there and back, $lx, $ly");
[, $y0] = $c0->localToWindow($card, 0, 0); [, $y1] = $c0->localToWindow($card, 200, 0);
check($y1 > $y0, "the far edge is shorter: tops at $y0 and $y1");
$colors = fn() => array_map(fn($q) => $q[2] . 'x' . $q[3], array_filter(quadsOf($t->paint()), fn($q) => $q[2] == 200 && $q[3] == 100));
same(1, count($colors()), 'the front shows, the back turned away does not');
[$x, $y] = $c0->localToWindow($card, 190, 50);
$t->click($x, $y);
same(1, $clicks, 'a click on the far side of the front');
$turn = 140.0; $t->frame();
same(1, count($colors()), 'turned over, one face shows');
[$x, $y] = $c0->localToWindow($back, 100, 50);
$t->click($x, $y);
same(1, $clicks, 'turned away, the front takes no click');

// Skew and perspective.
[$x, $y] = Transform::skewing(45, 0)->flat()->point(0, 10);
check(abs($x - 10) < 1e-4 && $y == 10, "skewed 45 degrees, (0, 10) goes to $x, $y");
$f = Transform::rotationY(60)->flat();
check(!$f->perspective() && abs($f->a - 0.5) < 1e-6, 'turned flat, it only looks narrower');
check(Transform::rotationX(30)->then(Transform::perspective(500))->then(Transform::translation(5, 5))->flat()->perspective(), 'in perspective, the map is projective');
$id = Transform::rotationY(25)->then(Transform::rotationY(-25))->flat();
check(!$id->set || (abs($id->a - 1) < 1e-5 && abs($id->g) < 1e-6), 'turned there and back, nothing');
check(Transform::rotationY(120)->facesAway() && !Transform::rotationY(60)->facesAway() && !Transform::scaling(-1, 1)->facesAway() && Transform::rotationX(-100)->then(Transform::perspective(300))->facesAway(), 'the faces turned away are those past 90 degrees');
foreach ([fn() => Transform::perspective(0), fn() => Transform::skewing(NAN, 0)] as $i => $f) {
    $threw = false; try { $f(); } catch (\InvalidArgumentException) { $threw = true; }
    check($threw, "invalid transform $i accepted");
}
// Affine maps compose and invert.
$m = Affine::translation(10, 5)->mul(Affine::rotation(90))->mul(Affine::scaling(2, 3));
[$x, $y] = $m->inverse()->point(...$m->point(7, -2));
check(abs($x - 7) < 1e-9 && abs($y + 2) < 1e-9, "there and back through an affine map: $x, $y");
check(Affine::scaling(0, 1)->inverse() === null, 'a singular map has no inverse');
same([-10.0, 0.0, 10.0, 20.0], Affine::rotation(90)->bounds(0, 0, 20, 10), 'turned a quarter, the bounds of a 20 × 10 box');

// A draw closure's transform, around a point, nested.
$t = new Headless(function (Context $c) {
    $c->box(200, 100, 0)->draw(function (DisplayList $list, Node $n) {
        $list->transform(Transform::translation(50, 0), 0, 0, fn() => $list->rect($n->x, $n->y, 20, 20, 0xdc141e));
        $list->transform(Transform::scaling(2, 2), $n->x + 100, $n->y, fn() => $list->transform(Transform::rotation(90), $n->x + 110, $n->y + 10, fn() => $list->rect($n->x + 100, $n->y + 5, 20, 10, 0x1e3cdc)));
    });
}, 200, 100);
$list = $t->paint();
$red = array_values(array_filter(quadsOf($list), fn($q) => $q[2] == 20 && $q[3] == 20));
[$x, $y] = through($list, $red[0][6], 0, 0);
closeFloat(50, $x, 'translated square, x'); closeFloat(0, $y, 'translated square, y');
$blue = array_values(array_filter(quadsOf($list), fn($q) => $q[2] == 20 && $q[3] == 10));
// The 20 × 10 bar turned upright around (110, 10) covers 105..115 by 0..20, then twice as large around (100, 0): 110..130 by 0..40.
$corners = [through($list, $blue[0][6], 100, 5), through($list, $blue[0][6], 120, 15)];
closeFloat(110, min($corners[0][0], $corners[1][0]), 'the bar, its left'); closeFloat(130, max($corners[0][0], $corners[1][0]), 'the bar, its right');
closeFloat(0, min($corners[0][1], $corners[1][1]), 'the bar, its top'); closeFloat(40, max($corners[0][1], $corners[1][1]), 'the bar, its bottom');
// One moved out of view is culled; one moved into view is drawn.
$t = new Headless(function (Context $c) {
    $c->box(100, 100, 0)->draw(function (DisplayList $list, Node $n) {
        $list->transform(Transform::translation(-1000, 0), 0, 0, function () use ($list, $n) { if (!$list->clipped($n->x, $n->y, 10, 10)) { $list->rect($n->x, $n->y, 10, 10, 0xff0000); } });
        $list->transform(Transform::translation(1000, 0), 0, 0, function () use ($list, $n) { if (!$list->clipped($n->x - 1000, $n->y, 10, 10)) { $list->rect($n->x - 1000, $n->y, 10, 10, 0x00ff00); } });
    });
}, 100, 100);
same(1, count(array_filter(quadsOf($t->paint()), fn($q) => $q[2] == 10)), 'culled through the transform: the one in view is drawn');

// Opacity: the node and its children at it, times their own.
$t = new Headless(function (Context $c) {
    $c->column(0, 0, 0, 0x202020)->size(100, 100)->opacity(0.5);
      $c->box(20, 20, 0xff0000)->opacity(0.5);
      $c->box(30, 30, 0x00ff00);
    $c->end();
}, 200, 200);
$q = quadsOf($t->paint());
$alpha = fn(float $w) => array_values(array_filter($q, fn($r) => $r[2] == $w))[0][4];
closeFloat(0.5, $alpha(100), 'the column at its opacity'); closeFloat(0.25, $alpha(20), 'a child at its own times the column'); closeFloat(0.5, $alpha(30), 'a child at the column');
$t = new Headless(fn(Context $c) => $c->box(40, 40, 0xff0000)->opacity(0), 100, 100);
same(0, count(array_filter(quadsOf($t->paint()), fn($r) => $r[2] == 40)), 'a transparent node draws nothing');

// Assistive technology sees a turned node within its bounds as it shows.
$tree = null;
$t = new Headless(function (Context $c) use (&$box, &$c0) {
    $c0 = $c;
    $box = $c->button('Turned')->at(80, 50)->scale(1.5, 1.2)->rotate(20);
}, 500, 300);
$t->rt->onAccess = function (AccessTree $tr) use (&$tree) { $tree = $tr; };
$t->input->push(['accessOn']); $t->frame(3);
$n = null; foreach ($tree->nodes as $an) { if ($an->role === Role::BUTTON && $an->label === 'Turned') { $n = $an; } }
$b = $c0->bounds($box);
check($n !== null && abs($n->bounds[0] - $b[0]) < 0.01 && abs($n->bounds[2] - $b[2]) < 0.01 && $b[2] > $c0->layoutBounds($box)[2], 'the button turned, its bounds as it shows: ' . json_encode([$n?->bounds, $b]));

// A view that is transformed and still asks for no frames.
$t = new Headless(fn(Context $c) => $c->box(80, 40, 0x145078)->rotate(10)->translate(50, 30), 200, 120);
$t->frame();
check(!$t->rt->needsFrame, 'static transformed content asks for no frames');

// An overlay moved away by its transform blocks the pointer where it shows, not where the layout put it.
$hits = 0;
$t = new Headless(function (Context $c) use (&$hits) {
    if ($c->clicked($c->button('Under'))) { $hits++; }
    $c->column()->at(0, 0)->size(300, 100)->translate(0, 200);
    $c->end();
}, 300, 400);
$t->frame();
$t->clickText('Under');
same(1, $hits, 'an overlay moved away by its transform lets the pointer through where it no longer is');
