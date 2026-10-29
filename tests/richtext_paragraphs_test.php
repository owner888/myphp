<?php
// The rich text editor's paragraphs (mygo's ui/richtext paragraph_test.go,
// 7e510df and a8f25ed): body paragraphs indented on their first line or
// spaced apart, the paragraphs in view where the editor paints them, and
// paragraphs' IDs following their text through edits, undo, JSON and the
// clipboard.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Transfer\Format;
use MyPHP\UI\{Context, Key};
use MyPHP\UI\RichText\{Attributes, Document, Edit, EditorOptions, ListKind, Paragraph, ParagraphBox, ParagraphStyle, RichText, Run, Text, Theme, View};
use MyPHP\Text\Params;

require_once __DIR__ . '/RichTextTest.php';

/** An editorBase of $doc, 10 points an em in theme $th, filling a window 300 wide and $h high, focused; its view reads the paragraphs in view as it builds, as an app's gutter does, into $boxes (mygo's proseTester). */
function proseTester(Document $doc, ?Theme $th, float $h, mixed &$boxes): Headless
{
    $boxes = [];
    $t = new Headless(function (Context $c) use ($doc, $th, &$boxes) {
        $boxes = $doc->visibleParagraphs();
        RichText::editorBase($c, $doc, new EditorOptions(fontSize: 10, theme: $th))->fill();
    }, 300, $h);
    $t->paints = true;
    $t->frame();
    $t->click(150, $h - 2);
    return $t;
}

/** Puts the caret at $i and returns where the editor shows it (mygo's caretAt). @return array{0: float, 1: float, 2: float, 3: float} */
function proseCaret(Headless $t, Document $doc, int $i): array
{
    $doc->setSelection($i, $i);
    $t->frame();
    return textCaret($t);
}

function body(string $s): Paragraph { return new Paragraph(runs: [new Run($s)]); }

/** @return list<int> the rune each paragraph of $t starts at */
function paraStarts(Text $t): array { return array_keys(iterator_to_array($t->paragraphs())); }

/** @return list<string> the IDs of the paragraphs of $t */
function paraIDs(Text $t): array { return array_values(array_map(fn(Paragraph $p) => $p->style->id, iterator_to_array($t->paragraphs()))); }

// Theme::$firstLineIndent indents a body paragraph after another; not the first after a heading, a centered paragraph, an empty one, a list or the start of a quotation.
$text = Text::of(
    new Paragraph(new ParagraphStyle(heading: 2), [new Run('Title')]),
    body('After the heading'), body('Indented'),
    new Paragraph(new ParagraphStyle(align: Params::CENTER), [new Run('* * *')]),
    body('After the break'), body(''), body('After an empty line'),
    new Paragraph(new ParagraphStyle(quote: true), [new Run('Quoted')]),
    new Paragraph(new ParagraphStyle(quote: true), [new Run('Quoted again')]),
    new Paragraph(new ParagraphStyle(list: ListKind::Bullets), [new Run('An item')]),
    body('After the list'),
    new Paragraph(new ParagraphStyle(align: Params::END), [new Run('Signed')]),
    body('Indented too?'), body('Indented again'),
);
$indented = [2 => true, 5 => true, 8 => true, 13 => true];
$doc = new Document($text); $plain = new Document($text);
$tt = proseTester($doc, new Theme(firstLineIndent: 2), 400, $b1);
$pt = proseTester($plain, null, 400, $b2);
foreach (paraStarts($text) as $p => $s) {
    $st = $text->paragraphStyleAt($s);
    if ($st->align !== Params::START || $st->heading > 0) { continue; }
    $got = proseCaret($tt, $doc, $s); $want = proseCaret($pt, $plain, $s);
    check($got[1] == $want[1] && $got[3] == $want[3], "paragraph $p: on its line");
    same(isset($indented[$p]) ? 20.0 : 0.0, $got[0] - $want[0], "paragraph $p " . (isset($indented[$p]) ? 'indented by 2 ems' : 'not indented'));
}

