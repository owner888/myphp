<?php
// The highlight of selected text and what paints with it (mygo's
// textselection_test.go TestSelectionColor, TestSelectionShowsOverSpanBackground,
// TestInputSelectionShowsOverTextBackground, TestInputBackgroundPreservesCompositionAndCaret,
// TestTransformedSelectionShowsOverSpanBackground, TestContentTransitionSelectionPaintOrder):
// a container's own color or the theme's, and the highlight over text
// and span backgrounds, under its glyphs; a composition's underline and
// the caret over the glyphs. The headless painter composes no pixels, so
// the order of the quads is checked: what is painted later is over.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Animation, Context, ContentTransition, Easing, Key, Span, TextState};

require_once __DIR__ . '/SelectionTest.php';

/** The quads painted: [kind (0 a rect, 1 a glyph), rgb, x, y, w, h], in order. @return list<array{int,int,float,float,float,float}> */
function spQuads(Headless $h): array
{
    $list = $h->paint(); $out = [];
    for ($k = 0; $k < $list->n; $k++) {
        $b = $k * 16;
        $rgb = ((int)round($list->buf[$b + 4] * 255) << 16) | ((int)round($list->buf[$b + 5] * 255) << 8) | (int)round($list->buf[$b + 6] * 255);
        $out[] = [((int)$list->buf[$b + 9]) % 16, $rgb, $list->buf[$b], $list->buf[$b + 1], $list->buf[$b + 2], $list->buf[$b + 3]];
    }
    return $out;
}

/** Whether rects of $rgb are painted, and none of $under after one of them and over it, and a glyph after each. */
function spOver(array $quads, int $rgb, int $under): bool
{
    $lit = array_keys(array_filter($quads, fn($q) => $q[0] === 0 && $q[1] === $rgb));
    if ($lit === []) { return false; }
    foreach ($lit as $i) {
        [, , $x, $y, $w, $hh] = $quads[$i];
        for ($j = $i + 1; $j < count($quads); $j++) {
            $q = $quads[$j];
            if ($q[0] === 0 && $q[1] === $under && $q[2] < $x + $w && $q[2] + $q[4] > $x && $q[3] < $y + $hh && $q[3] + $q[5] > $y) { return false; }
        }
        if (array_filter(array_slice($quads, $i + 1), fn($q) => $q[0] === 1) === []) { return false; }
    }
    return true;
}

$highlight = 0xffc800; $background = 0x505050;

// A container's own color for its selection; outside it, the theme's.
$h = new Headless(function (Context $c) use ($highlight): void {
    $c->column(20, 10);
      $c->column()->selectable()->selectionColor($highlight); $c->text('Colored'); $c->end();
      $c->column()->selectable(); $c->text('Plain'); $c->end();
    $c->end();
}, 300, 150);
$h->theme->selectionAlpha = 1.0;
$h->frame();
selectBetween($h, 'Colored', 0, 'Colored', 7);
check(array_filter(spQuads($h), fn($q) => $q[0] === 0 && $q[1] === $highlight) !== [], 'the selection in its color');
selectBetween($h, 'Plain', 0, 'Plain', 5);
$q = spQuads($h);
check(array_filter($q, fn($q) => $q[0] === 0 && $q[1] === $highlight) === [] && array_filter($q, fn($q) => $q[0] === 0 && $q[1] === $h->theme->selection) !== [], "the plain one in the theme's");

// Over a text's background, a span's, an inline node's, across lines and spans; what is copied.
foreach ([
    'plain' => ['test.go', fn(Context $c) => $c->text('test.go')],
    'text background' => ['test.go', fn(Context $c) => $c->text('test.go')->textBackground($background)],
    'span background' => ['test.go', fn(Context $c) => $c->richText([new Span('test.go', background: $background)])],
    'inline background' => ['test.go', function (Context $c) use ($background): void { $c->text('')->children(function () use ($c, $background): void { $c->text('test.go')->background($background); }); }],
    'lines and spans' => ["test.go\nnext.go", fn(Context $c) => $c->richText([new Span("test.go\n", background: $background), new Span('next.go', background: 0x646464)])->textBackground($background)->underline()],
] as $name => [$source, $build]) {
    $h = new Headless(function (Context $c) use ($highlight, $build): void {
        $c->column(20)->selectable()->selectionColor($highlight); $build($c); $c->end();
    }, 200, 100);
    $h->frame();
    foreach ([[1, mb_strlen($source) - 1], [0, mb_strlen($source)]] as [$from, $to]) {
        selectBetween($h, $source, $from, $source, $to);
        cmdKey($h, 'c');
        same(mb_substr($source, $from, $to - $from), clip($h), "$name: copied");
        $quads = spQuads($h);
        $lines = array_unique(array_map(fn($q) => $q[3], array_filter($quads, fn($q) => $q[0] === 0 && $q[1] === $highlight)));
        check(spOver($quads, $highlight, $background) && spOver($quads, $highlight, 0x646464) && count($lines) === substr_count($source, "\n") + 1, "$name $from-$to: over the backgrounds on each line, under the glyphs");
    }
}

