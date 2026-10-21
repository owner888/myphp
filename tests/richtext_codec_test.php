<?php
// The codecs of rich text (mygo's ui/richtext codec_test.go and
// markdown_parse_test.go): Markdown, HTML, RTF and JSON, both ways.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Text\Params;
use MyPHP\UI\RichText\{Attachment, Attributes, Clip, ListKind, Paragraph, ParagraphStyle, RichText, Run, Text};
use Random\Engine\PcgOneseq128XslRr64;
use Random\Randomizer;

require_once __DIR__ . '/RichTextTest.php';

// TestMarkdown
$want = implode("\n", [
    '# Trip',
    '',
    'Land at **10:40** near the [castle](https://x.org/a), *slowly*.',
    '',
    '- [x] Passport',
    '- [ ] Adapter',
    '1. One',
    '    1. Inner',
    '2. Two',
    '',
    '> A quote',
    '',
    '```',
    'a := 1',
    'b := 2',
    '```',
    '',
    '~~<u>mid</u>~~ and `x()`',
]);
same($want, richSample()->markdown(), 'markdown of every kind of paragraph and run');
same('\# not a heading', Text::new('# not a heading')->markdown(), 'what Markdown would read as a block is escaped in a body paragraph');

// TestHTMLRoundTrip
$in = richSample();
$out = RichText::parseHTML($in->html());
same($in->string(), $out->string(), 'HTML round trip: the text');
same(runsOf($in), runsOf($out), 'HTML round trip: the runs');
$styles = true;
foreach ($in->paragraphs() as $start => $p) {
    if (!$out->paragraphStyleAt($start)->equals($p->style)) { $styles = false; }
}
check($styles, 'HTML round trip: the paragraph styles');

// TestParseForeignHTML
$gdocs = '<meta charset="utf-8"><b style="font-weight:normal;" id="docs-internal-guid-1"><p dir="ltr"><span style="font-size:11pt;font-family:Arial;font-weight:700;">Bold</span><span style="font-size:11pt;"> and </span><span style="font-style:italic;">italic</span></p><ul><li><p><span>item</span></p></li></ul></b>';
$got = RichText::parseHTML($gdocs);
same("Bold and italic\nitem", $got->string(), 'HTML as Google Docs copies it: the text');
same('*Bold*| and |_italic_', runsOf($got->slice(0, 15)), 'HTML as Google Docs copies it: the runs');
check($got->paragraphStyleAt(17)->list === ListKind::Bullets, 'HTML as Google Docs copies it: the list');
same("a b\nc\nd", RichText::parseHTML('<style>p{}</style><p>  a ' . "\n" . '  b  </p><script>x()</script><p>c<br>d</p>')->string(), 'whitespace collapses; scripts and styles are left out');

// TestJSON
$in = richSample();
check(Text::unmarshalJSON($in->marshalJSON())->equal($in), 'JSON round trip');

// TestParseRTF
$rtf = '{\rtf1\ansi\ansicpg1252\cocoartf2822
{\fonttbl\f0\fswiss\fcharset0 Helvetica;}
{\colortbl;\red255\green255\blue255;}
\pard\tx560\pardirnatural\partightenfactor0
\f0\fs24 \cf0 Plain \b bold\b0  and \i italic\i0 , caf\\\'e9 \u8364 ? {\field{\*\fldinst{HYPERLINK "https://go.dev"}}{\fldrslt site}} end.\
\pard\qc centered\
\pard\tx220\li720\fi-720\pardirnatural\partightenfactor0
\ls1\ilvl0{\listtext	\uc0舦 	}one\
{\listtext	\uc0舦 	}two\
}';
$got = RichText::parseRTF($rtf);
same("Plain bold and italic, café € site end.\ncentered\none\ntwo", $got->string(), 'RTF as TextEdit copies it: the text');
[$first] = $got->paragraphRange(0);
same('Plain |*bold*| and |_italic_|, café € |[site]| end.', runsOf($got->slice($first, 39)), 'RTF: the runs');
check($got->paragraphStyleAt(45)->align === Params::CENTER, 'RTF: centered');
check($got->paragraphStyleAt($got->len())->list === ListKind::Bullets, 'RTF: the list');

