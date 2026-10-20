<?php
// The text system (mygo's internal/text tests that need no fonts, on the
// stub engine), and text styles, rich text and paragraphs with text inside
// them (mygo's richtext_test.go, textstyle_test.go and inline elements).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Text\{Glyph, Layout, Line, Params, Span as TextSpan, StubEngine, Style, System};
use MyPHP\UI\{AccessNode, Context, Node, Role, Span};

$sys = new System(new StubEngine());

// Lines wrap at the width, each starting where the one before ended.
$one = $sys->layout(new Params('Hello, world', new Style(size: 16)));
check(count($one->lines) === 1 && $one->width > 50 && $one->height >= 16, 'one line');
$wrapped = $sys->layout(new Params(str_repeat('The quick brown fox jumps over the lazy dog. ', 6), new Style(size: 16), 200));
check(count($wrapped->lines) >= 4, 'wrapped to ' . count($wrapped->lines) . ' lines');
$ok = true;
foreach ($wrapped->lines as $i => $line) {
    $ok = $ok && $line->width <= 200.5 && ($i === 0 || $line->start === $wrapped->lines[$i - 1]->end);
}
check($ok, 'no line wider than the width, each going on from the last');
same(count($wrapped->runes), $wrapped->lines[count($wrapped->lines) - 1]->end, 'the last line ends the text');

// Newlines and empty text.
$l = $sys->layout(new Params("a\n\nb", new Style(size: 14)));
same(3, count($l->lines), 'a line a newline');
same([2, 2, 3], [$l->lines[1]->start, $l->lines[1]->end, $l->lines[2]->start], 'an empty line between');
$empty = $sys->layout(new Params('', new Style(size: 14)));
check(count($empty->lines) === 1 && $empty->height > 0, 'empty text still has a line');

// Carets move right, and hit-testing finds them.
$l = $sys->layout(new Params('office files', new Style(size: 20)));
$prev = -1.0; $ok = true;
for ($i = 0; $i <= count($l->runes); $i++) {
    [$x, , $ch] = $l->caret($i);
    $ok = $ok && $x >= $prev && $ch > 0 && $l->indexAt($x + 0.1, 5) === $i;
    $prev = $x;
}
check($ok, 'carets in order, each found at its x');
$sel = $l->selection(0, 6);
check(count($sel) === 1 && $sel[0][2] > 0, 'a selection on one line is one box');

// Truncation and the ellipsis.
$l = $sys->layout(new Params(str_repeat('word ', 50), new Style(size: 14), 120, 2));
check(count($l->lines) === 2 && $l->truncated, 'maxLines cuts the text');
$l = $sys->layout(new Params('The quick brown fox jumps', new Style(size: 16), 100, 1));
$line = $l->lines[0]; $last = $line->glyphs[count($line->glyphs) - 1];
check($l->truncated && $line->width <= 100 && $line->end < count($l->runes), 'one line, ending early');
check($last->runes === 0 && $last->cluster === $line->end, 'the ellipsis ends it, holding no runes');

// Spans size their runes; truncated, the line keeps their styles.
$glyph = function (Layout $l, int $r): Glyph {
    foreach ($l->lines as $line) { foreach ($line->glyphs as $g) { if ($g->cluster === $r && $g->runes > 0) { return $g; } } }
    throw new \RuntimeException("no glyph of rune $r");
};
$text = 'Small LARGE small';
$plain = $sys->layout(new Params($text, new Style(size: 14)));
$big = $sys->layout(new Params($text, new Style(size: 14), spans: [new TextSpan(6), new TextSpan(11, size: 28, weight: 700)]));
same([14.0, 28.0, 14.0], [$glyph($big, 0)->size, $glyph($big, 7)->size, $glyph($big, 13)->size], 'glyph sizes by span');
check($big->height >= $plain->height * 1.5 && $big->width > $plain->width, 'a span twice the size leaves the text larger');
$cut = $sys->layout(new Params($text, new Style(size: 14), $glyph($big, 9)->x, 1, spans: [new TextSpan(6), new TextSpan(11, size: 28)]));
check($cut->truncated && $glyph($cut, 7)->size === 28.0, 'truncated, the large run keeps its size');
$l = $sys->layout(new Params("ab\ncd", new Style(size: 14), spans: [new TextSpan(1), new TextSpan(4, size: 30)]));
same([14.0, 30.0, 30.0, 14.0], [$glyph($l, 0)->size, $glyph($l, 1)->size, $glyph($l, 3)->size, $glyph($l, 4)->size], 'spans across paragraphs');
$in = TextSpan::in([new TextSpan(3, weight: 1), new TextSpan(8, weight: 2), new TextSpan(12, weight: 3)], 5, 10);
same([[3, 2], [5, 3]], array_map(fn(TextSpan $s) => [$s->end, $s->weight], $in), 'the spans of a paragraph, relative to it');

