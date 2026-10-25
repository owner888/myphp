<?php
// More widgets (mygo's widgets_more_test.go; TestTooltipHidesOnPress is in
// tooltips_test): tabs, splits, a number input, a toast, tables with
// sections and tall rows, a tree, a date input, a reversed progress bar.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Key, ListState, Node, Role, TableColumn};

// Tabs: a click chooses, the arrows move the choice and the focus, round; Home and End; what assistive technology sees.
$tab = 0; $changes = 0;
$h = new Headless(function (Context $c) use (&$tab, &$changes): void {
    $c->column(10, 0);
      if ($c->changed($c->tabs(['One', 'Two', 'Three'], $tab))) { $changes++; }
      $c->text("page $tab");
    $c->end();
}, 400, 200);
$h->frame();
$h->clickText('Two');
check($tab === 1 && $h->byText('page 1') !== null && $changes === 1, "a click on Two: tab $tab, $changes changes");
$h->right();
check($tab === 2 && in_array('Three', $h->texts($h->focused()), true), 'Right: Three, focused');
$h->right();
check($tab === 0 && in_array('One', $h->texts($h->focused()), true), 'Right past the last tab: One');
$h->end(); same(2, $tab, 'End');
$h->home(); same(0, $tab, 'Home');
$h->input->push(['accessOn']); $h->frame();
$tabs = array_filter($h->rt->accessTree->nodes, fn($n) => $n->role === Role::TAB);
check(count($tabs) === 3 && count(array_filter($tabs, fn($n) => ($n->states & AccessNode::CHECKED) !== 0)) === 1, 'three tabs, one chosen');

// A split: the second pane after the divider; the divider follows the pointer, within the window; the arrows once it has the focus; the handle over the first pane too.
$size = 120.0;
$h = new Headless(function (Context $c) use (&$size): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->split($size, fn() => $c->text('left'), fn() => $c->text('right'))->grow(1);
    $c->end();
}, 400, 200);
$h->frame();
closeFloat(121, $h->rect($h->byText('right'))[0], 'the second pane starts after the divider');
$h->press(123, 100); $h->drag(173, 100); $h->release(173, 100);
same(170.0, $size, 'dragged 50 right');
$h->press(173, 100); $h->drag(0, 100); $h->release(0, 100);
same(40.0, $size, 'dragged to the edge: the least');
$h->click(40.5, 100);
$h->right();
same(50.0, $size, 'Right on the divider');
same(51.0, (float)$h->rect($h->byText('right'))[0], 'the second pane follows');
$h->press(48, 100); $h->drag(68, 100); $h->release(68, 100);
same(70.0, $size, 'the handle reaches over the first pane');

// A vertical split: the second pane below the divider; dragged down.
$size = 60.0;
$h = new Headless(function (Context $c) use (&$size): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->split($size, fn() => $c->text('top'), fn() => $c->text('bottom'), true)->grow(1);
    $c->end();
}, 300, 200);
$h->frame();
closeFloat(61, $h->rect($h->byText('bottom'))[1], 'the second pane starts below the divider');
$h->press(100, 61); $h->drag(100, 101); $h->release(100, 101);
same(100.0, $size, 'dragged 40 down');

// A number input: its buttons, a number typed, one out of range, Up, the top.
$v = 5.0;
$h = new Headless(function (Context $c) use (&$v): void {
    $c->column(10, 0)->align(Node::START);
      $c->numberInput($v, 0, 10, 0.5)->label('Count');
    $c->end();
}, 400, 100);
$h->frame();
$h->clickLabel('Increase'); $h->clickLabel('Decrease'); $h->clickLabel('Decrease');
same(4.5, (float)$v, 'one step up and two down from 5');
$field = null; foreach ($h->root->kids[0]->kids[0]->kids as $k) { if ($k->kind === Node::INPUT) { $field = $k; } }
$h->clickOn($field);
$h->input->focus->selectAll(); $h->type('7.5');
same(7.5, (float)$v, 'typed 7.5');
$h->input->focus->selectAll(); $h->type('12');
same(7.5, (float)$v, '12 is out of range');
$h->up();
same(8.0, (float)$v, 'Up from 7.5');
for ($k = 0; $k < 5; $k++) { $h->up(); }
same(10.0, (float)$v, 'Up past the top');

