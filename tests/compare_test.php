<?php
// What running the examples beside mygo's showed (v1.26): a slider given
// no width growing in a row of fit content keeps mygo's least width, a
// leaf keeps the least size it is given, and the track spans the slider.
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
