<?php
// The controls right to left (mygo's direction_controls_test.go, but
// TestRTLTable, in tables_test): mirrored, their keys going the way the
// arrows point; paragraphs reading by their first strong character.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Key, Node, Role, Scrollbar, TextState};

/** The rectangles of the last paint filled with $rgb, opaque: [x, y, w, h]. @return list<array{0: float, 1: float, 2: float, 3: float}> */
function rtlFills(Headless $h, int $rgb): array
{
    $list = $h->paint(); $out = [];
    $c = [(($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255];
    for ($i = 0; $i < $list->n; $i++) {
        $k = $i * 16;
        if (fmod($list->buf[$k + 9], 16) == 0 && abs($list->buf[$k + 4] - $c[0]) < 1e-3 && abs($list->buf[$k + 5] - $c[1]) < 1e-3 && abs($list->buf[$k + 6] - $c[2]) < 1e-3 && $list->buf[$k + 7] > 0.99) {
            $out[] = [(float)$list->buf[$k], (float)$list->buf[$k + 1], (float)$list->buf[$k + 2], (float)$list->buf[$k + 3]];
        }
    }
    return $out;
}

/** The left and right ends of the glyphs of the last paint. @return array{0: float, 1: float} */
function rtlGlyphsX(Headless $h): array
{
    $list = $h->paint(); $lo = 1e9; $hi = -1e9;
    for ($i = 0; $i < $list->n; $i++) {
        if (fmod($list->buf[$i * 16 + 9], 16) == 1) { $lo = min($lo, $list->buf[$i * 16]); $hi = max($hi, $list->buf[$i * 16]); }
    }
    return [$lo, $hi];
}

/** A tester laid out right to left. */
function rtlTester(\Closure $view, float $w, float $h): Headless
{
    $t = new Headless($view, $w, $h);
    $t->rt->rightToLeft = true;
    $t->frame();
    return $t;
}

// Lines start at the right right to left, whatever they read, and End at the left.
$align = Node::START;
$h = rtlTester(function (Context $c) use (&$align): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->text('Hello')->textAlign($align);
    $c->end();
}, 300, 100);
check(rtlGlyphsX($h)[0] >= 200, 'right to left, English starts at the right');
$align = Node::END; $h->frame();
check(rtlGlyphsX($h)[1] <= 100, 'right to left, English at the end is at the left');

// A switch mirrored, its knob on the left when on; a check box's label left of its box.
$on = true; $checked = false;
$view = function (Context $c) use (&$on, &$checked): void {
    $c->column(10, 0)->align(Node::START);
      $c->switch('Wi-Fi', $on);
      $c->checkbox('Remember me', $checked);
    $c->end();
};
$knob = function (Headless $h): array {
    $s = $h->rect($h->byText('Wi-Fi'));
    foreach (rtlFills($h, 0xffffff) as $k) {
        if ($k[0] >= $s[0] && $k[0] + $k[2] <= $s[0] + $s[2] && $k[2] < 20) { return [$k, $s]; }
    }
    throw new \RuntimeException('no knob');
};
$h = new Headless($view, 300, 100); $h->frame();
[$k, $s] = $knob($h);
$track = $h->theme->space(9);
check($k[0] + $k[2] / 2 > $s[0] + $track / 2, 'on, left to right, the knob is on the right of the track');
$h = rtlTester($view, 300, 100);
[$k, $s] = $knob($h);
same(290.0, (float)($s[0] + $s[2]), 'right to left, the switch ends at 290');
check($k[0] + $k[2] / 2 < $s[0] + $s[2] - $track / 2 && $k[0] > $s[0] + $s[2] - $track, 'on, right to left, the knob is on the left of the track at the right');
$box = $h->rect($h->byText('Remember me'));
same(290.0, (float)($box[0] + $box[2]), 'right to left, the check box ends at 290');
check(rtlGlyphsX($h)[1] < 290 - 16, 'and its label is left of its box');

// A slider from the right: its fill, a click, the arrows, a press at the right.
$v = 25.0;
$h = rtlTester(function (Context $c) use (&$v): void {
    $c->column(20, 0)->align(Node::START);
      $c->slider($v, 0, 100, 220)->label('Volume');
    $c->end();
}, 260, 80);
$track = $h->rect($h->byLabel('Volume'));
$fill = rtlFills($h, $h->theme->accent);
check($fill !== [] && $fill[0][0] + $fill[0][2] >= $track[0] + $track[2] - $h->theme->space(2.5) - 1 && $fill[0][0] > $track[0] + $track[2] / 2, 'at a quarter, the fill covers its right quarter: ' . json_encode([$fill, $track]));
$h->clickOn($h->byLabel('Volume'));
same(50.0, $v, 'a click in the middle sets 50');
$h->left();
same(51.0, $v, 'Left raises it');
$h->right(); $h->right();
same(49.0, $v, 'Right twice lowers it');
$h->press($track[0] + $track[2] - 4, $track[1] + $track[3] / 2);
$h->release($track[0] + $track[2] - 4, $track[1] + $track[3] / 2);
same(0.0, $v, 'a press at the right sets 0');

// Progress bars and meters fill from the right, a reversed bar from the left.
$reverse = false;
$h = rtlTester(function (Context $c) use (&$reverse): void {
    $c->column(0, 10)->align(Node::START);
      $p = $c->progress(0.25, 200);
      if ($reverse) { $p->reverse(); }
      $c->meter(0.25, 0, 1, null, 200);
    $c->end();
}, 200, 100);
foreach ([$h->theme->accent, $h->theme->success] as $color) {
    $f = rtlFills($h, $color);
    check(count($f) === 1 && $f[0][0] == 150 && $f[0][2] == 50, 'right to left, a quarter fills 150+50: ' . json_encode($f));
}
$reverse = true; $h->frame();
$f = rtlFills($h, $h->theme->accent);
check(count($f) === 1 && $f[0][0] == 0, 'reversed right to left, from 0: ' . json_encode($f));

// A range slider's knobs from the right; Left raises the minimum.
$low = 20.0; $high = 60.0;
$h = rtlTester(function (Context $c) use (&$low, &$high): void {
    $c->rangeSlider($low, $high, 0, 100, 1, 220)->label('Price');
}, 220, 80);
$r = $h->rect($h->byLabel('Price'));
$f = rtlFills($h, $h->theme->accent);
$kw = $h->theme->space(5);
$x0 = $r[0] + $kw / 2; $span = $r[2] - $kw;
[$lowX, $highX] = [$r[0] + $r[2] - $kw / 2 - $span * 0.2, $r[0] + $r[2] - $kw / 2 - $span * 0.6];
check(count($f) === 1 && abs($f[0][0] - $highX) < 0.5 && abs($f[0][0] + $f[0][2] - $lowX) < 0.5, "right to left, the range fills $highX to $lowX: " . json_encode($f));
$h->tab(); $h->left();
same(21.0, $low, 'Left on the minimum raises it');

// The arrows go the way they point: tabs, ratings, steppers and toolbars.
$tab = 0; $stars = 2; $step = 5.0;
$h = rtlTester(function (Context $c) use (&$tab, &$stars, &$step): void {
    $c->column(0, 10)->align(Node::START);
      $c->tabs(['One', 'Two', 'Three'], $tab);
      $c->rating($stars, 5)->label('Stars');
      $c->stepper($step, 0, 10, 1)->label('Step');
      $c->toolbar(function () use ($c): void { $c->button('Cut'); $c->button('Copy'); })->label('Edit');
    $c->end();
}, 400, 300);
check($h->rect($h->byText('Two'))[0] < $h->rect($h->byText('One'))[0], 'right to left, the second tab is left of the first');
$h->clickText('One');
$h->left();
same(1, $tab, 'Left chooses the next tab');
$h->right();
same(0, $tab, 'Right the one before');
$h->tab(); $h->left();
same(3, $stars, 'Left raises the rating');
$h->tab(); $h->left();
same(6.0, (float)$step, 'Left steps up');
$h->tab();
check(str_contains(Headless::label($h->focused()), 'Cut'), 'Tab goes into the toolbar');
$h->left();
check(str_contains(Headless::label($h->focused()), 'Copy'), 'Left in the toolbar goes to Copy');
check($h->rect($h->byText('Copy'))[0] < $h->rect($h->byText('Cut'))[0], 'right to left, Copy is left of Cut');

// A tree opens with Left and closes with Right, its child indented from the right.
$open = false;
$h = rtlTester(function (Context $c) use (&$open): void {
    $c->tree(function () use ($c, &$open): void {
        $c->treeItem('Folder', $open, function () use ($c): void { $none = null; $c->treeItem('File', $none, null); });
    })->size(200, 0)->label('Files');
}, 200, 200);
$h->clickText('Folder');
$h->left();
check($open, 'Left opens the item');
$folder = $h->rect($h->byText('Folder')); $file = $h->rect($h->byText('File'));
check($file[0] + $file[2] < $folder[0] + $folder[2], 'the child ends left of its parent');
$h->right();
check(!$open, 'Right closes it');

// A split's first pane on the right: dragging the divider left, and Left, widen it.
$size = 100.0;
$h = rtlTester(function (Context $c) use (&$size): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->split($size, fn() => $c->text('First'), fn() => $c->text('Second'))->grow(1);
    $c->end();
}, 400, 200);
check($h->rect($h->byText('First'))[0] >= 300 && $h->rect($h->byText('Second'))[0] < 300, 'right to left, the first pane is at the right');
$h->press(300, 100); $h->drag(270, 100); $h->release(270, 100);
same(130.0, $size, 'dragging the divider 30 to the left widens the pane to 130');
$h->left();
check($size > 130, "Left widens it: $size");

