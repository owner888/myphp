# Rich text editor

`TextEditor(document: $doc)` edits rich text, a `RichText\Document` the
app keeps: headings, lists, links, images, as Notes does; `TextEditor(text:
$this->body)` edits plain text, as SwiftUI's.

<!-- no-test -->
```php
$view = fn() => TextEditor(document: $this->doc);
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](custom-widgets.md)).

The classes of `MyPHP\UI\RichText` edit attributed text, as NSTextView does
on macOS and SwiftUI's `TextEditor` bound to an `AttributedString`. They
support:

- **Formats:** bold, italic, underline, strikethrough, inline code, fonts,
  sizes and colors.
- **Links:** opened by a click, and set from the app or with ⌘K.
- **Paragraphs:** headings, bulleted, numbered and task lists, quotations,
  blocks of code and alignment.
- **Attachments:** images inside lines.
- **Editing:** the shortcuts and the Format menu of word processors, and
  undo that brings formats back with the text.
- **Exchange:** copy and paste in rich formats, input methods that compose
  in the typing attributes, Markdown export, and screen readers that read
  the text.

It lays out and draws only the paragraphs in view, so a long document edits
as a short one.

```php
use MyPHP\UI\RichText\{Document, RichText, Text};

// Kept by the app, as its other state.
$app = new class {
    public Document $doc;
    public array $saved = [];
    public function __construct() { $this->doc = new Document(Text::new('Hello')); }
    public function save(Text $t): void { $this->saved[] = $t->marshalJSON(); }
};
$view = function (Context $c) use ($app) {
    $editor = RichText::editor($c, $app->doc)->fill();
    if ($c->changed($editor)) {
        $app->save($app->doc->text());
    }
};
```

The [richtext example](../../examples/richtext) is an editor with a
toolbar, a link popover and the Format menu.

## Text

A `Text` is attributed text: paragraphs, each with a `ParagraphStyle`, made
of runs, each with its `Attributes`. It is a value, as a string is: its
methods return a new text and leave the one they are called on as it was,
sharing with it all they did not change. Keeping a version, to undo, to
save later or to compare, costs a reference.

```php
use MyPHP\UI\RichText\{Attributes, ListKind, Paragraph, ParagraphStyle, Run, Text};

$note = Text::of(
    new Paragraph(new ParagraphStyle(heading: 1), [new Run('Groceries')]),
    new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [
        new Run('Milk, '),
        new Run('oat', new Attributes(weight: 700)),
    ]),
);
$note = $note->format(0, 9, function (Attributes $a) { $a->color = [0x2563eb, 1.0]; });
$note = $note->replaceString($note->len(), $note->len(), "\nBread", new Attributes());
```

Offsets count runes, newlines included, as `TextState::textSelection()`
does. To read a text:

- `string()` and `plainText()` give its characters;
- `paragraphs()` and `runs()` give its structure;
- `attributesAt()` and `paragraphStyleAt()` describe one rune.

To change one:

- `replace()` and `replaceString()` change its text;
- `format()` changes the attributes of a range, its function getting a
  copy of each run's attributes to change;
- `formatParagraphs()` changes the styles of the paragraphs a range
  touches.

Attributes left at their defaults take the editor's style: its font, size
and color. `code`, `link` and `tag` (the app's own mark over a run, such as
a mention or a comment) hold only what they hold: text typed at their edges
does not join them.

A paragraph's style and the attributes of its newline belong to the
paragraph, as in word processors: an empty paragraph remembers what typing
gets there. When paragraphs are joined or split, each takes the style of
the paragraph its first rune comes from, as AppKit fixes paragraph styles:

- Deleting the newline after a heading keeps the heading.
- Deleting the heading whole, its newline included, leaves the paragraph
  after it.
- Text pasted at the start of a paragraph brings its own style.

Lists are flat, as in Quill and Automerge: a paragraph's `indent` is its
level, and items next to each other make a list, numbered at each level
from 1 (in letters and Roman numerals below the first).

A paragraph's `id` is the app's name for it, as the key of what the app
keeps about it, such as notes or a timeline. The editor does not show it,
and it follows the paragraph's text through edits:

- Splitting a paragraph keeps the ID in the first half, or in the second
  when Enter at its start moves the paragraph down. The other half has
  none.
- Joining two paragraphs keeps the first's, unless the first was empty.
- Paragraphs pasted or inserted have none: `replace()` never takes the IDs
  of the text it inserts, so no two paragraphs get one ID.

Undo and redo bring IDs back with the text, and JSON saves them. The
clipboard, HTML, Markdown and RTF leave them out. Give IDs as the text is
made (`Text::of()`, `Text::unmarshalJSON()`), or have the document name the
paragraphs that edits make, in the step of undo that makes them:

```php
use MyPHP\UI\RichText\{Document, Text};

