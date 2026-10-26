<?php
// What running the examples beside mygo's showed (v1.26, v1.27): a slider given
// no width growing in a row of fit content keeps mygo's least width, a
// leaf keeps the least size it is given, the track spans the slider, and a
// button's label shows in a style of its own.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node};

$scrub = 0.5; $slider = null; $box = null;
$h = new Headless(function (Context $c) use (&$scrub, &$slider, &$box): void {
    $c->column(24, 16, 1)->align(Node::CENTER);
      $c->row(0, 10);
        $slider = $c->slider($scrub, 0, 1, 0)->grow(1);
        $c->text('870 / 870 ms', 12)->size(96, 0);
      $c->end();
      $box = $c->box(10, 10, 0x336699)->minWidth(40)->minHeight(30);
    $c->end();
}, 400, 400);
$h->frame();
same(80.0, $h->rect($slider)[2], "a slider of no width keeps mygo's least width, Space(20)");
same([40.0, 30.0], array_slice($h->rect($box), 2), 'a leaf keeps the least size it is given');
$list = $h->paint(); $sr = $h->rect($slider); $spans = false;
for ($i = 0; $i < $list->n; $i++) { // a quad as wide as the slider, where it starts: its track
    $k = $i * \MyPHP\UI\DisplayList::FLOATS_PER_RECT;
    if (abs($list->buf[$k] - $sr[0]) < 0.01 && abs($list->buf[$k + 2] - $sr[2]) < 0.01 && $list->buf[$k + 3] < $sr[3]) { $spans = true; }
}
check($spans, 'the track spans the slider, its padding too');

// A button's label in a style of its own, as the notes' B, I, U and S: bold, struck through, underlined.
$bb = $sb = $ub = null;
$h = new Headless(function (Context $c) use (&$bb, &$sb, &$ub): void {
    $c->row(0, 4);
      $bb = $c->button('B')->bold();
      $sb = $c->button('S')->strikethrough();
      $ub = $c->button('U')->underline();
    $c->end();
}, 300, 100);
$h->frame();
same([\MyPHP\UI\Font::BOLD, true, true], [$bb->style & \MyPHP\UI\Font::BOLD, $sb->strike, $ub->underline], "the buttons' labels bold, struck through and underlined");

// A text given a width alone keeps its height, centered in its row beside a slider, as mygo's Width(96).
$sl2 = $tx2 = null; $v2 = 0.5;
$h = new Headless(function (Context $c) use (&$sl2, &$tx2, &$v2): void {
    $c->row(0, 10);
      $sl2 = $c->slider($v2, 0, 1, 0)->grow(1);
      $tx2 = $c->text('870 / 870 ms', 12)->size(96, 0);
    $c->end();
}, 300, 100);
$h->frame();
[$sr2, $tr2] = [$h->rect($sl2), $h->rect($tx2)];
same([96.0, 16.0, $sr2[1] + ($sr2[3] - 16) / 2], [$tr2[2], $tr2[3], $tr2[1]], 'the text keeps its height, centered in the row');

// A release whose press was elsewhere clicks nothing: confetti fired at its stage's corner when it opened under a mouse released then.
$hits = 0; $btn = null;
$h = new Headless(function (Context $c) use (&$hits, &$btn): void { $btn = $c->button('Burst'); if ($c->clicked($btn)) { $hits++; } }, 200, 100);
$h->frame();
[$bx, $by] = $h->middle($btn);
$h->release($bx, $by); $h->frame();
same(0, $hits, 'a release alone is no click');
$h->click($bx, $by); $h->frame();
same(1, $hits, 'a press and its release are');