// A popover lines up with the right of its anchor; a menu button's menu shows right to left at its right.
$open = true; $anchorN = null;
$h = rtlTester(function (Context $c) use (&$open, &$anchorN): void {
    $c->column(20, 0)->align(Node::START);
      $anchorN = $c->button('Anchor');
      $c->popover($anchorN, $open, function () use ($c): void { $c->text('Panel content')->size(200, 0); });
      $c->menuButton('Sort', function (\MyPHP\UI\Menu $m): void { $m->item('Name'); });
    $c->end();
}, 400, 300);
$a = $h->rect($anchorN); $p = $h->rect($h->byText('Panel content'));
check($a[0] + $a[2] == 380 && abs($p[0] + $p[2] - ($a[0] + $a[2])) <= 6, 'the panel lines up at the right of the anchor: ' . json_encode([$p, $a]));
$open = false; $h->frame();
$sort = $h->rect($h->byText('Sort'));
$h->clickText('Sort');
check($h->menu !== null && $h->menu['rtl'], 'the menu is right to left');
check($h->menu['at'][0] >= $sort[0] + $sort[2] - 12, 'and shows at the button\'s right: ' . json_encode([$h->menu['at'], $sort]));

// The vertical scroll bar on the left, the horizontal thumb at the right at the start.
$v = Scrollbar::thumb([0, 0, 200, 100], [0, 200, 100], 6, false, true);
$hz = Scrollbar::thumb([0, 0, 200, 100], [0, 200, 200], 6, true, true);
check($v !== null && $v[0] < 10, 'right to left, the vertical thumb at the left: ' . json_encode($v));
check($hz !== null && $hz[0] + $hz[2] >= 190, 'right to left at the start, the horizontal thumb at the right: ' . json_encode($hz));
$v = Scrollbar::thumb([0, 0, 200, 100], [0, 200, 100], 6, false, false);
check($v !== null && $v[0] >= 190, 'left to right, the vertical thumb at the right');

