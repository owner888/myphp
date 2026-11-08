# Markdown

`Markdown($text)` shows Markdown, its blocks and inline styles, as
MarkdownUI's view; `streaming: true` while the text is still coming in, as
an assistant's answer, its last block kept open.

```php
$view = fn() => ScrollView()(Markdown("# Notes\n\nSome **bold** text and a list:\n\n- one\n- two")->padding());
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](custom-widgets.md)).

The classes of `MyPHP\UI\Markdown` show Markdown in native UI: CommonMark,
GitHub's tables, task lists, strikethrough and autolinks, footnotes and
math. They are built for the replies of language models, which stream in
token by token: text appended is parsed alone, half-written syntax shows as
what it will become, and text comes in smoothly, fading in.

```php
use MyPHP\UI\Markdown\{Markdown, Options};

$app = new class {
    public Options $md;  // kept in the app's state, shared by every view
    public string $readme = "# Hello\n\nSome *Markdown*, with `code`.\n";
    public function __construct() { $this->md = new Options(); }
};
$view = function (Context $c) use ($app) {
    Markdown::view($c, $app->readme, $app->md);
};
```

`Markdown::view()` shows a finished document. Give it the source each
frame, as a text its string: it parses again only what changed since the
last frame, and keeps what it made of the rest, the text layouts of its
paragraphs included.

## Streaming replies

`Markdown::stream()` shows Markdown still being written. Append the tokens
of a reply to its string as they come, and show it with `view()` once it is
complete, in the same place, so that the view carries on:

```php
use MyPHP\UI\Markdown\{Markdown, Options};

final class Message { public function __construct(public string $text = '', public bool $done = false) {} }

$md = new Options();
$m = new Message('**Thinking about** your ques');
$message = function (Context $c, Message $m) use ($md) {
    if ($m->done) {
        Markdown::view($c, $m->text, $md);
    } else {
        Markdown::stream($c, $m->text, $md);
    }
};
$view = fn(Context $c) => $message($c, $m);
```

Appending text parses only the outermost block still open before it, or
nothing but the new lines when no block is: a token costs the same at the
end of a long reply as at its start, and blocks already shown are neither
parsed nor laid out again.

The end of a reply shows what its syntax will most likely become, as the
chat apps show it, without changing its text: `**bol` is bold, an unclosed
code span is code, `$$x_i` (and `$x_i` with the dollar-math extension) is
math, whose `_` and `*` are TeX rather than emphasis,
`[the docs](https://ex` is a link whose destination is still coming (not
clickable yet), and an open code fence shows as code. Lines that could
still turn into something else wait for what follows them: a line of a few
markers, as `-` or `==`, which would make the paragraph above a heading,
and a table's header, which waits for its delimiter row so that it does not
flash as a paragraph of pipes. Blocks already finished are never changed,
and a reply shown with `view()` once done is exactly as CommonMark reads
it.

### Pace and fade

Tokens arrive in bursts. `stream()` shows text at an even pace instead, a
word at a time, catching up with what came within a quarter of a second,
and each word fades in. `Options::$reveal` changes how:

```php
use MyPHP\UI\Markdown\{Options, Reveal, RevealUnit};

$md = new Options(reveal: new Reveal(
    unit: RevealUnit::ByLine, // a line at a time, once complete
    catchUp: 0.4,             // text waits at most this long, in seconds
    fade: 0.5,                // and fades in over this
));
```

