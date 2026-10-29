<?php
// Helpers of the tests of rich text (mygo's ui/richtext tests).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Text\Params;
use MyPHP\UI\{Context, Key, TextInputRange};
use MyPHP\UI\RichText\{Attributes, Document, EditorOptions, ListKind, Paragraph, ParagraphStyle, RichText, Run, Text};

/** The runs of a text: their text, marked with what they are, as "plain|*bold*|_italic_|[link]" (mygo's runsOf). */
function runsOf(Text $at): string
{
    $parts = [];
    $last = null;
    foreach ($at->runs(0, $at->len()) as $i => $r) {
        if ($i > 0 && $last !== null && $r->attributes->equals($last) && $parts !== []) {
            // A run of the next paragraph, as the one before: inside the marks.
            $run = $parts[count($parts) - 1];
            $k = strlen(rtrim($run, '*_~`]'));
            $parts[count($parts) - 1] = substr($run, 0, $k) . $r->text . substr($run, $k);
            continue;
        }
        $last = $r->attributes;
        $s = $r->text;
        $a = $r->attributes;
        if ($a->bold()) { $s = "*$s*"; }
        if ($a->italic) { $s = "_{$s}_"; }
        if ($a->underline) { $s = "~$s~"; }
        if ($a->code) { $s = "`$s`"; }
        if ($a->link !== '') { $s = "[$s]"; }
        $parts[] = $s;
    }
    return implode('|', $parts);
}

/** The paragraphs of a text, their styles and runs, to compare (mygo's dump). */
function dump(Text $t): string
{
    $b = '';
    foreach ($t->paragraphs() as $p) {
        $st = get_object_vars($p->style);
        $st['list'] = $p->style->list->name;
        $b .= json_encode($st) . ':';
        foreach ($p->runs as $r) {
            $a = get_object_vars($r->attributes);
            $at = $a['attachment'];
            unset($a['attachment']);
            $b .= ' ' . json_encode($r->text, JSON_UNESCAPED_UNICODE) . json_encode(array_filter($a));
            if ($at !== null) { $b .= '[' . json_encode($at->name) . ' ' . json_encode($at->url) . ']'; }
        }
        $b .= "\n";
    }
    return $b;
}

/** A text of every kind of paragraph and run (mygo's sample). */
function richSample(): Text
{
    $bold = new Attributes(weight: 700);
    return Text::of(
        new Paragraph(new ParagraphStyle(heading: 1), [new Run('Trip')]),
        new Paragraph(runs: [new Run('Land at '), new Run('10:40', $bold), new Run(' near the '),
            new Run('castle', new Attributes(link: 'https://x.org/a')), new Run(', '), new Run('slowly', new Attributes(italic: true)), new Run('.')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks, checked: true), [new Run('Passport')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Adapter')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Numbers), [new Run('One')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Numbers, indent: 1), [new Run('Inner')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Numbers), [new Run('Two')]),
        new Paragraph(new ParagraphStyle(quote: true), [new Run('A quote')]),
        new Paragraph(new ParagraphStyle(codeBlock: true), [new Run('a := 1')]),
        new Paragraph(new ParagraphStyle(codeBlock: true), [new Run('b := 2')]),
        new Paragraph(new ParagraphStyle(align: Params::CENTER), [new Run('mid', new Attributes(underline: true, strikethrough: true)), new Run(' and '), new Run('x()', new Attributes(code: true))]),
    );
}

/** An editor of $doc filling a window, focused at the end of its text (mygo's richTester); $changed is set when a build reports a change. */
function richTester(Document $doc, ?bool &$changed = null, ?EditorOptions $o = null): Headless
{
    $changed = false;
    $h = new Headless(function (Context $c) use ($doc, &$changed, &$o) {
        $e = RichText::editor($c, $doc, $o)->size(500, 400);
        if ($c->changed($e)) { $changed = true; }
    }, 500, 400);
    $h->paints = true;
    $h->frame();
    $h->click(250, 380);
    richKey($h, Key::COMMAND, Key::DOWN);
    return $h;
}

/** A key with modifiers, as the view sends it to an element taking its input (mygo's Tester.Key). */
function richKey(Headless $h, int $mods, int $key): void { $h->key($key, '', $mods); }

/** Types $s a rune at a time, as a keyboard does (mygo's typeRunes). */
function typeRunes(Headless $h, string $s): void
{
    foreach (mb_str_split($s, 1, 'UTF-8') as $r) { $h->type($r); }
}

/** The pointer moved to ($x, $y), as the view sends it (mygo's Tester.Move). */
function richMove(Headless $h, float $x, float $y): void
{
    $h->input->mouse($x, $y);
    $h->input->push(['move', $x, $y]);
    $h->frame();
}

/** An edit command of the menus (mygo's Tester.Command). */
function richCommand(Headless $h, string $name): void
{
    $h->input->push(['edit', $name]);
    $h->frame();
}

/** The caret's rectangle, as the input methods see it (mygo's TextCaret). @return array{0: float, 1: float, 2: float, 3: float} */
function textCaret(Headless $h): array
{
    $s = $h->input->client->selection();
    return $h->input->client->boundsForRange(new TextInputRange($s->caret(), $s->caret()))[0];
}
