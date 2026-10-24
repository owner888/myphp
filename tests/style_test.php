<?php
// Element styles and flags (mygo's style_test.go TestInvisible,
// TestClipOneWay, TestBorderWidths, TestPaints, TestScrollbarInsets,
// TestDebugAndCursors, shadow_test.go TestInsetShadow): what the painter
// lists for corners, gradients, stripes, dashed borders, shadows inside
// and out, dividers and debug outlines; invisible, pass-through and
// disabled nodes; cursors; the scroll bars' insets; grid tracks.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{BorderStyle, Context, Cursor, DisplayList, LinearGradient, Node, Scrollbar, Track};

/** The quads of a frame: [x, y, w, h, r, g, b, a, radius, kind, u, v, clip x, y, w, h] each, a box's and a box shadow's with the slot after as 'next'. @return list<array> */
function styleQuads(Headless $h): array
{
    $list = $h->paint();
    $out = [];
    for ($i = 0; $i < $list->n; $i++) {
        $q = [];
        for ($j = 0; $j < 16; $j++) { $q[] = $list->buf[$i * 16 + $j]; }
        $q[9] = fmod($q[9], DisplayList::KIND_STRIDE);
        if ($q[9] == 14.0 || $q[9] == 15.0) {
            $next = [];
            for ($j = 0; $j < 16; $j++) { $next[] = $list->buf[($i + 1) * 16 + $j]; }
            $q['next'] = $next;
            $i++;
        }
        $out[] = $q;
    }
    return $out;
}

/** The quads of kind $kind. @return list<array> */
function styleKind(Headless $h, int $kind): array
{
    return array_values(array_filter(styleQuads($h), fn(array $q): bool => (int)$q[9] === $kind));
}

function styleFind(?Node $n, string $label): ?Node
{
    if ($n === null) { return null; }
    if ($n->label === $label) { return $n; }
    foreach ($n->kids as $k) { if (($f = styleFind($k, $label)) !== null) { return $f; } }
    return null;
}

// Invisible: the node keeps its room, paints nothing, takes no click.
$clicked = false;
$h = new Headless(function (Context $c) use (&$clicked): void {
    $c->row();
      $b = $c->button('hidden')->size(40, 20)->invisible();
      if ($c->clicked($b)) { $clicked = true; }
      $c->box(10, 10, 0x00ff00)->label('after');
    $c->end();
}, 100, 100);
$h->frame();
same(40.0, styleFind($h->root, 'after')->x, 'an invisible node keeps its room');
$h->click(10, 10); $h->frame();
check(!$clicked, 'an invisible button takes no click');
check(array_filter(styleQuads($h), fn(array $q): bool => $q[0] < 40 && $q[9] == 1.0) === [], 'an invisible node paints no text');

// ClipX: the children cut at the left and right alone.
$h = new Headless(function (Context $c): void {
    $c->column()->size(50, 50)->margin(20)->clipX();
      $c->box(100, 100, 0xff0000)->margin(-10, 0, 0, -10);
    $c->end();
}, 150, 150);
$h->frame();
$red = array_values(array_filter(styleQuads($h), fn(array $q): bool => $q[4] == 1.0 && $q[5] == 0.0));
same([20.0, 0.0, 50.0, 150.0], array_slice($red[0], 12, 4), 'clipX cuts left and right only: down, the window\'s');

