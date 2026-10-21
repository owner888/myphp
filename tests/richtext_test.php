<?php
// The rich text model (mygo's ui/richtext text_test.go and
// document_test.go): Text against a naive model of a cell per rune, and
// the Document's commands and undo.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Text\Params;
use MyPHP\UI\RichText\{Attachment, Attributes, Document, Edit, FormatState, ListKind, Paragraph, ParagraphStyle, RichPara, Run, Seq, Text};
use Random\Engine\PcgOneseq128XslRr64;
use Random\Randomizer;

/**
 * A naive attributed text: a cell [rune, attributes, style] per rune, and a
 * last one ('' for the end of the text) with the attributes typing gets
 * there.
 *
 * @return list<array{0: string, 1: Attributes, 2: ParagraphStyle}>
 */
function rtNaiveOf(Text $t): array
{
    $out = [];
    $i = 0;
    foreach ($t->paragraphs() as $p) {
        foreach ($p->runs as $r) {
            foreach (mb_str_split($r->text, 1, 'UTF-8') as $c) {
                $out[] = [$c, $r->attributes, $p->style];
                $i++;
            }
        }
        $out[] = [$i === $t->len() ? '' : "\n", $t->attributesAt($i), $p->style];
        $i++;
    }
    return $out;
}

/** Gives every cell of a paragraph the style of its first, as a Text keeps one style a paragraph. */
function rtNormalize(array $n): array
{
    $start = 0;
    foreach ($n as $i => $c) {
        if ($c[0] === "\n" || $c[0] === '') {
            for ($k = $start; $k <= $i; $k++) { $n[$k][2] = $n[$start][2]; }
            $start = $i + 1;
        }
    }
    return $n;
}

function rtNaiveString(array $n): string
{
    return implode('', array_map(fn ($c) => $c[0], array_slice($n, 0, -1)));
}

/** What is wrong with the invariants of $at, or ''. */
function rtInvariants(Text $at): string
{
    $lines = explode("\n", $at->string());
    if ($at->paragraphCount() !== count($lines) || Seq::len($at->seq()) !== count($lines)) {
        return sprintf('%d paragraphs, %d in the sequence, %d lines', $at->paragraphCount(), Seq::len($at->seq()), count($lines));
    }
    $err = '';
    Seq::each($at->seq(), 0, function (int $i, RichPara $p) use ($lines, &$err): bool {
        $sum = 0;
        foreach ($p->runs as $k => $r) {
            if ($r[0] <= 0) { $err = "paragraph $i: empty run"; return false; }
            if ($k > 0 && $p->runs[$k - 1][1]->equals($r[1]) && $r[1]->attachment === null) { $err = "paragraph $i: runs not joined"; return false; }
            $sum += $r[0];
        }
        $want = mb_strlen($lines[$i], 'UTF-8');
        if ($p->n !== $want || $sum !== $want + 1) { $err = "paragraph $i: n {$p->n}, runs $sum, line " . json_encode($lines[$i]); return false; }
        return true;
    });
    return $err;
}

/** What differs between $at and the model $want, or ''. */
function rtCompare(Text $at, array $want): string
{
    if (($e = rtInvariants($at)) !== '') { return $e; }
    $got = rtNaiveOf($at);
    if (rtNaiveString($got) !== rtNaiveString($want)) { return 'text ' . json_encode(rtNaiveString($got)) . ', want ' . json_encode(rtNaiveString($want)); }
    foreach ($got as $i => $c) {
        if (!$c[1]->equals($want[$i][1])) { return "rune $i: attributes"; }
        if (!$c[2]->equals($want[$i][2])) { return "rune $i: style"; }
    }
    return '';
}

$rtAttrs = [new Attributes, new Attributes(weight: 700), new Attributes(italic: true), new Attributes(link: 'https://a'), new Attributes(weight: 700, italic: true), new Attributes(color: [0xc80000, 1.0])];
$rtStyles = [new ParagraphStyle, new ParagraphStyle(heading: 1), new ParagraphStyle(list: ListKind::Bullets), new ParagraphStyle(list: ListKind::Numbers, indent: 1), new ParagraphStyle(align: Params::CENTER)];