`RevealUnit::ByWord` (the default), `ByCharacter`, `BySentence`, `ByLine`
and `ByParagraph` show a unit at a time, the last three only once it is
complete: a sentence ends at a period, a question or exclamation mark
followed by a space, an ideographic full stop or the end of a line.
`AtOnce` shows text as it comes. A negative `fade` turns fading off, as
does the desktop's Reduce Motion. Text fades by its color and the rest of
blocks (list markers, code blocks, a table's rows) by their opacity, which
paint only: nothing is laid out again for it. What is still to reveal when
the message switches to `view()` keeps coming in.

## A chat

Show each message as a row of a [list](list.md) that follows its end: the
list keeps the end in view as the reply grows, gliding up to it with
`followAnimation`, until the user scrolls up, and follows again once they
scroll back to the end. `atEnd()` and `scrollToEnd()` make a button back to
the latest.

```php
use MyPHP\UI\Markdown\{Markdown, Options};

$app = new class {
    public ListState $list;
    public Options $md;
    public array $messages = [['id' => 1, 'text' => 'Hi!', 'done' => true], ['id' => 2, 'text' => 'Hello, how can', 'done' => false]];
    public function __construct() { $this->list = new ListState(); $this->md = new Options(); }
};
$app->list->key = fn(int $i) => $app->messages[$i]['id'];
$app->list->followEnd = true;
$app->list->followAnimation = Animation::smooth();
$view = function (Context $c) use ($app) {
    $c->list($app->list, count($app->messages), function (int $i) use ($c, $app) {
        $m = $app->messages[$i];
        $m['done'] ? Markdown::view($c, $m['text'], $app->md) : Markdown::stream($c, $m['text'], $app->md);
    })->grow(1)->gap(16)->justify(Node::END);
};
```

The [chat example](../../examples/markdown-chat) streams canned replies in
this way.

## Selecting and copying

A view's text is one selection across its blocks, as in a browser. Copy
puts the selected text on the clipboard, with blank lines between
paragraphs, a list's items on lines of their own and a table's cells
separated by tabs; as HTML, so that it pastes with its formatting into a
[rich text editor](rich-text-editor.md), mail or a word processor; and as
Markdown (`Transfer\Format::MARKDOWN`). The context menu's Copy as
Markdown, and Shift+Cmd+C, copy the source of the selection, completing the
emphasis, code and links it cuts through, or the whole document when
nothing is selected. Text keeps its selection as a reply streams in.

`shareSelection` leaves the selection to a `selectable()` container around
the view, as one across a chat's messages.

## Links and images

Links to `http`, `https` and `mailto` addresses open as a
[link](link.md)'s do, and show their address in a tooltip; a path without
a scheme goes there in the router around the view; `#a-heading` scrolls to
the heading of that anchor (`Markdown::slug()`); and links of other
schemes, as `javascript:`, do nothing. `onLink` handles every click
instead; `anchor` scrolls to a heading as the document shows:

```php
use MyPHP\UI\Markdown\Options;

$opened = [];
$md = new Options(onLink: function (string $url) use (&$opened) { $opened[] = $url; });
```

Images show their description until `loadImage` loads them, in a fiber of
its own; without it, they stay descriptions, as a view loads nothing from
the network by itself. `Markdown::fileImages($dir)` loads the files of a
directory. A paragraph of images alone shows them as large as they are, at
most as wide as the view.

```php
use MyPHP\UI\Markdown\{Markdown, Options};

$md = new Options(loadImage: Markdown::fileImages(__DIR__));
```

## Code

Fenced code is highlighted in its language with the tokenizers and the
colors of the [code editor](code-editor.md), a line at a time from the
state of the line before, so that a block streaming in highlights only its
new lines. A block has its language and a Copy button above its code,
which scrolls sideways (`wrapCode` wraps it instead). The button waits for
a block being written to close.

`codeBlock` builds code blocks in place of `Markdown::defaultCodeBlock()`,
and `code` the blocks of the languages it lists, as diagrams. Each gets the
context and a `CodeBlockView`: the block's `language`, its `code()` and
whether its fence is still `open`, and the parts of the default:

```php
use MyPHP\UI\Markdown\{CodeBlockView, Markdown, Options};

$md = new Options(
    code: [
        'mermaid' => function (Context $c, CodeBlockView $b) {
            if ($b->open) { // still streaming: show the code until it closes
                Markdown::defaultCodeBlock($c, $b);
                return;
            }
            $c->text('[a diagram of ' . strlen($b->code()) . ' bytes]');
        },
    ],
    codeBlock: function (Context $c, CodeBlockView $b) {
        $c->column(gap: 4);
          $c->text($b->language)->bold();
          $b->body($c);       // the highlighted, selectable code
          $b->copyButton($c); // the copy button
        $c->end();
    },
);
$view = fn(Context $c) => Markdown::view($c, "```php\necho 1;\n```\n\n```mermaid\ngraph TD\n```\n", $md);
```

## Math and HTML

Math between `$$` and `$$`, `\[` and `\]`, or `\(` and `\)` shows as its
TeX in the code font, as language models write it; `$…$` too with the
dollar-math extension, except where the dollars read as money. `math`
builds blocks of display math instead, as with a renderer of your own.

`inlineMath` makes math within text an object of the paragraph, from its
TeX and the size of the text around it: an `InlineObject` `width` wide,
`height` above the baseline and `depth` below it, which wraps with the
words and which its `draw` paints. Copied, as text or Markdown, it is its
source, as `$x_i$`. When it returns null, the math shows as TeX. It is
asked whenever its paragraph changes, as each frame of one streaming in,
so keep what it makes:

```php
use MyPHP\UI\Markdown\{InlineObject, Markdown, Options};

$formulas = [];
$md = new Options(inlineMath: function (string $tex, float $size) use (&$formulas): ?InlineObject {
    return $formulas["$tex@$size"] ??= new InlineObject(
        width: strlen($tex) * $size * 0.5, height: $size * 0.8, depth: $size * 0.2,
        draw: fn(Painter $p, array $r) => $p->stroke($r, 0x888888, 2, 1),
    ); // laid out once, kept by TeX and size
});
$view = fn(Context $c) => Markdown::view($c, "Where \\(x_i\\) is the mean.\n", $md);
```

Raw HTML shows as text, so that a reply cannot hide anything. Within text,
the tags of formatting that models write (`<b>`, `<i>`, `<u>`, `<s>`,
`<code>`, `<kbd>`, `<mark>`, `<sub>`, `<sup>` and `<br>`) apply; `html`
builds HTML blocks, as for `<details>` or `<think>`.

## Styling

A view looks as the window does, in its fonts and colors. `theme` sets what
changes, the rest taking `Theme::defaultTheme()`'s values for the window's
theme, so that a theme of one property changes one thing. Colors are
`[0xRRGGBB, alpha]`:

```php
use MyPHP\UI\Markdown\{HeadingStyle, Options, Theme};

$md = new Options(theme: new Theme(
    fontSize: 15,
    link: [0x7c3aed, 1.0],
    codeFont: 'JetBrains Mono',
    blockSpacing: 1,
    headings: [
        new HeadingStyle(size: 1.8, weight: 800, rule: true),
        new HeadingStyle(size: 1.4, rule: true),
    ],
));
```

A theme sets the font, the code font and their line heights; the colors of
text, secondary text, links, strong text and code; the size of code; the
spacing between blocks and list items; each heading level's size, weight,
color, spacing and rule; list indentation; block quotes' bar and text; code
blocks' background, border, corners, padding and token colors; tables'
lines, header, stripes, padding and widest column; marked text; and check
boxes. Lengths are in ems, times the font size, so that a theme scales with
it.

`block` replaces how any block shows: it is asked first for each block,
and builds it when it returns true, from the document's blocks and inlines
(see [the parser](#the-parser)):

```php
use MyPHP\UI\Markdown\{Markdown, Options};
use MyPHP\UI\Markdown\Parser\{Block, Document};

$md = new Options(block: function (Context $c, Document $d, int $i): bool {
    if ($d->block($i)->kind !== Block::THEMATIC_BREAK) {
        return false;
    }
    $c->text('⁂')->textAlign(Node::CENTER)->alignSelf(Node::STRETCH);
    return true;
});
$view = fn(Context $c) => Markdown::view($c, "One\n\n---\n\nTwo\n", $md);
```

## Accessibility

Headings are headings of their level, lists and their items lists and list
items, links links, tables tables of rows, column headers and cells, task
items check boxes, checked or not, images images named by their
descriptions, and code blocks groups named by their language, with a Copy
code button.

## The parser

The parser is `MyPHP\UI\Markdown\Parser`, which uses nothing of the UI, so
that what only reads Markdown (a rich text editor importing it, a server
rendering HTML) leaves the view out. `Document::parse()` reads a string,
`append()` adds text and `edit($from, $to, $text)` changes a range, each
parsing again only what the change touches. An edit parses from the
top-level block before it until a block starts, with nothing open, where
one started before, from which it keeps the blocks it had: typing in a long
document's preview parses a few blocks. A link defined later than where it
is used makes the blocks using it parse their inlines again, and only
those.

```php
use MyPHP\UI\Markdown\Parser\{Block, Document};

$d = Document::parse("# Title\n\n```php\necho 1;\n```\n");
$d->append("\nMore.\n");
$found = [];
foreach ($d->children(0) as $i) { // the top-level blocks
    if ($d->block($i)->kind === Block::CODE_BLOCK) {
        $found[] = $d->lines($i);
    }
}
$html = $d->html();
```

Blocks and inlines are flat arrays in document order, each with its byte
range in the source (`start` and `end`) and the index after its children
(`next`). `inlines($i)` parses a block's inlines the first time it is
asked. `new Document($ext)` reads other extensions than
`Extensions::DEFAULT`, and `setStreaming(true)` completes the end as
`stream()` does. It passes the examples of the CommonMark spec and those of
GitHub's extensions, in PHP.

## Performance

Frames build every block's nodes, as views do, while the parse, the spans
of paragraphs and their text layouts are kept: a document much longer than
a chat's reply is best shown a section at a time.
