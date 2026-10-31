<?php
// Transforms (mygo's transform_test.go and painter_transform_test.go): what a
// node's transform does to its drawing, the pointer, the coordinates of its
// layout and the window, and the bounds assistive technology sees; in 2D and
// in 3D; clips nested in transforms, carets, input clients, selections,
// transitions and inline text through them.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessTree, Affine, Context, DisplayList, InputEvent, Painter, Role, Theme, Transform};

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
    for ($k = 0; $k < 8; $k++) { $v[] = $list->xforms[$i * DisplayList::FLOATS_PER_TRANSFORM + $k]; } // its map; its mask after it
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
    $c->box(200, 100, 0)->draw(function (Painter $p, array $r) {
        $p->transform(Transform::translation(50, 0), 0, 0, fn() => $p->fill([$r[0], $r[1], 20, 20], 0xdc141e));
        $p->transform(Transform::scaling(2, 2), $r[0] + 100, $r[1], fn() => $p->transform(Transform::rotation(90), $r[0] + 110, $r[1] + 10, fn() => $p->fill([$r[0] + 100, $r[1] + 5, 20, 10], 0x1e3cdc)));
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
    $c->box(100, 100, 0)->draw(function (Painter $p, array $r) {
        $list = $p->displayList();
        $list->transform(Transform::translation(-1000, 0), 0, 0, function () use ($list, $r) { if (!$list->clipped($r[0], $r[1], 10, 10)) { $list->rect($r[0], $r[1], 10, 10, 0xff0000); } });
        $list->transform(Transform::translation(1000, 0), 0, 0, function () use ($list, $r) { if (!$list->clipped($r[0] - 1000, $r[1], 10, 10)) { $list->rect($r[0] - 1000, $r[1], 10, 10, 0x00ff00); } });
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

// Clips nested in transforms: the turned, scaled interior takes the pointer; past the inner clip, nothing does (mygo's TestTransformedNestedClips).
$clicks = 0; $child = null;
$t = new Headless(function (Context $c) use (&$clicks, &$child, &$c0) {
    $c0 = $c;
    $c->column()->absolute()->left(50)->top(50)->size(80, 60)->rotate(30)->radius(12)->clip();
      $c->column()->size(50, 50)->clip()->scale(1.2, 0.8);
        $child = $c->box(100, 80, 0xe60000)->shrink(0);
        if ($c->clicked($child)) { $clicks++; }
      $c->end();
    $c->end();
}, 220, 200);
$t->frame();
[$x, $y] = $c0->localToWindow($child, 20, 20);
$t->click($x, $y);
same(1, $clicks, 'the clipped interior takes the click');
[$x, $y] = $c0->localToWindow($child, 75, 20);
$t->click($x, $y);
same(1, $clicks, 'past the nested clip, no click');

// A transformed input: its caret as it shows, what assistive technology sees, a popover below it as it shows, not turned (mygo's TestTransformCaretAccessibilityAndAnchor).
$value = new \MyPHP\UI\TextState('hello'); $open = true; $input = null; $panel = null;
$t = new Headless(function (Context $c) use ($value, &$open, &$input, &$panel, &$c0) {
    $c0 = $c;
    $c->column()->absolute()->left(80)->top(50)->size(130, 60)->scale(1.5, 1.2)->rotate(20);
      $input = $c->input($value, '', 100)->autoFocus()->label('transformed input');
    $c->end();
    $panel = $c->popover($input, $open, fn() => $c->text('anchored'));
}, 500, 300);
$t->frame(); $t->paint(); $t->frame(1);
$world = $t->rt->worldOf($input->id);
check($world->set, 'the input is transformed');
$caret = $t->input->caretRect(5);
[$fx, $fy, $fh] = $t->input->focusText;
$want = $world->bounds($fx + $value->offsetX + 5 * 7.0, $fy, 1.0, $fh);
check(abs($caret[0] - $want[0]) < 0.01 && abs($caret[1] - $want[1]) < 0.01 && abs($caret[2] - $want[2]) < 0.01 && abs($caret[3] - $want[3]) < 0.01, 'the caret the window gets shows through the transform: ' . json_encode([$caret, $want]));
$t->input->push(['accessOn']); $t->frame();
$n = null; foreach ($t->rt->accessTree->nodes as $an) { if ($an->role === Role::TEXT_FIELD && $an->label === 'transformed input') { $n = $an; } }
$b = $c0->bounds($input);
check($n !== null && abs($n->bounds[0] - $b[0]) < 0.01 && abs($n->bounds[1] - $b[1]) < 0.01 && abs($n->bounds[2] - $b[2]) < 0.01, 'assistive technology sees it as it shows');
$p = $t->rect($panel);
check($p[1] >= $b[1] + $b[3] - 0.01, 'the popover is below the input as it shows: ' . json_encode([$p, $b]));
check(!$t->rt->worldOf($panel->id)->set, 'and does not take its transform');

// A text input client of the app's own, transformed: the rectangles the window gets and the index at a point go through it; collapsed, nothing is hit (mygo's TestTransformedTextInputClientGeometry).
require_once __DIR__ . '/PrimitiveClient.php';
$client = new PrimitiveClient('hello', new \MyPHP\UI\TextInputSelection(new \MyPHP\UI\TextInputRange(3, 3)));
$xScale = 1.5; $e = null;
$t = new Headless(function (Context $c) use ($client, &$xScale, &$e, &$c0) {
    $c0 = $c;
    $e = $c->box(100, 40, 0)->absolute()->left(80)->top(60)->transformOrigin(0, 0)->scale($xScale, 1.2)->rotate(30)->focusable()->autoFocus()->handleTextInput($client);
}, 400, 250);
$t->frame(); $t->frame(1);
foreach ([[3, 3], [1, 4]] as [$a, $z]) {
    $r = new \MyPHP\UI\TextInputRange($a, $z);
    [$local] = $client->boundsForRange($r);
    $want = $t->rt->worldOf($e->id)->bounds($e->x + $local[0], $e->y + $local[1], $local[2], $local[3]);
    [$got, $used] = $t->input->clientRect($r);
    check($used == $r && abs($got[0] - $want[0]) < 0.01 && abs($got[1] - $want[1]) < 0.01 && abs($got[2] - $want[2]) < 0.01 && abs($got[3] - $want[3]) < 0.01, "the client's rectangle of $a..$z, transformed: " . json_encode([$got, $want]));
}
[$x, $y] = $c0->localToWindow($e, 18, 15);
same(4, $t->input->clientIndex($x, $y), 'the index at a point, through the transform');
$xScale = 0; $t->frame();
same(null, $t->input->clientIndex($x, $y), 'collapsed, nothing is hit');

// A selection dragged across the texts of a turned column copies what it went over (mygo's TestTransformedContainerTextSelection).
$first = null; $second = null;
$t = new Headless(function (Context $c) use (&$first, &$second, &$c0) {
    $c0 = $c;
    $c->column()->absolute()->left(100)->top(70)->width(160)->rotate(30)->selectable();
      $first = $c->text('Alpha beta');
      $second = $c->text('Gamma delta');
    $c->end();
}, 400, 250);
$t->frame();
[$x, $y] = $c0->localToWindow($first, 2 * 7.0, 8);
$t->press($x, $y);
[$x, $y] = $c0->localToWindow($second, 4 * 7.0, 8);
$t->drag($x, $y); $t->release($x, $y);
$t->shortcut('c', \MyPHP\UI\Key::COMMAND);
same("pha beta\nGamm", ($t->rt->readClipboard)(), 'the turned selection copies what it went over');

// A node taking text of its own, transformed: its caret as it shows, the composition and the text it hears; a press on it captures the pointer, in its layout (mygo's TestTransformedCustomCaretAndCapture).
$got = []; $e = null;
$t = new Headless(function (Context $c) use (&$got, &$e, &$c0) {
    $c0 = $c;
    $e = $c->box(50, 40, 0)->absolute()->left(60)->top(70)->scale(2, 1)->rotate(-25)->focusable()->autoFocus()->textCaret(10, 5, 1, 16)
        ->handleInput(function (InputEvent $ev) use (&$got) { $got[] = $ev; return $ev->kind !== InputEvent::KEY_DOWN; }); // the keys it leaves type their text
}, 300, 220);
$t->frame(); $t->frame(1);
$caret = $t->input->caretRect(0);
$want = $t->rt->worldOf($e->id)->bounds($e->x + 10, $e->y + 5, 1, 16);
check(abs($caret[0] - $want[0]) < 0.01 && abs($caret[2] - $want[2]) < 0.01, 'its caret shows through its transform: ' . json_encode([$caret, $want]));
$t->compose('にほ', 2);
$ev = end($got);
check($ev->kind === InputEvent::COMPOSE && $ev->text === 'にほ' && $ev->caret === 2, 'it hears the composition, its caret in characters');
$t->type('日本');
$ev = end($got);
check($ev->kind === InputEvent::TEXT && $ev->text === '日本' && $t->input->composing === '', 'and the text committed');
[$x, $y] = $c0->localToWindow($e, 10, 10);
$t->input->mouse($x, $y); $t->input->push(['down', $x, $y, 0, 1, 0]);
[$x, $y] = $c0->localToWindow($e, 70, 50);
$t->input->mouse($x, $y); $t->input->push(['drag', $x, $y]);
$ev = end($got);
check($ev->kind === InputEvent::POINTER_MOVE && abs($ev->x - 70) < 0.01 && abs($ev->y - 50) < 0.01, 'captured, the moves come in its layout, out of it too');
$t->input->push(['up', $x, $y, 0, 1, 0]);
same(InputEvent::POINTER_UP, end($got)->kind, 'and the release');

// A node moving in a transition, turned: halfway, its layout is halfway, its turned shape there (mygo's TestTransformUpdatesDamageAndTransitionGeometry).
$now = 0; $bx = 0.0; $angle = 0.0; $e = null;
$t = poseTester(function (Context $c) use (&$bx, &$angle, &$e, &$c0) {
    $c0 = $c;
    $e = $c->box(50, 30, 0xf00000)->absolute()->left($bx)->top(40)->rotate($angle);
    $c->transition($e, new \MyPHP\UI\ElementTransition(1.0, \MyPHP\UI\Easing::linear(...)));
}, $now, 250, 160);
[$bx, $angle] = [100.0, 45.0]; $t->frame(1);
$now += 500_000_000; $t->frame(1);
closeFloat(50, $c0->layoutBounds($e)[0], 'halfway, its layout halfway');
check($t->rt->worldOf($e->id)->set, 'turned on the way');

// Inline text turned and moved takes the pointer where it shows (mygo's TestInlineTransformMovesGlyphsAndInteraction).
$link = null; $clicks = 0;
$t = new Headless(function (Context $c) use (&$link, &$clicks, &$c0) {
    $c0 = $c;
    $c->text('Before ')->absolute()->left(30)->top(30)->fontSize(22)->children(function () use ($c, &$link, &$clicks): void {
        $link = $c->text('LINK')->textColor(0xe60000)->underline()->translate(50, 30)->rotate(8);
        if ($c->clicked($link)) { $clicks++; }
    });
}, 300, 180);
$t->frame();
$r = $c0->layoutBounds($link);
[$x, $y] = $c0->localToWindow($link, $r[2] / 2, $r[3] / 2);
$t->click($x, $y);
same(1, $clicks, 'the transformed inline text takes the click where it shows');

// A clip mask (Node::clipMask, ASS's \clip and \iclip): what is drawn in the node and inside it goes through a transform entry of its own,
// the mask after its map: where the box lies in the window (its width below 0 inverted), its image's slot (none: the box itself);
// what is drawn after it, through the entry before.
$t = new Headless(function (Context $c): void {
    $c->column()->absolute()->left(20)->top(10)->size(100, 50)->clipMask(null, true);
      $c->box(30, 30, 0, 0xff0000);
    $c->end();
    $c->box(5, 5, 0, 0x00ff00);
}, 200, 100);
$t->frame();
$list = $t->paint();
$by = []; foreach (quadsOf($list) as $q) { $by[(int)$q[2]] = $q[6]; } // by width: the red box 30, the green 5
[$masked, $after] = [$by[30], $by[5]];
$m = []; for ($k = 8; $k < 16; $k++) { $m[] = $list->xforms[$masked * DisplayList::FLOATS_PER_TRANSFORM + $k]; }
same([[20.0, 10.0, -100.0, 50.0, 0.0, 0.0, 0.0, 0.0], 0], [$m, $after], 'the box inverted, no image; what follows it unmasked');
// A span laid out but not drawn (Span::$ghost): no glyphs of it, its room kept.
$t = new Headless(function (Context $c): void {
    $c->richText([new \MyPHP\UI\Span('ab'), new \MyPHP\UI\Span('cd', ghost: true), new \MyPHP\UI\Span('e')]);
}, 200, 100);
$t->frame();
$glyphs = array_filter(quadsOf($t->paint()), fn(array $q) => abs($q[5] - 1.0) < 1e-6);
same(3, count($glyphs), 'the ghost\'s two glyphs not drawn, the others\' three');
check(max(array_map(fn(array $q) => $q[0], $glyphs)) > min(array_map(fn(array $q) => $q[0], $glyphs)) + 3 * 5, 'the last after the ghost\'s room');