// An underline under a word, a strikethrough through it, a thickness on whole pixels.
$l = $sys->layout(new Params('ab cd', new Style(size: 20)));
[[$x0, $x1, $top, $bottom]] = $sys->decorate($l, 0, 0, count($l->lines[0]->glyphs), 0, 0, 2, System::UNDERLINE, 0);
check($x0 === 0.0 && $x1 === 50.0 && $top > $l->lines[0]->baseline, 'an underline along the line, under the baseline');
[[, , $top, $bottom]] = $sys->decorate($l, 0, 0, 2, 0, 0, 2, System::STRIKETHROUGH, 0);
check($bottom < $l->lines[0]->baseline, 'a strikethrough above it');
[[, , $top, $bottom]] = $sys->decorate($l, 0, 0, 2, 0, 0, 2, System::UNDERLINE, 1.2);
same(1.0, $bottom - $top, 'a thickness of 1.2 points is 2 pixels at 2x');
$l = $sys->layout(new Params(' ab', new Style(size: 20)));
same(10.0, $sys->decorate($l, 0, 0, 3, 0, 0, 2, System::UNDERLINE, 0)[0][0], 'spaces starting the line are not underlined');
same([], $sys->decorate($l, 0, 0, 1, 0, 0, 2, System::UNDERLINE, 0), 'nor is a run of them alone');

// Visual carets in mixed directions, from fixed advances (mygo's caret_test.go).
$mixed = function (): Layout {
    $runes = System::runes('abc אבג def');
    $l = new Layout(new Params('abc אבג def'), $runes);
    $line = new Line(0, 0, 110, 20, 0, 0, 0, 0, count($runes));
    foreach ($runes as $i => $_) {
        $rtl = $i >= 4 && $i < 7;
        $line->glyphs[] = new Glyph(null, 0, 10, $rtl ? (10 - $i) * 10 : $i * 10, 0, 10, $i, 1, $rtl);
    }
    $l->lines[] = $line;
    return $l;
};
$l = $mixed();
same([40.0, 70.0], [$l->caretAt(4, Layout::UPSTREAM)[0], $l->caretAt(4, Layout::DOWNSTREAM)[0]], 'the two edges of a bidi boundary');
$p = [3, Layout::DOWNSTREAM]; $right = [];
foreach (range(1, 5) as $_) { $p = $l->moveCaret($p[0], $p[1], 1); $right[] = $p; }
same([[4, 1], [6, 0], [5, 0], [4, 0], [8, 1]], $right, 'moving right through right-to-left text');
$left = [];
foreach (range(1, 5) as $_) { $p = $l->moveCaret($p[0], $p[1], -1); $left[] = $p; }
same([[7, 0], [5, 1], [6, 1], [7, 1], [3, 0]], $left, 'and left');
same([[4, 6], [7, 8]], $l->selectionRanges([6, Layout::DOWNSTREAM], [8, Layout::UPSTREAM]), 'a visual selection keeps its bidi gap');
$l = $mixed(); $l->lines[0]->glyphs[4]->advance += 0.00001;
same([4, Layout::DOWNSTREAM], $l->moveCaret(5, Layout::DOWNSTREAM, 1), 'rounding picks the right run edge');
$l = new Layout(new Params("e\u{301}😀x"), System::runes("e\u{301}😀x"));
$l1 = new Line(0, 0, 20, 20, 0, 0, 0, 0, 3); $l1->glyphs = [new Glyph(null, 0, 10, 0, 0, 10, 0, 2), new Glyph(null, 0, 10, 10, 0, 10, 2, 1)];
$l2 = new Line(0, 20, 10, 20, 0, 0, 0, 3, 4); $l2->glyphs = [new Glyph(null, 0, 10, 0, 0, 10, 3, 1)];
$l->lines = [$l1, $l2];
same(2, $l->moveCaret(0, Layout::DOWNSTREAM, 1)[0], 'a grapheme is not split');
same([0.0, 20.0], [$l->caretAt(3, Layout::UPSTREAM)[1], $l->caretAt(3, Layout::DOWNSTREAM)[1]], 'a soft break has a caret on each line');
same([3, Layout::UPSTREAM], $l->positionAt(20, 10), 'past the end of the first line, upstream');
same([3, Layout::DOWNSTREAM], $l->moveCaret(3, Layout::UPSTREAM, 1), 'moving on crosses to the next line');
$l = new Layout(new Params('ab cd'), System::runes('ab cd'));
$l1 = new Line(0, 0, 20, 20, 0, 0, 0, 0, 3); $l1->glyphs = [new Glyph(null, 0, 10, 0, 0, 10, 0, 1), new Glyph(null, 0, 10, 10, 0, 10, 1, 1)];
$l2 = new Line(0, 20, 20, 20, 0, 0, 0, 3, 5); $l2->glyphs = [new Glyph(null, 0, 10, 0, 0, 10, 3, 1), new Glyph(null, 0, 10, 10, 0, 10, 4, 1)];
$l->lines = [$l1, $l2];
[$x, $y, $ch] = $l->caret(2);
same([2, Layout::UPSTREAM], $l->positionAt($x, $y + $ch / 2), 'a hit before a trimmed wrap space takes the earlier offset');

