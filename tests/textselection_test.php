<?php
// Selecting and copying text that is not edited (mygo's
// textselection_test.go): the paragraphs of a Selectable container, drags,
// double and triple clicks, Shift with the keys, Copy and Select All, scopes,
// controls and Unselectable text, rebuilds and the highlight.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Transfer\{Data, Format, Item, Representation};
use MyPHP\UI\{Context, Key, Node, ScrollState, Span, TextState};

require_once __DIR__ . '/SelectionTest.php';

// Double and triple clicks take words and lines, and a drag goes on by them.
$h = new Headless(function (Context $c) {
    $c->column(12, 8)->selectable();
      $c->text('Alpha beta');
      $c->text('Gamma delta');
    $c->end();
}, 300, 150);
$h->frame();
foreach ([2 => "beta\nGamma", 3 => "Alpha beta\nGamma delta"] as $unit => $want) {
    selectBetween($h, 'Alpha beta', 7, 'Gamma delta', 2, $unit);
    cmdKey($h, 'c');
    same($want, clip($h), "a $unit-click drag copies");
}

// The topmost paragraph takes the drag; Select All copies all, in build order, the empty one included.
$h = new Headless(function (Context $c) {
    $c->column()->selectable()->size(300, 150);
      $c->text('Built first')->at(0, 80);
      $c->text('Underneath')->size(0, 30);
      $c->text('On top')->at(0, 0)->size(250, 0);
      $c->text('');
    $c->end();
}, 400, 200);
$h->frame();
selectBetween($h, 'On top', 0, 'On top', 6);
cmdKey($h, 'c');
same('On top', clip($h), 'the covered paragraph does not take the drag');
cmdKey($h, 'a'); cmdKey($h, 'c');
same("Built first\nUnderneath\nOn top\n", clip($h), 'Select All keeps build order and the empty paragraph');

// A drag near a scrolling container's edge scrolls it; Select All takes the paragraphs out of view too.
$scroll = new ScrollState();
$paras = array_map(fn($i) => sprintf('Paragraph %02d', $i), range(0, 11));
$h = new Headless(function (Context $c) use ($scroll, $paras) {
    $c->column(0, 8)->scroll($scroll)->selectable()->size(300, 120);
      foreach ($paras as $p) { $c->text($p); }
    $c->end();
}, 400, 200);
$h->frame();
[$x, $y] = selectableAt($h, $paras[0], 0);
$h->press($x, $y);
$h->drag(280, 140); // outside the viewport, below the last text that shows
check($scroll->y > 0, 'dragging near the edge scrolls');
for ($i = 0; $i < 20 && $scroll->y < $scroll->maxY; $i++) { $h->frame(1); }
$h->release(280, 140);
cmdKey($h, 'c');
same(implode("\n", $paras), clip($h), 'scrolling keeps the anchor and selects what came into view');
[$x, $y] = selectableAt($h, $paras[11], 3);
$h->click($x, $y);
cmdKey($h, 'a'); cmdKey($h, 'c');
same(implode("\n", $paras), clip($h), 'Select All copies the paragraphs out of view');

// A paragraph with text inside it keeps its selection as it is built again.
$h = new Headless(function (Context $c) {
    $c->richText([])->selectable()->children(function () use ($c) {
        $c->text('First ');
        $c->text('second')->bold();
    });
}, 300, 100);
$h->frame();
selectBetween($h, 'First second', 0, 'First second', 5);
$h->frame();
cmdKey($h, 'c');
same('First', clip($h), 'rebuilding the text inside a paragraph keeps its selection');

// Across paragraphs, either way; each paints its highlight; the menus' Copy copies.
[$first, $middle, $last] = ['First paragraph.', 'A styled paragraph.', 'Second paragraph.'];
$h = new Headless(function (Context $c) use ($first, $last) {
    $c->column(20, 12)->selectable();
      $c->text($first);
      $c->column();
        $c->richText([new Span('A styled ', weight: 700), new Span('paragraph.')]);
      $c->end();
      $c->text($last);
    $c->end();
}, 400, 200);
$h->theme->selection = 0xb4d2fa; $h->theme->selectionAlpha = 1.0;
$h->frame();
foreach ([false, true] as $reverse) {
    $reverse ? selectBetween($h, $last, 6, $first, 6) : selectBetween($h, $first, 6, $last, 6);
    cmdKey($h, 'c');
    same("paragraph.\nA styled paragraph.\nSecond", clip($h), ($reverse ? 'backwards' : 'forwards') . ' across three paragraphs');
    $list = $h->paint(); $lit = [];
    for ($q = 0; $q < $list->n; $q++) {
        if (abs($list->buf[$q * 16 + 4] - 0xb4 / 255) < 0.01 && abs($list->buf[$q * 16 + 6] - 0xfa / 255) < 0.01) {
            foreach ([$first, $middle, $last] as $p) {
                $n = selectableNode($h->root, $p);
                if ($list->buf[$q * 16 + 1] >= $n->y - 0.5 && $list->buf[$q * 16 + 1] < $n->y + $n->h) { $lit[$p] = true; }
            }
        }
    }
    same(3, count($lit), 'each selected paragraph paints its highlight, those without the focus too');
}
setClip($h, 'menu command');
$h->input->push(['edit', 'copy']); $h->frame();
same("paragraph.\nA styled paragraph.\nSecond", clip($h), "the menus' Copy copies the selection");
cmdKey($h, 'a'); cmdKey($h, 'c');
same("$first\n$middle\n$last", clip($h), 'Select All');
$h->type('replacement');
$h->input->push(['edit', 'paste']); $h->input->push(['edit', 'cut']); $h->frame();
check($h->byText($first) !== null || selectableNode($h->root, $first) !== null, 'selectable text is not edited');

