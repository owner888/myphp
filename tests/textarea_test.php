<?php
// Text areas and inputs (mygo's textarea_test.go TestTextAreaUndo,
// TestTextAreaUndoAppChanges, TestTextAreaUndoAppLog, TestTextAreaScrolls,
// TestTextAreaRevealsWrapped, TestTextAreaKeepsViewOnAppText,
// TestTextAreaLinesFollowWrappedText, TestTextSelection,
// TestInputShowsItsStartUnfocused, TestInputTextAlign, TestTextRanges):
// undo step by step, the app's changes
// undone with the typing, the wheel and the caret, the lines a text area
// grows to, the app moving the caret.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Node, TextState};

/** A window of one text area editing $s, focused by a click. */
function taWindow(TextState $s, ?\Closure $after = null, int $w = 400, int $h = 300): Headless
{
    $t = new Headless(function (Context $c) use ($s, $after): void {
        $c->column(0, 0, 1)->align(Node::STRETCH);
          $c->textarea($s, '', 0, 0)->label('Area');
        $c->end();
        if ($after !== null) { $after(); }
    }, $w, $h);
    $t->paints = true; // the painter scrolls the area to its caret
    $t->frame();
    $t->click(20, 15);
    return $t;
}

// Edits at random, then every one undone, then redone.
$s = new TextState("first line\nsecond line\nthird");
$h = taWindow($s);
mt_srand(56);
$texts = [$s->text];
$moves = [[Key::LEFT, 'moveLeft:'], [Key::RIGHT, 'moveRight:'], [Key::UP, 'moveUp:'], [Key::DOWN, 'moveDown:'], [Key::HOME, 'moveToBeginningOfLine:'], [Key::END, 'moveToEndOfLine:']];
for ($k = 0; $k < 60; $k++) {
    switch (mt_rand(0, 3)) {
        case 0: [$code, $sel] = $moves[mt_rand(0, 5)]; $h->key($code, $sel); continue 2;
        case 1: $h->type("xy\nz"); break;
        case 2: $h->key(Key::DELETE, 'deleteBackward:'); break;
        case 3: $h->key(Key::LEFT, 'moveLeftAndModifySelection:', Key::SHIFT); $h->key(Key::UP, 'moveUpAndModifySelection:', Key::SHIFT); $h->type('é'); break;
    }
    $h->key(Key::LEFT, 'moveLeft:'); $h->key(Key::RIGHT, 'moveRight:'); // moving the caret ends a step of typing
    $texts[] = $s->text;
}
$final = $s->text;
$ok = true;
for ($i = count($texts) - 2; $i >= 0; $i--) {
    $s->undo(); $h->frame();
    if ($s->text !== $texts[$i]) { $ok = false; echo "  undo to step $i: " . json_encode($s->text) . ', want ' . json_encode($texts[$i]) . "\n"; break; }
}
check($ok, 'each edit undone in turn');
for ($i = 0; $i < count($texts) - 1; $i++) { $s->redo(); }
same($final, $s->text, 'redoing everything');

// The app reformatting what is typed: undo takes its change back with the typing.
$s = new TextState('');
$h = taWindow($s, function () use ($s): void { $s->text = strtoupper($s->text); });
$h->type('ab'); $h->frame();
same('AB', $s->text, 'typed, and the app made it upper case');
$s->undo(); $h->frame();
same('', $s->text, 'undo takes both back');

// The app adding to the text, as a log does, after typing: undo takes it all back.
$s = new TextState('');
$h = taWindow($s);
$h->type('x'); $h->frame();
for ($k = 0; $k < 100; $k++) { $s->text .= "a line of the log\n"; $h->frame(); }
$s->undo(); $h->frame();
same('', $s->text, 'undo goes back before the typing');

