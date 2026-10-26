<?php
// A text area's lines, wrapped a paragraph at a time as mygo's area lays
// out only those in view: a long document counts a line a paragraph
// until one is asked for, a wrapped paragraph moves those after it down,
// lines and bytes map to each other through the paragraphs, a text area
// paints a document of 100 000 lines wrapping only what shows, and an
// edit keeps the lines of the paragraphs it left; a field in its own font.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, FieldLayout, TextState};

$m = new Metrics(); // 7 points a character, 16 a line
$doc = '';
for ($i = 0; $i < 1000; $i++) { $doc .= ($i === 500 ? str_repeat('word ', 30) : "line $i") . "\n"; }
$fl = FieldLayout::wrap($m, $doc, 13, 100);
same(1001, $fl->count(), 'a line a paragraph before any is wrapped, the empty one after the last newline among them');
same([0, 7], $fl->line(0), 'the first line, its newline with it');
$at = strpos($doc, 'word');
same(500, $fl->lineOf($at), 'the long paragraph starts on line 500 while those before it are one line each');
check($fl->count() > 1001, 'wrapping it made more lines: ' . $fl->count());
$extra = $fl->count() - 1001;
same(501 + $extra, $fl->lineOf(strpos($doc, 'line 501')), 'the paragraph after it moved down by the lines it gained');
same(501, $fl->paragraphAt(501 + $extra), 'and its line is in paragraph 501');
same(500, $fl->paragraphAt(500 + $extra), 'the last line of the long one is in it');
same('line 501', $fl->lineText(501 + $extra), 'the text of that line, without its newline');
[$x, $y] = $fl->caret($m, strpos($doc, 'line 501') + 4);
same([28.0, (501 + $extra) * 16.0], [$x, $y], 'the caret four characters into it');
same(strpos($doc, 'line 501') + 4, $fl->hit($m, 28, (501 + $extra) * 16 + 3), 'and the point there hits that byte');
same(strlen($doc), $fl->line($fl->count() - 1)[0], 'the last line is empty, after the last newline');

// An edit keeps the lines the paragraphs it left were wrapped into: the long one is not wrapped again.
$edited = FieldLayout::wrap($m, 'x' . $doc, 13, 100, $fl);
same(1001 + $extra, $edited->count(), 'an edit at the start leaves the long paragraph wrapped as it was, its lines counted at once');
$joined = FieldLayout::wrap($m, substr($doc, strlen("line 0\n")), 13, 100, $fl);
same(1000 + $extra, $joined->count(), 'with a paragraph above it gone, it is one paragraph up, still wrapped');
same(1001, FieldLayout::wrap($m, 'x' . $doc, 13, 120, $fl)->count(), 'at another width nothing is kept');

// A text area of 100 000 lines wraps only those it shows.
$big = '';
for ($i = 1; $i <= 100000; $i++) { $big .= "Line $i\n"; }
$s = new TextState($big);
$bigArea = null;
$h = new Headless(function (Context $c) use ($s, &$bigArea): void { $bigArea = $c->textarea($s, '', 400, 300); }, 500, 400);
$h->paints = true;
$t0 = hrtime(true);
$h->frame();
$ms = (hrtime(true) - $t0) / 1e6;
$l = $s->lastLayout();
check($l !== null && $l->count() === 100001, 'the layout of the document has a line a paragraph');
check($ms < 2000, sprintf('a frame of it takes %.0f ms', $ms));
$r = $h->rect($bigArea);
$h->click($r[0] + 10, $r[1] + 10);
$h->frame();
same(0, $l->lineOf($s->cursor), 'a click at its top puts the caret on the first line');

// A text area as high as its lines wraps no further than the lines it shows.
$grow = new TextState(str_repeat("short\n", 5000));
$growArea = null;
$h2 = new Headless(function (Context $c) use ($grow, &$growArea): void { $c->column(); $growArea = $c->textarea($grow, '', 300, 0, null, 1, 6); $c->end(); }, 400, 400);
$h2->frame();
same(6 * 16.0 + 2 * \MyPHP\UI\Runtime::FIELD_INSET_Y, $h2->rect($growArea)[3], 'six lines high, the most it grows to');

// A field's font is what it is measured and painted in: font('monospace') a text area's, bold() an input's.
$mono = new TextState("a\nb"); $boldField = new TextState('x');
$h3 = new Headless(function (Context $c) use ($mono, $boldField): void { $c->textarea($mono)->font('monospace'); $c->input($boldField)->bold(); }, 400, 300);
$h3->paints = true;
$h3->frame();
same(\MyPHP\UI\Font::MONO, $mono->style, 'the text area in the fixed-pitch font');
same(\MyPHP\UI\Font::MONO, $mono->lastLayout()?->style, 'and wrapped in it');
same(\MyPHP\UI\Font::BOLD, $boldField->style, 'the input bold');
