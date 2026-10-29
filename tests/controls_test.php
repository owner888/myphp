<?php
// The controls, one by one: what their keys, clicks and drags do to the state they edit.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Mem;
use MyPHP\UI\{Context, GridState, Image, Key, ListState, Node, Painter, Selection, SVG, TextState};

// Combobox: a click opens the options with the choice selected, typing filters, Down/Up/Enter choose, blur reverts; autocomplete suggests while typed.
$sel = 'Banana'; $ac = new TextState(''); $n = []; $ev = [];
$fruits = ['Apple', 'Banana', 'Cherry', 'Grape', 'Pineapple'];
$h = new Headless(function (Context $c) use (&$sel, $ac, &$n, &$ev, $fruits) {
    $c->column(10, 10, 1);
      $c->row(0, 10); $n['cb'] = $c->combobox($sel, $fruits, 200); if ($c->changed($n['cb'])) { $ev[] = "sel=$sel"; } $c->end();
      $c->row(0, 10); $n['ac'] = $c->autocomplete($ac, $fruits, 'fruit', 200); if ($c->changed($n['ac'])) { $ev[] = "ac=" . $ac->text; } $c->end();
    $c->end();
});
$items = function () use ($h) { if (count($h->root->kids) < 2) { return null; } $o = []; foreach (end($h->root->kids)->kids as $k) { $o[] = ($k->color !== null ? '*' : '') . $k->kids[0]->text; } return $o; };
$h->frame(); $in = $n['cb']->kids[0]; $r = $h->rect($n['cb']);
$h->click($r[0] + 30, $r[1] + $r[3] / 2);
same($in->id, $h->rt->focus, 'a click in the combobox focuses its input'); same($fruits, $items(), 'and opens every option'); same([0, 6], $in->edit->selection(), 'with the choice selected');
$h->down(); $h->down(); $h->down(); same('Apple|Banana|*Cherry|Grape|Pineapple', implode('|', $items()), 'Down moves the highlight');
$h->enter(); same('Cherry', $sel, 'Enter chooses'); same(null, $items(), 'and closes'); same(['sel=Cherry'], $ev, 'changed() reports');
$h->type('gr'); same(['*Grape'], $items(), 'typing filters, the first highlighted'); $h->enter(); same('Grape', $sel, 'Enter takes it');
$h->type('zz'); $h->rt->focus = null; $h->input->focus = null; $h->frame(); same('Grape', $in->edit->text, 'text matching nothing reverts on blur');
$arrow = $n['cb']->kids[1]; $h->clickOn($arrow); same($fruits, $items(), 'the chevron opens'); $pop = end($h->root->kids); $h->clickOn($pop->kids[0]); same('Apple', $sel, 'a click on an option chooses it');
$h->clickOn($n['ac']); $h->type('ap'); same(['Apple', 'Grape', 'Pineapple'], $items(), 'the autocomplete suggests what contains the text, prefixes first');
$h->down(); $h->down(); $h->enter(); same('Grape', $ac->text, 'Enter takes the highlighted suggestion'); $h->enter(); check($ac->submitted, 'Enter with none is submitted');

// Token field: Enter and a comma add, Backspace in an empty input takes the last out, the × takes one out, the chips wrap.
$tags = ['alpha', 'beta']; $all = ['alpha', 'beta', 'gamma', 'delta', 'epsilon']; $n = []; $ev = [];
$h = new Headless(function (Context $c) use (&$tags, $all, &$n, &$ev) { $c->column(10, 10, 1); $n['tf'] = $c->tokenField($tags, $all); if ($c->changed($n['tf'])) { $ev[] = implode(',', $tags); } $c->end(); }, 300, 400);
$h->frame(); $r = $h->rect($n['tf']); $h->click($r[0] + $r[2] - 20, $r[1] + 14);
$h->type('ga'); $h->down(); $h->enter(); same(['alpha', 'beta', 'gamma'], $tags, 'a suggestion taken is a token');
$h->type('zeta, eta'); same(['alpha', 'beta', 'gamma', 'zeta'], $tags, 'a comma adds what was typed'); $h->enter(); same('eta', end($tags), 'Enter adds the rest');
same(56.0, $h->rect($n['tf'])[3], 'five chips wrap onto two lines at 300 wide, inside the border, which takes room as in mygo');
$h->key(Key::DELETE, 'deleteBackward:'); same(['alpha', 'beta', 'gamma', 'zeta'], $tags, 'Backspace in the empty input takes the last out');
$x = $n['tf']->kids[0]->kids[2]; $h->clickOn($x); same(['beta', 'gamma', 'zeta'], $tags, 'the × of a chip takes it out');

