<?php
// Lists whose rows have keys (mygo's list_test.go TestListKeys,
// TestListKeysKeepTheChoice, TestListScrollAndRowsAboveInOneFrame,
// TestListChoiceAtTheEnds): rows coming or going above keep the place,
// the choice and the rows' own state with their items; a chosen item that
// goes leaves no choice. And the focus and the shortcuts of a list's state
// (lifetime_test.go TestListStateFocusAndShortcutsFollowCurrentBuild), what an
// empty list shows (TestListEmpty), one list to a state.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, ListState};

// Rows added and removed above: the same items stay where they show, each row's state with its item.
$ids = range(1000, 1199);
$opened = [];
$s = new ListState();
$s->key = function (int $i) use (&$ids) { return $ids[$i]; };
$h = new Headless(function (Context $c) use (&$ids, &$opened, $s) {
    $c->column(0, 0, 1);
      $c->list($s, count($ids), function (int $i) use ($c, &$ids, &$opened) {
          $id = $ids[$i];
          $box = $c->column()->size(0, 30);
            $open = $c->runtime->local($box->id, fn() => (object)['open' => $opened[$id] ?? false]);
            $c->text("Item $id " . ($open->open ? 'true' : 'false'));
          $c->end();
      })->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$s->scrollTo(100, false);
$h->frame(2);
$was = $h->rect($h->byText('Item 1100 false'));
$ids = [...range(500, 549), ...$ids]; // older items load above
$h->frame();
same($was, $h->rect($h->byText('Item 1100 false')), '50 rows added above: item 1100 stays where it was');
same(150, $s->row, 'the first row in view is its');
$opened[1101] = true;
$ids = array_slice($ids, 10); // ten rows go above
$h->frame();
check($h->byText('Item 1101 false') !== null, 'the row of item 1101 kept its state');
same($was, $h->rect($h->byText('Item 1100 false')), '10 rows removed above: item 1100 stays');

// The choice follows its item; the app choosing another is not rows moving.
$ids = ['a', 'b', 'c', 'd'];
$s = new ListState(); $s->selected = 2;
$s->key = function (int $i) use (&$ids) { return $ids[$i]; };
$h = new Headless(function (Context $c) use (&$ids, $s) {
    $c->column(0, 0, 1);
      $c->list($s, count($ids), function (int $i) use ($c, &$ids) { $c->text($ids[$i])->size(0, 20); })->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$ids = ['z', 'y', ...$ids];
$h->frame();
same([4, 'c'], [$s->selected, $ids[$s->selected]], 'two rows added above: c is still chosen');
$s->selected = 0;
$h->frame();
same(0, $s->selected, 'chose row 0: it is');
$ids = ['y', 'a', 'b'];
$s->selected = 2; $h->frame(); // b
$ids = ['y', 'a'];
$h->frame();
same(-1, $s->selected, 'the chosen item went away: no choice');

// Rows above and the wheel in one frame: the wheel moves from where the items were.
$ids = range(1000, 1199);
$s = new ListState();
$s->key = function (int $i) use (&$ids) { return $ids[$i]; };
$h = new Headless(function (Context $c) use (&$ids, $s) {
    $c->column(0, 0, 1);
      $c->list($s, count($ids), function (int $i) use ($c, &$ids) { $c->text("Item {$ids[$i]}")->size(0, 30); })->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$s->scrollTo(100, false);
$h->frame(2);
$h->hover(100, 100);
$was = $h->rect($h->byText('Item 1101'));
$ids = [...range(500, 549), ...$ids];
$h->input->push(['scroll', 100, 100, 0, -10]);
$h->frame();
same($was[1] - 10, $h->rect($h->byText('Item 1101'))[1], '50 rows above and the wheel 10 points down: item 1101 went up 10');

// The focus and the shortcuts of a list's state follow the list built in the current pass (mygo's lifetime_test.go TestListStateFocusAndShortcutsFollowCurrentBuild).
$s = new ListState();
$show = true; $asked = true; $listKeys = 0; $windowKeys = 0; $rowFocused = false; $early = false; $hiddenActs = false; $list = null;
$h = new Headless(function (Context $c) use ($s, &$show, &$asked, &$listKeys, &$windowKeys, &$rowFocused, &$early, &$hiddenActs, &$list) {
    if ($s->focused($c) || $s->focusWithin($c) || $s->focus($c) || $s->shortcut($c, 'k', Key::COMMAND)) { $early = true; }
    $c->column(0, 0, 1);
    if ($show) {
        $list = $c->list($s, 1, function (int $i) use ($c, $s, &$rowFocused) { $rowFocused = $s->focusWithin($c); $c->text('File')->size(0, 24); });
        $list->grow = 1;
        if ($asked && $s->focus($c)) { $asked = false; }
        if ($c->clicked($c->button('Hide'))) { $show = false; }
    } else {
        $c->button('Other');
        if ($s->focused($c) || $s->focusWithin($c) || $s->focus($c)) { $hiddenActs = true; }
    }
    if ($s->shortcut($c, 'k', Key::COMMAND)) { $listKeys++; }
    if ($c->shortcut('k', Key::COMMAND)) { $windowKeys++; }
    $c->end();
}, 200, 150);
$h->frame();
check(!$asked && $rowFocused && $h->rt->focus === $list->id, 'the list took the focus, which its rows see');
$h->shortcut('k', Key::COMMAND);
same([1, 0], [$listKeys, $windowKeys], "the focused list's shortcut, not the window's");
$h->clickText('Hide');
$h->shortcut('k', Key::COMMAND);
same([1, 1], [$listKeys, $windowKeys], 'hidden, the list takes no shortcut');
check(!$early && !$hiddenActs, 'neither before the list was built nor while it is hidden');
$asked = true; $h->frame();
check($asked, 'a focus asked for waits while the list is hidden');
$show = true; $h->frame(); $h->frame();
$h->shortcut('k', Key::COMMAND);
check(!$asked && $rowFocused && $listKeys === 2 && $windowKeys === 1, 'the list back takes the focus and its shortcut');

// Without rows, what the list shows instead (mygo's TestListEmpty); one state shows one list.
$n = 0; $s = new ListState();
$h = new Headless(function (Context $c) use (&$n, $s) {
    $c->column(0, 0, 1);
      $c->list($s, $n, fn(int $i) => $c->text("Row $i"), fn() => $c->text('No rows'))->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$r = $h->rect($h->byText('No rows'));
check($r[2] > 0 && $r[3] > 0, 'an empty list shows what it shows instead');
$n = 3; $h->frame();
check($h->byText('Row 2') !== null && $h->byText('No rows') === null, 'three rows, and no message');
same(16.0, $s->height(0), 'the message measured no row');
$twice = null;
$h = new Headless(function (Context $c) use ($s, &$twice) {
    try { $c->list($s, 1, fn() => $c->text('a')); $c->list($s, 1, fn() => $c->text('b')); } catch (\LogicException $e) { $twice = $e->getMessage(); }
}, 300, 400);
$h->frame(1);
same('a ListState shown by two lists', $twice, 'two lists of one state in a pass');

// A chat read upward from its end, the rows above never measured, every fifth ten times as tall: each step of the wheel moves the rows by the step (mygo's TestListScrollsByTheStepIntoRowsNotMeasured).
$s = new ListState();
$h = new Headless(function (Context $c) use ($s) {
    $c->column(0, 0, 1);
      $c->list($s, 5000, fn(int $i) => $c->box(0, $i % 5 === 0 ? 240 : 24, 0x333333)->label("r$i"))->grow = 1;
    $c->end();
}, 300, 400);
$h->frame(); $s->scrollToEnd(); $h->frame();
$h->hover(100, 100);
$exact = true;
foreach ([120, 200, 120, 37, 200, 120, 200, 5, 300, 120] as $k => $step) {
    for ($first = $s->first; ($r = $h->rect($h->byLabel("r$first")))[1] + $r[3] <= 0; $first++); // the first row in view
    $was = $h->rect($h->byLabel("r$first"))[1];
    $h->input->push(['scroll', 100, 100, 0, $step]); $h->frame();
    $r = $h->byLabel("r$first");
    if ($r === null || $h->rect($r)[1] != $was + $step) { $exact = false; echo "  step $k, $step: row $first from $was to ", $r === null ? 'gone' : $h->rect($r)[1], "\n"; }
}
check($exact, 'each step moved the rows by the step');