// Shift with the keys extends the selection across paragraphs.
$h = new Headless(function (Context $c) {
    $c->column(20, 10)->selectable();
      $c->text('Alpha');
      $c->text('Beta');
    $c->end();
}, 300, 150);
$h->frame();
$shift = fn(string $sel, int $code) => $h->key($code, $sel . 'AndModifySelection:', Key::SHIFT);
selectBetween($h, 'Alpha', 5, 'Alpha', 5);
$shift('moveRight', Key::RIGHT); // the paragraph boundary
$shift('moveRight', Key::RIGHT); // B
cmdKey($h, 'c');
same("\nB", clip($h), 'extending right crosses the boundary');
$shift('moveLeft', Key::LEFT);
cmdKey($h, 'c');
same("\n", clip($h), 'and back left');
selectBetween($h, 'Beta', 0, 'Beta', 0);
$shift('moveLeft', Key::LEFT); $shift('moveLeft', Key::LEFT);
cmdKey($h, 'c');
same("a\n", clip($h), 'extending backwards');
selectBetween($h, 'Alpha', 2, 'Alpha', 2);
[$x, $y] = selectableAt($h, 'Beta', 2);
$h->click($x, $y, Key::SHIFT);
cmdKey($h, 'c');
same("pha\nBe", clip($h), 'Shift-click extends');
selectBetween($h, 'Alpha', 0, 'Alpha', 0);
$shift('moveDown', Key::DOWN);
$shift('moveToEndOfDocument', Key::END);
cmdKey($h, 'c');
same("Alpha\nBeta", clip($h), 'extending down, then to the end');


// Scopes: controls keep their presses, nested containers their own selection, Unselectable and disabled text none.
$value = new TextState('editable'); $clicks = 0; $n = [];
$h = new Headless(function (Context $c) use ($value, &$clicks, &$n) {
    $c->column(0, 8)->selectable();
      $c->text('Outer start');
      if ($c->clicked($n['button'] = $c->button('Press me'))) { $clicks++; }
      $n['input'] = $c->input($value);
      $c->column()->selectable();
        $c->text('Inner first');
        $c->text('Inner second');
      $c->end();
      $d = $c->column(); $d->disabled = true; $c->text('Disabled'); $c->end();
      $n['excluded'] = $c->text('Excluded')->unselectable();
      $c->column()->unselectable(); $c->text('Excluded child'); $c->end();
      $c->text('Outer end');
    $c->end();
    $c->text('Outside')->selectable();
}, 400, 450);
$h->frame();
selectBetween($h, 'Outer start', 0, 'Outer end', 9);
cmdKey($h, 'a'); cmdKey($h, 'c');
same("Outer start\nOuter end", clip($h), 'the outer container: not the inner one, the controls nor the excluded text');
setClip($h, 'kept');
$h->clickOn($n['excluded']);
cmdKey($h, 'c');
same('kept', clip($h), 'a press on excluded text starts no selection');
$h->hover(...$h->middle($n['excluded']));
same(\MyPHP\UI\Cursor::Default, $h->rt->cursor, 'excluded text has the arrow');
[$x, $y] = selectableAt($h, 'Outer end', 2);
$h->hover($x, $y);
same(\MyPHP\UI\Cursor::Text, $h->rt->cursor, 'selectable text the I-beam');
selectBetween($h, 'Inner first', 0, 'Inner second', 12);
cmdKey($h, 'a'); cmdKey($h, 'c');
same("Inner first\nInner second", clip($h), 'the inner container copies its own');
$h->clickOn($n['button']);
same(1, $clicks, 'the button still clicks');
setClip($h, 'kept');
cmdKey($h, 'c');
same('kept', clip($h), 'copy after the focus left the selection changes nothing');
$h->clickOn($n['input']);
$h->type('changed');
check(str_contains($value->text, 'changed'), 'the input edits its own text');
selectBetween($h, 'Outside', 0, 'Outside', 7);
cmdKey($h, 'a'); cmdKey($h, 'c');
same('Outside', clip($h), 'a selectable text alone');