// A toast: shown after the click, once for the same message, gone after its time.
$now = 0;
$h = poseTester(function (Context $c): void {
    if ($c->clicked($c->button('Save'))) { $c->toast('Saved'); }
    $c->toasts();
}, $now, 400, 300);
$h->clickText('Save');
check($h->byText('Saved') !== null, 'the toast after the click');
$h->clickText('Save');
same(1, count(array_filter($h->texts(), fn($s) => $s === 'Saved')), 'the same message shows once');
$now += (int)((\MyPHP\UI\Runtime::TOAST_FOR + 1) * 1e9); $h->frame();
check($h->byText('Saved') === null, 'gone once its time is over');

// A table: its header and first rows; a click, Down, End to the last shown, Enter and a double click open.
$names = []; for ($i = 0; $i < 100; $i++) { $names[] = 'file' . chr(65 + $i % 26) . intdiv($i, 26); }
$s = new ListState; $s->selected = -1; $opened = -1;
$h = new Headless(function (Context $c) use ($s, $names, &$opened): void {
    $c->column(10, 0, 1)->align(Node::STRETCH);
      $c->table($s, [new TableColumn('Name'), new TableColumn('Size', 80, align: Node::END)], count($names), function (int $row, int $col) use ($c, $names): void {
          $c->text($col === 0 ? $names[$row] : "$row KB");
      })->grow(1);
      if ($s->submitted()) { $opened = $s->selected; }
    $c->end();
}, 400, 300);
$h->frame();
check($h->byText('Name') !== null && $h->byText('fileA0') !== null && $h->byText($names[99]) === null, 'the header and the first rows, not the last');
$h->clickText('fileC0');
same(2, $s->selected, 'a click on the third row');
$h->down(); $h->down();
same(4, $s->selected, 'Down twice');
$h->end();
check($s->selected === 99 && $h->byText($names[99]) !== null, 'End: the last, shown');
$h->enter(); $h->frame();
same(99, $opened, 'Enter opens it');
$h->home();
$h->clickOn($h->byText('fileA0'), 0, 1); $h->clickOn($h->byText('fileA0'), 0, 2); $h->frame();
check($s->selected === 0 && $opened === 0, "a double click on the first row: selected {$s->selected}, opened $opened");

// A table's sections and tall rows: a row as high as its tallest cell, the short cells centered; a header across; scrolled, the section's header stays on top.
$headerCols = 0;
$s = new ListState; $s->header = fn(int $i) => $i % 10 === 0;
$h = new Headless(function (Context $c) use ($s, &$headerCols): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->table($s, [new TableColumn('Name'), new TableColumn('Notes', 120)], 1000, function (int $row, int $col) use ($c, &$headerCols): void {
          if ($row % 10 === 0) { if ($col !== 0) { $headerCols++; } $c->text('Section ' . intdiv($row, 10)); }
          elseif ($col === 0) { $c->text("Row $row"); }
          elseif ($row % 7 === 0) { $c->richText([new \MyPHP\UI\Span('A note long enough to wrap over a few lines of its column')], wrap: true); }
          else { $c->text('Short'); }
      })->grow(1);
    $c->end();
}, 400, 600);
$h->frame();
$note = $h->rect($h->byText('A note long enough to wrap over a few lines of its column')); // a rich text, which wraps (Text, v0.81)
$row1 = $h->rect($h->byText('Row 1')); $row7 = $h->rect($h->byText('Row 7'));
check($note[3] >= 3 * 16, 'the note wraps over lines in its column: ' . json_encode($note));
check(abs(($row7[1] + $row7[3] / 2) - ($note[1] + $note[3] / 2)) <= 1, 'the short cell of the tall row is in its middle: ' . json_encode([$row7, $note]));
same(0, $headerCols, 'a header row builds its first column alone');
$s->scrollTo(505, false);
$h->frame();
$head = $h->rect($h->byText('Name')); $r505 = $h->rect($h->byText('Row 505'));
$r500 = $h->byText('Section 50');
check($r500 !== null && $h->rect($r500)[1] >= $head[1] + $head[3] - 0.5 && $h->rect($r500)[1] <= $r505[1], 'row 505 under the column headers, its section\'s header above it');
check($s->first <= 505 && $s->first >= 503, "the first row in view {$s->first}");

