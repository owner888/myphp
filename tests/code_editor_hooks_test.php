<?php
// What an app hooks into a code editor (mygo's hooks_test.go and
// line_backgrounds_test.go): its input first, the lines in view, the
// shapes of carets, input methods off, chords, and lines' backgrounds.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{InputEvent, Key, TextInputRange};
use MyPHP\UI\Editor\{Binding, Command, CursorShape, Edit, LineBackground, State, Theme};

require_once __DIR__ . '/EditorTest.php';

$edOf = fn(State $st) => (fn() => $this->ed)->call($st);

// onInput sees keys and typed text before the editor: what it takes the editor leaves, the rest goes on as without it.
$ct = new EditorTest('', 'ab|');
$texts = 0; $keys = 0;
$ct->st->onInput = function (InputEvent $ev) use (&$texts, &$keys): bool {
    if ($ev->kind === InputEvent::TEXT) { $texts++; return $ev->text === 'x'; }
    if ($ev->kind === InputEvent::KEY_DOWN) { $keys++; return $ev->key === Key::DELETE; }
    return false;
};
$ct->h->frame();
$ct->typ('x');
$ct->key(0, Key::DELETE);
$ct->want('ab|', 'what onInput takes the editor leaves');
$ct->typ('y');
$ct->key(0, Key::LEFT);
$ct->want('ab|y', 'the rest goes on');
same([2, 2], [$texts, $keys], 'onInput saw two texts and two keys');

// onInput hears of an event once, even when what it does with one it leaves, as edit the buffer, has the editor run its own handling again on the text as it is.
$ct = new EditorTest('', 'ab|');
$calls = 0;
$ct->st->onInput = function (InputEvent $ev) use ($ct, &$calls): bool {
    if ($ev->kind === InputEvent::TEXT) {
        $calls++;
        $ct->st->buffer->applyEdits(new Edit(0, 0, '>'));
    }
    return false;
};
$ct->h->frame();
$ct->typ('y');
$ct->want('>aby|', 'the editor types on the text as the app edited it');
same(1, $calls, 'onInput heard of the text once');

// visibleLines are the lines the last frame showed, scrolled or not.
$ct = new EditorTest('', str_repeat("line\n", 200));
$lh = $edOf($ct->st)->view->lineHeight;
same([0, (int)(400 / $lh)], $ct->st->visibleLines(), 'the lines in view');
$ct->st->scrollTo(50);
$ct->h->frame();
same([50, 50 + (int)(400 / $lh)], $ct->st->visibleLines(), 'scrolled');

// Carets red on white, for the tests to find them.
$red = new Theme;
$red->c = ['background' => [0xffffff, 1.0], 'cursor' => [0xff0000, 1.0]];

// A block caret covers the character after it, or a space's width at the end of a line, in the color of carets made translucent; an underline caret underlines it.
$ct = new EditorTest('', "|abc\nab");
$ct->st->theme = $red; $ct->st->noActiveLine = true;
$ct->h->frame();
$ed = $edOf($ct->st);
$cw = $ed->view->charWidth; $lh = $ed->view->lineHeight;
$isRed = function (int $line, int $col, float $y) use ($ct, $ed, $cw, $lh): bool {
    [$r, $g] = $ct->pixel($ed->textX + ($col + 0.6) * $cw, $line * $lh + $y);
    return $r > 200 && $g < 230;
};
$inBlock = fn(int $line, int $col) => $isRed($line, $col, 2);
check(!$inBlock(0, 0), 'a bar caret paints no block');
$ct->st->cursorShape = CursorShape::CursorBlock;
$ct->h->frame();
check($inBlock(0, 0) && !$inBlock(0, 1), 'the block caret covers the first character alone');
[$r, $g, $b] = $ct->pixel($ed->textX + 0.6 * $cw, 2);
check($r >= 250 && $g >= 60 && $g === $b, "the block caret is translucent red ($r, $g, $b)");
$ct->st->select(7, 7);
$ct->h->frame();
check($inBlock(1, 2) && !$inBlock(1, 3) && !$inBlock(0, 0), 'at the end of a line, a space\'s width');
$ct->st->cursorShape = CursorShape::CursorUnderline;
$ct->st->select(0, 0);
$ct->h->frame();
check($isRed(0, 0, $lh - 1) && !$isRed(0, 0, 2) && !$isRed(0, 1, $lh - 1), 'the underline caret underlines the first character alone');
$ct->st->cursorShape = CursorShape::CursorBlock;
$ct->h->rt->focus = null; // the window lost the keyboard
$ct->h->frame();
check(!$ct->st->focused() && !$inBlock(0, 0) && $isRed(0, 0, 0.5) && $isRed(0, 0, $lh - 0.5), 'without the keyboard, a block is outlined, not filled');