// Date input, calendar, time input.
$date = new \DateTimeImmutable('2026-10-16 09:30:00'); $time = new \DateTimeImmutable('2026-01-01 15:04:00'); $n = []; $ev = [];
$h = new Headless(function (Context $c) use (&$date, &$time, &$n, &$ev) {
    $c->column(10, 10, 1);
      $c->row(0, 10); $n['d'] = $c->dateInput($date); if ($c->changed($n['d'])) { $ev[] = 'date ' . $date->format('Y-m-d H:i'); } $c->end();
      $c->row(0, 10); $n['t'] = $c->timeInput($time); if ($c->changed($n['t'])) { $ev[] = 'time ' . $time->format('H:i'); } $c->end();
      $c->row(0, 10); $n['cal'] = $c->calendar($date); if ($c->changed($n['cal'])) { $ev[] = 'cal ' . $date->format('Y-m-d'); } $c->end();
    $c->end();
}, 600, 500);
$h->frame(); same('10/16/2026', $n['d']->kids[0]->text, 'the date in en-US'); same('October 2026', $n['cal']->kids[0]->kids[1]->kids[0]->text, 'the calendar title');
$h->clickOn($n['d']); $grid = end($h->root->kids)->kids[0]; same($grid->id, $h->rt->focus, 'the calendar opens with the focus');
$h->right(); $h->pageDown(); $h->enter(); same('2026-11-17 09:30', $date->format('Y-m-d H:i'), 'Right, Page Down, Enter: a month and a day on, the time kept'); same($n['d']->id, $h->rt->focus, 'the focus returns to the button');
$cell = $n['cal']->kids[3]->kids[2]; $h->clickOn($cell); same('2026-11-10 09:30', $date->format('Y-m-d H:i'), 'a click in the calendar chooses that day');
$h->down(); same('2026-11-17', $date->format('Y-m-d'), 'Down moves a week');
$h->clickOn($n['t']->kids[0]); $h->up(); same('16:04', $time->format('H:i'), 'Up steps the hours');
$h->type('1'); same('13:04', $time->format('H:i'), 'a digit sets the hour in its half of the day'); $h->type('1'); same('23:04', $time->format('H:i'), 'a second makes two');
same($n['t']->kids[2]->id, $h->rt->focus, 'and moves on to the minutes'); $h->type('4'); $h->type('5'); same('23:45', $time->format('H:i'), 'digits set the minutes');
$h->right(); $h->type('a'); same('11:45', $time->format('H:i'), 'A at the half of the day chooses AM'); $h->down(); same('23:45', $time->format('H:i'), 'Down switches it');

