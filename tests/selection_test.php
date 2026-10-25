<?php
// Choosing several rows, and rows by typing (mygo's selection_test.go, as
// on macOS; TestListMovesWithoutChoosing is Linux's and Windows', and
// TestSelectionOfAnotherKeyPanics Go's generics): a click's modifiers,
// Cmd- and Shift-clicks, Shift with the keys, the choice following its
// items, typing labels, the page keys, what assistive technology reads.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Key, ListState, Role, Selection, TableColumn};

/** The keys chosen, sorted. @return list<int|string> */
function selChosen(Selection $s): array
{
    $all = $s->all();
    sort($all);
    return $all;
}

/** A list of 20 rows of which the user chooses several, counting its changes. */
function severalList(ListState $s, int &$changes): Headless
{
    $h = new Headless(function (Context $c) use ($s, &$changes): void {
        $c->column(0, 0, 1);
          if ($c->changed($c->list($s, 20, function (int $i) use ($c): void { $c->text("Row $i")->height(30); })->grow(1))) { $changes++; }
        $c->end();
    }, 300, 400);
    $h->frame();
    return $h;
}

// The modifiers held at a click; none for Enter.
$got = -1;
$h = new Headless(function (Context $c) use (&$got): void {
    $b = $c->button('Go');
    if ($c->clicked($b)) { $got = $c->clickModifiers($b); }
}, 300, 200);
$h->frame();
$h->clickOn($h->byText('Go'), Key::SHIFT | Key::OPTION);
same(Key::SHIFT | Key::OPTION, $got, 'Shift+Option-click');
$h->clickText('Go');
same(0, $got, 'a click');
$h->clickOn($h->byText('Go'), Key::COMMAND);
$h->enter();
same(0, $got, 'Enter');

// Cmd-click adds, Shift-click chooses from the row last chosen, with the others, in place of those it chose; Cmd+Shift keeps them.
$s = new ListState; $s->selected = -1; $s->selection = new Selection; $changes = 0;
$h = severalList($s, $changes);
$h->clickText('Row 2');
$h->clickOn($h->byText('Row 5'), Key::COMMAND);
check(selChosen($s->selection) === [2, 5] && $changes === 2, 'Cmd-click adds: ' . json_encode(selChosen($s->selection)) . " ($changes changes)");
$h->clickOn($h->byText('Row 7'), Key::SHIFT);
same([2, 5, 6, 7], selChosen($s->selection), 'Shift-click from the row last chosen, with the others');
$h->clickOn($h->byText('Row 4'), Key::SHIFT);
same([2, 4, 5], selChosen($s->selection), 'again, in place of the rows it chose');
$h->clickOn($h->byText('Row 5'), Key::COMMAND);
same([2, 4], selChosen($s->selection), 'Cmd-click takes one out');
$h->clickOn($h->byText('Row 7'), Key::COMMAND | Key::SHIFT);
same([2, 4, 5, 6, 7], selChosen($s->selection), 'Cmd+Shift keeps the others');
$h->clickText('Row 9');
same([9], selChosen($s->selection), 'a click chooses one');
$changes = 0;
$h->clickText('Row 9');
same(0, $changes, 'the row chosen alone again is no change');

// Shift with the keys extends; Cmd+A all; Down goes alone from the row last chosen; Shift+End and Shift+Home.
$s = new ListState; $s->selected = -1; $s->selection = new Selection; $changes = 0;
$h = severalList($s, $changes);
$h->clickText('Row 3');
$h->down(Key::SHIFT); $h->down(Key::SHIFT);
same([3, 4, 5], selChosen($s->selection), 'Shift+Down twice');
for ($k = 0; $k < 3; $k++) { $h->up(Key::SHIFT); }
same([2, 3], selChosen($s->selection), 'Shift+Up three times');
$h->key(Key::A, 'selectAll:', Key::COMMAND, 'a');
same(20, $s->selection->count(), 'Cmd+A');
$h->down();
same([3], selChosen($s->selection), 'Down goes alone from the row last chosen');
$h->key(Key::END, 'moveToEndOfDocumentAndModifySelection:', Key::SHIFT);
check($s->selection->count() === 17 && $s->selection->has(19), 'Shift+End: ' . json_encode(selChosen($s->selection)));
$h->key(Key::HOME, 'moveToBeginningOfDocumentAndModifySelection:', Key::SHIFT);
same([0, 1, 2, 3], selChosen($s->selection), 'Shift+Home');
same(0, $s->first, 'the list shows its start again');

