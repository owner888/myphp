<?php
// How a list scrolls and keeps its place (mygo's list_test.go
// TestListVariableHeights, TestListFillsTheFirstFrame,
// TestListHugeScrollsByFractions, TestListScrollTo,
// TestListKeepsPlaceAsRowsAboveChange, TestListFollowEnd,
// TestListFollowEndStartsAtTheEnd, TestListJustifyEnd,
// TestListGapAndPadding, TestListRowsGoAway, TestListSelection,
// TestListKeepsTheFocusedRow): rows follow each other without gaps, the
// wheel moves them by as much as it scrolls, the place is kept.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, ListState, Node, Role, TextState};

require_once __DIR__ . '/ListTest.php';

// Rows of varied heights: from the top without gaps; the wheel moves them by as much as it scrolls, far down and back.
$s = new ListState(); $n = 10000;
$h = lbList($s, $n, fn(int $i) => lbVaried($i));
$h->frame();
[$first, $last] = lbVisible($h, $s);
check($first === 0 && $last >= 10 && lbRowsOk($h, $s), "the first frame shows rows $first to $last without gaps");
$r5 = lbRow($h, 5);
lbWheel($h, 37.5);
same($r5[1] - 37.5, lbRow($h, 5)[1], 'scrolled 37.5: row 5 moved by as much');
$ok = lbRowsOk($h, $s);
for ($k = 0; $k < 200; $k++) { lbWheel($h, 300); }
$ok = $ok && lbRowsOk($h, $s);
for ($k = 0; $k < 401 && $ok; $k++) { lbWheel($h, -150); $ok = lbRowsOk($h, $s); }
check($ok, 'far down, then back up through the rows measured on the way: no gaps');
check(lbVisible($h, $s)[0] === 0 && lbRow($h, 0)[1] == 0.0, 'back at the top, row 0 at the top');

// Rows far smaller than the estimate: the first frame shows no gap.
$s = new ListState(); $n = 1000;
$h = lbList($s, $n, fn(int $i) => 3.0);
$h->frame(1);
[$first, $last] = lbVisible($h, $s);
check($first === 0 && $last >= 133 && lbRowsOk($h, $s), "the first frame shows rows $first to $last of 3 points in 400");

// 50 million rows of 20: the place counts in rows, so a quarter of a point moves them far down.
$s = new ListState(); $n = 50_000_000;
$h = lbList($s, $n, fn(int $i) => 20.0);
$h->frame();
$s->scrollTo(25_000_000, false); $h->frame();
same(0.0, lbRow($h, 25_000_000)[1] ?? null, 'scrolled to row 25000000: it is at the top');
for ($k = 1; $k <= 3; $k++) { lbWheel($h, 0.25); }
same(-0.75, lbRow($h, 25_000_000)[1], 'three quarters of a point down');
check(lbRowsOk($h, $s), 'no gaps');
$h->key(Key::END, 'scrollToEndOfDocument:');
$r = lbRow($h, $n - 1);
check($r !== null && $r[1] + $r[3] == 400.0, 'End, the pointer over the list: the last row ends at the bottom');

// scrollTo with an alignment, in a list padded 10 above and below; scrollIntoView as little as shows a row.
$s = new ListState(); $n = 5000;
$h = lbList($s, $n, fn(int $i) => lbVaried($i), build: fn(Node $l) => $l->padding(10, 0));
$h->frame();
foreach ([[3000, Node::CENTER], [1000, Node::END], [4000, Node::START], [4999, Node::START], [0, Node::END], [2500, Node::CENTER]] as [$row, $align]) {
    $s->scrollTo($row, false, $align); $h->frame();
    $r = lbRow($h, $row);
    $want = match (true) { $row === 4999 => 390 - $r[3], $row === 0 => 10.0, $align === Node::START => 10.0, $align === Node::CENTER => (400 - $r[3]) / 2, default => 390 - $r[3] };
    check($r !== null && abs($r[1] - $want) < 0.001 && lbRowsOk($h, $s, 0, 10, 390), "scrollTo($row, $align): at {$r[1]}, not $want");
}
$s->scrollTo(2000, false, Node::CENTER); $h->frame();
[$first, $last] = lbVisible($h, $s, 10, 390);
$was = lbRow($h, $first + 2);
$s->scrollIntoView($first + 2); $h->frame();
same($was, lbRow($h, $first + 2), 'scrollIntoView of a row in view moves nothing');
$s->scrollIntoView($last + 10); $h->frame();
$r = lbRow($h, $last + 10);
same(390.0, $r[1] + $r[3], 'scrollIntoView of a row below: it ends at the bottom');
$s->scrollIntoView($first - 50); $h->frame();
same(10.0, lbRow($h, $first - 50)[1], 'scrollIntoView of a row above: it starts at the top');

// Rows above that grow keep what shows in place; back up through them, the list reaches its start exactly.
$s = new ListState(); $n = 1000; $grow = 0.0;
$h = lbList($s, $n, function (int $i) use (&$grow): float { return 30 + ($i < 40 ? $grow : 0); });
$h->frame();
$s->scrollTo(45, false); $h->frame();
$was = lbRow($h, 50);
$grow = 25; $h->frame();
same($was, lbRow($h, 50), 'rows above grew: row 50 stays');
$ok = true;
for ($k = 0; $k < 100; $k++) { lbWheel($h, -40); $ok = $ok && lbRowsOk($h, $s); }
check($ok && lbRow($h, 0)[1] == 0.0 && $s->row === 0 && $s->offset == 0.0, 'back at the top exactly');