$doc = new Document(Text::new("One\nTwo"));
$doc->identifyParagraphs(fn() => bin2hex(random_bytes(8)));

// On save, every paragraph has an ID:
$saved = [];
foreach ($doc->text()->paragraphs() as $start => $p) {
    $saved[$p->style->id] = [$start, $p];
}
```

`marshalJSON()` and `Text::unmarshalJSON()` save a text. `markdown()` and
`html()` export it, and `RichText::parseMarkdown()`, `parseHTML()` and
`parseRTF()` read Markdown, HTML and RTF, as a paste does.

## Documents

A `Document` is what an editor edits: a text, its selection with the
attributes typing gets there, and the history of undo. The app keeps it
from frame to frame, so a toolbar, a menu or a timer formats and edits it
while the editor shows it; the editor follows at its next frame.

Commands act on the selection as the editor's shortcuts do. On a range,
they change its runs or paragraphs as one step of undo. On a caret,
character formats change only the typing attributes, which the next typed
text gets and the next move of the caret forgets, as ⌘B does in
NSTextView.

```php
use MyPHP\UI\RichText\{Document, FormatState, RichText, Text};

$doc = new Document(Text::new('Some text'));
$view = function (Context $c) use ($doc) {
    $t = $c->theme;
    $f = $doc->formatting(); // the selection's formats, for a toolbar
    $bold = $c->button('B')->keepFocus();
    if ($f->bold === FormatState::FormatOn) {
        $bold->background($t->accent, 0.18);
    }
    if ($c->clicked($bold)) {
        $doc->toggleBold();
    }
    RichText::editor($c, $doc)->grow(1);
};
```

`formatting()` gives each character format as `FormatState::FormatOn`,
`FormatOff` or `FormatMixed`, along with the link the selection is in and
the style of its first paragraph. These methods change the document:

| Method | What it does |
|---|---|
| `toggleBold()`, `toggleItalic()`, `toggleUnderline()`, `toggleStrikethrough()`, `toggleCode()` | turns a format on, or off when all of the selection has it |
| `format($fn)` | changes any attribute, as a font, a size or a color |
| `setLink($url)` | links the selection, the link at the caret, or inserts the URL; `''` takes the link away |
| `setHeading($level)`, `toggleList($kind)`, `toggleQuote()`, `toggleCodeBlock()`, `indent($levels)`, `setAlign($a)` | change the paragraphs selected |
| `formatParagraphs($fn)` | changes any paragraph style |
| `toggleChecked($i)` | checks the task holding rune `$i` |
| `insertText()`, `insert()`, `insertAttachment()` | replace the selection |
| `edit($fn)` | changes it any way, as one step of undo |
| `undo()`, `redo()`, `breakUndoCoalescing()` | take steps back and forth |
| `setText($text)` | replaces the text, as opening another note, and clears the history |
| `identifyParagraphs($newID)` | names the paragraphs without an `id`, and those edits make |

`edit()` runs a function on an `Edit`, whose `replace()`,
`replaceString()`, `format()`, `formatParagraphs()` and `select()` change
the document as one step of undo. The selection follows what changes: a
caret where text is inserted goes after it, and a range does not grow at
its end.

```php
use MyPHP\UI\RichText\{Attributes, Document, Edit, Text};

