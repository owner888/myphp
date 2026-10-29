<?php
// Flex and absolute layout (mygo's style_test.go TestFlexOptions and
// primitives_test.go TestAttachTo): gaps and alignContent, stretched
// lines, reverse and wrapReverse, automatic margins, relative offsets and
// percentages, absolute nodes in the padding box, the largest sizes and
// aspect ratios, justify's spaces, attach and attachTo.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Anchor, Context, Node};

/** The rects of the nodes labeled $labels once $view is laid out in a $w × $h window. @return array<string, array{0: float, 1: float, 2: float, 3: float}> */
function flexBoxes(\Closure $view, float $w, float $h, string ...$labels): array
{
    $t = new Headless($view, $w, $h);
    $t->frame();
    $out = [];
    foreach ($labels as $l) { $n = flexFind($t->root, $l); $out[$l] = $n === null ? null : [$n->x, $n->y, $n->w, $n->h]; }
    return $out;
}

function flexFind(?Node $n, string $label): ?Node
{
    if ($n === null) { return null; }
    if ($n->label === $label) { return $n; }
    foreach ($n->kids as $k) { if (($f = flexFind($k, $label)) !== null) { return $f; } }
    return null;
}

function flexBox(Context $c, float $w, float $h, string $label): Node
{
    return $c->box($w, $h, 0x888888)->label($label);
}

function flexSame(array $want, array $got, string $what): void
{
    foreach ($want as $k => $r) {
        $g = $got[$k] ?? null;
        $ok = $g !== null;
        for ($i = 0; $ok && $i < 4; $i++) { $ok = abs($g[$i] - $r[$i]) < 0.01; }
        check($ok, "$what: $k at " . json_encode($g) . ', want ' . json_encode($r));
    }
}

// Gaps and alignContent: two lines of 10 and a gap of 6, 74 left, 37 above.
flexSame(['a' => [0, 37, 40, 10], 'b' => [50, 37, 40, 10], 'c' => [0, 53, 40, 10]], flexBoxes(function (Context $c) {
    $c->column();
      $c->row()->wrap(100)->size(100, 100)->gapX(10)->gapY(6)->alignContent(Node::CENTER)->align(Node::START);
        flexBox($c, 40, 10, 'a'); flexBox($c, 40, 10, 'b'); flexBox($c, 40, 10, 'c');
      $c->end();
    $c->end();
}, 200, 200, 'a', 'b', 'c'), 'gaps and alignContent');

// Stretched lines.
$b = flexBoxes(function (Context $c) {
    $c->column();
      $c->row()->wrap(100)->size(100, 100)->alignContent(Node::STRETCH)->align(Node::STRETCH);
        $c->column()->width(60)->label('a'); $c->end();
        $c->column()->width(60)->label('b'); $c->end();
      $c->end();
    $c->end();
}, 200, 200, 'a', 'b');
check($b['a'][3] == 50 && $b['b'][1] == 50, 'lines stretch: ' . json_encode($b));

// Reverse, and wrapReverse: lines from the bottom.
flexSame([
    'a' => [75, 0, 20, 10], 'b' => [45, 0, 20, 10],
    'c' => [0, 100, 20, 10], 'd' => [0, 90, 20, 10],
    'e' => [0, 140, 30, 10], 'f' => [0, 130, 30, 10],
], flexBoxes(function (Context $c) {
    $c->column()->align(Node::START);
      $c->row(0, 10)->reverse()->width(100); flexBox($c, 20, 10, 'a')->margin(0, 5, 0, 0); flexBox($c, 20, 10, 'b'); $c->end();
      $c->column()->reverse()->height(100); flexBox($c, 20, 10, 'c'); flexBox($c, 20, 10, 'd'); $c->end();
      $c->row()->wrapReverse(50)->size(50, 40)->align(Node::START); flexBox($c, 30, 10, 'e'); flexBox($c, 30, 10, 'f'); $c->end();
    $c->end();
}, 200, 300, 'a', 'b', 'c', 'd', 'e', 'f'), 'reverse');

// Automatic margins, and an absolute node between insets with automatic margins.
flexSame(['centered' => [75, 0, 50, 10], 'left' => [0, 25, 20, 10], 'right' => [180, 25, 20, 10], 'abs' => [80, 100, 40, 40]], flexBoxes(function (Context $c) {
    $c->column()->width(200);
      flexBox($c, 50, 10, 'centered')->margin(0, Node::AUTO);
      $c->row()->height(40); flexBox($c, 20, 10, 'left'); flexBox($c, 20, 10, 'right')->margin(Node::AUTO, 0, Node::AUTO, Node::AUTO); $c->end();
    $c->end();
    flexBox($c, 40, 40, 'abs')->absolute()->left(0)->right(0)->top(100)->margin(0, Node::AUTO);
}, 200, 200, 'centered', 'left', 'right', 'abs'), 'automatic margins');

// Relative offsets and percentages.
flexSame(['moved' => [3, 5, 20, 20], 'next' => [20, 0, 20, 20], 'quarter' => [40, 0, 50, 20], 'tenth' => [90, 0, 20, 20], 'half' => [100, 100, 10, 10]], flexBoxes(function (Context $c) {
    $c->row()->width(200)->align(Node::START);
      flexBox($c, 20, 20, 'moved')->top(5)->left(3);
      flexBox($c, 20, 20, 'next');
      $c->column()->basisPercent(25)->height(20)->label('quarter'); $c->end();
      $c->column()->grow(1)->maxWidthPercent(10)->height(20)->label('tenth'); $c->end();
    $c->end();
    flexBox($c, 10, 10, 'half')->absolute()->leftPercent(50)->topPercent(50);
}, 200, 200, 'moved', 'next', 'quarter', 'tenth', 'half'), 'relative offsets and percentages');