// Color picker and well.
$rgb = 0x3b82f6; $alpha = 1.0; $n = []; $ev = [];
$h = new Headless(function (Context $c) use (&$rgb, &$alpha, &$n, &$ev) {
    $c->column(10, 10, 1);
      $c->row(0, 10); $n['well'] = $c->colorWell($rgb, $alpha); if ($c->changed($n['well'])) { $ev[] = 'well ' . Context::hexOf($rgb, $alpha); } $c->end();
      $c->row(0, 10); $n['pk'] = $c->colorPicker($rgb, $alpha); if ($c->changed($n['pk'])) { $ev[] = 'pick ' . Context::hexOf($rgb, $alpha); } $c->end();
    $c->end();
}, 600, 600);
same('3b82f6', dechex(Context::fromHSV(...Context::toHSV(0x3b82f6))), 'HSV round trip'); same([0xaabbcc, 1.0], Context::parseHex('#abc'), '#rgb parses'); same('#12345680', Context::hexOf(0x123456, 0.5), 'hex with alpha');
$h->frame(); $pk = $n['pk']; $sq = $h->rect($pk->kids[0]);
same([240.0, 160.0], [$sq[2], $sq[3]], 'the square Space(60) by Space(40)');
$h->press($sq[0] + $sq[2] / 2, $sq[1] + $sq[3] / 4); same('#6084bf', Context::hexOf($rgb, $alpha), 'a press in the square sets saturation and value');
$h->drag($sq[0] + $sq[2], $sq[1]); $h->release($sq[0] + $sq[2], $sq[1]); same('#0061ff', Context::hexOf($rgb, $alpha), 'dragging to the corner');
$hs = $h->rect($pk->kids[1]); $h->click($hs[0] + $hs[2] / 2, $hs[1] + 8); same('#00ffff', Context::hexOf($rgb, $alpha), 'the hue slider: its middle is cyan');
$as = $h->rect($pk->kids[2]); $h->click($as[0] + 6 + ($as[2] - 12) / 4, $as[1] + 8); // a quarter across the values: the knob's half width in from each end same('#00ffff40', Context::hexOf($rgb, $alpha), 'the opacity slider'); same('#00ffff40', $pk->kids[3]->kids[1]->edit->text, 'the hex field follows');
$h->clickOn($n['pk']->kids[4]->kids[3]); same('#22c55e', Context::hexOf($rgb, $alpha), 'a swatch'); check($n['pk']->kids[4]->kids[3]->bordered, 'bordered as chosen');
$in = $n['pk']->kids[3]->kids[1]; $h->clickOn($in); $h->input->focus->selectAll(); $h->type('#ff0000'); same('#ff0000', Context::hexOf($rgb, $alpha), 'a hex typed sets the color');
$h->type('zz'); same('#ff0000', Context::hexOf($rgb, $alpha), 'junk leaves it'); same('#ff0000zz', $in->edit->text, 'as typed'); $h->rt->focus = null; $h->input->focus = null; $h->frame(); same('#ff0000', $n['pk']->kids[3]->kids[1]->edit->text, 'blur restores the hex');
$h->clickOn($n['well']); same(2, count($h->root->kids), 'the well opens a picker'); $pop = end($h->root->kids); $h->clickOn($pop->kids[0]->kids[0]->kids[4]->kids[0]); same('#ef4444', Context::hexOf($rgb, $alpha), 'a swatch in it'); same('well #ef4444', end($ev), 'which the well reports');
$h->escape(); same(1, count($h->root->kids), 'Escape closes it');

// Form and find bar.
$open = false; $q = new TextState('foo'); $cur = 0; $name = new TextState(''); $agree = false; $n = []; $ev = [];
$h = new Headless(function (Context $c) use (&$n, &$ev, &$open, $q, &$cur, $name, &$agree) {
    $c->column(0, 0, 1);
      $matches = $q->text === '' ? 0 : strlen($q->text);
      $n['bar'] = $c->findBar($open, $q, $matches, $cur); if ($n['bar'] !== null && $c->changed($n['bar'])) { $ev[] = "cur=$cur"; }
      $c->column(10, 10, 1);
        $n['form'] = $c->form(function () use ($c, &$n, $name, &$agree) {
            $n['f1'] = $c->field('Name', function () use ($c, $name) { $c->input($name, '', 0); }, 'For receipts.');
            $n['f2'] = $c->field('Agree to terms', function () use ($c, &$agree) { $c->checkbox('I agree', $agree); }, '', 'Required');
        });
      $c->end();
    $c->end();
}, 600, 500);
$h->frame(); check($n['bar'] === null, 'a closed find bar builds nothing');
$l1 = $h->rect($n['f1']->kids[0]); $l2 = $h->rect($n['f2']->kids[0]); same($l1[2], $l2[2], 'the labels of a form are as wide as the widest'); same(30.0, $l1[3], 'and as high as their control, its border in');
same('Required', $n['f2']->kids[1]->kids[1]->text, 'the error below the control'); same($h->theme->danger, $n['f2']->kids[1]->kids[1]->fg, 'in the danger color');
$h->clickOn($n['f1']->kids[0]->kids[0]); same($n['f1']->kids[1]->kids[0]->id, $h->rt->focus, 'a click on the label focuses the input');
$h->clickOn($n['f2']->kids[0]->kids[0]); check($agree, 'a click on a check box label checks it');
$open = true; $h->frame(); $bar = $n['bar']; $in = $bar->kids[0]->kids[1];
same($in->id, $h->rt->focus, 'the find bar takes the focus as it opens'); same([0, 3], $q->selection(), 'with the query selected'); same('1 of 3', $bar->kids[1]->kids[0]->text, 'the count');
$h->enter(); same(1, $cur, 'Enter steps forward'); $h->enter(Key::SHIFT); same(0, $cur, 'Shift+Enter back'); $h->enter(Key::SHIFT); same(2, $cur, 'round the end');
$h->shortcut('g', Key::COMMAND); same(0, $cur, 'Cmd+G forward');
$h->key(Key::END, 'moveToEndOfLine:'); $h->type('x'); same('1 of 4', $n['bar']->kids[1]->kids[0]->text, 'typing changes the count'); $h->escape(); check(!$open, 'Escape closes the bar');
$open = true; $h->frame(); same([0, 4], $q->selection(), 'opened again, the query is selected again');