// A long text area: the wheel scrolls it and the view stays; a caret moved shows; the end of the text.
$lines = '';
for ($i = 0; $i < 2000; $i++) { $lines .= 'line ' . str_repeat('x', $i % 7) . "\n"; }
$s = new TextState($lines);
$h = taWindow($s);
$h->key(Key::UP, 'moveToBeginningOfDocument:', Key::COMMAND);
$h->input->mouse(100, 100);
$h->input->push(['scroll', 100, 100, 0, -5000]); $h->frame();
check($s->scrollY >= 4000, "the wheel scrolled it to {$s->scrollY}");
$h->frame();
check($s->scrollY >= 4000, 'the view stays where the wheel put it, the caret above it');
$h->key(Key::DOWN, 'moveDown:');
check($s->scrollY <= 100, "the caret moved shows: the view is at {$s->scrollY}");
$h->key(Key::DOWN, 'moveToEndOfDocument:', Key::COMMAND);
$area = $h->rect($h->byLabel('Area'));
[, $cy] = $s->lastLayout()->caret($h->metrics, $s->cursor);
check($s->cursor === strlen($lines) && $cy >= $s->scrollY && $cy + $s->lastLayout()->lineHeight <= $s->scrollY + $area[3], 'the end of the text in view');

// The app adding a line leaves the view where the wheel put it.
$s = new TextState(str_repeat("a line of the log\n", 2000));
$h = taWindow($s);
$h->key(Key::UP, 'moveToBeginningOfDocument:', Key::COMMAND);
$h->input->mouse(100, 100);
$h->input->push(['scroll', 100, 100, 0, -5000]); $h->frame();
$was = $s->scrollY;
$s->text .= "another line\n"; $h->frame();
same($was, $s->scrollY, 'the view stays as the app adds a line');

// The caret shows when the lines above it wrap, long ones after short ones.
$s = new TextState(str_repeat("short\n", 2000) . str_repeat(str_repeat('word ', 80) . "\n", 300) . 'end');
$h = taWindow($s);
$shown = function () use ($h, $s): bool {
    $area = $h->rect($h->byLabel('Area'));
    [, $cy] = $s->lastLayout()->caret($h->metrics, $s->cursor);
    return $cy >= $s->scrollY && $cy + $s->lastLayout()->lineHeight <= $s->scrollY + $area[3];
};
$h->key(Key::UP, 'moveToBeginningOfDocument:', Key::COMMAND);
check($shown(), 'the caret at the start shows');
$h->key(Key::DOWN, 'moveToEndOfDocument:', Key::COMMAND);
check($shown(), 'the caret at the end shows');

// As high as the text wraps, from its least lines to its most, past which it scrolls.
$draft = new TextState('');
$h = new Headless(function (Context $c) use ($draft): void {
    $c->column()->size(200, 0);
      $c->textarea($draft, '', 200, 0, minLines: 1, maxLines: 4)->label('Draft');
    $c->end();
}, 400, 600);
$h->frame();
$empty = $h->rect($h->byLabel('Draft'))[3];
check($empty > 0, "an empty text area is $empty high");
$draft->text = 'one line'; $h->frame();
same($empty, $h->rect($h->byLabel('Draft'))[3], 'one short line: as high');
$draft->text = str_repeat('word ', 12); $h->frame();
$wrapped = $h->rect($h->byLabel('Draft'))[3];
check($wrapped > $empty * 1.5, "a paragraph that wraps is $wrapped high");
$draft->text = str_repeat('word ', 400); $h->frame();
$long = $h->rect($h->byLabel('Draft'))[3];
$line = $draft->lastLayout()->lineHeight;
check(abs($long - ($empty + 3 * $line)) <= 1, "a long text is $long high: four lines");

// The app puts the caret where it wants, as a mention completed where it was typed.
$draft = new TextState('hello world'); $move = -1;
$h = new Headless(function (Context $c) use ($draft, &$move): void {
    $c->textarea($draft)->label('Draft');
    if ($move >= 0) { $draft->setTextSelection($move, $move); $move = -1; }
}, 400, 300);
$h->frame();
$h->clickOn($h->byLabel('Draft'));
$move = 5; $h->frame();
same([5, 5], [$draft->anchor, $draft->cursor], 'the caret where the app put it');
$h->type(',');
check($draft->text === 'hello, world' && $draft->cursor === 6, 'typed there');
$draft->setTextSelection(0, 99);
same([0, strlen($draft->text)], [$draft->anchor, $draft->cursor], 'a selection past the end ends at the end');

