<?php
// What running the examples beside mygo's showed (v1.26 to v1.28): a slider
// given no width growing in a row of fit content keeps mygo's least width,
// a leaf keeps the least size it is given, the track spans the slider, a
// button's label shows in a style of its own, and a popover leaves the ids
// of what is built after it.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node};

$scrub = 0.5; $slider = null; $box = null;
$h = new Headless(function (Context $c) use (&$scrub, &$slider, &$box): void {
    $c->column(24, 16, 1)->alignItems(Node::CENTER);
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

// A popover opening before a focused text area leaves it its id: the field in the popover takes the focus and the typing, as the notes' link (v1.28).
$body = new \MyPHP\UI\TextState('hello'); $url = new \MyPHP\UI\TextState(''); $linkOpen = false; $link = null;
$h = new Headless(function (Context $c) use ($body, $url, &$linkOpen, &$link): void {
    $c->column(10, 0, 1);
      $link = $c->button('Link')->keepFocus();
      if ($c->clicked($link)) { $linkOpen = true; }
      $c->popover($link, $linkOpen, function () use ($c, $url): void { $c->input($url, 'https://', 260)->autoFocus()->label('URL'); });
      $c->textarea($body)->autoFocus();
    $c->end();
}, 400, 300);
$h->frame();
[$lx, $ly] = $h->middle($link);
$h->click($lx, $ly); $h->frame(3);
$h->type('abc');
same(['abc', 'hello'], [$url->text, $body->text], 'the popover field takes the typing, the text area keeps its text');

// A button given a width alone keeps its height, as mygo's Width(44): the counter's primary + was its row's height (v1.28).
$plain = $prim = null;
$h = new Headless(function (Context $c) use (&$plain, &$prim): void {
    $c->row(0, 8);
      $plain = $c->button('−')->size(44, 0);
      $prim = $c->primaryButton('+')->size(44, 0);
    $c->end();
}, 300, 100);
$h->frame();
[$pr, $qr] = [$h->rect($plain), $h->rect($prim)];
same([44.0, $pr[3] - 2, $pr[1] + 1], [$qr[2], $qr[3], $qr[1]], 'the primary button two points lower than the bordered one, centered beside it');

// A select is at least Space(35) wide whatever width it is given, as mygo's MinWidth: the web views' zoom of width 100 was 140 in mygo (v1.28).
$zoomI = 1; $zsel = null;
$h = new Headless(function (Context $c) use (&$zoomI, &$zsel): void {
    $c->row(0, 8);
      $zsel = $c->select(['75%', '100%', '125%'], $zoomI, 100);
    $c->end();
}, 400, 100);
$h->frame();
same(140.0, $h->rect($zsel)[2], 'the select Space(35) wide');