// An input's and a text area's selection over their text's background.
foreach ([false, true] as $multiline) {
    $value = new TextState('test.go');
    $h = new Headless(function (Context $c) use ($value, $multiline, $highlight, $background): void {
        ($multiline ? $c->textarea($value) : $c->input($value))->autoFocus()->textBackground($background)->selectionColor($highlight);
    }, 200, 100);
    $h->frame();
    foreach ([[1, 6], [0, 7]] as [$from, $to]) {
        $h->key(Key::UP, 'moveToBeginningOfDocument:', Key::COMMAND);
        for ($i = 0; $i < $from; $i++) { $h->right(); }
        for ($i = $from; $i < $to; $i++) { $h->key(Key::RIGHT, 'moveRightAndModifySelection:', Key::SHIFT); }
        check(spOver(spQuads($h), $highlight, $background), ($multiline ? 'text area' : 'input') . " $from-$to: over the text's background");
    }
}

// A composition's underline and the caret over the glyphs, with a text background.
foreach ([false, true] as $multiline) {
    $value = new TextState('ab'); $ink = 0xdc0000; $accent = 0x00b400;
    $h = new Headless(function (Context $c) use ($value, $multiline, $ink): void {
        ($multiline ? $c->textarea($value) : $c->input($value))->autoFocus()->textBackground(0x505050)->textColor($ink);
    }, 200, 100);
    $h->theme = $h->theme->withAccent($accent);
    $h->frame();
    $value->setMarked('xy', 2); $h->frame();
    $glyphs = $underline = $caret = -1;
    foreach (spQuads($h) as $i => [$kind, $rgb, , , $w, $hh]) {
        if ($kind === 1) { $glyphs = $i; continue; }
        if ($rgb === $ink && $hh <= 1.01 && $w > 1) { $underline = $i; }
        if ($rgb === $accent && $w <= 2.01 && $hh > 1) { $caret = $i; }
    }
    check($glyphs >= 0 && $underline > $glyphs && $caret > $underline, ($multiline ? 'text area' : 'input') . ": glyphs $glyphs, the composition's underline $underline, the caret $caret");
}

// Moved by a transform, the selection still over the span's background.
$h = new Headless(function (Context $c) use ($highlight, $background): void {
    $c->column()->selectable()->selectionColor($highlight);
      $c->richText([new Span('test.go', background: $background)])->absolute()->top(120)->translate(0, -110);
    $c->end();
}, 200, 100);
$h->frame();
check(array_filter(spQuads($h), fn($q) => $q[0] === 0 && $q[1] === $background) !== [], 'the background moved into view with its text');
$h->click(2, 16); cmdKey($h, 'a'); // where the transform shows it, 110 above its place
check(spOver(spQuads($h), $highlight, $background), 'the selection over it');

// In a content transition, the selection over the background and under the glyphs, which still fade.
foreach ([false, true] as $numeric) {
    $value = '120'; $now = 0;
    $h = poseTester(function (Context $c) use (&$value, $numeric, $highlight, $background): void {
        $c->column()->selectable()->selectionColor($highlight);
          $c->text($value)->textBackground($background)->contentTransition(new ContentTransition(numeric: $numeric, animation: new Animation(duration: 0.1, ease: Easing::linear(...))));
        $c->end();
    }, $now, 200, 100);
    $h->paints = true; // every frame painted, as the window's: the transition goes on from what was painted
    $h->paint();
    $value = '130'; $h->frame(1); $h->paint();
    $now += 50_000_000; $h->frame(1);
    selectBetween($h, '130', 0, '130', 3);
    $quads = spQuads($h);
    check(spOver($quads, $highlight, $background) && count(array_filter($quads, fn($q) => $q[0] === 1)) > 3, ($numeric ? 'numeric' : 'plain') . ': over the background, under the glyphs, the old ones fading');
}