// TestMarkdownEscapes
$para = fn (ParagraphStyle $st, Run ...$runs) => new Paragraph($st, $runs);
foreach ([
    [Text::of($para(new ParagraphStyle(list: ListKind::Bullets), new Run('# not a heading'))), '- \# not a heading'],
    [Text::of($para(new ParagraphStyle(list: ListKind::Numbers), new Run('> x'))), '1. \> x'],
    [Text::of($para(new ParagraphStyle(heading: 2), new Run('Issue #'))), '## Issue \#'],
    [Text::new('---'), '\---'],
    [Text::new('-'), '\-'],
    [Text::new('    indented'), '&#32;&#32;&#32;&#32;indented'],
    [Text::new('Tom &amp; Jerry & co'), 'Tom \&amp; Jerry & co'],
    [Text::of($para(new ParagraphStyle, new Run('x!'), new Run('y', new Attributes(link: 'http://e')))), 'x\![y](http://e)'],
    [Text::of($para(new ParagraphStyle, new Run('a``b', new Attributes(code: true)))), '```a``b```'],
    [Text::of($para(new ParagraphStyle, new Run('`x', new Attributes(code: true)))), '`` `x ``'],
    // Delimiters that cannot open or close where they are.
    [Text::of($para(new ParagraphStyle, new Run('foo.', new Attributes(weight: 700)), new Run('bar'))), '<strong>foo.</strong>bar'],
    [Text::of($para(new ParagraphStyle, new Run('x'), new Run('(y)', new Attributes(italic: true)))), 'x<em>(y)</em>'],
    [Text::of($para(new ParagraphStyle, new Run('foo.', new Attributes(weight: 700)), new Run(' bar'))), '**foo.** bar'],
] as [$t, $want]) {
    same($want, $t->markdown(), 'markdown escapes ' . json_encode($t->string()));
}

// TestCodecsSanitize
$at = Clip::decode('{"paragraphs":[{"style":{"list":1,"indent":-1,"heading":99},"runs":[{"text":"x","attributes":{"link":" javascript:alert(1)"}}]}]}');
check($at !== null, 'the clipboard format decodes');
$st = $at->paragraphStyleAt(0);
check($st->indent === 0 && $st->heading === 6, 'styles out of range come in range');
same('', $at->attributesAt(0)->link, 'a link that runs a script is left out of the clipboard format');
$at->html();
$at->markdown();
foreach (['<a href=" javascript:alert(1)">x</a>', "<a href=\"java\tscript:alert(1)\">x</a>", '<a href="VBScript:x">x</a>'] as $src) {
    same('', RichText::parseHTML($src)->attributesAt(0)->link, "no link from $src");
}
same('', RichText::parseRTF('{\rtf1{\field{\*\fldinst HYPERLINK "javascript:alert(1)"}{\fldrslt x}}}')->attributesAt(0)->link, 'no RTF link that runs a script');
check(!str_contains(Text::of(new Paragraph(runs: [new Run('x', new Attributes(link: 'javascript:alert(1)'))]))->html(), 'javascript'), 'no HTML link that runs a script');
$img = RichText::parseHTML('<img src="x.png" width="NaN" height="-Inf">');
$a = $img->attributesAt(0)->attachment;
check($a !== null && $a->width === 0.0 && $a->height === 0.0, 'sizes that are not are 0');
$img->marshalJSON();
Clip::encode(Text::new('my old copy'));
same('theirs', Clip::decode('{"copy":"1","paragraphs":[{"runs":[{"text":"theirs"}]}]}')?->string(), "another app's copy with the name of this one's is its own text");

// TestHTMLQuotes
$in = Text::of(
    new Paragraph(new ParagraphStyle(heading: 2, quote: true), [new Run('QH')]),
    new Paragraph(new ParagraphStyle(list: ListKind::Bullets, quote: true), [new Run('item')]),
    new Paragraph(new ParagraphStyle(quote: true, codeBlock: true), [new Run('code')]),
    new Paragraph(runs: [new Run('after')]),
);
$quotes = [];
foreach (RichText::parseHTML($in->html())->paragraphs() as $p) { $quotes[] = $p->style->quote; }
same([true, true, true, false], $quotes, 'quoted lists, headings and code stay quoted through HTML');

// TestRTFWordPad
$at = RichText::parseRTF('{\rtf1{\pntext\f1\\\'B7\tab}{\*\pn\pnlvlblt{\pntxtb\\\'B7}}one\par{\pntext 1.\tab}{\*\pn\pnlvlbody}first\par
e\_mail vis{\v hid}ible\par alpha\cell beta\cell\row {\field{\*\fldinst HYPERLINK \\\\l "top"}{\fldrslt up}}\par}');
$got = [];
foreach ($at->paragraphs() as $p) {
    $got[] = $p->style->list->value . ':' . implode('', array_map(fn (Run $r) => $r->text, $p->runs));
}
same(['1:one', '2:first', "0:e\u{2011}mail visible", "0:alpha\tbeta\t", '0:up'], $got, 'RTF as WordPad and Word write it');
same('#top', $at->attributesAt($at->len() - 1)->link, 'RTF links to bookmarks');