// An input starts at the right: its caret, its placeholder, its text.
$name = new TextState('');
$h = rtlTester(function (Context $c) use ($name): void {
    $c->column()->align(Node::START);
      $c->input($name, 'Name', 200)->label('Name field');
    $c->end();
}, 200, 100);
$field = $h->rect($h->byLabel('Name field'));
$h->clickOn($h->byLabel('Name field'));
$h->paint(); // the painter places the text, and the caret with it
$caret = $h->input->caretRect(0);
check($caret !== null && $caret[0] >= $field[0] + $field[2] - 20, 'an empty input\'s caret is at the right: ' . json_encode([$caret, $field]));
check(rtlGlyphsX($h)[0] >= 100, 'the placeholder starts at the right');
$h->type('Hello');
check(rtlGlyphsX($h)[0] >= 100, 'a short English text starts at the right');

// Assistive technology sees the frames shown, and its increment raises a slider.
$v = 30.0; $wideState = new \MyPHP\UI\ScrollState;
$h = rtlTester(function (Context $c) use (&$v, $wideState): void {
    $c->column(10, 10)->align(Node::START);
      $c->row(0, 8); $c->button('OK'); $c->button('Cancel'); $c->end();
      $c->slider($v, 0, 100, 200)->label('Volume');
      $c->row()->size(100, 30)->scroll($wideState, true, false);
        $c->button('Wide')->size(250, 0);
      $c->end();
    $c->end();
}, 400, 300);
$h->input->push(['accessOn']); $h->frame();
$tree = $h->rt->accessTree;
$node = function (int $role, string $label) use ($tree): AccessNode {
    foreach ($tree->nodes as $n) { if ($n->role === $role && $n->label === $label) { return $n; } }
    throw new \RuntimeException("no $label");
};
foreach (['OK', 'Cancel'] as $label) {
    $b = $node(Role::BUTTON, $label)->bounds; $r = $h->rect($h->byText($label));
    check($b[0] <= $r[0] && $b[0] + $b[2] >= $r[0] + $r[2], "$label: the frame shown: " . json_encode([$b, $r]));
}
$ok = $node(Role::BUTTON, 'OK')->bounds; $cancel = $node(Role::BUTTON, 'Cancel')->bounds;
check($ok[0] + $ok[2] == 390 && $cancel[0] < $ok[0], 'right to left, OK at the right, Cancel left of it');
$wide = $node(Role::BUTTON, 'Wide')->bounds;
same(390.0, (float)($wide[0] + $wide[2]), 'the wide button, scrolled to its start, ends at 390');
$h->input->push(['access', $node(Role::SLIDER, 'Volume')->id, AccessNode::INCREMENT]); $h->frame();
check($v > 30, "increment raises it: $v");