/** The glyphs painted on the line of $r, [x, rgb] each, by the fake text engine's 7-point cells. @param array{0: float, 1: float, 2: float, 3: float} $r @return list<array{0: float, 1: int}> */
function taGlyphs(\MyPHP\UI\DisplayList $list, array $r): array
{
    $out = [];
    for ($i = 0; $i < $list->n; $i++) {
        $b = $list->buf; $k = $i * \MyPHP\UI\DisplayList::FLOATS_PER_RECT;
        $kind = $b[$k + 9] - \MyPHP\UI\DisplayList::KIND_STRIDE * floor($b[$k + 9] / \MyPHP\UI\DisplayList::KIND_STRIDE);
        if ((int)round($kind) !== 1 || $b[$k + 1] < $r[1] - 0.5 || $b[$k + 1] >= $r[1] + $r[3]) { continue; } // on its line: what scrolled out of the field is clipped, still in the list
        $out[] = [$b[$k], ((int)round($b[$k + 4] * 255) << 16) | ((int)round($b[$k + 5] * 255) << 8) | (int)round($b[$k + 6] * 255)];
    }
    return $out;
}

// A field without the focus shows the start of a text too long for it; with the focus, the caret at its end.
$long = new TextState(str_repeat('abc ', 60)); $other = new TextState('');
$h = new Headless(function (Context $c) use ($long, $other): void {
    $c->column();
      $c->input($long, '', 120)->label('Long');
      $c->input($other, '', 120)->label('Other');
    $c->end();
}, 300, 80);
$h->frame();
$first = fn() => taGlyphs($h->paint(), $h->rect($h->byLabel('Long')))[0][0] ?? null;
$r = $h->rect($h->byLabel('Long'));
check($first() >= $r[0], 'unfocused, its start shows');
$h->clickOn($h->byLabel('Long')); $long->setCursor(strlen($long->text)); $h->frame();
check($first() < $r[0], 'focused, the caret at the end shows: the start scrolled out');
$h->tab();
check($first() >= $r[0], 'the focus gone, its start shows again');

// A field's text goes where textAlign() puts it while it fits; a click there puts the caret by it.
$left = new TextState('abc'); $right = new TextState('abc');
$h = new Headless(function (Context $c) use ($left, $right): void {
    $c->column()->size(200, 0);
      $c->input($left, '', 200)->label('Left');
      $c->input($right, '', 200)->textAlign(Node::END)->label('Right');
    $c->end();
}, 300, 100);
$h->frame();
$list = $h->paint();
$lg = taGlyphs($list, $h->rect($h->byLabel('Left'))); $rg = taGlyphs($list, $h->rect($h->byLabel('Right')));
$box = $h->rect($h->byLabel('Right'));
check($lg !== [] && $rg !== [] && $rg[0][0] > $lg[0][0] + 50 && end($rg)[0] + 7 >= $box[0] + $box[2] - 16, 'the right-aligned text at the end of its field');
$h->click($rg[0][0] + 1, $box[1] + $box[3] / 2);
same(0, $right->cursor, 'a click on its first letter puts the caret before it');

// textRanges paint runs of a field's text in their color, only in the frames that ask.
$line = new TextState('to @Scout now'); $styled = true;
$h = new Headless(function (Context $c) use ($line, &$styled): void {
    $n = $c->input($line, '', 260)->label('Line');
    if ($styled) { $n->textRanges(new \MyPHP\UI\TextRange(3, 9, 0xdc0000)); }
}, 320, 80);
$h->frame();
$red = fn() => count(array_filter(taGlyphs($h->paint(), $h->rect($h->byLabel('Line'))), fn(array $g) => $g[1] === 0xdc0000));
same(6, $red(), 'the six letters of the range red');
$styled = false; $h->frame();
same(0, $red(), 'none red in a frame that asks for none');
