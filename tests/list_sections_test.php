<?php
// More of how a list behaves (mygo's list_test.go TestListStickyHeaders,
// TestListRowsShowBelowThePinnedHeader, TestListStickyHeadersSkipTheChoice,
// TestListPinnedHeaderHandlesAClickOnce, TestListScrollBarDragReachesTheEnd,
// TestListScrollBarDragFollowsThePointerAsEstimatesChange,
// TestListTabRevealsRows, TestListViewSeesWhereItIs,
// TestListKeepsPlaceAcrossResizes, TestListKeepsPlaceWhenBuiltAnew,
// TestListNewStateStartsAtTheStart, TestListOfManyStates,
// TestListRevealsAFarRowWithoutAGap, TestListSizedByItsRows,
// TestListFollowAnimation).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Animation, Context, Key, ListState, Node, Scrollbar, ScrollState};

require_once __DIR__ . '/ListTest.php';

/** A headless window of a list of sections: a header of 24 every 21 rows, rows of 30 ("r$i", "Section n" texts). */
function lsSections(ListState $s, int &$n, ?\Closure $onHeader = null): Headless
{
    $s->header = fn(int $i): bool => $i % 21 === 0;
    return new Headless(function (Context $c) use ($s, &$n, $onHeader): void {
        $c->column(0, 0, 1)->align(Node::STRETCH);
          $c->list($s, $n, function (int $i) use ($c, $onHeader): void {
              if ($i % 21 === 0) {
                  $c->column(0, 0, 0, 0xc8c8c8)->size(0, 24)->label("r$i");
                    $t = $c->text('Section ' . intdiv($i, 21));
                    if ($onHeader !== null) { $onHeader($t); }
                  $c->end();
                  return;
              }
              $c->box(0, 30, 0x333333)->label("r$i");
          })->grow = 1;
        $c->end();
    }, 300, 400);
}

// The header of the section at the top stays there; the next pushes it up; one partly out pins where it is.
$s = new ListState(); $n = 2100;
$h = lsSections($s, $n);
$h->frame();
same(0.0, lbRow($h, 0)[1], 'the first header at the top');
$s->scrollTo(5 * 21 + 8, false); $h->frame();
same(0.0, lbRow($h, 5 * 21)[1] ?? null, "in section 5, its header is at the top");
same(0.0, $h->rect($h->byText('Section 5'))[1], "the pinned header's text too");
$s->scrollTo(6 * 21, false); $h->frame();
lbWheel($h, -14);
same([14.0, -10.0], [lbRow($h, 6 * 21)[1], lbRow($h, 5 * 21)[1]], 'the next header pushes the pinned one up');
$s->scrollTo(5 * 21, false); $h->frame();
lbWheel($h, 10);
$row = lbRow($h, 5 * 21 + 1);
$h->frame();
same([$row, 14.0, 0.0], [lbRow($h, 5 * 21 + 1), lbRow($h, 5 * 21 + 1)[1], lbRow($h, 5 * 21)[1]], 'a header scrolled out by 10 pins at the top, the rows under it still');

