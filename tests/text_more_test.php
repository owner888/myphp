<?php
// More of mygo's text tests (textlines_test.go TestTextLines and
// TestTextLinesBeforeLayout, textobject_test.go TestTextObjects): where a
// text area of 504 lines laid them out, its wrapped line among them, as
// its caret has them, and after an edit; lines not laid out yet; an
// inline object between words.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Span, TextLayout, TextLines, TextState, TextStyle};

// 504 lines, the second wrapped: their rows and tops as the caret has them; the line at a point; after an edit.
$s = new TextState("one\n" . str_repeat('word ', 80) . "\nthree\n" . str_repeat("short\n", 500));
$lines = new TextLines();
$h = new Headless(function (Context $c) use ($s, $lines): void {
    $c->column(0, 0, 1)->align(\MyPHP\UI\Node::STRETCH);
      $c->textarea($s, '', 400, 300)->trackLines($lines)->grow = 1;
    $c->end();
}, 400, 300);
$h->frame(); $h->paint();
same(504, $lines->count(), '504 lines');
same([0], $lines->rows(0), 'the first on one row');
$rows = $lines->rows(1);
check(count($rows) >= 3 && $rows[0] === 0, 'the second on several: ' . json_encode($rows));
$l = $s->lastLayout(); $lh = $l->lineHeight;
$start = strlen("one\n"); $ok = true;
foreach ($rows as $k => $r) {
    [, $y] = $l->caret($h->metrics, $start + $r);
    if ($y != $lines->top(1) + $k * $lh) { $ok = false; }
}
check($ok, "each row's first rune has its caret on that row");
same($lines->top(1) + count($rows) * $lh, $lines->top(2), 'the third under the wrapped one');
same([1, 0, 503], [$lines->at($lines->top(1) + $lh * 1.5), $lines->at(-10), $lines->at(1e9)], 'the line in the wrapped one, above, below');
check($lines->height() >= $lines->top(503), 'the height below the last line');
$h->click(20, 10);
$h->key(Key::UP, 'moveToBeginningOfDocument:', Key::COMMAND);
$h->enter(); $h->enter(); $h->paint();
same(506, $lines->count(), 'after two newlines, 506');
check(count($lines->rows(3)) >= 3, 'the long line still on several rows');

// Before a layout, lines a line apart.
$none = new TextLines();
check($none->count() === 0 && $none->top(3) > 0 && $none->at(100) === 0 && $none->rows(0) === [0], 'not laid out yet');

// An inline object between words: its width in the line, its height standing on the baseline, the caret after it past it; narrow, the line breaks around it.
$sys = $h->metrics->textSystem(); $font = new TextStyle();
[$pw, $ph] = TextLayout::shape($sys, [new Span('ab')], $font, 14)->size();
$l = TextLayout::shape($sys, [new Span('a'), new Span('￼', objectWidth: 40, objectHeight: 50), new Span('b')], $font, 14);
[$w, $hh] = $l->size();
check($w >= $pw + 39 && $w <= $pw + 41, "width $w: the text's $pw and the object's 40");
check($hh >= 50 && $hh > $ph, "height $hh: the line holds the object");
$objects = $l->objects();
check(count($objects) === 1 && $objects[0][0] === 1, 'one object, at 1: ' . json_encode($objects));
[$ox, $oy, $ow, $oh] = $objects[0][1];
check($ow >= 39 && $ow <= 41 && $oh == 50 && $ox > 0 && $oy >= 0, 'its box: ' . json_encode($objects[0][1]));
check($l->caret(2)[0] >= $ox + $ow - 1, 'the caret after it past its box');
[, $nh] = TextLayout::shape($sys, [new Span('words words '), new Span('￼', objectWidth: 60, objectHeight: 20), new Span(' more')], $font, 14, 90)->size();
check($nh >= 40, "narrow, $nh high: a line broke at the object");