// Checkbox group, breadcrumbs, alert.
$mail = true; $cal = false; $asking = false; $chosen = 0; $path = ['Macintosh HD', 'Users', 'ada', 'Documents', 'A very long folder name indeed']; $n = []; $ev = [];
$h = new Headless(function (Context $c) use (&$n, &$ev, &$mail, &$cal, &$asking, &$chosen, $path) {
    $c->column(10, 10, 1);
      $n['g'] = $c->checkboxGroup('Notifications', function () use ($c, &$mail, &$cal) { $c->checkbox('Mail', $mail); $c->checkbox('Calendar', $cal); });
      $n['bc'] = $c->breadcrumbs($path, $chosen); if ($c->changed($n['bc'])) { $ev[] = "chose $chosen"; }
      if ($c->clicked($n['open'] = $c->button('Delete…'))) { $asking = true; }
      $r = $c->alertDialog($asking, 'Delete “Notes”?', "You can't undo this.", 'Cancel', 'Delete'); if ($r !== -1) { $ev[] = "alert $r"; }
    $c->end();
}, 400, 400);
$h->frame(); $head = $n['g']->kids[0]; check(!$head->on && $head->mixed, 'some checked: the head is mixed');
$h->clickOn($head); check($mail && $cal, 'its click checks all'); check($n['g']->kids[0]->on, 'and it is on'); $h->clickOn($head); check(!$mail && !$cal, 'again clears all');
$h->clickOn($n['g']->kids[1]->kids[1]->kids[0]); check($n['g']->kids[0]->mixed, 'one checked: mixed again');
same(24.0, $h->rect($n['bc']->kids[4])[2], 'a crumb shrinks to its min width'); $h->clickOn($n['bc']->kids[2]); same(1, $chosen, 'a crumb clicked'); $h->clickOn($n['bc']->kids[8]); same(1, $chosen, 'the last is no link');
$h->clickOn($n['open']); check($asking, 'the alert opens'); same('B(Delete)', Headless::label($h->focused()), 'with its default focused'); $h->enter(); same('alert 1', end($ev), 'Enter chooses it');
$h->clickOn($n['open']); $h->click(5, 5); check($asking, 'a click outside does nothing'); $h->escape(); same('alert -2', end($ev), 'Escape dismisses');

// Grid view: virtualized rows, a choice by click and keys, letters typed; several chosen; items dragged.
$gs = new GridState; $gs->selects = true; $gs->label = fn(int $i) => "Item $i"; $N = 1000; $n = []; $ev = []; $built = 0;
$h = new Headless(function (Context $c) use (&$n, &$ev, $gs, $N, &$built) {
    $c->column(0, 0, 1); $built = 0;
    $n['g'] = $c->gridView($gs, $N, 140, 100, function (int $i) use ($c, &$built) { $built++; $c->text("Item $i"); });
    if ($c->changed($n['g'])) { $ev[] = "sel={$gs->selected}"; } if ($gs->rows->submitted()) { $ev[] = "open {$gs->selected}"; }
    $c->end();
}, 500, 300);
$h->frame(); same(3, $gs->cols, 'three columns of 140 in 500'); check($built < 30, "only the rows in view are built ($built cells)");
$h->clickOn($n['g']->kids[1]->kids[2]); same(5, $gs->selected, 'a click chooses'); same($n['g']->id, $h->rt->focus, 'and focuses the grid');
$h->down(); same(8, $gs->selected, 'Down a row'); $h->right(); same(9, $gs->selected, 'Right one'); $h->end(); same(999, $gs->selected, 'End the last'); check($gs->rows->row > 300, 'scrolled to it');
$h->up(); $h->down(); same(999, $gs->selected, 'Down from the row above the shorter last lands on the last'); $h->home(); same(0, $gs->selected, 'Home');
foreach (['i', 't', 'e', 'm', ' ', '7'] as $ch) { $h->type($ch); } same(7, $gs->selected, 'letters typed choose, Space among them');
$h->enter(); same('open 7', end($ev), 'Enter submits'); $h->clickOn($n['g']->kids[0]->kids[0]); $h->clickOn($n['g']->kids[0]->kids[0], 0, 2); same('open 0', end($ev), 'a double click too');