// A click on the pinned header, while rows come every frame, is one click.
$s = new ListState(); $n = 2100; $clicks = 0;
$h = new Headless(function (Context $c) use ($s, &$n, &$clicks): void {
    $n++;
    $s->header = fn(int $i): bool => $i % 21 === 0;
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->list($s, $n, function (int $i) use ($c, &$clicks): void {
          if ($i % 21 === 0) {
              $c->column(0, 0, 0, 0xc8c8c8)->size(0, 24);
                if ($c->clicked($c->text('Section ' . intdiv($i, 21)))) { $clicks++; }
              $c->end();
              return;
          }
          $c->box(0, 30, 0x333333);
      })->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$s->scrollTo(5 * 21 + 15, false); $h->frame();
$h->clickText('Section 5');
$h->frame(5);
same(1, $clicks, 'one click on the pinned header');

// The choice moved above the view shows below the pinned header.
$sel = 300; $s = new ListState(); $s->selected = &$sel; $n = 2100;
$h = lsSections($s, $n);
$h->frame();
$s->scrollTo(300, false); $h->frame();
same(24.0, lbRow($h, 300)[1], 'scrollTo puts the row under its section\'s header');
$h->rt->focusOn($h->root->kids[0]->kids[0]->id); $h->frame();
$ok = true;
for ($k = 1; $k <= 5; $k++) { $h->up(); $r = lbRow($h, $sel); if ($sel !== 300 - $k || $r === null || $r[1] < 24) { $ok = false; } }
check($ok, 'Up shows the choice below the header');

// Headers are not chosen: the keys go past them.
$sel = 1; $s = new ListState(); $s->selected = &$sel; $s->header = fn(int $i): bool => $i % 5 === 0;
$h = new Headless(function (Context $c) use ($s): void {
    $c->column(0, 0, 1);
      $c->list($s, 50, fn(int $i) => $c->box(0, 20, 0x333333))->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$h->rt->focusOn($h->root->kids[0]->kids[0]->id); $h->frame();
for ($k = 0; $k < 4; $k++) { $h->down(); }
same(6, $sel, 'Down past a header');
$h->home();
same(1, $sel, 'Home goes to the first row, not the header');

// The scroll bar's thumb dragged to the end reaches it, the rows following each other on the way.
$s = new ListState(); $n = 5000;
$h = lbList($s, $n, fn(int $i) => lbVaried($i));
$h->frame();
$list = $h->root->kids[0]->kids[0];
$h->hover(150, 200);
$t = Scrollbar::thumb($h->rect($list), $s->extent(), $h->rt->scrollbarWidth);
[$x, $y] = [$t[0] + $t[2] / 2, $t[1] + $t[3] / 2];
$h->hover($x, $y);
$h->press($x, $y);
$ok = true;
for ($k = 1; $k <= 40; $k++) { $h->drag($x, $y + $k * 10); $ok = $ok && lbRowsOk($h, $s); }
$h->release($x, $y + 400);
$r = lbRow($h, 4999);
check($ok && $s->atEnd && $r !== null && $r[1] + $r[3] == 400.0, 'dragged to the end, without gaps on the way');

// A drag of the thumb goes through the rows as far as the pointer goes along the track, though the rows measured meanwhile are far shorter than those at the top.
$s = new ListState(); $n = 5000;
$h = lbList($s, $n, fn(int $i) => $i < 8 ? 300.0 : 20.0);
$h->frame();
$list = $h->root->kids[0]->kids[0];
$h->hover(150, 200);
$rect = $h->rect($list);
$t = Scrollbar::thumb($rect, $s->extent(), $h->rt->scrollbarWidth);
[$x, $y] = [$t[0] + $t[2] / 2, $t[1] + $t[3] / 2];
$travel = $rect[3] - 4 - $t[3];
$h->hover($x, $y);
$h->press($x, $y);
$ok = true; $last = -1; $along = true;
for ($k = 1; $k * 8 < $travel; $k++) {
    $h->drag($x, $y + $k * 8);
    $first = lbVisible($h, $s)[0];
    if ($first <= $last || $s->atEnd) { $ok = false; }
    $last = $first;
    $ok = $ok && lbRowsOk($h, $s);
    [$offset, $range] = $s->extent();
    if (abs($k * 8 / $travel - $offset / $range) > 0.02) { $along = false; }
}
$h->drag($x, $y + $travel);
$h->release($x, $y + $travel);
$r = lbRow($h, 4999);
check($ok, 'further through the rows at each step, without gaps');
check($along, 'the offset as far through the content as the thumb along the track');
check($s->atEnd && $r !== null && $r[1] + $r[3] == 400.0, 'dragged to the end');

// Tab through buttons in rows: the focus always shows, the rows following each other.
$s = new ListState();
$h = new Headless(function (Context $c) use ($s): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->list($s, 1000, function (int $i) use ($c): void { $c->row()->size(0, 44 + $i % 3 * 10)->label("r$i"); $c->button("Button $i"); $c->end(); })->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$ok = true;
for ($k = 0; $k < 60; $k++) {
    $h->tab();
    $f = $h->rt->focus === null ? null : $h->rt->rectOf($h->rt->focus);
    if ($f === null || $f[1] < 0 || $f[1] + $f[3] > 400 || !lbRowsOk($h, $s)) { $ok = false; break; }
}
check($ok, 'Tab shows the focus at each step');
check(lbVisible($h, $s)[0] >= 40, 'tabbed through 60 rows, the list shows them');

// The view sees where the list is: the frame that scrolls away builds again, so the buttons are where the next click finds them.
$s = new ListState(); $s->followEnd = true; $older = 0;
$h = new Headless(function (Context $c) use ($s, &$older): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->row(0, 8);
        $c->text('200 messages')->grow = 1;
        if ($c->clicked($c->button('Load older'))) { $older++; }
        if (!$s->atEnd && $c->clicked($c->button('Jump to latest'))) { $s->scrollToEnd(); }
      $c->end();
      $c->list($s, 200, fn(int $i) => $c->box(0, lbVaried($i), 0x333333)->label("r$i"))->grow = 1;
    $c->end();
}, 500, 400);
$h->frame();
$h->input->mouse(100, 300);
$h->input->push(['scroll', 100, 300, 0, 230]); $h->frame();
check($h->byText('Jump to latest') !== null, 'scrolled away from the end: a button to jump back');
$h->clickText('Load older');
same(1, $older, 'Load older clicked');
$h->clickText('Jump to latest');
check($s->atEnd && $h->byText('Jump to latest') === null, 'jumped to the end');

// The place stays across resizes, the rows wrapping anew.
$s = new ListState(); $w = 400;
$h = new Headless(function (Context $c) use ($s): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->list($s, 1000, fn(int $i) => $c->text("Row $i " . str_repeat('words that wrap ', 1 + $i % 7))->label("r$i"))->grow = 1;
    $c->end();
}, 400, 400);
$h->frame();
$s->scrollTo(500, false); $h->frame();
$ok = true;
foreach ([300, 220, 500, 400] as $w) {
    $h->w = $w; $h->frame();
    $r = lbRow($h, 500);
    if ($r === null || $r[1] != 0.0 || !lbRowsOk($h, $s)) { $ok = false; echo "  $w wide: ", json_encode($r), "\n"; }
}
check($ok, 'row 500 at the top at each width');

// Shown again after another page, a list keeps its place; a new state starts at the start; one list showing the place of each page.
$s = new ListState(); $show = true;
$h = new Headless(function (Context $c) use (&$s, &$show): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      if ($show) { $c->list($s, 1000, fn(int $i) => $c->box(0, lbVaried($i), 0x333333)->label("r$i"))->grow = 1; } else { $c->text('Another page'); }
    $c->end();
}, 300, 400);
$h->frame();
$s->scrollTo(500, false); $h->frame();
$was = lbRow($h, 500);
$show = false; $h->frame(); $show = true; $h->frame();
same($was, lbRow($h, 500), 'shown again, row 500 where it was');
$s = new ListState(); $show = false; $h->frame(); $show = true; $h->frame();
check(lbVisible($h, $s)[0] === 0 && lbRowsOk($h, $s), 'a new state shows from the start');
$pages = [new ListState(), new ListState()]; $page = 0;
$h = new Headless(function (Context $c) use (&$pages, &$page): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->list($pages[$page], 1000, fn(int $i) => $c->text("Page $page row $i")->size(0, 20))->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$pages[0]->scrollTo(500, false); $h->frame();
$page = 1; $h->frame();
same(0.0, $h->rect($h->byText('Page 1 row 0'))[1], 'page 1 from its start');
$page = 0; $h->frame();
same(0.0, ($r = $h->byText('Page 0 row 500')) === null ? null : $h->rect($r)[1], 'back on page 0, at row 500');

// The focus kept out of view, brought back, shows in one frame without a gap.
$s = new ListState();
$h = new Headless(function (Context $c) use ($s): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->list($s, 1000, function (int $i) use ($c): void { $c->row()->size(0, 40 + $i % 3 * 10)->label("r$i"); $c->button("Button $i"); $c->end(); })->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$h->clickText('Button 3');
$s->scrollTo(250, false); $h->frame();
$s->scrollIntoView(3); $h->frame(1);
$f = $h->rt->rectOf($h->rt->focus);
check($f !== null && $f[1] >= -0.01 && $f[1] + $f[3] <= 400.01 && lbRowsOk($h, $s), 'the focus back in view at once');

// A list without a size is as high as its rows, frame after frame; bounded, it scrolls.
$s = new ListState(); $bounded = new ListState();
$h = new Headless(function (Context $c) use ($s, $bounded): void {
    $c->column(0, 0, 1)->scroll(new ScrollState());
      $c->list($s, 30, fn(int $i) => $c->box(0, 20, 0x333333))->shrink(0)->label('sized');
      $c->list($bounded, 1000, fn(int $i) => $c->box(0, 20, 0x333333))->shrink(0)->maxHeight(200)->label('bounded');
    $c->end();
}, 300, 400);
$ok = true;
for ($k = 0; $k < 5; $k++) {
    $h->frame();
    if ($h->rect($h->byLabel('sized'))[3] != 600.0 || $h->rect($h->byLabel('bounded'))[3] != 200.0) { $ok = false; }
}
check($ok, 'a list of 30 rows of 20 is 600 high, one bounded to 200 is 200');

// Following the end with an animation: it glides to the end as the last row grows; the user scrolling stops it; without motion, at once.
$now = 0; $n = 30; $last = 40.0;
$s = new ListState(); $s->followEnd = true; $s->followAnimation = Animation::smooth();
$h = poseTester(function (Context $c) use ($s, &$n, &$last): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->list($s, $n, function (int $i) use ($c, &$n, &$last): void { $c->box(0, $i === $n - 1 ? $last : 40.0, 0x333333)->label("r$i"); })->grow = 1;
    $c->end();
}, $now, 300, 400);
$h->frame();
$r = lbRow($h, $n - 1);
same(400.0, $r[1] + $r[3], 'at first, the last row ends at the bottom');
$last += 60; $h->frame();
$r0 = lbRow($h, $n - 1);
check($r0[1] + $r0[3] > 450 && $s->atEnd, 'grown: its end below the list');
$now += 80_000_000; $h->frame();
$r1 = lbRow($h, $n - 1);
check($r1[1] + $r1[3] < $r0[1] + $r0[3] && $r1[1] + $r1[3] > 400, 'on the way up');
$now += 2_000_000_000; $h->frame();
$r = lbRow($h, $n - 1);
check($r[1] + $r[3] == 400.0 && $s->atEnd, 'there');
$n++; $h->frame();
$now += 30_000_000;
lbWheel($h, -150);
$r2 = lbRow($h, $n - 5);
$now += 1_000_000_000; $h->frame();
check(lbRow($h, $n - 5) == $r2 && !$s->atEnd, 'scrolled up on the way: it stays');
$h->rt->reduceMotion = true;
$s->scrollToEnd(); $h->frame();
$last += 60; $h->frame();
$r = lbRow($h, $n - 1);
same(400.0, $r[1] + $r[3], 'without motion, the end at once');