// Corners each their own, a gradient at an angle, stripes over a color.
$h = new Headless(function (Context $c): void {
    $c->row(0, 8);
      $c->column(0, 0, 0, 0x336699)->size(40, 40)->radius(1, 2, 3, 4);
      $c->column()->size(40, 20)->gradient(0xff0000, 0x0000ff, 90);
      $c->column(0, 0, 0, 0x808080)->size(40, 40)->stripes(0xffffff, 2, 4, 45, 0.5);
      $c->column()->size(40, 20)->linearGradient(new LinearGradient(0xff0000, 1.0, 0x00ff00, 1.0, 180, oklab: true));
    $c->end();
}, 300, 100);
$h->frame();
$boxes = styleKind($h, 14);
same(4, count($boxes), 'four boxes');
same([1.0, 2.0, 3.0, 4.0], array_slice($boxes[0]['next'], 0, 4), 'the corners in order: top left, top right, bottom right, bottom left');
same([0.0, 10.0, 40.0, 10.0], array_slice($boxes[1]['next'], 12, 4), 'a gradient at 90°: from the left edge to the right');
same(1.0, $boxes[1]['next'][11], 'mixed in sRGB');
same([0x0000ff, 1.0], [(int)$boxes[1][10], $boxes[1][11]], 'to blue');
same(3.0, $boxes[2]['next'][11], 'stripes');
check(abs($boxes[2]['next'][12] - M_SQRT1_2) < 1e-6 && $boxes[2]['next'][14] == 2.0 && $boxes[2]['next'][15] == 6.0, 'at 45°, 2 points wide every 6');
same([0x808080, 0.5], [(int)$boxes[2][10], $boxes[2][7]], 'over the gray, at half the alpha');
same(2.0, $boxes[3]['next'][11], 'an Oklab gradient');

// Corners too round for their side are scaled down together, as CSS's.
same([10.0, 10.0, 10.0, 10.0], DisplayList::fitRadii(20, 20, [20, 20, 20, 20]), 'radii fitted');

// Borders: dashed, and with corners each their own, in one box.
$h = new Headless(function (Context $c): void {
    $c->column()->size(60, 40)->borderWidth(2)->borderColor(0xff0000)->borderStyle(BorderStyle::Dashed);
    $c->column()->size(60, 40)->borderWidth(1, 2, 3, 4)->borderColor(0x00ff00)->radius(0, 8, 0, 8);
}, 200, 200);
$h->frame();
$boxes = styleKind($h, 14);
same(2, count($boxes), 'two bordered boxes');
same([2.0, 2.0, 2.0, 2.0], array_slice($boxes[0]['next'], 4, 4), 'the border widths');
same([0xff0000, 1.0, 4.0], [(int)$boxes[0]['next'][8], $boxes[0]['next'][10], $boxes[0]['next'][11]], 'in red, dashed');
same(0.0, $boxes[0][7], 'no fill under the border');
same([1.0, 2.0, 3.0, 4.0, 0.0], [...array_slice($boxes[1]['next'], 4, 4), $boxes[1]['next'][11]], 'a side each, solid');

// Shadows: outside, cut out under the box, inset inside its padding edge with the hole offset and shrunk by the spread.
$h = new Headless(function (Context $c): void {
    $c->column(0, 0, 0, 0xffffff)->size(100, 50)->margin(30)->round(8)->shadow(2, 4, 10, 3, 0x000000, 0.5)->insetShadow(1, 2, 4, 5, 0x112233)->borderWidth(1);
}, 200, 200);
$h->frame();
$sh = styleKind($h, 15);
same(2, count($sh), 'an outer shadow and an inner one');
[$out, $in] = $sh;
same([32.0 - 3 - 15, 34.0 - 3 - 15, 106.0 + 30, 56.0 + 30], array_slice($out, 0, 4), 'the outer: offset, grown by the spread and taking in three sigmas');
same([5.0, 0.0], [$out[10], $out[11]], 'sigma half the blur, outside');
same([11.0, 11.0, 11.0, 11.0], array_slice($out['next'], 0, 4), 'its corners grown by the spread');
same([30.0, 30.0, 100.0, 50.0], array_slice($out['next'], 4, 4), 'cut out under the box');
same([31.0, 31.0, 98.0, 48.0], array_slice($in, 0, 4), 'the inner: the padding box');
same([2.0, 1.0], [$in[10], $in[11]], 'sigma half the blur, inside');
same([31.0 + 1 + 5, 31.0 + 2 + 5, 88.0, 38.0], array_slice($in['next'], 4, 4), 'the hole offset and shrunk by the spread');
same([7.0, 7.0, 7.0, 7.0], array_slice($in['next'], 0, 4), 'the padding box\'s corners inside the border');
same([2.0, 2.0, 2.0, 2.0], [$in['next'][8], $in['next'][10], $in['next'][11], $in['next'][12]], 'the hole\'s corners less the spread');
$kinds = array_map(fn(array $q): float => $q[9], styleQuads($h));
check(array_search(15.0, $kinds) < array_search(14.0, $kinds) || !in_array(14.0, $kinds, true), 'the outer shadow under the box');