$rtRandom = function (Randomizer $rnd) use ($rtAttrs, $rtStyles): Text {
    $paras = [];
    $words = ['a', 'bc', 'déf', 'ghij', ' ', '😀'];
    for ($k = 1 + $rnd->getInt(0, 3); $k > 0; $k--) {
        $p = new Paragraph($rtStyles[$rnd->getInt(0, count($rtStyles) - 1)]);
        for ($r = $rnd->getInt(0, 3); $r > 0; $r--) {
            $p->runs[] = new Run($words[$rnd->getInt(0, count($words) - 1)], $rtAttrs[$rnd->getInt(0, count($rtAttrs) - 1)]);
        }
        $paras[] = $p;
    }
    return Text::of(...$paras);
};

// TestAttributedTextOf
$at = Text::of(
    new Paragraph(new ParagraphStyle(heading: 1), [new Run('Title')]),
    new Paragraph(runs: [new Run('Hello '), new Run('bold', new Attributes(weight: 700)), new Run(' and'), new Run(' more')]),
    new Paragraph,
);
same('', rtInvariants($at), 'Text::of keeps the invariants');
same("Title\nHello bold and more\n", $at->string(), 'Text::of: the text');
check($at->paragraphStyleAt(2)->heading === 1 && $at->paragraphStyleAt(8)->heading === 0, 'Text::of: paragraph styles');
$runs = [];
foreach ($at->runs(6, 25) as $r) { $runs[] = $r->text; }
same('Hello |bold| and more', implode('|', $runs), 'runs join neighbors with the same attributes');
check($at->attributesAt(12)->bold() && !$at->attributesAt(16)->bold(), 'attributesAt');
check((new Text)->len() === 0 && (new Text)->paragraphCount() === 1, 'the empty text');
check(Text::of(new Paragraph(runs: [new Run("a\nb")]))->equal(Text::new("a\nb")), 'a newline in a run starts a paragraph');

// TestAttributedTextJoinParagraphs
$at = Text::of(new Paragraph(new ParagraphStyle(heading: 1), [new Run('Title')]), new Paragraph(runs: [new Run('body')]));
$joined = $at->delete(5, 6);
check($joined->string() === 'Titlebody' && $joined->paragraphStyleAt(0)->heading === 1, 'deleting the newline after a heading keeps the heading');
$gone = $at->delete(0, 6);
check($gone->string() === 'body' && $gone->paragraphStyleAt(0)->heading === 0, 'deleting the heading whole leaves the body');
$split = $at->replaceString(5, 5, "\n", new Attributes);
check($split->string() === "Title\n\nbody" && $split->paragraphStyleAt(6)->heading === 1 && $split->paragraphStyleAt(7)->heading === 0, 'Enter at the end of a heading: both are headings');
same("Title\nbody", $at->string(), 'the text a replace is called on stays as it was');

// TestAttributedTextAttachments
$img = new Attachment(width: 20, height: 10, name: 'photo');
$at = Text::new('ab')->replaceString(1, 1, "\u{FFFC}\u{FFFC}x", new Attributes(attachment: $img));
same('', rtInvariants($at), 'attachments keep the invariants');
check($at->attributesAt(1)->attachment === $img && $at->attributesAt(2)->attachment === $img && $at->attributesAt(3)->attachment === null, 'only object replacement characters hold the attachment');
$f = $at->format(0, $at->len(), function (Attributes $a): void { $a->weight = 700; $a->attachment = null; });
check($f->attributesAt(1)->attachment === $img && $f->attributesAt(1)->bold(), 'format keeps attachments');