// The indent is no character: the caret, keys and clicks skip it.
$doc = new Document(Text::of(body('One'), body(str_repeat('word ', 30)), body('Three')));
$tt = proseTester($doc, new Theme(firstLineIndent: 2), 400, $boxes);
$first = proseCaret($tt, $doc, 4);
richKey($tt, 0, Key::DOWN);
check($doc->selection()[1] > 4, 'down from the start');
richKey($tt, 0, Key::HOME);
$second = textCaret($tt);
check($first[0] - $second[0] == 20 && $second[1] > $first[1], 'the second line wraps back to the edge: ' . json_encode([$first, $second]));
proseCaret($tt, $doc, 4);
richKey($tt, 0, Key::LEFT);
same(3, $doc->selection()[1], 'left from the start goes to the paragraph before');
richKey($tt, 0, Key::RIGHT);
same(4, $doc->selection()[1], 'right comes back');
richKey($tt, 0, Key::RIGHT);
same(5, $doc->selection()[1], 'and on');
$tt->click(5, $first[1] + $first[3] / 2);
same([4, 4], $doc->selection(), 'a click in the indent puts the caret at the start');
proseCaret($tt, $doc, 4);
$tt->type('A');
check(str_starts_with($doc->text()->string(), "One\nAword") && textCaret($tt)[0] > $first[0], 'typing at the start goes after the indent');
proseCaret($tt, $doc, 4);
richKey($tt, 0, Key::DELETE);
check(str_starts_with($doc->text()->string(), 'OneAword'), 'backspace at the start joins the paragraphs');

// Theme::$paragraphSpacing between body paragraphs only; the caret and the boxes with the text painted.
$text = Text::of(
    body('One'), body('Two'),
    new Paragraph(new ParagraphStyle(heading: 2), [new Run('Heading')]),
    body('Three'),
    new Paragraph(new ParagraphStyle(list: ListKind::Bullets), [new Run('Item')]),
    new Paragraph(new ParagraphStyle(list: ListKind::Bullets), [new Run('Item')]),
    body('Four'),
    new Paragraph(new ParagraphStyle(align: Params::CENTER), [new Run('* * *')]),
    new Paragraph(new ParagraphStyle(quote: true), [new Run('Quoted')]),
    new Paragraph(new ParagraphStyle(codeBlock: true), [new Run('code')]),
    body('Five'),
);
$spaced = [1 => true, 7 => true, 8 => true];
$doc = new Document($text); $plain = new Document($text);
$tt = proseTester($doc, new Theme(paragraphSpacing: 1), 400, $boxes);
proseTester($plain, null, 400, $plainBoxes);
same([11, 11], [count($boxes), count($plainBoxes)], 'every paragraph in view');
$added = 0.0; $bad = [];
foreach ($boxes as $p => $b) {
    $pb = $plainBoxes[$p];
    if (isset($spaced[$p])) { $added += 10; }
    if (abs($b->top - $pb->top - $added) > 0.01 || $b->height != $pb->height) { $bad[] = "paragraph $p at {$b->top}, {$pb->top} without"; }
    if (($c = proseCaret($tt, $doc, $b->start))[1] != $b->top) { $bad[] = "paragraph $p: caret at {$c[1]}, its box at {$b->top}"; }
}
same([], $bad, 'spaced 1 em between body paragraphs, the caret at each box\'s top');
$two = $boxes[1];
$tt->click(100, $two->top - 4);
check($doc->selection()[1] >= $two->start && $doc->selection()[1] <= $two->start + 3, 'a click in the space above a paragraph goes into it');

