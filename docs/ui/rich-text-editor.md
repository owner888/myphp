# Rich text editor

`TextEditor(document: $doc, options: $opts)` edits rich attributed text (`MyPHP\UI\RichText\Document`): headings, bold, italic, underline, strikethrough, inline code, colors, links, bulleted/numbered/task lists, blockquotes, code blocks, and inline image attachments, as macOS Notes and SwiftUI's `AttributedString` `TextEditor` do.

```php
use MyPHP\UI\RichText\{Document, EditorOptions, Text as RichText};

$app = new class {
    public Document $doc;
    public function __construct() {
        $this->doc = new Document(RichText::new("Hello Rich Text"));
    }
};
$view = fn() => VStack(spacing: 8)(
    HStack(spacing: 6)(
        Button('Bold', action: $app->doc->toggleBold(...)),
        Button('Italic', action: $app->doc->toggleItalic(...)),
        Button('Undo', action: $app->doc->undo(...))->disabled(!$app->doc->canUndo()),
    ),
    TextEditor(document: $app->doc, options: new EditorOptions(label: 'Note'))->frame(height: 240),
)->padding();
```

It lays out and draws only the paragraphs in view, so long documents edit as fast as short ones. See [`examples/richtext`](../../examples/richtext) and [`examples/notes`](../../examples/notes) for full editors with formatting toolbars.

## Attributed `Text` model

`MyPHP\UI\RichText\Text` is an immutable persistent value made of `Paragraph`s (each with a `ParagraphStyle`) containing `Run`s (each with `Attributes`):

```php
use MyPHP\UI\RichText\{Attributes, ListKind, Paragraph, ParagraphStyle, Run, Text as RichText};

$note = RichText::of(
    new Paragraph(new ParagraphStyle(heading: 1), [new Run('Groceries')]),
    new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [
        new Run('Milk, '),
        new Run('oat', new Attributes(weight: 700)),
    ]),
);
$note = $note->format(0, 9, function (Attributes $a) { $a->color = [0x2563eb, 1.0]; });
```

`Document` wraps a `RichText` value with selection, undo/redo history, Markdown/HTML/RTF import and export (`toMarkdown()`, `fromMarkdown()`, `toHTML()`, `marshalJSON()`), and formatting commands (`toggleBold()`, `toggleItalic()`, `setHeading()`, `toggleList()`, `setLink()`).