// A block caret covers a whole character, with its combining marks.
$ct = new EditorTest('', "|e\u{0301}x");
$ct->st->theme = $red; $ct->st->noActiveLine = true; $ct->st->cursorShape = CursorShape::CursorBlock;
$ct->h->frame();
$ed = $edOf($ct->st);
$l = $ed->view->line(0);
$width = (fn() => $this->caretWidth($l, $l->rows[0], 0, $this->view->xOf($l, $l->rows[0], 0)))->call($ed);
same($ed->view->xOf($l, $l->rows[0], $l->toDisp(3, false)), $width, 'the block caret is as wide as é');

// With noInputMethod, the editor turns the system's input methods off while it takes text: keys type text, which comes as TEXT for onInput, and nothing is composed.
$ct = new EditorTest('', 'ab|');
$ct->st->noInputMethod = true;
$typed = [];
$ct->st->onInput = function (InputEvent $ev) use (&$typed): bool {
    if ($ev->kind === InputEvent::TEXT) { $typed[] = $ev->text; return true; }
    return false;
};
$ct->h->frame();
check($ct->h->input->client !== null && !$ct->h->input->inputMethod, 'text input on, input methods off');
$ct->h->compose('に', 1);
$ct->want('ab|', 'nothing is composed');
$ct->key(0, Key::J, 'j');
$ct->want('ab|', 'the key typed text, which onInput took');
same(['j'], $typed, 'onInput saw the text');
$ct->st->noInputMethod = false;
$ct->h->frame();
check($ct->h->input->client !== null && $ct->h->input->inputMethod, 'back on');
$ct->h->compose('に', 1);
$ct->want('abに|', 'input methods compose in the text');

// An input method replacing text other than the selection, as the accents of press and hold replace the letter before the caret, edits the text itself: onInput hears only of text typed at the carets.
$ct = new EditorTest('', 'cafe|');
$typed = [];
$ct->st->onInput = function (InputEvent $ev) use (&$typed): bool {
    if ($ev->kind === InputEvent::TEXT) { $typed[] = $ev->text; }
    return false;
};
$ct->h->frame();
$ed = $edOf($ct->st);
$ed->replaceText(new TextInputRange(3, 4), 'é');
$ct->want('café|', 'the accent replaces the letter');
$ed->replaceText(new TextInputRange(4, 4), '!');
$ct->want('café!|', 'text typed at the caret');
same(['!'], $typed, 'onInput saw the text typed at the caret alone');

// The key after the first of a chord is the chord's, not onInput's.
$ct = new EditorTest('', 'ab|');
$keys = [];
$ct->st->onInput = function (InputEvent $ev) use (&$keys): bool {
    if ($ev->kind === InputEvent::KEY_DOWN) { $keys[] = $ev->key; }
    return false;
};
$ct->st->bindings = [new Binding(Key::COMMAND, Key::K, Key::COMMAND, Key::U, Command::UPPERCASE)];
$ct->h->frame();
$ct->st->select(0, 2);
$ct->key(Key::COMMAND, Key::K, 'k');
$ct->key(Key::COMMAND, Key::U, 'u');
$ct->key(0, Key::RIGHT);
$ct->want('AB|', 'the chord ran');
same([Key::K, Key::RIGHT], $keys, 'onInput saw K and Right');

// Line backgrounds fill the rows of their lines across the text, not the gutter: those a fold hides take no rows, and a wrapped line's every row.
$white = new Theme;
$white->c = ['background' => [0xffffff, 1.0]];
$ct = new EditorTest('go', "func a() {\n\tx()\n\ty()\n}\nfunc b() {}\nz|");
$ct->st->theme = $white; $ct->st->noActiveLine = true;
$ct->st->lineBackgrounds = [new LineBackground(0, 4, [0x0000ff, 1.0])];
$ct->h->frame();
$ed = $edOf($ct->st);
$filled = function (float $x, int $row) use ($ct, $ed): bool {
    [$r, , $b] = $ct->pixel($x, $row * $ed->view->lineHeight + 2);
    return $b > 200 && $r < 60;
};
$rows = fn(float $x, int $n) => array_map(fn(int $row) => $filled($x, $row), range(0, $n - 1));
same([true, true, true, true, true, false], $rows(500, 6), 'the rows of the lines filled');
check(!$filled(2, 0), 'not the gutter');
$ct->st->fold(0);
$ct->h->frame();
same([true, true, true, false], $rows(500, 4), 'folded, the lines hidden take no rows');
$ct = new EditorTest('', str_repeat('x', 150) . "\nz|");
$ct->st->theme = $white; $ct->st->noActiveLine = true; $ct->st->softWrap = true;
$ct->st->lineBackgrounds = [new LineBackground(0, 0, [0x0000ff, 1.0])];
$ct->h->frame();
$ct->h->frame();
$ed = $edOf($ct->st);
$filled = function (float $x, int $row) use ($ct, $ed): bool {
    [$r, , $b] = $ct->pixel($x, $row * $ed->view->lineHeight + 2);
    return $b > 200 && $r < 60;
};
same(2, count($ed->view->line(0)->rows), 'the line wrapped into rows of 7-point characters');
same([true, true, false], array_map(fn(int $row) => $filled(300, $row), [0, 1, 2]), 'wrapped, the line fills its rows');
