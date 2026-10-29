<?php
// A window's content zoomed sharply (mygo's zoom_test.go, 91d105a): the view
// laid out in a window 1/z its size; the pointer hitting what the zoom shows,
// a touchpad scrolling as far as the fingers move; a zoom the view sets
// building again at once, clamped; what goes to the window in its points:
// the input methods' caret, context menus, drags; the frame drawn at the
// window's scale times the zoom.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Transfer\{Data, DragOptions, Item, Operation, Representation};
use MyPHP\UI\{Context, Menu, Node, ScrollState, TextState};

// Hit testing: the view sees the content's size, the tester's points stay the window's.
$clicked = ''; $size = [0.0, 0.0]; $zoom = 0.0; $s = new ScrollState(); $n = [];
$h = new Headless(function (Context $c) use (&$clicked, &$size, &$zoom, $s, &$n) {
    $size = $c->size(); $zoom = $c->zoom();
    $c->row(0, 0);
      $n['a'] = $c->box(50, 50, 0xff0000); if ($c->clicked($n['a'])) { $clicked = 'A'; }
      $c->box(10, 50, 0);
      $n['b'] = $c->box(50, 50, 0x0000ff); if ($c->clicked($n['b'])) { $clicked = 'B'; }
    $c->end();
    $c->column(0, 0)->scroll($s)->size(100, 40); $c->box(100, 400, 0); $c->end();
}, 400, 200);
$h->frame();
$h->rt->setZoom(2); $h->frame();
same([[200.0, 100.0], 2.0], [$size, $zoom], 'zoomed 2 times, the view sees 200 × 100');
same([120.0, 0.0, 100.0, 100.0], $h->rect($n['b']), 'a node\'s box in the window\'s points');
$h->click(170, 50);
same('B', $clicked, 'a click at (170, 50), past both boxes in the content, hits B zoomed');
$h->click(90, 90);
same('A', $clicked, 'and at (90, 90), A');
$h->clickOn($n['b']);
same('B', $clicked, 'clicking a node clicks where it shows');
$h->input->push(['scroll', 100, 160, 0, -40, true]); $h->frame();
same(20.0, $s->y, '40 points of a touchpad scroll the content 20');
same(2.0, $h->paint()->zoom, 'the frame draws at the window\'s scale times the zoom');
$h->rt->setZoom(1); $h->frame();
same([400.0, 200.0], $size, 'back at 1, the window\'s size');
$clicked = '';
$h->click(170, 50);
same('', $clicked, 'unzoomed, (170, 50) hits nothing');

// A zoom the view sets builds the frame at the new size at once; none, or NaN, is 1; others clamped.
$z = 1.0; $sizes = [];
$h = new Headless(function (Context $c) use (&$z, &$sizes) {
    $c->setZoom($z);
    $sizes[] = $c->size();
    $c->box(10, 10, 0);
}, 300, 200);
$h->frame();
$sizes = []; $z = 1.5;
$h->frame(1);
same([200.0, 200 / 1.5], end($sizes), 'zoomed 1.5 times, the frame\'s last pass saw the new size');
same(1.5, $h->rt->frameZoom, 'and drew at it');
foreach ([[0.0, 1.0], [NAN, 1.0], [-2.0, 1.0], [0.1, 0.25], [9.0, 5.0]] as [$asked, $got]) {
    $z = $asked; $h->frame(1);
    same($got, $h->rt->zoom, 'setZoom(' . $asked . ')');
}

// What goes to the window, in its points: the caret input methods compose at, a context menu, a drag.
$at = function (float $zoom): array {
    $n = [];
    $t = new Headless(function (Context $c) use (&$n) {
        $c->column(10, 10);
          $n['field'] = $c->input(new TextState('Ada'), '', 120);
          $n['report'] = $c->row(8)->contextMenu(function (Menu $m) { $m->item('Open'); }); $c->text('Report'); $c->end();
          $n['drag'] = $c->dragData($c->box(60, 30, 0xff0000), new Data(new Item(Representation::bytes('text/plain', 'x'))), new DragOptions(Operation::COPY));
        $c->end();
    }, 400, 400);
    $t->frame();
    $t->rt->setZoom($zoom); $t->frame();
    $t->clickOn($n['field']);
    $caret = $t->input->caretRect(0);
    $t->rightClick(...($r = $t->middle($n['report'])));
    $menu = [$t->menu['at'], $r];
    $t->closeMenu(); $t->frame();
    [$x, $y] = $t->middle($n['drag']);
    $t->hover($x, $y); $t->press($x, $y); $t->drag($x + 20, $y + 20);
    return [$caret, $menu, [$t->dataDrag['x'] ?? null, $t->dataDrag['y'] ?? null], [$x + 20, $y + 20]];
};
[$c1, $m1, $d1, $p1] = $at(1);
[$c2, $m2, $d2, $p2] = $at(2);
same(array_map(fn($v) => 2 * $v, $c1), $c2, 'the caret, zoomed 2 times, twice as far and as tall');
same([$m1[1], $m2[1]], [$m1[0], $m2[0]], 'a context menu opens under the pointer, zoomed or not, in the window\'s points');
same([$p1, $p2], [$d1, $d2], 'a drag starts under the pointer, in the window\'s points');
check($p2[1] > 2 * $p1[1] - 30, 'the zoomed node lower down the window: ' . json_encode([$p1, $p2]));