// Following the end: rows coming keep the last at the bottom; scrolled away it stays; back at the end it follows again.
$s = new ListState(); $s->followEnd = true; $n = 5;
$h = lbList($s, $n, fn(int $i) => lbVaried($i));
$h->frame();
check(lbRow($h, 0)[1] == 0.0 && $s->atEnd, 'five rows from the top, at the end');
$ok = true;
for ($k = 0; $k < 40; $k++) { $n++; $h->frame(); $r = lbRow($h, $n - 1); if ($n > 12 && $r[1] + $r[3] != 400.0) { $ok = false; } }
check($ok, 'as rows come, the last ends at the bottom');
lbWheel($h, -100);
$was = lbRow($h, $n - 10);
$n += 3; $h->frame();
check(lbRow($h, $n - 13) == $was && !$s->atEnd, 'scrolled up, rows came: what shows stays');
$s->scrollToEnd(); $h->frame();
$n++; $h->frame();
$r = lbRow($h, $n - 1);
check($r[1] + $r[3] == 400.0 && $s->atEnd, 'back at the end, it follows again');

// Following the end from the first frame.
$s = new ListState(); $s->followEnd = true; $n = 1000;
$h = lbList($s, $n, fn(int $i) => lbVaried($i));
$h->frame();
$r = lbRow($h, 999);
check($r !== null && $r[1] + $r[3] == 400.0 && $s->atEnd && lbRowsOk($h, $s), 'the first frame shows the end');

// Rows short of the view at its end (justify END), inside the padding.
$s = new ListState(); $n = 3;
$h = lbList($s, $n, fn(int $i) => 50.0, build: fn(Node $l) => $l->justify(Node::END)->padding(8));
$h->frame();
same([242.0, 292.0, 342.0], [lbRow($h, 0)[1], lbRow($h, 1)[1], lbRow($h, 2)[1]], 'the rows at the bottom');

// A gap between rows, padding and a border; scrolled to the end through every row.
$s = new ListState(); $n = 100;
$h = lbList($s, $n, fn(int $i) => lbVaried($i), build: fn(Node $l) => $l->gap(6)->padding(12, 4)->borderWidth(1));
$h->frame();
$r = lbRow($h, 0);
same([5.0, 13.0, 290.0], [$r[0], $r[1], $r[2]], 'row 0 inside the border and the padding');
$ok = lbRowsOk($h, $s, 6, 13, 387);
for ($k = 0; $k < 100 && !$s->atEnd; $k++) { lbWheel($h, 100); $ok = $ok && lbRowsOk($h, $s, 6, 13, 387); }
$r = lbRow($h, 99);
check($ok && $s->atEnd && $r[1] + $r[3] == 387.0, 'to the end without gaps: the last row ends inside the padding');

// Rows going away: the place stays within those left.
$s = new ListState(); $n = 1000;
$h = lbList($s, $n, fn(int $i) => 30.0);
$h->frame();
$s->scrollToEnd(); $h->frame();
$n = 20; $h->frame();
$r = lbRow($h, 19);
check($r[1] + $r[3] == 400.0 && lbRowsOk($h, $s), '20 rows left: the last ends at the bottom');
$n = 5; $h->frame();
same(0.0, lbRow($h, 0)[1], '5 rows left: from the top');
$n = 0; $h->frame();
check(lbRow($h, 0) === null && $s->atEnd, 'no rows');

// Choosing: a click, Down showing the choice, End, Home, Up at the start, Enter.
$sel = -1; $s = new ListState(); $s->selected = &$sel; $submitted = 0; $list = null; $n = 500;
$h = new Headless(function (Context $c) use ($s, &$n, &$submitted, &$list): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $list = $c->list($s, $n, fn(int $i) => $c->box(0, lbVaried($i), 0x333333)->label("r$i"));
      $list->grow = 1;
      if ($s->submitted()) { $submitted++; }
    $c->end();
}, 300, 400);
$h->frame();
$h->clickOn($h->byLabel('r2'));
check($sel === 2 && $h->rt->focus === $list->id, 'a click chooses row 2 and focuses the list');
$ok = true;
for ($k = 3; $k < 60; $k++) { $h->down(); $r = lbRow($h, $sel); if ($sel !== $k || $r === null || $r[1] < 0 || $r[1] + $r[3] > 400) { $ok = false; } }
check($ok, 'Down moves the choice, which shows');
$h->end(); $r = lbRow($h, 499);
check($sel === 499 && $r[1] + $r[3] == 400.0, 'End chooses the last, at the bottom');
$h->home();
check($sel === 0 && lbRow($h, 0)[1] == 0.0, 'Home the first, at the top');
$h->up();
same(0, $sel, 'Up at the start stays');
$h->enter();
same(1, $submitted, 'Enter submits');

// A focused field in a row scrolled far away keeps the focus and takes the keys.
$texts = array_map(fn() => new TextState(''), range(0, 299)); $s = new ListState();
$h = new Headless(function (Context $c) use ($s, $texts): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->list($s, 300, function (int $i) use ($c, $texts): void { $c->row()->size(0, 30); $c->input($texts[$i])->label("Field $i"); $c->end(); })->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$h->clickOn($h->byLabel('Field 3'));
$focus = $h->rt->focus;
for ($k = 0; $k < 20; $k++) { lbWheel($h, 200); }
check($s->first >= 100, "scrolled to row {$s->first}");
$h->type('kept');
check($h->rt->focus === $focus && $texts[3]->text === 'kept', 'the field kept the focus and took the typing');