// Rich text: a span of size 30 leaves it higher; the red span paints red, plain text none.
$quads = function (Headless $h): array {
    $list = $h->paint(); $out = [];
    for ($q = 0; $q < $list->n; $q++) {
        $b = []; for ($k = 0; $k < 16; $k++) { $b[] = $list->buf[$q * 16 + $k]; }
        $out[] = $b;
    }
    return $out;
};
$in = function (array $q, array $r): bool { return $q[0] >= $r[0] - 0.01 && $q[1] >= $r[1] - 0.01 && $q[0] + $q[2] <= $r[0] + $r[2] + 0.01 && $q[1] + $q[3] <= $r[1] + $r[3] + 0.01; };
$red = 0xff0000; $blue = 0x0000ff;
$n = [];
$h = new Headless(function (Context $c) use (&$n, $red, $blue) {
    $c->column(10, 10)->align(Node::START);
      $n['rich'] = $c->richText([new Span('plain '), new Span('RED', 30, color: $red, weight: 700)]);
      $n['lined'] = $c->richText([new Span('under', underline: true, color: $blue), new Span(' over')]);
      $n['text'] = $c->text('plain RED');
    $c->end();
}, 400, 200);
$h->frame();
check($h->rect($n['rich'])[3] >= $h->rect($n['text'])[3] * 1.5, 'a span of size 30 leaves the text higher than plain text');
$qs = $quads($h);
$isColor = fn(array $q, int $rgb) => abs($q[4] - (($rgb >> 16) & 255) / 255) < 0.01 && abs($q[5] - (($rgb >> 8) & 255) / 255) < 0.01 && abs($q[6] - ($rgb & 255) / 255) < 0.01;
same(3, count(array_filter($qs, fn($q) => $q[9] >= 1 && $isColor($q, $red) && $in($q, $h->rect($n['rich'])))), 'three red glyphs in the red span');
same(0, count(array_filter($qs, fn($q) => $isColor($q, $red) && $in($q, $h->rect($n['text'])))), 'none in plain text');
$lined = $h->rect($n['lined']);
$lines = array_values(array_filter($qs, fn($q) => $q[9] < 1 && $isColor($q, $blue) && $in($q, $lined)));
check(count($lines) === 1 && $lines[0][2] >= $lined[2] * 0.4 && $lines[0][2] <= $lined[2] * 0.8, 'the underline is a line of blue under the first word alone');

