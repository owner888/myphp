<?php
// Layout: the grid's tracks, placement and spans; a wrapping row; a split's handle cursor.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node, Track};

$n = [];
$h = new Headless(function (Context $c) use (&$n) {
    $c->column(10, 10, 1);
      $n['g1'] = $c->grid(3, 8); for ($i = 0; $i < 7; $i++) { $c->text("Cell $i"); } $c->end();
      $n['g2'] = $c->grid([Track::fixed(80), Track::fit(), Track::fr(1), Track::fr(2)], 4);
        $c->text('a'); $c->text('bbbbbbbbbb'); $c->text('c'); $c->text('d');
        $c->row(0, 0, 0, 0x123456)->size(0, 30); $c->end(); $c->text('x')->alignSelf(Node::CENTER); $c->text('y'); $c->text('zzzz')->alignSelf(Node::END);
      $c->end();
      $c->row(0, 0); $n['g3'] = $c->grid(2, 4); $c->text('one'); $c->text('three'); $c->text('tw'); $c->end(); $c->end();
      $n['g4'] = $c->grid([Track::fixed(60), Track::fr(1), Track::fr(1)], 4);
        $c->text('side')->rowSpan(2); $c->text('header')->columnSpan(-1); $c->text('a'); $c->text('b'); $c->text('c')->columnStart(2)->rowStart(3); $c->text('d'); $c->text('e')->columnSpan(2);
      $c->end();
      $n['g5'] = $c->grid([Track::fit(), Track::fit()], 4); $c->text('x'); $c->text('y'); $c->text('a wide one here')->columnSpan(2); $c->end();
      $c->column(0, 0)->size(100, 0); $n['w'] = $c->row(0, 4)->wrap(100); for ($i = 0; $i < 5; $i++) { $c->text('abcd'); } $c->end(); $c->end(); // 28 wide each: 3 a line at 100
    $c->end();
}, 400, 400);
$h->frame();
$r = fn(Node $k) => array_map(fn($v) => round($v, 2), $h->rect($k));
$k = $n['g1']->kids;
same([10.0, 10.0, 121.33, 16.0], $r($k[0]), 'three equal columns: the first cell'); same([10.0, 58.0, 121.33, 16.0], $r($k[6]), 'the seventh cell on the third row');
$k = $n['g2']->kids;
same(80.0, $r($k[0])[2], 'a fixed column'); same(70.0, $r($k[1])[2], 'a fit column as wide as its content'); same(72.67, $r($k[2])[2], 'fr(1)'); same(145.33, $r($k[3])[2], 'fr(2) twice as wide');
same(30.0, $r($k[4])[3], 'a row as high as its tallest'); same(111.0, $r($k[5])[1], 'alignSelf center'); same(118.0, $r($k[7])[1], 'alignSelf end');
same(74.0, $r($n['g3'])[2], 'a grid in a row is as wide as its content asks');
$k = $n['g4']->kids;
same([60.0, 16.0], [$r($k[0])[2], $r($k[0])[3]], 'a row span over two fit rows'); same(380.0, $r($k[1])[2], 'columnSpan(-1) to the last column');
same([74.0, 20.0], [$r($k[4])[0], $r($k[4])[1] - $r($n['g4'])[1]], 'columnStart 2 and rowStart 3 place the cell (two fit rows of 6 above it)');
same(220.0, $r($k[6])[2], 'a span of two fr columns');
$k = $n['g5']->kids; same($r($k[0])[2], $r($k[1])[2], 'a span over two fit tracks widens them equally'); same(380.0, $r($k[2])[2], 'and spans both');
$k = $n['w']->kids; same($r($k[0])[1], $r($k[2])[1], 'three on the first line'); check($r($k[3])[1] > $r($k[0])[1], 'the fourth wraps');

// A split's handle shows the resize cursor and drags.
$size = 200.0;
$h = new Headless(function (Context $c) use (&$size) { $c->split($size, function () use ($c) { $c->text('left'); }, function () use ($c) { $c->text('right'); }); }, 600, 300);
$h->frame(); $h->hover(200, 150); same('resizeEW', $h->rt->cursor, 'the split handle cursor');
$h->press(200, 150); $h->drag(240, 150); $h->release(240, 150); same(240.0, $size, 'dragging the handle resizes');

// Basis(0) with grow: the children share the row equally whatever they hold, and never widen their parent.
$n = [];
$h = new Headless(function (Context $c) use (&$n) {
    $c->row(0, 0, 1);
      $n['a'] = $c->column(0, 0, 1)->basis(0); $c->text('x'); $c->end();
      $n['b'] = $c->column(0, 0, 1)->basis(0); $n['r'] = $c->row(0, 0);
        $n['c'] = $c->column(0, 0, 1)->basis(0); $c->text(str_repeat('wide', 20)); $c->end();
        $n['d'] = $c->column(0, 0, 1)->basis(0); $c->text('y'); $c->end();
      $c->end(); $c->end();
    $c->end();
}, 600, 100);
$h->frame(); $r = fn($k) => $h->rect($k);
same([300.0, 300.0], [$r($n['a'])[2], $r($n['b'])[2]], 'the long text does not widen its column: half each');
same([150.0, 150.0], [$r($n['c'])[2], $r($n['d'])[2]], 'the two of basis 0 share it equally');

// A grid's justifyItems places its children in their cells across; justifySelf overrides it; stretch is the default.
$n = [];
$h = new Headless(function (Context $c) use (&$n) {
    $n['g'] = $c->grid([Track::fixed(100), Track::fixed(100)], 0)->justifyItems(Node::CENTER);
      $n['a'] = $c->text('ab'); $n['b'] = $c->text('cd')->justifySelf(Node::END); $n['c'] = $c->text('ef')->justifySelf(Node::STRETCH); $n['d'] = $c->text('gh')->justifySelf(Node::START);
    $c->end();
}, 300, 100);
$h->frame(); $r = fn($k) => $h->rect($k);
same([43.0, 14.0], [$r($n['a'])[0], $r($n['a'])[2]], 'centered in a 100 column: its own width');
same(186.0, $r($n['b'])[0], 'justifySelf end: at the right of the second column');
same([0.0, 100.0], [$r($n['c'])[0], $r($n['c'])[2]], 'justifySelf stretch fills the cell'); same(100.0, $r($n['d'])[0], 'start');