// Unicode, a link inside a paragraph, a rebuild at another width; a change to the text, or an endpoint gone, clears it.
$firstText = 'café 日本語 🎉'; $shown = true; $width = 300.0;
$h = new Headless(function (Context $c) use (&$firstText, &$shown) {
    $c->column(12, 8)->selectable();
      if ($shown) { $c->key('first')->text($firstText); }
      $c->key('rich')->richText([])->selectable()->children(function () use ($c) {
          $c->text('Read ');
          $c->link('the guide', 'https://example.com');
          $c->text(' and more.');
      });
    $c->end();
}, 300, 180);
$h->frame();
$rich = 'Read the guide and more.';
selectBetween($h, $firstText, 5, $rich, 14);
$h->frame();
cmdKey($h, 'c');
same("日本語 🎉\nRead the guide", clip($h), 'runes of Unicode, and a link inside a paragraph');
$h->w = 110; $h->frame(3);
cmdKey($h, 'c');
same("日本語 🎉\nRead the guide", clip($h), 'reflowing keeps the selection');
$h->w = 300;
$firstText = 'different text'; $h->frame();
setClip($h, 'kept');
cmdKey($h, 'c');
same('kept', clip($h), 'a change to selected text clears the selection');
selectBetween($h, $firstText, 0, $rich, 14);
$shown = false; $h->frame();
cmdKey($h, 'c');
same('kept', clip($h), 'so does removing an endpoint');

// Text changing elsewhere keeps it; text growing at the end too.
$other = 'Unselected paragraph'; $grow = 'Selected last';
$h = new Headless(function (Context $c) use (&$other, &$grow) {
    $c->column(0, 8)->selectable();
      $c->text($other);
      $c->text('Selected first');
      $c->text($grow);
    $c->end();
}, 300, 200);
$h->frame();
selectBetween($h, 'Selected first', 0, 'Selected last', 13);
$other = 'Updated elsewhere'; $grow = 'Selected last, and more'; $h->frame();
cmdKey($h, 'c');
same("Selected first\nSelected last", clip($h), 'changing other text, or growing at the end, keeps the selection');

// The highlight shows only in the key window, and a disabled endpoint clears the selection.
$disabled = false;
$h = new Headless(function (Context $c) use (&$disabled) {
    $c->column(0, 8)->selectable();
      $t = $c->text('First'); $t->disabled = $disabled;
      $c->text('Last');
    $c->end();
}, 200, 100);
$h->theme->selection = 0x0a1e46; $h->theme->selectionAlpha = 1.0;
$highlighted = function () use ($h): bool {
    $list = $h->paint();
    for ($q = 0; $q < $list->n; $q++) {
        if (abs($list->buf[$q * 16 + 4] - 0x0a / 255) < 0.01 && abs($list->buf[$q * 16 + 5] - 0x1e / 255) < 0.01 && abs($list->buf[$q * 16 + 6] - 0x46 / 255) < 0.01) { return true; }
    }
    return false;
};
$h->frame();
selectBetween($h, 'First', 0, 'Last', 4);
check($highlighted(), 'the selection is highlighted');
$h->rt->windowFocus = false; $h->frame();
check(!$highlighted(), 'not while the window is not key');
$h->rt->windowFocus = true; $h->frame();
check($highlighted(), 'again once it is');
$disabled = true; $h->frame();
check(!$highlighted(), 'disabling an endpoint leaves no highlight');
setClip($h, 'kept');
cmdKey($h, 'a'); cmdKey($h, 'c');
same('kept', clip($h), 'nor a selection to copy');

// SelectedTexts and CopyData: the parts, and the data Copy puts on the clipboard.
$box = null; $parts = [];
$h = new Headless(function (Context $c) use (&$box, &$parts) {
    $box = $c->column(0, 8)->selectable()->copyData(function (array $ps) use (&$parts): Data {
        $parts = $ps;
        return new Data(new Item(Representation::bytes(Format::TEXT, implode("\n\n", array_map(fn($p) => mb_substr($p->text, $p->start, $p->end - $p->start), $ps))), Representation::bytes(Format::HTML, '<p>x</p>')));
    });
      $c->text('One two');
      $c->text('Three');
    $c->end();
}, 300, 150);
$h->frame();
selectBetween($h, 'One two', 4, 'Three', 3);
$got = array_map(fn($p) => [$p->text, $p->start, $p->end], $box->selectedTexts());
same([['One two', 4, 7], ['Three', 0, 3]], $got, 'selectedTexts gives the parts');
cmdKey($h, 'c');
same(2, count($parts), 'Copy gives copyData the parts');
same("two\n\nThr", clip($h), 'and puts what it made on the clipboard');