// TestParseMarkdown
$paras = [];
foreach (richSample()->paragraphs() as $p) {
    $p->style->align = Params::START; // Markdown has no alignment
    $paras[] = $p;
}
same(dump(Text::of(...$paras)), dump(RichText::parseMarkdown(richSample()->markdown())), 'markdown reads back what it wrote');
$bold = new Attributes(weight: 700);
foreach ([
    ["- item\n\n  more\n\n      code\n- next", Text::of(
        $para(new ParagraphStyle(list: ListKind::Bullets), new Run('item')),
        $para(new ParagraphStyle(indent: 1), new Run('more')),
        $para(new ParagraphStyle(indent: 1, codeBlock: true), new Run('code')),
        $para(new ParagraphStyle(list: ListKind::Bullets), new Run('next')),
    )],
    ["> - [x] done\n>   - nested", Text::of(
        $para(new ParagraphStyle(list: ListKind::Tasks, checked: true, quote: true), new Run('done')),
        $para(new ParagraphStyle(list: ListKind::Bullets, indent: 1, quote: true), new Run('nested')),
    )],
    ["a  \nb", Text::of($para(new ParagraphStyle, new Run('a')), $para(new ParagraphStyle, new Run('b')))],
    ["1. a\\\n   b", Text::of($para(new ParagraphStyle(list: ListKind::Numbers), new Run('a')), $para(new ParagraphStyle(indent: 1), new Run('b')))],
    ["| a | b |\n|---|---|\n| 1 | **2** |", Text::of(
        $para(new ParagraphStyle, new Run("a\tb", $bold)),
        $para(new ParagraphStyle, new Run("1\t"), new Run('2', $bold)),
    )],
    ['x <u>under *it*</u> <b>b</b>', Text::of($para(new ParagraphStyle, new Run('x '), new Run('under ', new Attributes(underline: true)),
        new Run('it', new Attributes(underline: true, italic: true)), new Run(' '), new Run('b', $bold)))],
    ['![a *cat*](cat.png "t") [x](javascript:alert(1))', Text::of($para(new ParagraphStyle,
        new Run(Text::OBJECT_REPLACEMENT, new Attributes(attachment: new Attachment(name: 'a cat', url: 'cat.png'))),
        new Run(' x')))],
    ["see \$\$x^2\$\$, \\[a\\] and[^1]\n\n[^1]: The note.", Text::of(
        $para(new ParagraphStyle, new Run('see $$x^2$$, [a] and[1]')),
        $para(new ParagraphStyle, new Run('[1] The note.')),
    )],
    ['', new Text],
] as [$src, $want]) {
    same(dump($want), dump(RichText::parseMarkdown($src)), 'parseMarkdown ' . json_encode($src));
}

// TestMarkdownRoundTrip: random runs written as Markdown read back the
// same, as far as Markdown keeps them.
$rng = new Randomizer(new PcgOneseq128XslRr64(12));
$words = ['foo', 'bar', 'a', 'x1', '.', ',', '!', '?', '(', ')', '-', '#', '*', '_', '`', '~', '[', ']', '<', '>', '&', '\\', '1.', 'é', '"', "'", ':', '/'];
$pick = function () use ($rng): Attributes {
    $a = new Attributes;
    if ($rng->getInt(0, 2) === 0) { $a->weight = 700; }
    if ($rng->getInt(0, 2) === 0) { $a->italic = true; }
    if ($rng->getInt(0, 4) === 0) { $a->strikethrough = true; }
    if ($rng->getInt(0, 5) === 0) { $a->underline = true; }
    if ($rng->getInt(0, 5) === 0) { $a->link = 'https://e.org/' . $rng->getInt(0, 2); }
    if ($rng->getInt(0, 6) === 0) { $a = new Attributes(code: true); }
    return $a;
};
// What Markdown can keep: no spaces at the ends of a paragraph, and none at
// the ends of code, which a code span takes as its padding.
$normalize = function (Text $t): array {
    $out = [];
    foreach ($t->paragraphs() as $p) {
        $runs = [];
        foreach ($p->runs as $i => $r) {
            $s = $r->text;
            if ($i === 0) { $s = ltrim($s, ' '); }
            if ($i === count($p->runs) - 1) { $s = rtrim($s, ' '); }
            if ($r->attributes->code) { $s = trim($s, ' '); }
            if ($s === '') { continue; }
            if (($n = count($runs)) > 0 && $runs[$n - 1]->attributes->equals($r->attributes)) {
                $runs[$n - 1]->text .= $s;
                continue;
            }
            $runs[] = new Run($s, $r->attributes);
        }
        $p->runs = $runs;
        $out[] = $p;
    }
    return $out;
};
$mdFailures = 0; $firstFail = '';
for ($round = 0; $round < 3000; $round++) {
    $runs = [];
    for ($k = 1 + $rng->getInt(0, 4); $k > 0; $k--) {
        $s = '';
        for ($w = 0, $nw = 1 + $rng->getInt(0, 2); $w < $nw; $w++) {
            if ($w > 0 && $rng->getInt(0, 1) === 0) { $s .= ' '; }
            $s .= $words[$rng->getInt(0, count($words) - 1)];
        }
        $runs[] = new Run($s, $pick());
    }
    $want = Text::of(...$normalize(Text::of(new Paragraph(runs: $runs))));
    $md = $want->markdown();
    if (dump(RichText::parseMarkdown($md)) !== dump($want)) {
        $mdFailures++;
        if ($firstFail === '') { $firstFail = json_encode($want->string()) . ' as ' . json_encode($md) . ' reads ' . dump(RichText::parseMarkdown($md)) . ' want ' . dump($want); }
    }
}
same('', $firstFail, "random runs read back from Markdown ($mdFailures of 3000 failed)");