// The choice of several follows its items as rows come above; Shift goes on from where it was; assistive technology reads them chosen.
$items = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
$s = new ListState; $s->selected = -1; $s->selection = new Selection; $s->key = function (int $i) use (&$items) { return $items[$i]; };
$h = new Headless(function (Context $c) use ($s, &$items): void {
    $c->column(0, 0, 1);
      $c->list($s, count($items), function (int $i) use ($c, &$items): void { $c->text($items[$i])->height(30); })->grow(1);
    $c->end();
}, 300, 400);
$h->frame();
$h->clickText('c');
$h->clickOn($h->byText('e'), Key::SHIFT);
same(['c', 'd', 'e'], selChosen($s->selection), 'c to e');
$items = ['x', 'y', ...$items]; $h->frame();
$h->down(Key::SHIFT);
same(['c', 'd', 'e', 'f'], selChosen($s->selection), 'rows above, Shift+Down goes on from e');
$h->input->push(['accessOn']); $h->frame();
same(['c', 'd', 'e', 'f'], array_values(array_map(fn($n) => $n->label, array_filter($h->rt->accessTree->nodes, fn($n) => $n->role === Role::LIST_ITEM && ($n->states & AccessNode::CHECKED) !== 0))), 'the rows read chosen');

// Typing a row's first letters chooses it: the same letter again the next starting with it, letters typed quickly adding up.
$names = ['Apple', 'Banana', 'Cherry', 'Chestnut', 'Date', 'Citrus'];
$now = 0; $s = new ListState; $s->selected = -1; $s->label = fn(int $i) => $names[$i];
$h = poseTester(function (Context $c) use ($s, $names): void {
    $c->column(0, 0, 1);
      $c->list($s, count($names), function (int $i) use ($c, $names): void { $c->text($names[$i])->height(30); })->grow(1)->autoFocus();
    $c->end();
}, $now, 300, 400);
$h->frame(1);
$typeKey = function (string $ch) use ($h, &$now): void { $now += 50_000_000; $h->type($ch); };
$pause = function () use (&$now): void { $now += 2_000_000_000; };
$typeKey('a');
same(0, $s->selected, 'A with no row chosen');
$pause(); $went = [];
for ($k = 0; $k < 4; $k++) { $typeKey('c'); $went[] = $s->selected; }
same([2, 3, 5, 2], $went, 'c, c, c, c');
$pause(); $typeKey('D');
same(4, $s->selected, 'D');
$pause(); $went = [];
foreach (['c', 'h', 'e', 's'] as $ch) { $typeKey($ch); $went[] = $s->selected; }
same([5, 2, 2, 3], $went, 'c, h, e, s');
$h->input->push(['accessOn']); $h->frame(1);
check(array_filter($h->rt->accessTree->nodes, fn($n) => $n->role === Role::LIST_ITEM && $n->label === 'Chestnut') !== [], 'assistive technology reads the label');

// Typing leaves the window's shortcuts; a space goes on what was typed.
$names = ['Apple', 'Banana', 'Cherry'];
$now = 0; $s = new ListState; $s->selected = -1; $s->label = fn(int $i) => $names[$i]; $j = 0;
$h = poseTester(function (Context $c) use ($s, $names, &$j): void {
    if ($c->shortcut('j')) { $j++; }
    $c->column(0, 0, 1);
      $c->list($s, count($names), function (int $i) use ($c, $names): void { $c->text($names[$i])->height(30); })->grow(1);
    $c->end();
}, $now, 300, 400);
$h->frame(1);
$h->clickText('Apple');
$h->shortcut('j', 0);
$now += 50_000_000; $h->type('b');
check($j === 1 && $s->selected === 1, "the window took J $j time; B chose {$s->selected}");
$now += 50_000_000; $h->type(' ');
same('b ', $s->typed, 'a space goes on what was typed');