// Several rows and items chosen, by key.
$ls = new ListState; $ls->selected = -1; $ls->selection = new Selection; $ls->key = fn(int $i) => "row$i";
$gs = new GridState; $gs->selects = true; $gs->selection = new Selection; $n = [];
$h = new Headless(function (Context $c) use (&$n, $ls, $gs) {
    $c->row(0, 0, 1);
      $n['l'] = $c->list($ls, 20, function (int $i) use ($c) { $c->row(0, 0)->size(0, 20); $c->text("Row $i"); $c->end(); });
      $n['g'] = $c->gridView($gs, 30, 50, 40, function (int $i) use ($c) { $c->text("$i"); });
    $c->end();
}, 400, 400);
$h->frame(); $row = fn(int $i) => $n['l']->kids[$i]; $all = fn() => array_map(fn($k) => (int)substr($k, 3), $ls->selection->all());
$h->clickOn($row(2)); same([2], $all(), 'a click chooses one'); $h->clickOn($row(5), Key::SHIFT); same([2, 3, 4, 5], $all(), 'Shift-click extends'); $h->clickOn($row(4), Key::SHIFT); same([2, 3, 4], $all(), 'and shrinks');
$h->clickOn($row(8), Key::COMMAND); same([2, 3, 4, 8], $all(), 'Cmd-click adds'); $h->clickOn($row(8), Key::COMMAND); same([2, 3, 4], $all(), 'and takes out');
$h->clickOn($row(8), Key::COMMAND); $h->down(Key::SHIFT); same([2, 3, 4, 8, 9], $all(), 'Shift+Down extends from the pivot, keeping the rest'); $h->down(); same([10], $all(), 'Down alone chooses alone');
$h->key(0, 'selectAll:', Key::COMMAND, 'a'); same(20, $ls->selection->count(), 'Cmd+A chooses all');
$cell = fn(int $i) => $n['g']->kids[intdiv($i, $gs->cols)]->kids[$i % $gs->cols];
$h->clickOn($cell(1)); $h->clickOn($cell(4), Key::SHIFT); same([1, 2, 3, 4], $gs->selection->all(), 'Shift-click in the grid'); $h->clickOn($cell(7), Key::COMMAND); $h->down(Key::SHIFT);
same(3, $gs->cols, 'the grid shares the row with the list, half each: three columns'); // Basis(0): its rows never widen it
same([1, 2, 3, 4, 7, 8, 9, 10], $gs->selection->all(), 'Shift+Down in the grid extends a row down from the pivot');
$h->shortcut('a', Key::COMMAND); same(30, $gs->selection->count(), 'Cmd+A in the grid');