// A tree: a closed folder hides its items; its arrow opens it; nested items indented; the arrows move, close, go to the parent; Enter chooses.
$srcOpen = false; $cmdOpen = true; $clicked = '';
$h = new Headless(function (Context $c) use (&$srcOpen, &$cmdOpen, &$clicked): void {
    $c->tree(function () use ($c, &$srcOpen, &$cmdOpen, &$clicked): void {
        $item = function (string $label, ?bool &$open, ?\Closure $children) use ($c, &$clicked): void {
            if ($c->clicked($c->treeItem($label, $open, $children, $clicked === $label))) { $clicked = $label; }
        };
        $none = null; $none2 = null; $none3 = null;
        $item('src', $srcOpen, function () use ($item, &$cmdOpen, &$none, &$none2): void {
            $item('main.go', $none, null);
            $item('cmd', $cmdOpen, function () use ($item, &$none2): void { $item('tool.go', $none2, null); });
        });
        $item('go.mod', $none3, null);
    });
}, 400, 300);
$h->frame();
check($h->byText('main.go') === null && $h->byText('go.mod') !== null, 'a closed folder hides its items');
$r = $h->rect($h->byText('src'));
$h->click($r[0] - 10, $r[1] + $r[3] / 2);
check($srcOpen && $h->byText('main.go') !== null && $h->byText('tool.go') !== null && $clicked === '', 'its arrow opens it');
check($h->rect($h->byText('main.go'))[0] > $r[0], 'nested items are indented');
$h->clickText('src');
same('src', $clicked, 'a click chooses');
$h->down();
check(in_array('main.go', $h->texts($h->focused()), true), 'Down focuses main.go');
$h->down(); $h->left();
check(!$cmdOpen && $h->byText('tool.go') === null, 'Left closes cmd');
$h->left();
check(in_array('src', $h->texts($h->focused()), true), 'Left on the closed cmd goes to src');
$h->enter();
same('src', $clicked, 'Enter chooses');
$h->left();
check(!$srcOpen && $h->byText('main.go') === null, 'Left on src closes it');

// A date input: its date; a click shows the calendar; a day chosen closes it; from the keyboard; Next month; Escape.
$date = new \DateTimeImmutable('2026-10-03 09:30:00', new \DateTimeZone('UTC')); $changes = 0;
$h = new Headless(function (Context $c) use (&$date, &$changes): void {
    $c->column(10, 0)->align(Node::START);
      if ($c->changed($c->dateInput($date)->label('Due'))) { $changes++; }
    $c->end();
}, 400, 420);
$h->frame();
check($h->byText('10/3/2026') !== null, 'the date');
$h->clickLabel('Due');
check($h->byText('October 2026') !== null, 'the calendar after a click');
$h->clickLabel('October 15, 2026');
check($date->format('Y-m-d H:i') === '2026-10-15 09:30' && $changes === 1 && $h->byText('October 2026') === null, 'a click on the 15th');
$h->enter(); $h->right(); $h->down(); $h->pageDown(); $h->enter();
same('2026-11-23 09:30', $date->format('Y-m-d H:i'), 'Enter, Right, Down, Page Down, Enter');
$h->clickLabel('Due');
$h->clickLabel('Next month');
check($h->byText('December 2026') !== null, 'Next month');
$h->escape();
check($h->byText('December 2026') === null, 'Escape closes it');

// The calendar under a wide field is at its start, not across it.
$date = new \DateTimeImmutable('2026-10-03');
$h = new Headless(function (Context $c) use (&$date): void {
    $c->column(10, 0, 1)->align(Node::STRETCH);
      $c->dateInput($date)->label('Due');
    $c->end();
}, 800, 420);
$h->frame();
$h->clickLabel('Due');
$field = $h->rect($h->byLabel('Due')); $next = $h->rect($h->byLabel('Next month'));
check($next[0] + $next[2] <= $field[0] + $field[2] / 2, 'the calendar is at the start of the field: ' . json_encode([$field, $next]));

// A reversed progress bar fills from the right.
foreach ([false, true] as $reverse) {
    $h = new Headless(function (Context $c) use ($reverse): void {
        $c->column(10, 0, 1)->align(Node::STRETCH);
          $p = $c->progress(0.25, 0)->height(10);
          if ($reverse) { $p->reverse(); }
        $c->end();
    }, 220, 30);
    $h->frame(); $list = $h->paint(); $fill = null;
    for ($i = 0; $i < $list->n; $i++) {
        $k = $i * 16;
        if (abs($list->buf[$k + 2] - 50) < 0.01 && fmod($list->buf[$k + 9], 16) == 0) { $fill = [(float)$list->buf[$k], (float)$list->buf[$k + 2]]; }
    }
    same($reverse ? [160.0, 50.0] : [10.0, 50.0], $fill, $reverse ? 'reversed, from the right' : 'from the left');
}