// The paragraphs in view where the editor paints them, as it scrolls and edits.
$paras = [];
for ($i = 0; $i < 200; $i++) { $paras[] = body("Paragraph $i, long enough to wrap in a window this narrow at times."); }
$doc = new Document(Text::of(...$paras));
$tt = proseTester($doc, new Theme(firstLineIndent: 1.5, paragraphSpacing: 0.5), 400, $boxes);
$doc->setSelection(0, 0); $tt->frame();
$painted = function () use ($doc): array { $v = $doc->shownIn?->get(); return $v === null ? [] : (fn() => $this->boxes())->call($v); };
$inView = function (string $what) use (&$boxes, $painted, $doc) {
    check($boxes !== [] && $boxes == $painted(), "$what: the view read where the editor painted");
    $ok = true;
    foreach ($boxes as $k => $b) {
        $ok = $ok && $doc->text()->paragraphRange($b->start)[0] === $b->start && ($k === 0 || $b->index === $boxes[$k - 1]->index + 1 && $b->top >= $boxes[$k - 1]->top + $boxes[$k - 1]->height);
    }
    check($ok, "$what: boxes of paragraphs, in order");
};
$inView('first frame');
same([0, 0.0], [$boxes[0]->index, $boxes[0]->top], 'paragraph 0 at the top');
same($boxes[2]->top, proseCaret($tt, $doc, $boxes[2]->start)[1], 'the caret\'s line at its box\'s top');
$before = $boxes[0]->index;
$tt->input->push(['scroll', 150, 200, 0, -1000, true]); $tt->frame(); $tt->frame();
$inView('scrolled');
check($boxes[0]->index !== $before, 'scrolled, other paragraphs in view');
$end = $doc->text()->len();
$doc->setSelection($end, $end); $tt->frame(); $tt->frame();
$inView('revealed');
same(199, $boxes[count($boxes) - 1]->index, 'revealing the end');
$firstBox = $boxes[1];
$doc->edit(function (Edit $e) use ($firstBox) { $e->replaceString($firstBox->start, $firstBox->start, "New\n", new Attributes); });
$tt->frame(); $tt->frame();
$inView('edited');
same([$firstBox->start, $firstBox->start + 4], [$boxes[1]->start, $boxes[2]->start], 'a paragraph inserted above moves them down');

// Before an editor showed the document, none; then the paragraphs; frames that change nothing ask for none more.
$doc = new Document(Text::of(body('One'), body('Two')));
same([], $doc->visibleParagraphs(), 'no editor yet');
$reads = [];
$t = new Headless(function (Context $c) use ($doc, &$reads) {
    $reads[] = $doc->visibleParagraphs();
    RichText::editorBase($c, $doc, new EditorOptions(fontSize: 10))->fill();
}, 300, 200);
$t->paints = true;
$t->frame(1);
check($reads[0] === [] && $t->rt->needsFrame, 'the first frame read none, and asks for another');
$t->frame(1);
$got = end($reads);
check(count($got) === 2 && $got[0]->top == 0 && $got[1]->top == $got[0]->height, 'then the paragraphs: ' . json_encode($got));
$t->frame(1);
check(!$t->rt->needsFrame, 'a frame that changes nothing asks for none more');

// IDs as paragraphs are split and joined by the editor's keys; undo and redo bring them back.
$doc = new Document(Text::of(
    new Paragraph(new ParagraphStyle(id: 'a'), [new Run('alpha')]),
    new Paragraph(new ParagraphStyle(id: 'b'), [new Run('beta')]),
    new Paragraph(new ParagraphStyle(heading: 2, id: 'c'), [new Run('gamma')]),
));
$h = richTester($doc);
$steps = [
    ['Enter in the middle', 2, Key::RETURN, ['a', '', 'b', 'c'], "al\npha\nbeta\ngamma"],
    ['Enter at the start', 7, Key::RETURN, ['a', '', '', 'b', 'c'], "al\npha\n\nbeta\ngamma"],
    ['Enter at the end', 13, Key::RETURN, ['a', '', '', 'b', '', 'c'], "al\npha\n\nbeta\n\ngamma"],
    ['Backspace joining', 3, Key::DELETE, ['a', '', 'b', '', 'c'], "alpha\n\nbeta\n\ngamma"],
    ['Backspace after an empty paragraph', 7, Key::DELETE, ['a', 'b', '', 'c'], "alpha\nbeta\n\ngamma"],
    ['Delete joining', 10, Key::FORWARD_DELETE, ['a', 'b', 'c'], "alpha\nbeta\ngamma"],
];
$versions = [];
foreach ($steps as [$what, $caret, $key, $ids, $str]) {
    $versions[] = $doc->text();
    $doc->setSelection($caret, $caret); $h->frame();
    richKey($h, 0, $key);
    same([$str, $ids], [$doc->text()->string(), paraIDs($doc->text())], $what);
}
for ($k = count($steps) - 1; $k >= 0; $k--) {
    richKey($h, Key::COMMAND, Key::Z);
    check($doc->text()->equal($versions[$k]), 'undoing ' . $steps[$k][0] . ': ' . json_encode(paraIDs($doc->text())));
}
foreach ($steps as [$what, , , $ids]) {
    richKey($h, Key::COMMAND | Key::SHIFT, Key::Z);
    same($ids, paraIDs($doc->text()), "redoing $what");
}