// Rows and items dragged to another place.
$items = range(0, 9); $ls = new ListState; $ls->selected = -1; $ls->selection = new Selection; $n = []; $ev = [];
$move = function (array &$items, array $rows, int $to): void {
    $moving = array_map(fn($r) => $items[$r], $rows); $out = []; $placed = false;
    foreach ($items as $i => $v) { if (!$placed && $i >= $to) { array_push($out, ...$moving); $placed = true; } if (!in_array($i, $rows, true)) { $out[] = $v; } }
    if (!$placed) { array_push($out, ...$moving); } $items = $out;
};
$ls->reorder = function (array $rows, int $to) use (&$items, &$ev, $move) { $ev[] = 'move ' . implode(',', $rows) . " before $to"; $move($items, $rows, $to); };
$h = new Headless(function (Context $c) use (&$n, &$items, $ls) { $c->column(0, 0, 1); $n['l'] = $c->list($ls, count($items), function (int $i) use ($c, &$items) { $c->row(0, 0)->size(0, 20); $c->text("Item {$items[$i]}"); $c->end(); }); $c->end(); }, 300, 300);
$h->frame(); $rowY = fn(int $i) => $h->rect($n['l']->kids[$i - $ls->first])[1] + 10;
$h->click(20, $rowY(1)); same(1, $ls->selected, 'a click without moving chooses'); check($ls->drag === null, 'and starts no drag');
$h->press(20, $rowY(1)); $h->drag(20, $rowY(1) + 2); check(!$ls->drag['far'], 'two points is no drag yet'); $h->drag(20, $rowY(5) - 8);
check($ls->drag['far'], 'past four it is'); same(2, count($h->root->kids), 'a copy of the row rides at the root'); same(0.4, $n['l']->kids[1]->alpha, 'the row fades where it was'); check($n['l']->drawOver !== null, 'the drop line shows');
$h->release(20, $rowY(5) - 8); same(['move 1 before 5'], $ev, 'dropped before row 5'); same([0, 2, 3, 4, 1, 5, 6, 7, 8, 9], $items, 'and moved'); same(1, count($h->root->kids), 'the copy is gone');
$h->click(20, $rowY(0)); $h->click(20, $rowY(2), Key::COMMAND); $h->press(20, $rowY(0)); $h->drag(20, $rowY(0) + 6); $h->drag(20, 298); same(3, count($h->root->kids), 'two rows dragged: the copy and a count');
$h->release(20, 298); same('move 0,2 before 10', end($ev), 'dropped past the last');
$items = range(0, 11); $gs = new GridState; $gs->selects = true; $gs->selection = new Selection; $ev = [];
$gs->reorder = function (array $rows, int $to) use (&$items, &$ev, $move) { $ev[] = 'move ' . implode(',', $rows) . " before $to"; $move($items, $rows, $to); };
$h = new Headless(function (Context $c) use (&$n, &$items, $gs) { $c->column(0, 0, 1); $n['g'] = $c->gridView($gs, count($items), 80, 50, function (int $i) use ($c, &$items) { $c->text("I{$items[$i]}"); }); $c->end(); }, 400, 300);
$h->frame(); $cr = fn(int $i) => $h->rect($n['g']->kids[intdiv($i, $gs->cols)]->kids[$i % $gs->cols]);
$r = $cr(1); $h->press($r[0] + 10, $r[1] + 10); $h->drag($r[0] + 16, $r[1] + 10); $r6 = $cr(6); $h->drag($r6[0] + $r6[2] * 0.8, $r6[1] + 10); $h->release($r6[0] + $r6[2] * 0.8, $r6[1] + 10);
same(['move 1 before 7'], $ev, 'an item dropped on the right half of a cell goes after it');
$r = $cr(0); $h->press($r[0] + 10, $r[1] + 10); $h->drag($r[0] + 16, $r[1] + 10); $h->drag(50, 250); $h->release(50, 250); same('move 0 before 12', end($ev), 'dropped below the cells: past the last');

// A hidden title bar's drag handle.
$drags = []; $count = 0; $n = [];
$h = new Headless(function (Context $c) use (&$n, &$count) {
    $c->titleBar = ['height' => 28.0, 'left' => 78.0, 'right' => 0.0]; $bar = $c->titleBar();
    $c->column(0, 0, 1);
      $n['bar'] = $c->row(0, 8, 0, 0x222222)->size(0, max($bar['height'], 32))->align(Node::CENTER)->dragWindow();
        $c->column(0, 0)->size($bar['left'], 0); $c->end(); $c->text('Title'); $c->row(0, 0, 1); $c->end(); $n['btn'] = $c->button('Count'); if ($c->clicked($n['btn'])) { $count++; }
      $c->end();
      $c->column(10, 10, 1); $c->text('body'); $c->end();
    $c->end();
});
$h->rt->dragWindow = function (int $clicks) use (&$drags) { $drags[] = $clicks; };
$h->frame(); $h->click(100, 14); same([1], $drags, 'a press on the bar drags the window'); $h->click(100, 14, 0, 2); same([1, 2], $drags, 'a double click zooms');
$h->clickOn($n['btn']); same([1, 2], $drags, 'a button in the bar keeps its press'); same(1, $count, 'and clicks'); $h->click(100, 100); same([1, 2], $drags, 'the body is no handle');