// Paragraphs without a strong character read as the node is laid out, the others by their first strong character (mygo's TestRTLParagraphs).
$sys = new \MyPHP\Text\System(new \MyPHP\Text\StubEngine());
foreach ([['123', false, false], ['123', true, true], ['', true, true], ['Hello', true, false], ['שלום', false, true], ['(1) مرحبا', false, true]] as [$s, $rtl, $want]) {
    $l = $sys->layout(new \MyPHP\Text\Params($s, new \MyPHP\Text\Style(size: 14), rtl: $rtl));
    same($want, $l->lines[0]->rtl, json_encode($s) . ($rtl ? ' right to left' : ' left to right'));
}

// The word arrows go the way they point in a right-to-left paragraph, as AppKit's (mygo's TestRTLWordArrows); the line-end ones too.
$hebrew = new TextState('שלום עולם');
$h = new Headless(function (Context $c) use ($hebrew): void { $c->input($hebrew, '', 300)->label('Field'); }, 300, 100);
$h->frame();
$h->clickOn($h->byLabel('Field'));
$hebrew->setCursor(0);
$h->key(Key::LEFT, 'moveWordLeft:', Key::OPTION);
check($hebrew->cursor > 0, 'the word arrow pointing left goes forward in a right-to-left paragraph');
$h->key(Key::LEFT, 'moveToLeftEndOfLine:', Key::COMMAND);
same(strlen($hebrew->text), $hebrew->cursor, 'the line-end arrow pointing left goes to its end');
$latin = new TextState('hello world');
$latin->setCursor(strlen('hello world'));
$latin->command('moveWordLeft:');
same(strlen('hello '), $latin->cursor, 'left to right, back a word');