// A maximum that is a percentage of the parent binds on the main axis as one in points does, though the automatic minimum is measured where it does not resolve (mygo's bbcc7b7).
$row = flexBoxes(function (Context $c) {
    $c->row()->size(400, 100);
      $c->text('supercalifragilisticexpialidocious')->maxWidthPercent(10)->shrink(0)->label('pct');
      $c->text('supercalifragilisticexpialidocious')->maxWidth(40)->shrink(0)->label('px');
    $c->end();
}, 400, 400, 'pct', 'px');
check($row['pct'][2] <= 40.5, "a text limited to 10% of a 400-wide row: {$row['pct'][2]} wide, want 40 (maxWidth(40) gives {$row['px'][2]})");
$col = flexBoxes(function (Context $c) {
    $c->column()->size(200, 400);
      $c->box(0, 300, 0)->maxHeightPercent(50)->shrink(0)->label('pct');
    $c->end();
}, 200, 400, 'pct');
check($col['pct'][3] <= 200.5, "a box limited to 50% of a 400-high column: {$col['pct'][3]} high, want 200");

// Absolute nodes in the padding box: insets from inside the border, whatever the padding; percentages of the padding box.
flexSame(['start' => [4, 4, 14, 14], 'end' => [28, 12, 4, 4], 'half' => [18, 10, 2, 2]], flexBoxes(function (Context $c) {
    $c->column();
      $c->column()->size(36, 20)->borderWidth(1)->borderColor(0)->padding(5)->align(Node::START);
        flexBox($c, 14, 14, 'start')->absolute()->left(3)->top(3);
        flexBox($c, 4, 4, 'end')->absolute()->right(3)->bottom(3);
        flexBox($c, 2, 2, 'half')->absolute()->leftPercent(50)->topPercent(50);
      $c->end();
    $c->end();
}, 200, 200, 'start', 'end', 'half'), 'absolute in the padding box');

// The largest sizes, an aspect ratio, justify's spaces, fill.
flexSame([
    'max' => [0, 0, 60, 10], 'grown' => [60, 0, 140, 10],
    'video' => [0, 10, 160, 90],
    'e1' => [27.5, 100, 30, 10], 'e2' => [85, 100, 30, 10], 'e3' => [142.5, 100, 30, 10],
    'b1' => [0, 110, 30, 10], 'b3' => [170, 110, 30, 10],
    'fill' => [0, 120, 200, 80],
], flexBoxes(function (Context $c) {
    $c->column()->width(200)->height(200);
      $c->row()->height(10)->align(Node::START);
        $c->column()->grow(1)->maxWidth(60)->height(10)->label('max'); $c->end();
        $c->column()->grow(1)->height(10)->label('grown'); $c->end();
      $c->end();
      $c->column()->width(160)->aspectRatio(16 / 9)->label('video'); $c->end();
      $c->row()->justify(Node::SPACE_EVENLY)->height(10); flexBox($c, 30, 10, 'e1'); flexBox($c, 30, 10, 'e2'); flexBox($c, 30, 10, 'e3'); $c->end();
      $c->row()->justify(Node::SPACE_BETWEEN)->height(10); flexBox($c, 30, 10, 'b1'); flexBox($c, 30, 10, 'b2'); flexBox($c, 30, 10, 'b3'); $c->end();
      $c->column()->grow(1)->fillWidth()->label('fill'); $c->end();
    $c->end();
}, 200, 200, 'max', 'grown', 'video', 'e1', 'e2', 'e3', 'b1', 'b3', 'fill'), 'max, aspect ratio, spaces, fill');

// Attached to a corner of the parent; right to left, Start is the right.
flexSame(['corner' => [170, 80, 30, 20], 'start' => [0, 0, 10, 10]], flexBoxes(function (Context $c) {
    $c->column()->size(200, 100);
      flexBox($c, 30, 20, 'corner')->attach(Anchor::BottomRight, Anchor::BottomRight);
      flexBox($c, 10, 10, 'start')->attach(Anchor::TopStart, Anchor::TopStart);
    $c->end();
}, 300, 300, 'corner', 'start'), 'attach');

// AttachTo: below, to the right; near the right edge, the other way; above where there is no room below.
$at = Anchor::BottomLeft; $self = Anchor::TopLeft; $right = false;
$t = new Headless(function (Context $c) use (&$at, &$self, &$right) {
    $b = $c->button('anchor')->size(40, 20)->absolute()->top(100)->left($right ? 350 : 100);
    $c->overlay(function () use ($c, $b, &$at, &$self) {
        flexBox($c, 60, 30, 'panel')->attachTo($b, $at, $self)->margin(4, 0, 0, 0);
    });
}, 400, 300);
$panel = function () use ($t): array { $t->frame(); $n = flexFind($t->root, 'panel'); return [$n->x, $n->y]; };
same([100.0, 124.0], $panel(), 'attachTo: below');
[$at, $self] = [Anchor::Right, Anchor::Left];
same([140.0, 99.0], $panel(), 'to the right');
$right = true;
same(290.0, $panel()[0], 'to the right near the edge: to the left');
[$at, $self] = [Anchor::BottomLeft, Anchor::TopLeft];
same([330.0, 124.0], $panel(), 'below near the right edge: its right edge aligned');
$t->h = 140;
same(66.0, $panel()[1], 'above where there is no room below, apart by its top margin');