// SVG: an icon fits the font size and paints its mask in full; the renderer is tested in svg_test.
$search = SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" d="M14.5 14.5L20 20m-4-10a6 6 0 1 1-12 0a6 6 0 0 1 12 0Z"/></svg>');
same([24.0, 24.0, true], [$search->w, $search->h, $search->doc->usesCurrentColor], 'the size from the viewBox; it draws currentColor');
$seen = []; $n = [];
$h = new Headless(function (Context $c) use (&$n, $search) { $c->row(0, 8); $n['i'] = $c->icon($search); $n['a'] = $c->avatar('Ada Lovelace'); $c->end(); }, 300, 100);
$h->frame(); same([13.0, 13.0], [$n['i']->w, $n['i']->h], 'an icon is as high as the font size, square for a square viewBox');
$list = $h->paint(); $quads = []; for ($q = 0; $q < $list->n; $q++) { $quads[] = [$list->buf[$q * 16], $list->buf[$q * 16 + 2], $list->buf[$q * 16 + 7], $list->buf[$q * 16 + 8]]; }
$icon = array_values(array_filter($quads, fn($q) => $q[3] === 0.0 && $q[0] >= 0 && $q[0] < 13)); // glyph quads (kind 0) within the icon
check($icon !== [] && $icon[0][2] === 1.0, 'the icon paints its paths in full (not the box\'s alpha)');
same(['AL', 29.25, 14.625], [$n['a']->kids[0]->text, $n['a']->w, $n['a']->radius], 'an avatar: the initials, 2.25 × fontSize, round');
$art = SVG::parse('<svg viewBox="0 0 20 10"><rect width="20" height="10" fill="red"/></svg>');
$h = new Headless(function (Context $c) use (&$n, $art, $search) { $c->row(0, 8); $n['p'] = $c->image($art); $n['h'] = $c->image($art, 0, 5); $n['r'] = $c->icon($search)->rotate(45); $c->end(); }, 300, 100);
$h->frame(); same([20.0, 10.0, 10.0, 5.0, $art, 45.0], [$n['p']->w, $n['p']->h, $n['h']->w, $n['h']->h, $n['h']->svg, $n['r']->rotation], 'an SVG as an image: its own size, or scaled to a height; an icon turned');
// Fit: where a 20 × 10 picture goes in a 10 × 10 box, and what part of it shows.
$fit = fn(int $f) => array_map(fn($v) => is_array($v) ? array_map(fn($x) => round($x, 3), $v) : round($v, 3), Painter::fitIn(0, 0, 10, 10, 20, 10, $f));
same([[0.0, 2.5, 10.0, 5.0], [0.0, 0.0, 1.0, 1.0], 10.0, 5.0], $fit(Node::CONTAIN), 'contain: letterboxed, all of it');
same([[0.0, 0.0, 10.0, 10.0], [0.25, 0.0, 0.5, 1.0], 20.0, 10.0], $fit(Node::COVER), 'cover: the box, the middle half of the picture');
same([[0.0, 0.0, 10.0, 10.0], [0.0, 0.0, 1.0, 1.0], 10.0, 10.0], $fit(Node::FILL_BOX), 'fill: stretched');
same([[0.0, 2.5, 10.0, 5.0], [0.0, 0.0, 1.0, 1.0], 10.0, 5.0], $fit(Node::SCALE_DOWN), 'scale down: as contain when larger than the box');
same([[4.0, 4.5, 2.0, 1.0], [0.0, 0.0, 1.0, 1.0], 2.0, 1.0], array_map(fn($v) => is_array($v) ? array_map(fn($x) => round($x, 3), $v) : round($v, 3), Painter::fitIn(0, 0, 10, 10, 2, 1, Node::SCALE_DOWN)), 'scale down: its own size when smaller, centered');
same([[0.0, 0.0, 10.0, 10.0], [0.25, 0.0, 0.5, 1.0], 20.0, 10.0], $fit(Node::NATURAL_SIZE), 'natural size: centered, cropped');
$h = new Headless(function (Context $c) use (&$n, $art) { $n['g'] = $c->image($art, 20, 10)->grayscale(); $n['c'] = $c->image($art, 10, 10)->fit(Node::COVER); }, 300, 100);
$h->frame(); $list = $h->paint(); same([12.0, 2.0], [$list->buf[16 + 9], $list->buf[32 + 9]], 'a gray image is quad kind 12, a colored one 2 (after the root\'s background)');
same([5.0, 10.0, 10.0], [$list->buf[32 + 10], $list->buf[32 + 4], $list->buf[32 + 5]], 'cover shows the middle half of the slot, from u = 5 (fake slots are the pixel size, 20 × 10)');
same('JP', Context::initials('  jean-luc  picard '), 'initials of the first and last words');

