<?php
// The rich text editor (mygo's ui/richtext editor_test.go, theme_test.go
// and the editor's tests of codec_test.go and markdown_parse_test.go):
// typing, formats, undo, lists, headings, Markdown shortcuts, input
// methods, the pointer, the theme, copy and paste.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Text\Params;
use MyPHP\Transfer\{Data, Format, FormatUnavailable, Item, Representation};
use MyPHP\UI\{Context, Key, TextInputRange, Theme as UITheme};
use MyPHP\UI\Markdown\Markdown;
use MyPHP\UI\RichText\{Attributes, Document, EditorOptions, FormatState, HeadingStyle, ListKind, Paragraph, ParagraphStyle, RichText, Run, Text, Theme, View};

require_once __DIR__ . '/RichTextTest.php';

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

// TestRichTextTyping
$doc = new Document(new Text);
$rtChanged = false;
$h = richTester($doc, $rtChanged);
$h->type('plain ');
richKey($h, Key::COMMAND, Key::B);
$h->type('bold');
richKey($h, Key::COMMAND, Key::B);
$h->type(' more');
same('plain |*bold*| more', runsOf($doc->text()), 'typing with ⌘B');
check($rtChanged, 'typing reports a change');
$doc->setSelection(10, 10); $h->frame();
$h->type('er');
$doc->setSelection(6, 6); $h->frame();
$h->type('x');
same('plain x|*bolder*| more', runsOf($doc->text()), 'typing at the end of bold continues it; at its start, it does not');

// TestRichTextLinkEdges
$doc = new Document(Text::of(new Paragraph(runs: [new Run('see '), new Run('docs', new Attributes(link: 'https://x')), new Run(' now')])));
$h = richTester($doc);
$doc->setSelection(8, 8); $h->frame();
$h->type('!');
$doc->setSelection(6, 6); $h->frame();
$h->type('o');
same('see |[doocs]|! now', runsOf($doc->text()), 'a link grows inside, not at its end');

// TestRichTextCaretFormatIsNoStep
$doc = new Document(Text::new('hello'));
$h = richTester($doc);
richKey($h, Key::COMMAND, Key::B);
check(!$doc->canUndo(), '⌘B at a caret makes no step of undo');
check($doc->formatting()->bold === FormatState::FormatOn, 'the typing attributes are bold');
richKey($h, 0, Key::LEFT);
check($doc->formatting()->bold === FormatState::FormatOff, 'moving the caret forgets them');

// TestRichTextUndoRestoresAttributes
$doc = new Document(Text::of(new Paragraph(runs: [new Run('a '), new Run('bold', new Attributes(weight: 700)), new Run(' word')])));
$h = richTester($doc);
$earlier = $doc->text();
$doc->setSelection(2, 6); $h->frame();
richKey($h, 0, Key::DELETE);
same('a  word', $doc->text()->string(), 'Backspace deletes the selection');
richKey($h, Key::COMMAND, Key::Z);
check($doc->text()->equal($earlier), 'undo brings the bold back: ' . runsOf($doc->text()));
same([2, 6], $doc->selection(), 'and the selection');
richKey($h, Key::COMMAND | Key::SHIFT, Key::Z);
same('a  word', $doc->text()->string(), 'redo');

// TestRichTextFormatUndo
$doc = new Document(Text::new('one two'));
$h = richTester($doc);
$doc->setSelection(4, 7); $h->frame();
richKey($h, Key::COMMAND, Key::I);
richKey($h, Key::COMMAND, Key::U);
same('one |~_two_~', runsOf($doc->text()), '⌘I and ⌘U');
richKey($h, Key::COMMAND, Key::Z);
same('one |_two_', runsOf($doc->text()), 'one undo takes back one format');
same([4, 7], $doc->selection(), 'undo keeps the selection');

