<?php
// Right to left (mygo's direction_test.go and direction_controls_test.go):
// rows from the right, columns across from the right, logical edges on
// the side lines start, a subtree's own direction, a grid's columns, a
// scroll from the right with its offset counted from there, text from the
// right, and the controls whose keys go along the line.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Direction, Key, Node, ScrollState, Track};

/** A w×h box named $label. */
function dirBox(Context $c, string $label, float $w, float $h): Node
{
    return $c->box($w, $h, 0x888888)->shrink(0)->label($label);
}

/** The node named $label. */
function dirFind(?Node $n, string $label): ?Node
{
    if ($n === null) { return null; }
    if ($n->label === $label) { return $n; }
    foreach ($n->kids as $k) { if (($f = dirFind($k, $label)) !== null) { return $f; } }
    return null;
}

/** Checks the left edges of the nodes named. @param array<string, float> $xs */
function dirX(Headless $h, Node $root, array $xs, string $what): void
{
    $got = [];
    foreach ($xs as $label => $_) {
        $n = dirFind($root, $label);
        $got[$label] = $n === null ? null : (float)$n->x;
    }
    same(array_map('floatval', $xs), $got, $what);
}

/** Lays $h out right to left, as a window of a language written so. */
function dirRtl(Headless $h): Node
{
    $h->rt->rightToLeft = true;
    return $h->frame();
}

// A row from the right, with its gap, its padding, justify and automatic margins; from the left again in reverse.
$justify = Node::START;
$reverse = false;
$h = new Headless(function (Context $c) use (&$justify, &$reverse): void {
    $c->column(0, 0, 1);
      $row = $c->row(0, 10)->size(0, 20)->padding(0, 4, 0, 8)->justify($justify);
      if ($reverse) { $row->reverse(); }
        dirBox($c, 'a', 30, 10);
        dirBox($c, 'b', 40, 10);
      $c->end();
      $c->row()->size(0, 20);
        dirBox($c, 'first', 10, 10);
        dirBox($c, 'pushed', 10, 10)->marginStart(Node::AUTO);
      $c->end();
    $c->end();
}, 300, 200);
dirX($h, $h->frame(), ['a' => 8, 'b' => 48, 'first' => 0, 'pushed' => 290], 'left to right');
dirX($h, dirRtl($h), ['a' => 300 - 4 - 30, 'b' => 300 - 4 - 30 - 10 - 40, 'first' => 290, 'pushed' => 0], 'right to left: from the right, the padding staying physical');
$justify = Node::END;
dirX($h, $h->frame(), ['a' => 8 + 40 + 10, 'b' => 8], 'justified to the end: the left');
[$justify, $reverse] = [Node::START, true];
dirX($h, $h->frame(), ['a' => 8, 'b' => 48], 'reversed: from the left');

// A column aligns across from the right, start and end swapped, center and stretch alike.
$h = new Headless(function (Context $c): void {
    $c->column(0, 0, 1)->align(Node::START);
      dirBox($c, 'start', 30, 10);
      dirBox($c, 'end', 30, 10)->alignSelf(Node::END);
      dirBox($c, 'center', 30, 10)->alignSelf(Node::CENTER);
      $c->column()->size(0, 10)->alignSelf(Node::STRETCH)->marginStart(20)->label('stretch'); $c->end();
    $c->end();
}, 300, 200);
dirX($h, $h->frame(), ['start' => 0, 'end' => 270, 'center' => 135, 'stretch' => 20], 'a column left to right');
$root = dirRtl($h);
dirX($h, $root, ['start' => 270, 'end' => 0, 'center' => 135, 'stretch' => 0], 'right to left');
same(280.0, (float)dirFind($root, 'stretch')->w, 'stretched with a start margin of 20, on the right');

// Logical edges go by the node's direction; physical ones stay.
$h = new Headless(function (Context $c): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->row()->paddingStart(10)->paddingEnd(30); dirBox($c, 'padded', 10, 10); $c->end();
      $c->row()->padding(0, 0, 0, 7); dirBox($c, 'left', 10, 10); $c->end();
      $c->row()->borderStartWidth(5)->borderColor(0); dirBox($c, 'bordered', 10, 10); $c->end();
    $c->end();
}, 300, 200);
dirX($h, $h->frame(), ['padded' => 10, 'left' => 7, 'bordered' => 5], 'edges left to right');
dirX($h, dirRtl($h), ['padded' => 300 - 10 - 10, 'left' => 300 - 10, 'bordered' => 300 - 5 - 10], 'right to left');