// Images shown far smaller than their pixels come from a halving: each pixel the average of the 2 × 2 it covers; an odd last column counts twice.
$img = Image::fromPixels(3, 2, pack('C*', 0, 0, 0, 255, 100, 0, 0, 255, 200, 0, 0, 255, 0, 0, 0, 255, 100, 0, 0, 255, 200, 0, 0, 255));
check($img->smaller(1.5) === $img, 'less than twice as small: the image itself');
$half = $img->smaller(2.5); same([2, 1], [$half->w, $half->h], 'twice as small: half as large, rounded up');
$buf = Mem::alloc(8); Mem::copy(Mem::addr($buf), $half->addr, 8);
same([50, 0, 0, 255, 200, 0, 0, 255], array_values(unpack('C8', \FFI::string($buf, 8))), 'the averages: (0+100)/2, the last column twice'); Mem::free($buf);
check($img->smaller(4) === $img->smaller(5.9) && $img->smaller(4) !== $half, 'four times: the next level, kept');

// A text area that grows with its lines (mygo's Lines); a read-only field keeps its text and takes no caret.
$draft = new TextState; $log = new TextState("kept\nas is"); $log->readOnly = true; $n = [];
$h = new Headless(function (Context $c) use (&$n, $draft, $log) { $c->column(0, 8); $n['d'] = $c->textarea($draft, '', 200, 0, null, 1, 3); $n['l'] = $c->textarea($log, '', 200, 60); $c->end(); }, 400, 300);
$h->frame(); same(16.0 + 12, $n['d']->h, 'one line: a line plus the insets');
$draft->insert("a\nb\nc\nd\ne"); $h->frame(); same(16.0 * 3 + 12, $n['d']->h, 'five lines: three at most, then it scrolls');
$h->clickOn($n['l']); $h->type('x'); $h->key(Key::DELETE, 'deleteBackward:'); same("kept\nas is", $log->text, 'read-only: typing and deleting change nothing');
$h->key(0, 'selectAll:', Key::COMMAND, 'a'); same("kept\nas is", $log->selectedText(), 'but the text is selected');
$list = $h->paint(); $carets = 0; for ($q = 0; $q < $list->n; $q++) { if ($list->buf[$q * 16 + 2] === 1.0 && $list->buf[$q * 16 + 3] === 16.0) { $carets++; } }
same(0, $carets, 'and no caret shows');

// A disabled button at half its opacity: its border a ring over its face, not a rect of the border's color under it, which the dimmed face lets show through (mygo's).
$n = [];
$h = new Headless(function (Context $c) use (&$n) {
    $c->column(10);
      $n['b'] = $c->button('Clear')->disabled();
    $c->end();
}, 200, 100);
$h->frame();
$list = $h->paint(); [$bx, $by, $bw, $bh] = $h->rect($n['b']);
$kinds = [];
for ($q = 0; $q < $list->n; $q++) {
    $o = $q * 16;
    if (abs($list->buf[$o] - $bx) < 0.01 && abs($list->buf[$o + 1] - $by) < 0.01 && abs($list->buf[$o + 2] - $bw) < 0.01) { $kinds[] = [(int)$list->buf[$o + 9], round($list->buf[$o + 7], 2)]; }
}
same([[0, 0.5], [4, 0.5]], $kinds, 'the face, then its ring, both dimmed');
