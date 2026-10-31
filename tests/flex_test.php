<?php
// Flex and absolute layout (mygo's style_test.go TestFlexOptions and
// primitives_test.go TestAttachTo): gaps and alignContent, stretched
// lines, reverse and wrapReverse, automatic margins, relative offsets and
// percentages, absolute nodes in the padding box, the largest sizes and
// aspect ratios, justify's spaces, attach and attachTo.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Anchor, Context, Direction, Node, ScrollState, Track};

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
      $c->row()->wrap(100)->size(100, 100)->gapX(10)->gapY(6)->alignContent(Node::CENTER)->alignItems(Node::START);
        flexBox($c, 40, 10, 'a'); flexBox($c, 40, 10, 'b'); flexBox($c, 40, 10, 'c');
      $c->end();
    $c->end();
}, 200, 200, 'a', 'b', 'c'), 'gaps and alignContent');

// Stretched lines.
$b = flexBoxes(function (Context $c) {
    $c->column();
      $c->row()->wrap(100)->size(100, 100)->alignContent(Node::STRETCH)->alignItems(Node::STRETCH);
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
    $c->column()->alignItems(Node::START);
      $c->row(0, 10)->reverse()->width(100); flexBox($c, 20, 10, 'a')->margin(0, 5, 0, 0); flexBox($c, 20, 10, 'b'); $c->end();
      $c->column()->reverse()->height(100); flexBox($c, 20, 10, 'c'); flexBox($c, 20, 10, 'd'); $c->end();
      $c->row()->wrapReverse(50)->size(50, 40)->alignItems(Node::START); flexBox($c, 30, 10, 'e'); flexBox($c, 30, 10, 'f'); $c->end();
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
    $c->row()->width(200)->alignItems(Node::START);
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
      $c->column()->size(36, 20)->borderWidth(1)->borderColor(0)->padding(5)->alignItems(Node::START);
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
      $c->row()->height(10)->alignItems(Node::START);
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

// Flexbox as Chrome lays it out, where it strayed from CSS (mygo's layout_css_test.go, the sizes Chrome's), in a 600×500 column.
$css = function (\Closure $build, string ...$labels): array {
    return flexBoxes(function (Context $c) use ($build) { $c->column()->size(600, 500); $build($c); $c->end(); }, 600, 500, ...$labels);
};
$near = fn(array $r, float $w, float $h): bool => abs($r[2] - $w) < 0.05 && abs($r[3] - $h) < 0.05;
$b = $css(function (Context $c) {
    $c->box(8, 0, 0)->padding(0, 3, 0, 11)->label('col');
    $c->row(); $c->box(8, 0, 0)->padding(0, 3, 0, 11)->label('row'); $c->end();
}, 'col', 'row');
check($near($b['col'], 14, 0) && $near($b['row'], 14, 0), 'a box no smaller than its padding: ' . json_encode($b));
$b = $css(function (Context $c) {
    $c->row()->width(300)->alignItems(Node::STRETCH);
      $c->box(0, 0, 0)->basis(200)->label('a');
      $c->box(0, 0, 0)->basis(200)->padding(0, 0, 0, 100)->label('b');
    $c->end();
}, 'a', 'b');
check($near($b['a'], 133.33, 0) && $near($b['b'], 166.67, 0), 'items shrink by their bases less their padding: ' . json_encode($b));
$b = $css(function (Context $c) {
    $c->box(0, 9, 0)->grow(1)->padding(14, 0, 0, 0)->label('a');
    $c->box(0, 0, 0)->grow(2)->label('b');
}, 'a', 'b');
check($near($b['a'], 600, 176) && $near($b['b'], 600, 324), 'a base smaller than the padding is the padding\'s: ' . json_encode($b));
$b = $css(function (Context $c) {
    $c->box(0, 0, 0)->grow(1)->label('a');
    $c->box(0, 0, 0)->minHeight(166)->grow(1)->label('b');
}, 'a', 'b');
check($near($b['a'], 600, 250) && $near($b['b'], 600, 250), 'a minimum does not change the base free space is shared from: ' . json_encode($b));
$b = $css(function (Context $c) {
    $c->column();
      $c->row()->height(163)->minHeight(42)->maxHeight(14)->label('a'); $c->end();
      $c->column()->grow(1)->label('b'); $c->box(0, 0, 0)->margin(8, 0, 0, 0); $c->end();
    $c->end();
}, 'a', 'b');
check($near($b['a'], 600, 42) && $near($b['b'], 600, 8), 'a minimum over its maximum freezes the item: ' . json_encode($b));
$b = $css(function (Context $c) {
    $c->row()->alignItems(Node::START);
      $c->row()->label('fixed'); $c->box(0, 0, 0)->shrink(0)->basis(47); $c->end();
      $c->row()->label('capped'); $c->row()->basis(56); $c->box(117, 0, 0); $c->end(); $c->end();
    $c->end();
}, 'fixed', 'capped');
check($near($b['fixed'], 47, 0) && $near($b['capped'], 117, 0), 'a row as wide as its content counts its items\' bases: ' . json_encode($b));

// Children lined up by their baselines (mygo's baseline_test.go; the boxes without text Chrome's).
$t = new Headless(function (Context $c) use (&$bl) {
    $bl['row'] = $c->row(0, 4)->alignItems(Node::BASELINE);
      $bl['price'] = $c->text('$42')->fontSize(40)->padding(10, 0, 0, 0); // the test's metrics are one size: the padding makes the ascent larger
      $bl['unit'] = $c->text('/month')->fontSize(13)->padding(3, 0, 6, 0);
    $c->end();
    $c->row()->alignItems(Node::START);
      $bl['big'] = $c->text('Title')->fontSize(28)->alignSelf(Node::BASELINE);
      $bl['small'] = $c->text('note')->fontSize(11)->alignSelf(Node::BASELINE);
      $bl['dot'] = $c->box(6, 6, 0)->alignSelf(Node::BASELINE);
      $bl['top'] = $c->text('top')->fontSize(11);
    $c->end();
}, 400, 300);
$t->frame();
$baseline = fn(Node $n) => $n->y + $n->padT() + $t->metrics->ascent($n->fontSize, $n->style);
[$row, $price, $unit] = [$bl['row'], $bl['price'], $bl['unit']];
check(abs($baseline($price) - $baseline($unit)) < 0.01 && $price->y == $row->y, 'texts of two sizes on one baseline, the larger at the top: ' . json_encode([$baseline($price), $baseline($unit)]));
check(abs($row->h - max($price->h, $unit->y - $row->y + $unit->h)) < 0.01, "the row as high as its highest ascent and deepest descent: {$row->h}");
check(abs($baseline($bl['big']) - $baseline($bl['small'])) < 0.01 && abs($bl['dot']->y + $bl['dot']->h - $baseline($bl['big'])) < 0.01, 'alignSelf: those asking line up, a box without text by its bottom');
same($bl['big']->y, $bl['top']->y, 'one aligned to the start stays at the top');
$b = flexBoxes(function (Context $c) {
    $c->column()->alignItems(Node::START);
      $c->grid([Track::fixed(100), Track::fixed(100)])->alignItems(Node::BASELINE)->label('grid');
        $c->box(0, 20, 0)->margin(5, 0, 0, 0)->label('a'); $c->box(0, 50, 0)->label('b');
        $c->box(0, 10, 0)->label('c'); $c->box(0, 30, 0)->margin(0, 0, 4, 0)->alignSelf(Node::START)->label('d');
      $c->end();
      $c->grid([Track::fixed(100), Track::fixed(100)], 0, [Track::fixed(200)])->alignItems(Node::BASELINE)->label('fixed');
        $c->box(0, 20, 0)->label('e'); $c->box(0, 50, 0)->margin(3, 0, 0, 0)->label('f');
      $c->end();
    $c->end();
}, 400, 600, 'grid', 'a', 'b', 'c', 'd', 'fixed', 'e', 'f');
flexSame(['grid' => [0, 0, 200, 84], 'a' => [0, 30, 100, 20], 'b' => [100, 0, 100, 50], 'c' => [0, 50, 100, 10], 'd' => [100, 50, 100, 30],
    'fixed' => [0, 84, 200, 200], 'e' => [0, 117, 100, 20], 'f' => [100, 87, 100, 50]], $b, 'a grid\'s rows line up their items\' baselines');
$b = flexBoxes(function (Context $c) {
    $c->row()->width(300)->alignItems(Node::BASELINE)->label('row');
      $c->box(0, 40, 0)->label('a');
      $c->column()->padding(7, 0, 0, 0)->label('col'); $c->box(0, 10, 0); $c->box(0, 20, 0); $c->end();
      $c->row()->alignItems(Node::BASELINE)->margin(2, 0, 0, 0)->label('inner'); $c->box(0, 5, 0); $c->box(0, 15, 0)->margin(0, 0, 6, 0); $c->end();
    $c->end();
}, 400, 300, 'row', 'a', 'col', 'inner');
flexSame(['row' => [0, 0, 300, 60], 'a' => [0, 0, 0, 40], 'col' => [0, 23, 0, 37], 'inner' => [0, 25, 0, 21]], $b, 'a row\'s baseline is its first child\'s by its baseline, a column\'s its first child\'s');
$b = flexBoxes(function (Context $c) {
    $c->column()->size(300, 100)->direction(Direction::RightToLeft)->alignItems(Node::BASELINE);
      $c->box(50, 10, 0)->label('a'); $c->box(100, 10, 0)->margin(0, 10, 0, 0)->label('b');
    $c->end();
}, 400, 300, 'a', 'b');
flexSame(['a' => [190, 0, 50, 10], 'b' => [190, 10, 100, 10]], $b, 'across a column, the starts line up, right to left too');

// A grid child's percentages are of its area, not the grid's (mygo's TestGridPercentages).
$b = flexBoxes(function (Context $c) {
    $c->grid(2, 12);
      $c->column()->widthPercent(100)->padding(12)->label('a'); $c->text('card-a'); $c->end();
      $c->column()->padding(12)->label('b'); $c->text('card-b'); $c->end();
    $c->end();
}, 600, 400, 'a', 'b');
check(abs($b['a'][0]) < 0.05 && abs($b['a'][2] - 294) < 0.05 && abs($b['b'][0] - 306) < 0.05 && abs($b['b'][2] - 294) < 0.05, 'a full width in a column of two: its column\'s: ' . json_encode($b));
$b = flexBoxes(function (Context $c) {
    $c->grid([Track::fit(), Track::fit(), Track::fr()])->width(400);
      $c->column()->widthPercent(100)->height(10)->label('a'); $c->box(80, 0, 0); $c->end();
      $c->column()->widthPercent(50)->height(10)->label('b'); $c->box(60, 0, 0); $c->end();
      $c->box(0, 10, 0)->label('c');
    $c->end();
}, 500, 100, 'a', 'b', 'c');
flexSame(['a' => [0, 0, 80, 10], 'b' => [80, 0, 30, 10], 'c' => [140, 0, 260, 10]], $b, 'percentages of columns fitting their content');

// A hidden node takes no room: its siblings close up with one gap between them, in rows, columns and grids, and what sizes itself by its content does not count it (mygo's TestHiddenTakesNoRoom).
$hide = true; $nodes = [];
$t = new Headless(function (Context $c) use (&$hide, &$nodes) {
    $c->column()->alignItems(Node::START);
      $nodes['row'] = $c->row(0, 10);
        $nodes['a'] = $c->box(20, 20, 0);
        $c->column()->size(30, 30)->hidden($hide); $nodes['inside'] = $c->box(5, 5, 0); $c->end();
        $nodes['c'] = $c->box(20, 20, 0);
      $c->end();
      $c->column(0, 10); $nodes['d'] = $c->box(20, 20, 0); $c->box(30, 30, 0)->hidden($hide); $nodes['f'] = $c->box(20, 20, 0); $c->end();
      $c->row()->width(100)->justify(Node::SPACE_BETWEEN); $nodes['g'] = $c->box(20, 20, 0); $c->box(20, 20, 0)->hidden($hide); $nodes['i'] = $c->box(20, 20, 0); $c->end();
      $c->grid(2, 10); $nodes['j'] = $c->box(20, 20, 0); $c->box(20, 20, 0)->hidden($hide); $nodes['l'] = $c->box(20, 20, 0); $c->end();
    $c->end();
}, 300, 300);
$hiddenChecks = function (string $when) use ($t, &$nodes): void {
    $t->frame();
    $r = fn(string $k) => [$nodes[$k]->x, $nodes[$k]->y, $nodes[$k]->w, $nodes[$k]->h];
    check($r('c')[0] === $r('a')[0] + 30.0 && $r('row')[2] === 50.0 && $r('row')[3] === 20.0, "$when: a row closes up, sized by what shows");
    check($r('f')[1] === $r('d')[1] + 30.0, "$when: a column too");
    check($r('i')[0] === $r('g')[0] + 80.0, "$when: space between two, not three");
    check($r('l')[0] === $r('j')[0] + 30.0 && $r('l')[1] === $r('j')[1], "$when: a grid gives it no cell");
    check($r('inside')[2] === 0.0 && $t->rt->rectOf($nodes['inside']->id)[2] === 0.0, "$when: what is inside it has no room");
};
$hiddenChecks('hidden');
$hide = false; $t->frame();
check($nodes['c']->x === $nodes['a']->x + 70.0 && $nodes['inside']->w === 5.0, 'shown, it takes its room again');
$hide = true;
$hiddenChecks('hidden again');

// A wrapping row in reverse (mygo's flipMain): each line goes from the right, in order, the lines still top to bottom; right to left, from the left.
foreach ([false, true] as $rtl) {
    $n = [];
    $h = new Headless(function (Context $c) use (&$n, $rtl) {
        $r = $c->row(0, 0)->size(100, 100)->reverse(); $r->wrap(100);
        if ($rtl) { $r->direction(\MyPHP\UI\Direction::RightToLeft); }
          foreach (['a', 'b', 'c'] as $k) { $n[$k] = $c->column(0, 0)->size(40, 20); $c->end(); }
        $c->end();
    }, 100, 100);
    $h->frame();
    $at = fn($k) => array_slice($h->rect($n[$k]), 0, 2);
    same($rtl ? [[0.0, 0.0], [40.0, 0.0], [0.0, 20.0]] : [[60.0, 0.0], [20.0, 0.0], [60.0, 20.0]], [$at('a'), $at('b'), $at('c')], ($rtl ? 'right to left: ' : '') . 'a reversed wrapping row: each line from the far side');
}

// A wrapping row given no width (wrap(0)), or another than it gets: wrapped at the width it is arranged at, as high as its lines, in the same frame (mygo's Wrap);
// what follows it in a column goes below its lines: the gallery's rich text toolbar went under the editor.
$r = $below = null;
$h = new Headless(function (Context $c) use (&$r, &$below) {
    $c->column(16, 12, 1);
      $r = $c->row(0, 4)->wrap(0)->alignItems(Node::CENTER);
        foreach (range(1, 18) as $i) { $c->button("Button $i"); }
      $c->end();
      $below = $c->box(0, 100, 0x333333)->fillWidth();
    $c->end();
}, 500, 600);
$h->frame(1);
$last = end($r->kids);
check($last->y + $last->h <= $r->y + $r->h + 0.5 && $below->y >= $r->y + $r->h, 'a row wrapping at the width it got is as high as its lines, what follows below them: ' . json_encode([$r->h, $last->y, $below->y]));
// Its lines break at its children's flex base sizes, their basis (mygo's hypothetical sizes): cards of basis 320 two by two in 715 points, not four.
$cards = [];
$h = new Headless(function (Context $c) use (&$cards) {
    $c->row(0, 16)->wrap(0)->alignItems(Node::START);
      for ($i = 0; $i < 5; $i++) { $cards[$i] = $c->column(18, 12, 0)->basis(320)->grow(1)->minWidth(0); $c->box(100, 50, 0); $c->end(); }
    $c->end();
}, 715, 900);
$h->frame();
same([[0.0, 349.5], [365.5, 349.5], [0.0, 349.5], [365.5, 349.5], [0.0, 715.0]], array_map(fn($n) => [$n->x, $n->w], $cards), 'cards of basis 320 wrap two by two');
// A scroll container whose content the wrapping makes taller: it scrolls over all of it, its offset not kept within the content as it measured before the wrapping (the gallery's Charts page did not scroll).
$s = new ScrollState();
$h = new Headless(function (Context $c) use ($s) {
    $c->column(16, 16, 1)->fill()->scroll($s);
      $c->row(0, 16)->wrap(0)->alignItems(Node::START);
        for ($i = 0; $i < 5; $i++) { $c->column(18, 12, 0)->basis(320)->grow(1)->minWidth(0); $c->box(100, 300, 0); $c->end(); }
      $c->end();
    $c->end();
}, 715, 600);
$h->frame();
$s->y = 200;
$h->frame();
same([200.0, true], [$s->y, $s->maxY > 400], 'scrolled over the wrapped rows: ' . $s->maxY);