// Text styles: inherited from the nodes around, a style text() does not measure laid out as rich text.
$n = [];
$h = new Headless(function (Context $c) use (&$n) {
    $c->column(0, 8)->align(Node::START);
      $n['plain'] = $c->text('Wide');
      $n['spaced'] = $c->text('Wide')->letterSpacing(6);
      $c->row()->fontFeatures('tnum', 'liga=0')->letterSpacing(1);
        $n['inherits'] = $c->text('12:30');
      $c->end();
      $c->column()->fontSize(20)->textColor(0x336699);
        $n['big'] = $c->text('Big');
        $n['bold'] = $c->text('Bold')->bold();
      $c->end();
      $n['cut'] = $c->richText([new Span(str_repeat('word ', 20))], width: 100)->maxLines(2);
      $n['striked'] = $c->text('gone')->strikethrough();
    $c->end();
}, 400, 300);
$h->frame();
same(Node::RICH, $n['spaced']->kind, 'letter spacing lays the text out');
$s = $n['inherits']->textLayout()->params->style;
same(['tnum,liga=0', 1.0], [$s->features, $s->letterSpacing], 'text inherits features and letter spacing');
same([20.0, 0x336699], [$n['big']->fontSize, $n['big']->fg], 'and the size and color of the nodes around it');
same(\MyPHP\UI\Font::BOLD, $n['bold']->style & \MyPHP\UI\Font::BOLD, 'bold() on text');
same(Node::TEXT, $n['big']->kind, 'a size and a color are measured as before');
same(2, count($n['cut']->textLayout()->lines), 'maxLines on rich text');
check($n['cut']->textLayout()->truncated, 'cut with an ellipsis');
$qs = $quads($h); $r = $h->rect($n['striked']);
check(count(array_filter($qs, fn($q) => $q[9] < 1 && $in($q, $r) && $q[2] > $r[2] * 0.5)) === 1, 'a strikethrough through the text');

// A paragraph: the text inside it continues it, its words its area.
$n = [];
$h = new Headless(function (Context $c) use (&$n) {
    $c->column(10);
      $n['para'] = $c->richText([], width: 120)->fontSize(14)->children(function () use ($c, &$n) {
          $c->text('Read ');
          $n['link'] = $c->link('the guide', 'https://example.com/guide');
          $c->text(' to get ');
          $n['em'] = $c->text('started')->italic()->textBackground(0xffff00);
          $c->text(' now.');
      });
    $c->end();
}, 400, 300);
$h->frame();
same('Read the guide to get started now.', $n['para']->plainText(), 'the paragraph reads its text and theirs');
same([5, 14], $n['link']->runes, 'the link has its runes in the paragraph');
check(count($n['link']->frags) >= 1, 'and the boxes of its words');
$f = $n['link']->frags[0];
check($f[0] > $h->rect($n['para'])[0] && abs($f[1] - $h->rect($n['para'])[1]) < 0.01, 'its words follow "Read " on the first line');
$l = $n['para']->textLayout();
check(count($l->lines) >= 2, 'the paragraph wraps at 120');
$spans = $n['para']->spans;
same(true, $spans[3]->italic, 'a style set on text inside it styles its span');
same(0xffff00, $spans[3]->background, 'and its background');
check($spans[1]->color !== null, 'the link keeps its accent color');
$qs = $quads($h);
check(count(array_filter($qs, fn($q) => $q[9] < 1 && $isColor($q, 0xffff00))) >= 1, 'the highlight paints');
[$fx, $fy, $fw, $fh] = $f;
$h->hover($fx + 2, $fy + $fh / 2);
check($h->rt->hovered($n['link']->id), 'the pointer on its words hovers the link');
$box = $h->rect($n['link']);
if ($box[2] > $fw + 1) { // its box reaches past its words when they wrap: there it is not hovered
    $h->hover($fx + $fw + 1, $fy + $fh / 2);
    check(!$h->rt->hovered($n['link']->id), 'beside its words it is not');
}
$h->input->push(['accessOn']); $h->frame();
$tree = $h->rt->accessTree;
$text = null; $link = null;
foreach ($tree->nodes as $an) {
    if ($an->id === $n['para']->id) { $text = $an; }
    if ($an->role === Role::LINK) { $link = $an; }
}
check($text !== null && $text->role === Role::TEXT, 'the paragraph is text to assistive technology');
check($link !== null && $tree->nodes[$link->parent]->id === $n['para']->id, 'the link inside it a node of its own, under it');
