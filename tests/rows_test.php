<?php
// What v1.11 added to lists and controls for the gallery: a list's
// headers pinned at its top and passed over by the choice, rows chosen by
// typing their labels, the editable text of a table's rows, radio groups,
// a trimmed path, and scroll columns that keep their offsets.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, ListState, Node, Path, Role, Selection, TableColumn};

// Headers: the one of the section at the top stays there, pushed away by the next; the choice passes over them.
$ls = new ListState();
$ls->selected = 1;
$ls->header = fn(int $i): bool => $i % 10 === 0;
$ls->label = fn(int $i): string => $i % 10 === 0 ? "Day $i" : "Item $i";
$h = new Headless(function (Context $c) use ($ls) {
    $c->column(0, 0, 1);
      $c->list($ls, 100, function (int $i) use ($c, $ls) {
          $c->row(0, 0, 0, $ls->isHeader($i) ? 0xeeeeee : null)->size(0, 20);
            $c->text($ls->isHeader($i) ? "Day $i" : "Item $i");
          $c->end();
      })->size(0, 200);
    $c->end();
}, 300, 200);
$h->frame();
$list = $h->root->kids[0]->kids[0];
$pinned = $list->pinned;
check($pinned !== null && $pinned->kids[0]->text === 'Day 0', 'the first header is pinned');
same(Role::LIST_ITEM === Role::LIST_ITEM, true, 'roles stay');
same('Item 3', $list->kids[3]->label, 'a row is named by its label');
$ls->scrollBy(45); $h->frame(); $h->frame();
$list = $h->root->kids[0]->kids[0];
$top = $h->rect($list)[1];
same('Day 0', $list->pinned->kids[0]->text, 'scrolled into the first section, its header stays');
same($top, $h->rect($list->pinned)[1], 'at the top of the list');
$ls->scrollBy(140); $h->frame(); $h->frame(); // scrolled 185: Day 10 15 below the top pushes Day 0 up
$list = $h->root->kids[0]->kids[0];
same('Day 0', $list->pinned->kids[0]->text, 'before the next header reaches the top, the earlier one');
check(abs($h->rect($list->pinned)[1] - ($top - 5)) < 0.01, 'pushed up by the next header: ' . ($h->rect($list->pinned)[1] - $top));
$ls->scrollBy(20); $h->frame(); $h->frame(); // 205: Day 10 above the top
$list = $h->root->kids[0]->kids[0];
same('Day 10', $list->pinned->kids[0]->text, 'the next section takes over once its header reaches the top');
same($top, $h->rect($list->pinned)[1], 'at the top');
$ls->choose(9); $ls->choose(10);
same(11, $ls->selected, 'Down onto a header passes over it');
$ls->choose(10);
same(9, $ls->selected, 'and Up');
// Typing a label's first letters chooses the row, past headers.
$ls->scrollTo(0, false); $ls->choose(1); $h->frame();
$h->clickOn($h->byText('Item 1')); $h->frame();
$h->type('i'); $h->frame();
same(2, $ls->selected, 'typing "i" goes to the next row whose label starts with it');
$h->type('tem 5'); $h->frame();
same(5, $ls->selected, 'more letters, within a second, narrow it');

// The editable text of a table's rows: Enter edits the chosen row's, Enter keeps what was typed, Escape goes back; a double click submits.
$names = ['report.pdf', 'notes.md', 'photo.jpg'];
$ts = new ListState(); $ts->selected = 0;
$changed = 0;
$view = function (Context $c) use ($ts, &$names, &$changed) {
    $c->column(0, 0, 1);
      $c->table($ts, [new TableColumn('Name')], count($names), function (int $row, int $col) use ($c, &$names, &$changed) {
          if ($c->changed($c->editableText($names[$row]))) { $changed++; }
      })->size(0, 200);
    $c->end();
};
$h = new Headless($view, 400, 260);
$h->frame();
$h->clickOn($h->byText('notes.md')); $h->frame(2);
same(1, $ts->selected, 'a click chooses the row');
$h->enter(); $h->frame(2);
$in = $h->find($h->rt->focus);
check($in !== null && $in->kind === Node::INPUT, 'Enter edits the chosen row\'s name');
same([0, 5], $in?->edit?->selection(), 'with the name before its extension selected');
$h->type('todo'); $h->enter(); $h->frame(2);
same(['report.pdf', 'todo.md', 'photo.jpg', 1], [...$names, $changed], 'Enter keeps what was typed');
check($h->rt->focus !== null && $h->find($h->rt->focus)?->kind === Node::CONTAINER, 'and the list has the focus again');
$h->enter(); $h->frame(2);
$h->type('x'); $h->escape(); $h->frame(2);
same('todo.md', $names[1], 'Escape goes back');
check(!$ts->submitted(), 'no submit yet');
[$x, $y] = $h->middle($h->byText('photo.jpg'));
$h->click($x, $y); $h->click($x, $y, clicks: 2); $h->frame(2);
check($ts->submitted(), 'a double click on a row submits it');