// Dividers: between each two children, in the middle of the gap, across the row inside its border.
$h = new Headless(function (Context $c): void {
    $c->row(0, 10)->size(0, 30)->dividers(2, 0x00ff00)->borderWidth(1);
      $c->box(20, 10, 0); $c->box(20, 10, 0); $c->box(20, 10, 0);
    $c->end();
}, 200, 100);
$h->frame();
$green = array_values(array_filter(styleQuads($h), fn(array $q): bool => $q[9] == 0.0 && $q[5] == 1.0 && $q[4] == 0.0));
same([[25.0, 1.0, 2.0, 28.0], [55.0, 1.0, 2.0, 28.0]], array_map(fn(array $q): array => array_slice($q, 0, 4), $green), 'two lines in the gaps');

// Debug: the node and those inside it outlined.
$h = new Headless(function (Context $c): void {
    $c->column(4)->margin(2)->debug();
      $c->box(10, 10, 0);
    $c->end();
}, 100, 100);
$h->frame();
$rings = array_values(array_filter(styleQuads($h), fn(array $q): bool => $q[9] == 4.0));
same(4, count($rings), 'a margin, padding and box outline, and the child\'s box');

// Cursors: the node's own while the pointer is over it.
$h = new Headless(function (Context $c): void {
    $c->box(50, 50, 0)->cursor(Cursor::Grab);
}, 100, 100);
$h->frame(); $h->hover(10, 10); $h->frame();
same(Cursor::Grab, $h->rt->cursor, 'a grabbing hand');
same('nwse-resize', Cursor::ResizeNWSE->value, 'named as CSS names it');

// Pass-through: an overlay the pointer goes through to the button under it.
$clicked = false;
$h = new Headless(function (Context $c) use (&$clicked): void {
    $b = $c->button('Go')->size(60, 30);
    if ($c->clicked($b)) { $clicked = true; }
    $c->column(0, 0, 0, 0x000000)->at(0, 0)->size(100, 100)->passThrough();
    $c->end();
}, 200, 200);
$h->frame(); $h->click(10, 10); $h->frame();
check($clicked, 'the click reaches the button under a pass-through overlay');

// Disabled: what is inside a disabled container takes no click and no focus, and the container paints at half its opacity.
$clicked = false; $inside = null;
$h = new Headless(function (Context $c) use (&$clicked, &$inside): void {
    $c->column(0, 0, 0, 0xff0000)->disabled();
      $b = $c->button('Go')->size(60, 30);
      $inside = $b->isDisabled();
      if ($c->clicked($b)) { $clicked = true; }
    $c->end();
}, 200, 200);
$h->frame(); $h->click(10, 10); $h->frame();
check(!$clicked && $inside === true, 'a button in a disabled container is disabled');
$h->tab(); $h->frame();
same(null, $h->rt->focus, 'and takes no focus');
$red = array_values(array_filter(styleQuads($h), fn(array $q): bool => $q[4] == 1.0 && $q[5] == 0.0 && $q[9] == 0.0));
same(0.5, $red[0][7], 'the container at half its opacity');

// Focus ring off: the node rings itself.
$h = new Headless(function (Context $c): void {
    $c->button('Go')->focusRing(false);
}, 200, 200);
$h->frame(); $h->tab(); $h->frame();
check(!$h->root->kids[0]->focusVisible, 'no ring from the painter');

// Scroll bars kept in from the edges.
same([10.0, 64.0, 80.0, 30.0], Scrollbar::track([0.0, 0.0, 100.0, 100.0], [64.0, 10.0, 6.0, 10.0]), 'the track inside the insets');

// Grid tracks.
$h = new Headless(function (Context $c): void {
    $c->row()->size(300, 0)->columnTracks(Track::fixed(100), Track::fr())->label('grid');
      $c->box(0, 10, 0)->label('a'); $c->box(0, 10, 0)->label('b');
    $c->end();
}, 300, 100);
$h->frame();
same([100.0, 200.0], [styleFind($h->root, 'a')->w, styleFind($h->root, 'b')->w], 'a fixed column and the rest');