// IDs in texts: a newline in a run, a replacement with IDs, JSON, formats, the clipboard.
$text = Text::of(new Paragraph(new ParagraphStyle(id: 'a'), [new Run("one\ntwo")]), new Paragraph(new ParagraphStyle(id: 'b'), [new Run('three')]));
same(['a', '', 'b'], paraIDs($text), 'a newline in a run starts a paragraph without the ID');
same(['a', '', '', '', 'b'], paraIDs($text->replace(4, 4, $text->slice(0, $text->len()))), 'text inserted, IDs and all, brings none');
check(Text::unmarshalJSON($text->marshalJSON())->equal($text), 'JSON keeps them');
$clip = View::clipboardData($text);
foreach ([RichText::FORMAT, Format::HTML] as $f) {
    $data = $clip->read($f);
    check(!str_contains($data, '"a"') && !str_contains($data, 'id='), "the clipboard's $f holds no IDs");
}
$d = new Document($text);
$d->setSelection(0, $text->len());
$f = $d->formatting();
check(!$f->paragraphsMixed && $f->paragraph->id === 'a', 'paragraphs alike but for their IDs are no mix of styles');

// Copied and pasted, the copies have none; the paragraphs copied keep theirs.
$doc = new Document(Text::of(new Paragraph(new ParagraphStyle(id: 'a'), [new Run('one')]), new Paragraph(new ParagraphStyle(id: 'b'), [new Run('two')])));
$h = richTester($doc);
$doc->setSelection(0, 7); $h->frame();
richKey($h, Key::COMMAND, Key::C);
$end = $doc->text()->len();
$doc->setSelection($end, $end); $h->frame();
richKey($h, 0, Key::RETURN);
richKey($h, Key::COMMAND, Key::V);
same(["one\ntwo\none\ntwo", ['a', 'b', '', '']], [$doc->text()->string(), paraIDs($doc->text())], 'pasted: the copies without IDs');
$doc->setSelection(0, 4); $h->frame();
richKey($h, Key::COMMAND, Key::X);
richKey($h, Key::COMMAND, Key::V);
$ids = paraIDs($doc->text());
check($ids[0] === '' && in_array('b', $ids, true), 'cut and pasted back, the text pasted is new: ' . json_encode($ids));

// A document naming paragraphs names those it has and those edits make, in their steps of undo.
$doc = new Document(Text::of(new Paragraph(new ParagraphStyle(id: 'keep'), [new Run('one')]), body('two')));
$n = 0;
$doc->identifyParagraphs(function () use (&$n) { return 'n' . ++$n; });
same(['keep', 'n1'], paraIDs($doc->text()), 'those it has');
$h = richTester($doc);
$doc->setSelection(1, 1); $h->frame();
richKey($h, 0, Key::RETURN);
$after = paraIDs($doc->text());
same(['keep', 'n2', 'n1'], $after, 'those Enter makes');
richKey($h, Key::COMMAND, Key::Z);
same(['keep', 'n1'], paraIDs($doc->text()), 'undone');
$doc->redo();
same($after, paraIDs($doc->text()), 'redone, the same IDs');
$doc->setSelection(0, 0);
$doc->insert(Text::of(new Paragraph(new ParagraphStyle(id: 'keep'), [new Run("x\ny\n")])));
$doc->edit(function (Edit $e) { $e->formatParagraphs(0, 0, function (ParagraphStyle $p) { $p->id = ''; }); });
$ids = paraIDs($doc->text());
check(!in_array('', $ids, true) && count(array_unique($ids)) === count($ids), 'pasted paragraphs, and one whose ID was taken away, get new ones: ' . json_encode($ids));
$doc->setText(Text::of(body('new'), body('text')));
check(!in_array('', paraIDs($doc->text()), true), 'a text set gets them too');