// An editable text on its own: a double click edits it.
$title = 'Untitled';
$h = new Headless(function (Context $c) use (&$title) { $c->column(10, 0, 1); $c->editableText($title); $c->end(); }, 300, 100);
$h->frame();
[$x, $y] = $h->middle($h->byText('Untitled'));
$h->click($x, $y); $h->click($x, $y, clicks: 2); $h->frame(2);
$h->type('Draft'); $h->enter(); $h->frame(2);
same('Draft', $title, 'a double click edits it, Enter keeps it');

// A radio group: one stop of Tab, the arrows choose.
$size = 'Small';
$h = new Headless(function (Context $c) use (&$size) {
    $c->column(10, 0, 1);
      $c->radioGroup(function () use ($c, &$size) { foreach (['Small', 'Medium', 'Large'] as $o) { $c->radio($o, $size, $o); } }, row: true)->label('Size');
      $c->button('After');
    $c->end();
}, 400, 100);
$h->frame();
same(2, count($h->tabStops()), 'the group is one stop, then the button');
$h->rt->focus = null; $h->tab(); $h->frame();
same('RD(Small)', Headless::label($h->focused()), 'Tab goes to the one chosen');
$h->right(); $h->frame();
same('Medium', $size, 'Right chooses the next');
same(Role::RADIO_GROUP, $h->byLabel('Size')?->role, 'seen as a radio group');

// A trimmed path keeps the part of its length asked for, and a stroke shorter than its width fades.
$p = (new Path())->moveTo(0, 0)->lineTo(100, 0)->trim(0, 0.5);
same([[Path::MOVE, 0.0, 0.0], [Path::LINE, 50.0, 0.0]], array_map(fn($c) => array_slice($c, 0, 3), $p->cmds), 'half a line');
same(50.0, $p->kept, 'its length');
$p = (new Path())->moveTo(0, 0)->lineTo(10, 0)->lineTo(10, 10)->trim(0.25, 0.75);
same([[Path::MOVE, 5.0, 0.0], [Path::LINE, 10.0, 0.0], [Path::LINE, 10.0, 5.0]], array_map(fn($c) => array_slice($c, 0, 3), $p->cmds), 'across a corner');
$c = (new Path())->circle(0, 0, 10)->trim(0, 1);
check(abs($c->kept - 2 * M_PI * 10) < 0.05, 'a circle, flattened: ' . $c->kept);
$p = (new Path())->moveTo(10, 10)->lineTo(10, 10.5)->lineTo(30, 10.5)->trim(0, 0.05); // a point of 20.5
$h = new Headless(function (Context $c) use ($p) {
    $c->box(100, 100, 0)->draw(function ($list, $n, $t, $text) use ($p) { (new \MyPHP\UI\Painter($list, $text, $t))->strokePath($p, 4, 0xff0000); });
}, 200, 200);
$h->frame();
$l = $h->paint();
$alpha = null;
for ($i = 0; $i < $l->n; $i++) { if (fmod($l->buf[$i * 16 + 9], 16) == 1) { $alpha = round($l->buf[$i * 16 + 7], 3); } }
same(0.256, $alpha, 'a stroke a point long and 4 wide at about a quarter');
$p->cap(Path::CAP_BUTT); $h->frame(); $l = $h->paint(); $alpha = null;
for ($i = 0; $i < $l->n; $i++) { if (fmod($l->buf[$i * 16 + 9], 16) == 1) { $alpha = round($l->buf[$i * 16 + 7], 3); } }
same(1.0, $alpha, 'with butt caps, which show no dot, it does not fade');

// A scroll column keeps its offset while it shows.
$h = new Headless(function (Context $c) {
    $c->scroll(0, 0, 1)->size(0, 100);
      for ($i = 0; $i < 30; $i++) { $c->text("Line $i")->size(0, 20); }
    $c->end();
}, 200, 100);
$h->frame();
$sc = $h->root->kids[0];
$sc->scroll->scrollBy(60); $h->frame();
same(60.0, $h->root->kids[0]->scroll->y, 'the offset stays the next frame');