$doc = new Document(Text::new('I hope you are well.'));
$doc->edit(function (Edit $e) {
    $e->replaceString(0, 0, "Dear Ann,\n", new Attributes());
    $e->format(0, 8, function (Attributes $a) { $a->italic = true; });
});
```

## The editor

`RichText::editor()` is the editor with the look of a text area: padding, a
background, a border and corners. `RichText::editorBase()` has none of
these, for an editor of the app's own look.

`$c->changed()` reports the edits the user made in the editor, not those
the app made through the document. `EditorOptions` set:

- `label` and `placeholder`;
- `font` and `fontSize`, the editor's font and size;
- `readOnly`, a text the user selects, copies, and clicks the links of;
- `noMarkdown`, which turns the Markdown shortcuts off;
- `onLink`, to open links itself instead of the system;
- `onLinkCommand`, to ask for a link's URL on ⌘K, as in a popover;
- `tagStyle`, to style the runs that carry a `tag`;
- `theme`, how the text looks (see [Theme](#theme)).

The keys are those of word processors:

| Key | macOS |
|---|---|
| Bold, italic, underline | ⌘B, ⌘I, ⌘U |
| Strikethrough | ⇧⌘X |
| Code | ⌘E |
| Link | ⌘K |
| Numbered, bulleted, task list | ⇧⌘7, ⇧⌘8, ⇧⌘L |
| Indent, outdent | ⌘], ⌘[ (Tab and ⇧Tab in a list) |
| Align left, center, right | ⌘{, ⌘\|, ⌘} |
| Heading 1 to 6, body | ⌥⌘1 to ⌥⌘6, ⌥⌘0 |
| Previous, next paragraph | ⌥↑, ⌥↓ |
| Start, end of the text | ⌘↑, ⌘↓ |

Paragraphs behave as in Notes and Google Docs:

- Enter continues a list, and on an empty item leaves it.
- Enter at the end of a heading starts a body paragraph.
- Backspace at the start of a list's item, a heading, a quotation or a
  block of code takes the style away before joining the paragraph to the
  one above.
- A click on a task's check box checks it, and a click on a link opens it:
  an `http`, `https` or `mailto` address, as Markdown views open them.
  `onLink` opens links itself instead.

The Markdown shortcuts format what is typed: at the start of a paragraph,
`# ` to `###### ` make headings, `- `, `* ` and `+ ` bulleted lists, `1. `
numbered ones, `[ ] ` and `[x] ` tasks, `> ` a quotation, and ```` ``` ````
then Enter a block of code. Around words, `**bold**`, `*italic*`,
`_italic_`, `` `code` `` and `~~strikethrough~~` format what they hold as
the closing mark is typed; an underscore inside a word, as in
`snake_case`, stays one. Undo takes a shortcut back to what was typed.

The [Format menu's roles](../menus.md#roles) format the editor with the
keyboard focus. Paste and Match Style pastes plain text in the typing
attributes.

## Theme

`EditorOptions::$theme` sets how the text looks: the colors of text, muted
text, links, the caret and the selection; the font, size and colors of
code, within text and in blocks, and the corners and room of blocks of
code; each level of heading's size, weight, color and room above and
below; the first-line indent of body paragraphs and the room between them;
the indentation of lists, the colors of their markers and check boxes; the
bar and text of quotations; and the padding of `editor()`, which replaces
the window theme's. What it leaves unset takes the value of the default
theme for the window's theme, so that a theme sets only what it changes,
and follows the window into dark mode. Colors are `[0xRRGGBB, alpha]`:

```php
use MyPHP\UI\RichText\{Document, EditorOptions, HeadingStyle, RichText, Text, Theme as TextTheme};

$doc = new Document(Text::new('Title'));
$violet = [0x7c3aed, 1.0];
$view = function (Context $c) use ($doc, $violet) {
    RichText::editor($c, $doc, new EditorOptions(theme: new TextTheme(
        link: $violet,
        checkbox: $violet,
        headings: [new HeadingStyle(size: 2.2, weight: 800)],
    )));
};
```

Lengths are in ems, times the editor's font size, so that a theme scales
with its font, but for the padding and the quotation bar's width, in
points. Changing the theme lays the text out again.

Body paragraphs (not headings, items of lists or code) run together by
default, as in notes. For prose, set them apart in either of two ways:

- `firstLineIndent` indents the first line of a body paragraph, as printed
  fiction does (about 1.2 ems). The first paragraph after a heading, a
  list, a block of code, an empty paragraph or one aligned otherwise isn't
  indented, nor is the first of a quotation. Only paragraphs aligned at the
  start are indented.
- `paragraphSpacing` puts room between two body paragraphs, as prose on
  screens does. Headings keep their own room above and below.

```php
use MyPHP\UI\RichText\{Document, EditorOptions, RichText, Text, Theme as TextTheme};

$doc = new Document(Text::new("It was a dark night.\nThe rain fell."));
$view = fn(Context $c) => RichText::editorBase($c, $doc, new EditorOptions(theme: new TextTheme(firstLineIndent: 1.2)));
```

The indent is no character: the caret, selection, clicks and arrow keys
skip over it, as in a word processor.

## Paragraphs on screen

`Document::visibleParagraphs()` returns the paragraphs in view in the
editor showing the document, as `ParagraphBox`es: each one's `index`, the
rune it `start`s at, and the `top` and `height` of its lines in points from
the top of the editor's node, scrolled. Read it as the view builds to line
a gutter up with the paragraphs, as a timeline, notes in the margin or
paragraph numbers. Put the gutter beside the editor with the same top, and
draw at its top plus each paragraph's:

```php
use MyPHP\UI\RichText\{Document, RichText, Text};

