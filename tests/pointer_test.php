<?php
// The pointer, keys and text an app reads of a node (mygo's input.go
// PointerPosition, DragVelocity, DragEnded, scope.go PressedOutside and
// OverlayShortcut, Dismissed, SetChanged, scroll.go RowWidth and
// ScrollWith, textlines.go, textinput.go TextRanges and SetTextSelection).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Anchor, Context, Key, ListState, Node, TextLines, TextRange, TextState};

function ptrFind(?Node $n, string $label): ?Node
{
    if ($n === null) { return null; }
    if ($n->label === $label) { return $n; }
    foreach ($n->kids as $k) { if (($f = ptrFind($k, $label)) !== null) { return $f; } }
    return null;
}

// PointerPosition: from the node's top left, and whether it is over it.
$pos = null;
$h = new Headless(function (Context $c) use (&$pos): void {
    $b = $c->box(50, 40, 0)->margin(10, 0, 0, 20);
    $pos = $c->pointerPosition($b);
}, 200, 200);
$h->frame(); $h->hover(30, 25); $h->frame();
same([10.0, 15.0, true], $pos, 'the pointer from the box\'s top left, over it');
$h->hover(100, 100); $h->frame();
same([80.0, 90.0, false], $pos, 'and outside it');

// DragVelocity while the press moves, DragEnded with the fling's velocity once let go; a click ends no drag.
$now = 0; $v = null; $ended = null; $endedAt = [];
$h = poseTester(function (Context $c) use (&$v, &$ended, &$endedAt): void {
    $b = $c->box(100, 100, 0);
    $v = $c->dragVelocity($b);
    if (($e = $c->dragEnded($b)) !== null) { $ended = $e; $endedAt[] = true; }
}, $now, 200, 200);
$h->frame();
$h->press(10, 10);
for ($i = 1; $i <= 4; $i++) { $now += 10_000_000; $h->drag(10 + 5 * $i, 10); }
closeFloat(500.0, $v[0], 'dragging 5 points every 10 ms: 500 points a second');
$h->release(30, 10);
check($ended !== null && abs($ended[0] - 500) < 1, 'the drag ends with its velocity: ' . json_encode($ended));
$h->frame();
same(1, count($endedAt), 'once');
same([0.0, 0.0], $v, 'no velocity once let go');
$ended = null;
$h->click(50, 50); $h->frame();
same(null, $ended, 'a click ends no drag');

// PressedOutside: a press on neither the panel nor what it is attached to, reported once.
$outside = 0;
$h = new Headless(function (Context $c) use (&$outside): void {
    $anchor = $c->button('Open')->label('anchor');
    $p = $c->column(4, 0, 0, 0x333333)->absolute()->attachTo($anchor, Anchor::BottomLeft, Anchor::TopLeft)->size(80, 60)->label('panel');
      $c->button('Inside')->label('inside');
    $c->end();
    if ($c->pressedOutside($p)) { $outside++; }
}, 400, 300);
$h->frame();
$at = fn(string $l): array => [ptrFind($h->root, $l)->x + 2, ptrFind($h->root, $l)->y + 2];
$h->click(...$at('inside')); $h->frame();
$h->click(...$at('anchor')); $h->frame();
same(0, $outside, 'a press inside the panel, or on what it is attached to, is not outside');
$h->click(390, 290); $h->frame();
same(1, $outside, 'a press elsewhere is, once');

// Dismissed: a press outside a popover closes it.
$open = true; $dismissed = 0;
$h = new Headless(function (Context $c) use (&$open, &$dismissed): void {
    $anchor = $c->button('Open');
    $pop = $c->popover($anchor, $open, function () use ($c): void { $c->text('Hi'); });
    if ($pop !== null && $c->dismissed($pop)) { $dismissed++; }
}, 400, 300);
$h->frame(); $h->click(390, 290); $h->frame();
same([1, false], [$dismissed, $open], 'dismissed the popover');