// TestRichTextLists
$doc = new Document(new Text);
$h = richTester($doc);
typeRunes($h, '- milk');
richKey($h, 0, Key::RETURN);
typeRunes($h, 'eggs');
richKey($h, 0, Key::RETURN);
$at = $doc->text();
same("milk\neggs\n", $at->string(), '"- " starts a list, Enter continues it');
$lists = [];
for ($i = 0; $i < 3; $i++) { $lists[] = $at->paragraphStyleAt($at->paraStart($i))->list; }
check($lists === [ListKind::Bullets, ListKind::Bullets, ListKind::Bullets], 'three items');
richKey($h, 0, Key::RETURN);
$at = $doc->text();
check($at->string() === "milk\neggs\n" && $at->paragraphStyleAt($at->len())->list === ListKind::NoList, 'Enter on an empty item leaves the list');
$doc->setSelection(5, 5); $h->frame();
richKey($h, 0, Key::TAB);
same(1, $doc->text()->paragraphStyleAt(5)->indent, 'Tab indents an item');
richKey($h, 0, Key::DELETE);
richKey($h, 0, Key::DELETE);
$st = $doc->text()->paragraphStyleAt(5);
check($st->list === ListKind::NoList && $st->indent === 0, 'Backspace at its start outdents it, then takes it out of the list');

// TestRichTextHeadings
$doc = new Document(new Text);
$h = richTester($doc);
typeRunes($h, '## Title');
richKey($h, 0, Key::RETURN);
typeRunes($h, 'body');
$at = $doc->text();
check($at->string() === "Title\nbody" && $at->paragraphStyleAt(0)->heading === 2 && $at->paragraphStyleAt(7)->heading === 0, '"## " makes a heading, which Enter ends');
$doc2 = new Document(new Text);
$h2 = richTester($doc2);
typeRunes($h2, '# ');
richKey($h2, Key::COMMAND, Key::Z);
check($doc2->text()->string() === '# ' && $doc2->text()->paragraphStyleAt(0)->heading === 0, 'undo takes the shortcut back to what was typed');

// TestRichTextInlineShortcuts
$doc = new Document(new Text);
$h = richTester($doc);
typeRunes($h, 'a **big** and `x` then');
same('a |*big*| and |`x`| then', runsOf($doc->text()), '**bold** and `code` as they are typed');

// TestRichTextCompose
$doc = new Document(Text::new('ab'));
$h = richTester($doc);
richKey($h, Key::COMMAND, Key::B);
$h->compose('ni', 2);
$h->compose('你', 1);
$h->compose('', 0);
$h->type('你');
same('ab|*你*', runsOf($doc->text()), 'a composition commits in the typing attributes');

// TestRichTextAppCommands
$doc = new Document(Text::new("first\nsecond"));
$h = richTester($doc);
$doc->setSelection(0, 3);
$doc->toggleBold();
$doc->setHeading(1);
$h->frame();
check(runsOf($doc->text()) === "*fir*|st\nsecond" && $doc->text()->paragraphStyleAt(0)->heading === 1, "the app's commands");
$h->type('X');
same("*X*|st\nsecond", runsOf($doc->text()), 'typing replaces the selection the app set');

// TestRichTextFormatMenu
$doc = new Document(Text::new('one two'));
$h = richTester($doc);
$doc->setSelection(0, 3); $h->frame();
richCommand($h, 'bold');
richCommand($h, 'alignCenter');
check(runsOf($doc->text()) === '*one*| two' && $doc->text()->paragraphStyleAt(0)->align === Params::CENTER, 'the Format menu');

// TestRichTextEdgesAsNSTextView: Enter and Backspace after bold keep typing bold, a link extends only inside, never at its start.
$bold = new Attributes(weight: 700);
$link = new Attributes(link: 'https://x');
foreach ([
    ['enter at the end of bold', Text::of(new Paragraph(runs: [new Run('plain '), new Run('bold', $bold)]), new Paragraph(runs: [new Run('next')])), 10,
        function (Headless $h) { richKey($h, 0, Key::RETURN); typeRunes($h, 'x'); }, "plain |*bold\nx\n*|next"],
    ['backspace a bold character', Text::of(new Paragraph(runs: [new Run('plain '), new Run('B', $bold)])), 7,
        function (Headless $h) { richKey($h, 0, Key::DELETE); typeRunes($h, 'x'); }, 'plain |*x*'],
    ['the start of a link', Text::of(new Paragraph(runs: [new Run('see '), new Run('docs', $link)])), 4,
        function (Headless $h) { typeRunes($h, 'x'); }, 'see x|[docs]'],
    ['a link starting a paragraph', Text::of(new Paragraph(runs: [new Run('docs', $link), new Run(' now')])), 0,
        function (Headless $h) { typeRunes($h, 'x'); }, 'x|[docs]| now'],
] as [$name, $text, $caret, $keys, $want]) {
    $doc = new Document($text);
    $h = richTester($doc);
    $doc->setSelection($caret, $caret); $h->frame();
    $keys($h);
    same($want, runsOf($doc->text()), "as NSTextView: $name");
}