// TestAttributedTextRandom
$fail = '';
for ($seed = 0; $seed < 300 && $fail === ''; $seed++) {
    $rnd = new Randomizer(new PcgOneseq128XslRr64($seed * 7 + 1));
    $at = $rtRandom($rnd);
    $model = rtNaiveOf($at);
    if (($e = rtCompare($at, $model)) !== '') { $fail = "seed $seed: $e"; break; }
    for ($step = 0; $step < 30; $step++) {
        $n = $at->len();
        $a = $rnd->getInt(0, $n);
        $z = $a + $rnd->getInt(0, $n - $a);
        switch ($rnd->getInt(0, 3)) {
            case 0: case 1:
                $with = $rtRandom($rnd);
                if ($rnd->getInt(0, 2) === 0) {
                    $b = $rnd->getInt(0, $n);
                    $with = $at->slice($b, $b + $rnd->getInt(0, $n - $b)); // a piece of the text itself, as a paste
                }
                $wm = rtNaiveOf($with);
                $next = [...array_slice($model, 0, $a), ...array_slice($wm, 0, -1), ...array_slice($model, $z)];
                $at = $at->replace($a, $z, $with);
                $model = rtNormalize($next);
                break;
            case 2:
                $attrs = $rtAttrs[$rnd->getInt(0, count($rtAttrs) - 1)];
                $at = $at->format($a, $z, function (Attributes $x) use ($attrs): void {
                    foreach (get_object_vars($attrs) as $k => $v) { $x->$k = $v; }
                });
                if ($z === $n && $a < $z) { $z++; } // to the end of the text, what typing gets there
                for ($i = $a; $i < $z; $i++) {
                    $c = clone $attrs;
                    $c->attachment = $model[$i][1]->attachment;
                    $model[$i][1] = $c;
                }
                break;
            case 3:
                $style = $rtStyles[$rnd->getInt(0, count($rtStyles) - 1)];
                [$pa, $pz] = $at->paragraphSpan($a, $z);
                $at = $at->formatParagraphs($a, $z, function (ParagraphStyle $p) use ($style): void {
                    foreach (get_object_vars($style) as $k => $v) { $p->$k = $v; }
                });
                $para = 0;
                foreach ($model as $i => $c) {
                    if ($para >= $pa && $para <= $pz) { $model[$i][2] = $style; }
                    if ($c[0] === "\n") { $para++; }
                }
                break;
        }
        if (($e = rtCompare($at, $model)) !== '') { $fail = "seed $seed step $step: $e"; break; }
    }
}
same('', $fail, 'random replaces, formats and paragraph styles match the naive model');

// TestAttributedTextSliceReplace
$fail = '';
for ($seed = 0; $seed < 200; $seed++) {
    $rnd = new Randomizer(new PcgOneseq128XslRr64($seed * 9 + 2));
    $at = $rtRandom($rnd);
    $n = $at->len();
    $a = $rnd->getInt(0, $n);
    $z = $a + $rnd->getInt(0, $n - $a);
    $got = $at->replace($a, $z, $at->slice($a, $z));
    $ga = rtNaiveOf($got); $wa = rtNaiveOf($at);
    $equal = count($ga) === count($wa);
    for ($i = 0; $equal && $i < count($ga); $i++) { $equal = $ga[$i][1]->equals($wa[$i][1]); }
    if ($got->string() !== $at->string() || !$equal) { $fail = "seed $seed: [$a, $z)"; break; }
}
same('', $fail, 'putting a slice back where it came from changes nothing');

// TestDocumentLinkEndsWithLine
$d = new Document(Text::new("abc\nxyz"));
$d->setSelection(0, 4);
$d->setLink('u');
$d->setSelection(3, 3);
same('', $d->typingAttributes()->link, 'typing at the end of a line linked whole does not continue the link');

// TestDocumentFormattingFromNewline
$d = new Document(Text::of(new Paragraph(runs: [new Run('plain')]), new Paragraph(runs: [new Run('BOLD', new Attributes(weight: 700))])));
$d->setSelection(5, 10);
check($d->formatting()->bold === FormatState::FormatOn, 'a selection from a newline has the formats of the runes after it');
$d->toggleBold();
check(!$d->text()->attributesAt(7)->bold(), '⌘B takes bold off it');

// TestDocumentInsertLeavesCaret
$d = new Document(Text::new('hello world'));
$d->setSelection(6, 11);
$d->insertText('there');
same([11, 11], $d->selection(), 'insertText leaves the caret after it');
$d->setSelection(0, 1);
$d->insert(Text::new("A\nB"));
same([3, 3], $d->selection(), 'insert leaves the caret after it');

// TestDocumentFormatToEnd
$d = new Document(Text::new("abc\n"));
$d->setSelection(0, 4);
$d->toggleBold();
$d->setSelection(4, 4);
check($d->typingAttributes()->bold(), 'formatting to the end formats what typing gets in a last empty paragraph');