// OverlayShortcut: the overlay built last takes the key.
$closed = [];
$h = new Headless(function (Context $c) use (&$closed): void {
    foreach (['lower', 'upper'] as $name) {
        $o = $c->column(0, 0, 0, 0x222222)->at(0, 0)->size(50, 50)->label($name); $c->end();
        if ($c->overlayShortcut($o, Key::ESCAPE)) { $closed[] = $name; }
    }
}, 200, 200);
$h->frame(); $h->escape(); $h->frame();
same(['upper'], $closed, 'Escape closes the overlay on top');

// SetChanged: a control of one's own reports a change.
$changed = null;
$h = new Headless(function (Context $c) use (&$changed): void {
    $n = $c->box(10, 10, 0)->setChanged();
    $changed = $c->changed($n);
}, 100, 100);
$h->frame();
check($changed === true, 'changed() reports setChanged');

// RowWidth: a list's rows wider than it scroll sideways, a header goes with them.
$ls = new ListState;
$h = new Headless(function (Context $c) use ($ls): void {
    $c->column()->size(200, 300);
      $head = $c->row()->clip()->label('head');
        $c->box(400, 20, 0)->label('title');
      $c->end();
      $list = $c->list($ls, 3, function (int $i) use ($c): void { $c->box(0, 20, 0x444444)->label("row$i"); });
      $list->rowWidth(400)->size(200, 200);
      $c->end();
      $head->scrollWith($list);
    $c->end();
}, 200, 300);
$h->frame();
same([400.0, 200.0], [ptrFind($h->root, 'row0')->w, $ls->maxX], 'the rows as wide as asked, scrolling the rest');
$ls->scrollByX(50); $h->frame();
same([-50.0, -50.0], [ptrFind($h->root, 'row0')->x, ptrFind($h->root, 'title')->x], 'the rows and the header scrolled together');

// TextLines: where a text area laid out its lines, a line wrapped taking several rows.
$s = new TextState("one\n" . str_repeat('x', 30) . "\nthree");
$lines = new TextLines;
$h = new Headless(function (Context $c) use ($s, $lines): void {
    $c->textarea($s, '', 120, 200)->trackLines($lines);
}, 300, 300);
$h->frame(); $h->paint();
same(3, $lines->count(), 'three lines');
$lh = $s->lastLayout()->lineHeight;
$rows = $lines->rows(1);
check(count($rows) > 1 && $rows[0] === 0, 'the long line on several rows: ' . json_encode($rows));
same([0.0, $lh, $lh * (1 + count($rows))], [$lines->top(0), $lines->top(1), $lines->top(2)], 'the tops of the lines');
same(2, $lines->at($lh * (1 + count($rows)) + 1), 'the line at a point');

// TextRanges: runs of an input's text in their colors, the last given winning; SetTextSelection by runes.
$s = new TextState('héllo world');
$h = new Headless(function (Context $c) use ($s): void {
    $c->input($s, '', 200)->textRanges(new TextRange(0, 5, 0xff0000), new TextRange(2, 3, 0x00ff00));
}, 300, 100);
$h->frame();
$list = $h->paint(); $colors = [];
for ($q = 0; $q < $list->n; $q++) {
    if (fmod($list->buf[$q * 16 + 9], 16) == 1.0) { $colors[] = sprintf('%02x%02x%02x', round($list->buf[$q * 16 + 4] * 255), round($list->buf[$q * 16 + 5] * 255), round($list->buf[$q * 16 + 6] * 255)); }
}
same(['ff0000', 'ff0000', '00ff00', 'ff0000', 'ff0000'], array_slice($colors, 0, 5), 'hello in red, its first l in green');
$s->setTextSelection(1, 3);
same([1, 4], [$s->anchor, $s->cursor], 'runes 1 to 3: bytes 1 to 4');
$s->setTextSelection(99, 99);
same([strlen('héllo world'), strlen('héllo world')], [$s->anchor, $s->cursor], 'kept within the text');