// Page Down scrolls without choosing, as AppKit's.
$s = new ListState; $s->selected = -1;
$h = new Headless(function (Context $c) use ($s): void {
    $c->column(0, 0, 1);
      $c->list($s, 100, function (int $i) use ($c): void { $c->text("Row $i")->height(30); })->grow(1);
    $c->end();
}, 300, 400);
$h->frame();
$h->clickText('Row 1');
$first = $s->first;
$h->pageDown();
check($s->selected === 1 && $s->first > $first, "Page Down chose {$s->selected}, scrolled to {$s->first}");

// Assistive technology: a list choosing several, its rows chosen, the focus on the row last chosen.
$s = new ListState; $s->selected = -1; $s->selection = new Selection; $changes = 0;
$h = severalList($s, $changes);
$h->input->push(['accessOn']); $h->frame();
$h->clickText('Row 2');
$h->clickOn($h->byText('Row 4'), Key::SHIFT);
$tree = $h->rt->accessTree;
$list = array_values(array_filter($tree->nodes, fn($n) => $n->role === Role::LIST))[0] ?? null;
check($list !== null && ($list->states & AccessNode::MULTISELECTABLE) !== 0, 'the list chooses several');
same([2, 3, 4], array_values(array_map(fn($n) => $n->posInSet - 1, array_filter($tree->nodes, fn($n) => $n->role === Role::LIST_ITEM && ($n->states & AccessNode::CHECKED) !== 0))), 'the rows chosen');
$f = array_values(array_filter($tree->nodes, fn($n) => $n->id === $tree->focus))[0] ?? null;
same(5, $f?->posInSet, 'the focus on the row last chosen');

// A table choosing several.
$s = new ListState; $s->selected = -1; $s->selection = new Selection;
$h = new Headless(function (Context $c) use ($s): void {
    $c->column(0, 0, 1);
      $c->table($s, [new TableColumn('Name'), new TableColumn('Size', 80)], 50, function (int $row, int $col) use ($c): void { $c->text($col === 0 ? "File $row" : "$row KB"); })->grow(1);
    $c->end();
}, 400, 400);
$h->frame();
$h->clickText('File 1');
$h->clickOn($h->byText('File 3'), Key::SHIFT);
$h->clickOn($h->byText('File 6'), Key::COMMAND);
same([1, 2, 3, 6], selChosen($s->selection), 'the table chooses several');
$h->input->push(['accessOn']); $h->frame();
check(array_filter($h->rt->accessTree->nodes, fn($n) => $n->role === Role::TABLE && ($n->states & AccessNode::MULTISELECTABLE) !== 0) !== [], 'it says so');

// A click and a letter before the next frame: the letter goes on from the row clicked.
$names = ['Apple', 'Banana', 'Cherry'];
$s = new ListState; $s->selected = -1; $s->label = fn(int $i) => $names[$i];
$h = new Headless(function (Context $c) use ($s, $names): void {
    $c->column(0, 0, 1);
      $c->list($s, count($names), function (int $i) use ($c, $names): void { $c->text($names[$i])->height(30); })->grow(1);
    $c->end();
}, 300, 400);
$h->frame();
[$x, $y] = $h->middle($h->byText('Apple'));
$h->input->mouse($x, $y);
$h->input->push(['down', $x, $y, 0, 1, 0]); $h->input->push(['up', $x, $y, 0, 1, 0]);
$h->input->push(['key', 'c', Key::C, 0]); $h->input->push(['insert', 'c']);
$h->frame();
same(2, $s->selected, 'a click on Apple, then c');
