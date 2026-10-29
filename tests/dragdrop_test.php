<?php
// Values dragged within a window (mygo's dragdrop_test.go): a source's
// value dropped on the node taking its type, the innermost under the
// pointer; a press that hardly moves is a click; Escape gives the drag
// up; a button inside a source drags it, a field keeps its moves.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node, TextState};

/** Presses at ($fx, $fy), moves in four steps to ($tx, $ty) and releases there. */
function dragTo(Headless $t, float $fx, float $fy, float $tx, float $ty): void
{
    $t->hover($fx, $fy); $t->press($fx, $fy);
    for ($k = 1; $k <= 4; $k++) {
        $t->drag($fx + ($tx - $fx) * $k / 4, $fy + ($ty - $fy) * $k / 4);
    }
    $t->release($tx, $ty);
}

$dropped = []; $over = false; $clicks = 0; $numbers = 0; $nodes = [];
$t = new Headless(function (Context $c) use (&$dropped, &$over, &$clicks, &$numbers, &$nodes) {
    $c->column(20, 20);
      $src = $c->drag($c->box(100, 40, 0xff0000)->label('Source'), 'hello');
      if ($c->clicked($src)) { $clicks++; }
      $target = $c->column()->size(200, 100)->label('Target');
        $inner = $c->box(50, 30, 0x00ff00)->label('Numbers'); // inside the target, a node taking numbers, not text
        if ($c->drop($inner, 'int', $n)) { $numbers++; }
      $c->end();
      if ($c->drop($target, 'string', $v)) { $dropped[] = $v; }
      $over = $c->dragOver($target, 'string');
    $c->end();
    $nodes = ['src' => $src, 'target' => $target, 'inner' => $inner];
}, 400, 400);
$t->frame();
$at = fn(string $k) => $t->rect($nodes[$k]);
[$sx, $sy] = $at('src'); [$tx, $ty] = $at('target'); [$ix, $iy] = $at('inner');
dragTo($t, $sx + 10, $sy + 10, $sx + 12, $sy + 11);
check($clicks === 1 && $dropped === [], 'a press moving 2 points is a click');
$t->hover($sx + 10, $sy + 10); $t->press($sx + 10, $sy + 10);
$t->drag($sx + 30, $sy + 30);
check($c = $t->rt->valueDrag !== null && $t->rt->valueDrag['src'] === $nodes['src']->id, 'past a few points, the value is dragged');
$t->drag($tx + 120, $ty + 60);
check($over, 'over the target, it sees the value');
$list = $t->paint();
$quads = $list->n;
$moved = [];
for ($e = 1; $e < $list->transforms; $e++) { $moved[] = [round($list->xforms[$e * \MyPHP\UI\DisplayList::FLOATS_PER_TRANSFORM + 4]), round($list->xforms[$e * \MyPHP\UI\DisplayList::FLOATS_PER_TRANSFORM + 5])]; }
check(in_array([$tx + 120 - $sx - 10, $ty + 60 - $sy - 10], $moved), 'the copy shows under the pointer, where the press took the source: moved by ' . json_encode($moved));
$t->release($tx + 120, $ty + 60);
check($dropped === ['hello'] && $clicks === 1 && !$over, 'dropped on the target: no click');
dragTo($t, $sx + 10, $sy + 10, $ix + 10, $iy + 10);
check($numbers === 0 && $dropped === ['hello', 'hello'], 'a node taking numbers takes no text: the target around it does');
dragTo($t, $sx + 10, $sy + 10, $sx + 60, $sy + 20);
check($clicks === 1 && count($dropped) === 2, 'dragged and let go on the source itself: no click, nothing dropped');
$t->hover($sx + 10, $sy + 10); $t->press($sx + 10, $sy + 10);
$t->drag($tx + 120, $ty + 60);
$painting = $t->paint()->n;
check($painting > 0 && $quads === $painting, 'the copy following the pointer paints');
$t->escape();
$t->release($tx + 120, $ty + 60);
check(count($dropped) === 2 && $clicks === 1, 'Escape gives the drag up');
check($t->paint()->n < $painting, 'without a drag, no copy');

// A button inside a source drags it once it moves, and clicks it otherwise; a field keeps its moves.
$dropped = []; $opened = 0; $note = new TextState('');
$t = new Headless(function (Context $c) use (&$dropped, &$opened, $note, &$nodes) {
    $c->column(0, 0, 1);
      $card = $c->drag($c->row(8, 8), 'card');
        $open = $c->button('Open');
        if ($c->clicked($open)) { $opened++; }
        $field = $c->input($note, 'Note', 120);
      $c->end();
      $target = $c->box(200, 60, 0)->label('Target');
      if ($c->drop($target, 'string', $v)) { $dropped[] = $v; }
    $c->end();
    $nodes = ['open' => $open, 'field' => $field, 'target' => $target];
}, 300, 400);
$t->frame();
[$ox, $oy] = $t->rect($nodes['open']); [$tx, $ty] = $t->rect($nodes['target']); [$fx, $fy] = $t->rect($nodes['field']);
dragTo($t, $ox + 4, $oy + 4, $tx + 20, $ty + 20);
check($dropped === ['card'] && $opened === 0, 'a button in a source drags it, and is no click');
$t->clickOn($nodes['open']);
same(1, $opened, 'a press that does not move clicks the button');
dragTo($t, $fx + 4, $fy + 4, $tx + 20, $ty + 20);
same(['card'], $dropped, 'a field keeps the pointer\'s moves');