// TestDocumentNoOpSteps
$d = new Document(Text::new('abc'));
$d->setSelection(0, 3);
$v = $d->version();
$d->setLink('');
$d->toggleChecked(0);
check($d->version() === $v && !$d->canUndo(), 'commands changing nothing make no step of undo');
for ($i = 0; $i < 200; $i++) { $d->insertText('x'); }
$n = count($d->undo);
$d->edit(function (Edit $e): void {});
same($n, count($d->undo), 'a full history keeps its steps through an edit changing nothing');

// Undo and redo bring the text, its attributes and the selection back.
$d = new Document(Text::new('hello'));
$d->setSelection(0, 5);
$d->toggleBold();
$d->setSelection(5, 5);
$d->insertText(' world');
$d->undo();
check($d->text()->string() === 'hello' && $d->text()->attributesAt(0)->bold() && $d->selection() === [5, 5], 'undo takes back the insert');
$d->undo();
check(!$d->text()->attributesAt(0)->bold() && $d->selection() === [0, 5], 'undo takes back the format, with the selection before it');
$d->redo();
$d->redo();
check($d->text()->string() === 'hello world' && $d->text()->attributesAt(0)->bold() && $d->selection() === [11, 11] && !$d->canRedo(), 'redo does both again');

// Typing attributes at a caret, and the paragraph commands.
$d = new Document(Text::new("one\ntwo\nthree"));
$d->setSelection(2, 2);
$d->toggleItalic();
check($d->typingAttributes()->italic && !$d->text()->attributesAt(1)->italic, '⌘I at a caret changes only the typing attributes');
$d->insertText('X');
check($d->text()->attributesAt(2)->italic && $d->typingAttributes()->italic, 'what is typed next gets them');
$d->setSelection(0, 9);
$d->toggleList(ListKind::Tasks);
check($d->text()->paragraphStyleAt(0)->list === ListKind::Tasks && $d->text()->paragraphStyleAt(6)->list === ListKind::Tasks && $d->text()->paragraphStyleAt(12)->list === ListKind::NoList, 'toggleList makes the paragraphs selected items');
$d->toggleChecked(6);
check($d->text()->paragraphStyleAt(6)->checked && !$d->text()->paragraphStyleAt(0)->checked, 'toggleChecked checks one task');
$f = $d->formatting();
check($f->paragraph->list === ListKind::Tasks && $f->paragraphsMixed, 'formatting tells mixed paragraph styles');
$d->toggleList(ListKind::Tasks);
check($d->text()->paragraphStyleAt(0)->list === ListKind::NoList && !$d->text()->paragraphStyleAt(6)->checked, 'toggleList again takes them out of the list');
$d->indent(20);
same(ParagraphStyle::MAX_INDENT, $d->text()->paragraphStyleAt(0)->indent, 'indent stops at the deepest level');
$d->setHeading(2);
same(2, $d->text()->paragraphStyleAt(0)->heading, 'setHeading');
$d->setAlign(Params::END);
same(Params::END, $d->text()->paragraphStyleAt(6)->align, 'setAlign');

// A link at a caret: inserted linked to itself, then changed whole.
$d = new Document(Text::new('see '));
$d->setLink('https://x.dev');
check($d->text()->string() === 'see https://x.dev' && $d->text()->attributesAt(6)->link === 'https://x.dev', 'setLink at a caret inserts the URL linked');
$d->setSelection(8, 8);
$d->setLink('https://y.dev');
check($d->text()->attributesAt(4)->link === 'https://y.dev' && $d->text()->attributesAt(16)->link === 'https://y.dev' && $d->text()->attributesAt(3)->link === '', 'setLink inside a link changes it whole');

// The change log the editor reads.
$d = new Document(Text::new('abcdef'));
$v = $d->version();
$d->setSelection(1, 1);
$d->insertText('XY');
$d->setSelection(6, 8);
$d->insertText('');
$c = $d->since($v);
check($c !== null && $c->start === 1 && $c->oldEnd === 6 && $c->newEnd === 6, 'since composes the changes after a version');

// Attachments insert as one character.
$d = new Document(Text::new('ab'));
$d->setSelection(1, 1);
$d->insertAttachment($img);
check($d->text()->string() === "a\u{FFFC}b" && $d->text()->attributesAt(1)->attachment === $img && $d->selection() === [2, 2], 'insertAttachment');