// TestRichTextTypingIsOneStep
$doc = new Document(Text::new(''));
$h = richTester($doc);
typeRunes($h, 'abc');
richKey($h, Key::COMMAND, Key::Z);
same('', $doc->text()->string(), 'a word typed undoes whole');

// TestRichTextCursor: an arrow over a task's box, which a click checks, an I-beam over text and a hand over links.
$doc = new Document(Text::of(
    new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('milk')]),
    new Paragraph(runs: [new Run('see '), new Run('the site', new Attributes(link: 'https://example.com'))]),
));
$h = richTester($doc);
$find = function (string $want) use ($h): ?array {
    for ($y = 2; $y < 80; $y += 4) {
        for ($x = 0; $x < 200; $x += 4) {
            richMove($h, $x, $y);
            if ($h->rt->cursor === $want) { return [$x, $y]; }
        }
    }
    return null;
};
$p = $find('arrow');
check($p !== null, "an arrow over the task's box");
if ($p !== null) {
    $h->click($p[0], $p[1]);
    check($doc->text()->paragraphStyleAt(0)->checked, 'a click where the arrow shows checks the task');
    richMove($h, $p[0] + 60, $p[1]);
    same('ibeam', $h->rt->cursor, "an I-beam over the task's text");
}
check($find('pointer') !== null, 'a hand over the link');

// TestRichTextComposeUndo
$doc = new Document(new Text);
$h = richTester($doc);
typeRunes($h, 'hello');
richKey($h, 0, Key::LEFT);
richKey($h, 0, Key::RIGHT);
$h->compose('ni', 2);
$h->type('你');
same('hello你', $doc->text()->string(), 'what an input method commits');
richKey($h, Key::COMMAND, Key::Z);
same('hello', $doc->text()->string(), 'undo takes back what it committed, not the typing before it');

// TestRichTextComposeDropped
$doc = new Document(new Text);
$h = richTester($doc);
$start = textCaret($h);
typeRunes($h, 'abc');
$h->compose('nihao', 5);
$doc->setSelection(0, 0); $h->frame();
same($start[0], textCaret($h)[0], "a composition the app's selection drops leaves the caret where it put it");
$h->type('z');
same('zabc', $doc->text()->string(), 'and typing goes there');

// TestRichTextEnterAtCheckedTask
$doc = new Document(Text::of(new Paragraph(new ParagraphStyle(list: ListKind::Tasks, checked: true), [new Run('done')])));
$h = richTester($doc);
$doc->setSelection(0, 0); $h->frame();
richKey($h, 0, Key::RETURN);
$at = $doc->text();
check($at->string() === "\ndone" && !$at->paragraphStyleAt(0)->checked && $at->paragraphStyleAt(1)->checked, 'Enter at the start of a checked task keeps it checked, with an empty item above');

// TestRichTextTaskBoxOnly
$doc = new Document(Text::of(new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('todo')])));
$h = richTester($doc);
for ($x = 0; $x < 40; $x += 2) { $h->click($x, 300); }
check(!$doc->text()->paragraphStyleAt(0)->checked, "a click below a task doesn't check it");

// TestRichTextUnderscoreInWord
$doc = new Document(new Text);
$h = richTester($doc);
typeRunes($h, 'snake_case_name and _this_');
same('snake_case_name and |_this_', runsOf($doc->text()), 'snake_case stays as typed');

// TestRichTextCodeBlockSpace: a code block's last line gets its space below back when the line after it leaves the block.
$doc = new Document(Text::of(
    new Paragraph(new ParagraphStyle(codeBlock: true), [new Run('code')]),
    new Paragraph(new ParagraphStyle(codeBlock: true)),
    new Paragraph(runs: [new Run('after')]),
));
$h = richTester($doc);
$doc->setSelection(5, 5); $h->frame();
richKey($h, 0, Key::RETURN);
$doc->setSelection($doc->text()->len(), $doc->text()->len()); $h->frame();
$got = textCaret($h);
$fresh = richTester(new Document($doc->text()));
same(textCaret($fresh)[1], $got[1], 'the last line where it is laid out afresh');

