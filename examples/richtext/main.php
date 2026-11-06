<?php
// A rich text editor (mygo's gallery Rich Text page): a note with headings,
// tasks, lists, a quote, code and a link, and a toolbar formatting the
// selection, which shows the selection's formats. ⌘B, ⌘I, ⌘U, ⇧⌘X, ⌘E and
// ⌘K format; ⇧⌘7, ⇧⌘8 and ⇧⌘L make lists, Tab and ⇧Tab indent them;
// ⌥⌘1–3 make headings; "# ", "- " and **bold** format as you type.
// Run: ./run.sh richtext
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;
use MyPHP\UI\RichText\{Attributes, Document, ListKind, Paragraph, ParagraphStyle, Run, Text};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

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

$doc = new Document(sampleNote());
App::setName('Rich text');
App::whenReady(function () use ($doc) {
    new Window(new WindowOptions(title: 'MyPHP native — Rich text', width: 820, height: 640,
        content: Content::file(__DIR__ . '/view.php', $doc)));
});
App::run();
