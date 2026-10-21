<?php
// A rich text editor (mygo's gallery Rich Text page): a note with headings,
// tasks, lists, a quote, code and a link, and a toolbar formatting the
// selection, which shows the selection's formats. ⌘B, ⌘I, ⌘U, ⇧⌘X, ⌘E and
// ⌘K format; ⇧⌘7, ⇧⌘8 and ⇧⌘L make lists, Tab and ⇧Tab indent them;
// ⌥⌘1–3 make headings; "# ", "- " and **bold** format as you type.
// Run: ./run.sh richtext
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Text\Params;
use MyPHP\UI\{Content, Context};
use MyPHP\UI\RichText\{Attributes, Document, EditorOptions, FormatState, ListKind, Paragraph, ParagraphStyle, RichText, Run, Text};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** The text the editor starts with. */
function sampleNote(): Text
{
    $bold = new Attributes(weight: 700);
    $code = new Attributes(code: true);
    return Text::of(
        new Paragraph(new ParagraphStyle(heading: 1), [new Run('Trip to Lisbon')]),
        new Paragraph(runs: [new Run('Flights land at '), new Run('10:40', $bold), new Run(' on Friday. The hotel is in '),
            new Run('Alfama', new Attributes(italic: true)), new Run(', near the '),
            new Run('castle', new Attributes(link: 'https://en.wikipedia.org/wiki/S%C3%A3o_Jorge_Castle')), new Run('.')]),
        new Paragraph(new ParagraphStyle(heading: 2), [new Run('Pack')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks, checked: true), [new Run('Passport')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Walking shoes')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Adapter for '), new Run('type F', $code), new Run(' sockets')]),
        new Paragraph(new ParagraphStyle(heading: 2), [new Run('Eat')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Numbers), [new Run('Pastéis de nata', $bold), new Run(' at Manteigaria')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Numbers, indent: 1), [new Run('Warm, with cinnamon')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Numbers), [new Run('Grilled sardines')]),
        new Paragraph(new ParagraphStyle(quote: true), [new Run('Lisbon is a city to walk, slowly, uphill.')]),
        new Paragraph(runs: [new Run('Type '), new Run('# ', $code), new Run(', '), new Run('- ', $code), new Run(' or '), new Run('**bold**', $code), new Run(' to format as you write.')]),
    );
}

function view(Context $c, Document $doc): void
{
    $t = $c->theme;
    $f = $doc->formatting();
    $button = function (string $label, bool $on, Closure $run) use ($c, $t): void {
        $b = $c->button($label)->keepFocus();
        if ($on) { $b->background($t->accent, 0.18); }
        if ($c->clicked($b)) { $run(); }
    };
    $rule = fn() => $c->box(1, 20, $t->border);
    $c->column(20, 12, 1);
      $c->row(0, 4)->wrap($c->width - 40);
        $button('B', $f->bold === FormatState::FormatOn, $doc->toggleBold(...));
        $button('I', $f->italic === FormatState::FormatOn, $doc->toggleItalic(...));
        $button('U', $f->underline === FormatState::FormatOn, $doc->toggleUnderline(...));
        $button('S', $f->strikethrough === FormatState::FormatOn, $doc->toggleStrikethrough(...));
        $button('</>', $f->code === FormatState::FormatOn, $doc->toggleCode(...));
        $rule();
        foreach (['Body', 'H1', 'H2', 'H3'] as $level => $name) {
            $button($name, !$f->paragraphsMixed && $f->paragraph->heading === $level, fn() => $doc->setHeading($level));
        }
        $rule();
        $button('• List', $f->paragraph->list === ListKind::Bullets, fn() => $doc->toggleList(ListKind::Bullets));
        $button('1. List', $f->paragraph->list === ListKind::Numbers, fn() => $doc->toggleList(ListKind::Numbers));
        $button('☑ Tasks', $f->paragraph->list === ListKind::Tasks, fn() => $doc->toggleList(ListKind::Tasks));
        $button('❝', $f->paragraph->quote, $doc->toggleQuote(...));
        $button('{ }', $f->paragraph->codeBlock, $doc->toggleCodeBlock(...));
        $rule();
        $button('Left', $f->paragraph->align === Params::START, fn() => $doc->setAlign(Params::START));
        $button('Center', $f->paragraph->align === Params::CENTER, fn() => $doc->setAlign(Params::CENTER));
        $button('Right', $f->paragraph->align === Params::END, fn() => $doc->setAlign(Params::END));
        $rule();
        $undo = $c->button('Undo')->keepFocus();
        $undo->disabled = !$doc->canUndo();
        if ($c->clicked($undo)) { $doc->undo(); }
        $redo = $c->button('Redo')->keepFocus();
        $redo->disabled = !$doc->canRedo();
        if ($c->clicked($redo)) { $doc->redo(); }
      $c->end();
      RichText::editor($c, $doc, new EditorOptions(label: 'Note'))->size(0, 460);
      $c->muted('⌘B, ⌘I, ⌘U, ⇧⌘X, ⌘E and ⌘K format; ⇧⌘7, ⇧⌘8 and ⇧⌘L make lists, Tab and ⇧Tab indent them; ⌥⌘1–3 make headings.', 12);
    $c->end();
}

$doc = new Document(sampleNote());
App::setName('Rich text');
App::whenReady(function () use ($doc) {
    new Window(new WindowOptions(title: 'MyPHP native — Rich text', width: 820, height: 640,
        content: Content::view(fn(Context $c) => view($c, $doc))));
});
App::run();