// TestRichTextParagraphKeys: ⌥↑↓ by paragraphs.
$doc = new Document(Text::new("one two\nthree four\nfive"));
$h = richTester($doc);
$doc->setSelection(12, 12); $h->frame();
richKey($h, Key::OPTION, Key::UP);
same(8, $doc->selection()[1], '⌥↑ to the start of the paragraph');
richKey($h, Key::OPTION, Key::UP);
same(0, $doc->selection()[1], 'again to the one before');
richKey($h, Key::OPTION, Key::DOWN);
same(7, $doc->selection()[1], '⌥↓ to the end of the paragraph');

// TestRichTextLinkOpensSafely: only links Context::safeToOpen allows open.
$doc = new Document(Text::of(
    new Paragraph(runs: [new Run('run', new Attributes(link: 'calc'))]),
    new Paragraph(runs: [new Run('site', new Attributes(link: 'https://example.com'))]),
));
$h = richTester($doc);
$opened = [];
$h->rt->openURL = function (string $u) use (&$opened) { $opened[] = $u; };
for ($y = 2; $y < 80; $y += 4) {
    richMove($h, 10, $y);
    if ($h->rt->cursor === 'pointer') { $h->click(10, $y); }
}
check($opened !== [] && array_unique($opened) === ['https://example.com'], 'opened ' . json_encode($opened));

// TestThemeResolve
$def = Theme::defaultTheme(UITheme::light());
$red = [0xc80000, 1.0];
$th = Theme::resolve(new Theme(text: $red, codeSize: 0.8, headings: [new HeadingStyle(size: 2.5)]), $def);
check($th->headings[0]->size == 2.5 && $th->headings[0]->weight === 700 && $th->headings[1]->size == $def->headings[1]->size, 'headings resolve');
check($th->headings[0]->color === $red && $th->marker === $red && $th->code === $red, 'headings, markers and code follow the text');
check($th->codeSize == 0.8 && $th->indent == $def->indent && $th->link === $def->link && $th->quoteText === $def->muted, 'what a theme leaves unset takes the default');
check(Theme::resolve(null, $def) === $def, 'no theme is the default');

// TestThemeLook
$link = [0x7c3aed, 1.0]; $code = [0x007800, 1.0]; $heading = [0x0000c8, 1.0];
$th = new Theme(link: $link, codeFont: 'Menlo', codeSize: 0.8, code: $code, codeBackground: $code, codeBlockPadding: [1, 2], indent: 2,
    headings: [new HeadingStyle(3, 800, $heading, 1, 0.5)]);
$v = new View(new Document);
$v->opts = new EditorOptions(fontSize: 10, theme: $th);
$v->setLook(UITheme::light(), false);
$s = $v->look->span('x', new Attributes(link: 'https://x'), new ParagraphStyle);
check($s->color === $link[0], 'the link color');
$s = $v->look->span('x', new Attributes(code: true), new ParagraphStyle);
check($s->background === $code[0] && $s->font === 'Menlo' && $s->size == 8, 'code within text');
check($v->look->span('x', new Attributes, new ParagraphStyle(codeBlock: true))->color === $code[0], 'the text of a code block');
check($v->look->span('x', new Attributes, new ParagraphStyle(heading: 1))->color === $heading[0], "a heading's color");
[, $size, $weight] = $v->paraFont(new ParagraphStyle(heading: 1));
check($size == 30 && $weight === 800, "a heading's font");
[$family, $size] = $v->paraFont(new ParagraphStyle(codeBlock: true));
check($family === 'Menlo' && $size == 8, "a code block's font");
same([30.0, 10.0], $v->insets(new ParagraphStyle(indent: 1, codeBlock: true)), "a code block's insets");
$v->opts = new EditorOptions(theme: new Theme(padding: [20, 0]));
$v->setLook(UITheme::light(), true);
check($v->padX == 20 && $v->padY == UITheme::light()->space(2.5), "an editor's padding");
$v->setLook(UITheme::light(), false);
check($v->padX == 0 && $v->padY == 0, "an editorBase's padding");

