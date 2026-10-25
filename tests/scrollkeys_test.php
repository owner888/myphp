<?php
// Keys that scroll and step (mygo's ui_test.go TestKeyboardScrolling,
// list_test.go TestListPageKeys and the Page Up of
// TestListScrollsByTheStepIntoRowsNotMeasured, primitives_test.go's slider
// Page Up, indicators_test.go's stepper arrows): without a focus the keys
// scroll what the pointer is over, a focused control keeps the keys it
// takes, Page Down in a list scrolls without choosing, as AppKit's.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, ListState, ScrollState};

$pageUp = fn(Headless $h) => $h->key(Key::PAGE_UP, 'scrollPageUp:');
$space = fn(Headless $h, int $mods = 0) => $h->key(Key::SPACE, '', $mods, ' ');

// The keys scroll the container under the pointer; a focused slider takes the arrows.
$sc = new ScrollState();
$volume = 40.0;
$h = new Headless(function (Context $c) use ($sc, &$volume): void {
    $c->column(0, 0, 1);
      $c->button('Increment');
      $c->slider($volume, 0, 100)->label('Volume');
      $c->column(0, 0, 1)->scroll($sc)->label('rows');
        for ($i = 0; $i < 1000; $i++) { $c->text("Row $i")->size(0, 28); }
      $c->end();
    $c->end();
}, 640, 600);
$h->frame();
$rows = $h->rect($h->byLabel('rows'));
$h->hover($rows[0] + 10, $rows[1] + 10);
$h->down();
same(40.0, $sc->y, 'Down scrolled a line');
$h->pageDown();
same(40.0 + $rows[3] - 40, $sc->y, 'Page Down a page less a line');
$h->key(Key::END, 'scrollToEndOfDocument:');
same($sc->maxY, $sc->y, 'End to the end');
check($sc->maxY > 0 && $h->byText('Row 999') !== null, 'the last row in view');
$space($h, Key::SHIFT);
same($sc->maxY - $rows[3] + 40, $sc->y, 'Shift-Space a page back');
$h->key(Key::HOME, 'scrollToBeginningOfDocument:');
same(0.0, $sc->y, 'Home to the top');
$space($h);
same($rows[3] - 40, $sc->y, 'Space a page down');
$h->key(Key::UP, 'moveToBeginningOfDocument:', Key::COMMAND);
same(0.0, $sc->y, 'Cmd-Up to the top');
$h->clickOn($h->byLabel('Volume'));
$volume = 40.0; $h->frame();
$h->right();
same(41.0, $volume, 'the focused slider took Right');
$h->down();
same([40.0, 0.0], [$volume, $sc->y], 'and Down, which scrolled nothing');

// A slider: Page Up and Page Down by a tenth of the range, or a step when more.
$v = 50.0; $w = 50.0;
$h = new Headless(function (Context $c) use (&$v, &$w): void {
    $c->column();
      $c->slider($v, 0, 100)->label('v');
      $c->stepSlider($w, 0, 100, 25)->label('w');
    $c->end();
}, 300, 200);
$h->frame();
$h->clickOn($h->byLabel('v'));
$v = 50.0; $h->frame();
$pageUp($h);
same(60.0, $v, 'Page Up: a tenth');
$h->pageDown(); $h->pageDown();
same(40.0, $v, 'Page Down twice');
$h->clickOn($h->byLabel('w'));
$h->right(); $pageUp($h);
same(100.0, $w, 'Right and Page Up by steps of 25 from 50');

// A stepper: Left and Right step the way they point, right to left too.
$n = 5;
$h = new Headless(function (Context $c) use (&$n): void {
    $c->stepper($n, 0, 10)->label('Copies');
}, 300, 200);
$h->frame();
$h->clickOn($h->byLabel('Copies'));
$n = 5; $h->frame();
$h->right();
same(6, $n, 'Right steps up');
$h->left(); $h->left();
same(4, $n, 'Left steps down');
$h->rt->rightToLeft = true; $h->frame();
$h->left();
same(5, $n, 'right to left, Left steps up');

// Page Down in a list scrolls, as AppKit's, the choice staying.
$sel = -1;
$s = new ListState();
$s->selected = &$sel;
$h = new Headless(function (Context $c) use ($s): void {
    $c->column(0, 0, 1);
      $c->list($s, 100, function (int $i) use ($c): void { $c->text("Row $i")->size(0, 30); })->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$h->clickText('Row 1');
$first = $s->first;
$h->pageDown();
check($sel === 1 && $s->first > $first, "Page Down chose $sel, scrolled to row {$s->first}");

// Page Up keeps a line of the page before, among rows of several heights above that were never measured.
$s = new ListState();
$h = new Headless(function (Context $c) use ($s): void {
    $c->column(0, 0, 1);
      $c->list($s, 5000, function (int $i) use ($c): void { $c->box(0, $i % 5 === 0 ? 240 : 24, 0x333333)->label("r$i"); })->grow = 1;
    $c->end();
}, 300, 400);
$h->frame();
$s->scrollToEnd(); $h->frame(); // read upward from the end
$h->hover(100, 100);
for ($k = 0; $k < 5; $k++) {
    $first = $s->first;
    $was = $h->rect($h->byLabel("r$first"))[1];
    $pageUp($h);
    $at = $h->rect($h->byLabel("r$first"))[1];
    same($was + 360, $at, "Page Up $k: row $first moved down a page less a line");
}