// A subtree laid out in its own direction, which the nodes inside inherit.
$dirs = [];
$h = new Headless(function (Context $c) use (&$dirs): void {
    $dirs = [$c->direction()];
    $c->column(0, 0, 1);
      $c->row(); dirBox($c, 'window', 10, 10); $c->end();
      $c->column()->direction(Direction::LeftToRight);
        $dirs[] = $c->direction();
        $c->row(); dirBox($c, 'ltr', 10, 10); $c->end();
      $c->end();
      $c->row();
        $c->row()->direction(Direction::RightToLeft)->size(100, 0);
          $dirs[] = $c->direction();
          dirBox($c, 'rtl', 10, 10);
        $c->end();
      $c->end();
    $c->end();
}, 300, 200);
$root = $h->frame();
same([Direction::LeftToRight, Direction::LeftToRight, Direction::RightToLeft], $dirs, 'the directions left to right');
dirX($h, $root, ['window' => 0, 'ltr' => 0, 'rtl' => 90], 'laid out left to right');
$root = dirRtl($h);
same([Direction::RightToLeft, Direction::LeftToRight, Direction::RightToLeft], $dirs, 'right to left');
dirX($h, $root, ['window' => 290, 'ltr' => 0, 'rtl' => 290], 'laid out right to left');

// A direction given after the edges: they move.
$h = new Headless(function (Context $c): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->row()->paddingStart(20)->direction(Direction::RightToLeft);
        dirBox($c, 'late', 10, 10);
      $c->end();
    $c->end();
}, 300, 200);
dirX($h, $h->frame(), ['late' => 270], 'the start padding moves to the right');

// A grid's columns from the right, items at the start of their cells.
$h = new Headless(function (Context $c): void {
    $c->grid([Track::fixed(100), Track::fixed(100), Track::fixed(100)])->size(300, 0)->justifyItems(Node::START);
      dirBox($c, 'one', 20, 10);
      dirBox($c, 'two', 20, 10);
      dirBox($c, 'wide', 20, 10)->columnSpan(2)->justifySelf(Node::END);
    $c->end();
}, 300, 200);
dirX($h, $h->frame(), ['one' => 0, 'two' => 100, 'wide' => 180], 'a grid left to right');
dirX($h, dirRtl($h), ['one' => 280, 'two' => 180, 'wide' => 100], 'right to left');

// Sideways scrolling from the right: the content starts there, the offset counts from there, the wheel moves it as it does left to right.
$s = new ScrollState;
$h = new Headless(function (Context $c) use ($s): void {
    $c->row()->size(200, 50)->scroll($s, true, false);
      dirBox($c, 'first', 150, 10);
      dirBox($c, 'last', 150, 10);
    $c->end();
}, 200, 200);
dirX($h, $h->frame(), ['first' => 0, 'last' => 150], 'scrolled from the left');
dirX($h, dirRtl($h), ['first' => 50, 'last' => -100], 'right to left, from the right');
check($s->x == 0 && $s->maxX == 100, "at the start, the offset is {$s->x} of {$s->maxX}");
$h->input->push(['scroll', 100, 25, -50, 0]);
dirX($h, $h->frame(), ['first' => 100], 'the wheel going left shows what is further left');
same(50.0, $s->x, 'scrolled 50 to the left');

// Text starts at the right of a node laid out right to left.
$h = new Headless(function (Context $c): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->text('Hi')->label('hi');
    $c->end();
}, 300, 200);
dirRtl($h);
$list = $h->paint();
$glyphs = [];
for ($i = 0; $i < $list->n; $i++) {
    if (fmod($list->buf[$i * 16 + 9], 16) == 1) { $glyphs[] = $list->buf[$i * 16]; }
}
same([286.0, 293.0], $glyphs, 'right to left, a line starts at the right');

// Controls: the arrows go along the line, from the right.
$val = 2;
$h = new Headless(function (Context $c) use (&$val): void {
    $c->rating($val)->label('stars');
}, 300, 200);
dirRtl($h);
$h->tab();
$h->left();
same(3, $val, 'right to left, Left raises a rating');

// A slider's value grows toward the left, which Left raises; a progress bar fills from the right.
$v = 0.5;
$h = new Headless(function (Context $c) use (&$v): void {
    $c->column(20, 10, 1);
      $c->slider($v, 0, 1)->label('slider');
      $c->progress(0.25, 200)->label('progress');
    $c->end();
}, 300, 200);
$root = dirRtl($h);
$h->tab();
$h->left();
check($v > 0.5, "right to left, Left raises a slider: $v");
$list = $h->paint();
$p = dirFind($root, 'progress');
$fills = [];
for ($i = 0; $i < $list->n; $i++) {
    $k = $i * 16;
    if ($list->buf[$k + 1] >= $p->y && $list->buf[$k + 1] <= $p->y + $p->h && abs($list->buf[$k + 2] - 50) < 0.01) { $fills[] = $list->buf[$k]; }
}
same([$p->x + 150], $fills, 'a progress bar fills from the right');