$doc = new Document(Text::new("One\nTwo\nThree"));
$view = function (Context $c) use ($doc) {
    $accent = $c->theme->accent;
    $boxes = $doc->visibleParagraphs();
    $c->row()->fill()->alignItems(Node::STRETCH);
      $c->column()->width(40)->clip()->draw(function (Painter $p, array $r) use ($boxes, $accent) {
          foreach ($boxes as $b) {
              $p->fill([$r[0] + 16, $r[1] + $b->top, 4, $b->height], $accent, 2);
          }
      });
      $c->end();
      RichText::editorBase($c, $doc)->grow(1);
    $c->end();
};
```

The boxes are where the editor paints the paragraphs in that frame, since
it lays them out as they are read. When a frame moves them from where they
were read, as the editor scrolls, its width changes or it reveals the
caret, it builds another, so the gutter follows without the app watching
the scrolling. The room above and below a paragraph is left out of its
box, so a mark at its top lines up with its first line. It lays out only
what is in view, as painting does, and returns none until an editor showed
the document.

## Typing at the edges of formats

Text typed takes the attributes of the character before the caret. At the
start of a paragraph it takes those of the character after it, and in
place of a selection those of the first character selected. These are
NSTextView's rules, and those of ProseMirror, Lexical and Quill:

- Typing at the end of bold continues bold; at its start, it does not.
- Typing at the end of a link, a code span or a tag does not continue it.
  Inside one, it does.
- After the text before the caret is deleted, typing gets its attributes
  until the caret moves: delete a bold word and type, and the text is bold.
- After Enter, typing keeps the attributes typed before it.

## Attachments

An attachment is an object replacement character (U+FFFC) whose
`Attributes::$attachment` holds an `Attachment`: an image, or a drawing of
the app's, laid out as a box inside the line, standing on the baseline. It
is selected, copied and deleted as one character, and lines break around
it. A box wider than the editor is scaled down to fit.

```php
use MyPHP\UI\RichText\{Attachment, Document, Text};

$doc = new Document(Text::new('A photo: '));
$photo = Image::fromPixels(2, 2, str_repeat("\x40\x80\xc0\xff", 4));
$doc->insertAttachment(new Attachment(image: $photo, name: 'Beach', width: 240));
```

Without an image or a `draw` function, an attachment shows its `name` in a
box, as an image not loaded yet. HTML and Markdown write an attachment's
`url`.

## Copy and paste

Copying puts the selection on the clipboard in three formats:

- the editor's own (`RichText::FORMAT`, JSON), which MyPHP apps paste
  whole, attachments included within the app;
- HTML;
- plain text.

Pasting reads the richest format the clipboard holds: the editor's own,
then HTML, then RTF, then Markdown (`Transfer\Format::MARKDOWN`, which
Markdown views and apps copy), then plain text. A [Markdown
view](markdown.md)'s selection pastes with its formatting.

- HTML, as browsers, mail, Word and Google Docs copy it, keeps its
  headings, lists, task lists, quotations, blocks of code, alignment, bold,
  italic, underline, strikethrough, code and links.
- RTF, as TextEdit and Pages copy it without HTML, keeps its paragraphs,
  alignment, lists, bold, italic, underline, strikethrough and links.
  Fonts, sizes and colors are left out, as Notes leaves them, so that what
  is pasted takes the editor's. Paste and Match Style (⇧⌥⌘V) pastes plain
  text in the typing attributes.

## Input methods

An input method composes at the caret in the typing attributes, as AppKit
and Qt show it, underlined while it composes. The whole composition is one
step of undo with what it replaced.

## Accessibility

Screen readers read the editor as a text field, with its text, its
selection and its placeholder, and move the selection too.

## Large documents

The text is a rope of chunks with a persistent sequence of paragraphs
beside it, so an edit copies what it touches and the path to it, and costs
O(log n). The editor lays out only the paragraphs in view, keeps the
layouts of those near it, and estimates the heights of the others.

## Markdown

`Text::markdown()` writes CommonMark with GitHub's extensions:

- headings, lists, task lists, quotations and fenced blocks of code;
- `**bold**`, `*italic*`, `~~strikethrough~~`, `` `code` ``, links and
  images;
- underlines as `<u>`, and as `<strong>`, `<em>` and `<del>` the marks
  that Markdown's delimiters cannot open or close where they are.

Alignment, fonts and colors have no Markdown and are left out.

`RichText::parseMarkdown()` reads it back, and what a model or another app
wrote: CommonMark with GitHub's extensions and footnotes, with the parser
of [Markdown views](markdown.md). A list item's paragraphs after its first
are indented under it, and a table's rows are paragraphs of their cells
apart by tabs. What `markdown()` writes reads back the same.