// TestThemeChangeLaysOut
$doc = new Document(RichText::parseMarkdown("# Title\n\nSome `code` and a [link](https://x)."));
$th = null;
$h = new Headless(function (Context $c) use ($doc, &$th) { RichText::editor($c, $doc, new EditorOptions(theme: $th))->size(400, 200); }, 400, 200);
$shot = function () use ($h): string {
    $h->frame();
    $l = $h->paint();
    return \FFI::string($l->buf, $l->n * \MyPHP\UI\DisplayList::FLOATS_PER_RECT * 4);
};
$earlier = $shot();
$th = new Theme(link: [0xc80000, 1.0], headings: [new HeadingStyle(size: 2.4)]);
check($shot() !== $earlier, 'the theme changed, and the text with it');
$th = null;
check($shot() === $earlier, 'back to the default theme, the text shows as it did');

// TestCopyPaste
$doc = new Document(richSample());
$h = richTester($doc);
$doc->setSelection(5, 18); $h->frame(); // "Land at 10:40"
richKey($h, Key::COMMAND, Key::C);
$d = ($h->rt->readClipboardData)(RichText::FORMAT, Format::HTML, Format::TEXT);
check($d->formats() === [RichText::FORMAT, Format::HTML, Format::TEXT], 'copied in the editor\'s format, HTML and text');
same('Land at 10:40', $d->read(Format::TEXT), 'the plain text copied');
$doc->setSelection(0, 0); $h->frame();
richKey($h, Key::COMMAND, Key::V);
same('Land at |*10:40*', runsOf($doc->text()->slice(0, 13)), 'pasted with its formats');
richKey($h, Key::COMMAND, Key::Z);
check($doc->text()->equal(richSample()), 'one step of undo takes the paste back');
($h->rt->writeClipboardData)(new Data(new Item(Representation::bytes(Format::HTML, '<b>hi</b>'), Representation::bytes(Format::TEXT, 'hi'))));
$doc->setSelection(0, 0); $h->frame();
richKey($h, Key::COMMAND, Key::V);
same('*hi*', runsOf($doc->text()->slice(0, 2)), "another app's HTML pastes its formats");
richCommand($h, 'pasteAndMatchStyle');
same('*hihi*', runsOf($doc->text()->slice(0, 4)), 'Paste and Match Style pastes its text in the typing attributes');

// TestPasteFromMarkdownView: a Markdown view's selection pastes with its formatting, through HTML, and the Markdown Markdown apps copy.
$src = "## Plan\n\nSome **bold** and `code`.\n\n- [x] done\n";
$view = new Headless(function (Context $c) use ($src) { Markdown::view($c, $src); }, 500, 300);
$view->frame();
$n = $view->byText('Plan');
$view->click($n->x + 2, $n->y + $n->h / 2);
$view->input->push(['edit', 'selectAll']); $view->frame();
$view->input->push(['edit', 'copy']); $view->frame();
$data = ($view->rt->readClipboardData)(Format::TEXT, Format::HTML, Format::MARKDOWN);
check($data->formats() === [Format::TEXT, Format::HTML, Format::MARKDOWN], 'a Markdown view copies text, HTML and Markdown: ' . json_encode($data->formats()));
$want = Text::of(
    new Paragraph(new ParagraphStyle(heading: 2), [new Run('Plan')]),
    new Paragraph(runs: [new Run('Some '), new Run('bold', new Attributes(weight: 700)), new Run(' and '), new Run('code', new Attributes(code: true)), new Run('.')]),
    new Paragraph(new ParagraphStyle(list: ListKind::Tasks, checked: true), [new Run('done')]),
);
foreach ([Format::HTML, Format::MARKDOWN] as $fmt) {
    try { $b = $data->read($fmt); } catch (FormatUnavailable) { $b = ''; }
    $doc = new Document(new Text);
    $h = richTester($doc);
    ($h->rt->writeClipboardData)(new Data(new Item(Representation::bytes($fmt, $b), Representation::bytes(Format::TEXT, 'plain'))));
    richKey($h, Key::COMMAND, Key::V);
    same(dump($want), dump($doc->text()), "pasted from $fmt");
}
