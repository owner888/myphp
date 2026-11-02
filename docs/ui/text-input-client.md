# Text-input primitives

`$c->textInputBase()` and `$c->textAreaBase()` provide ordinary editable
text controls without a look. For a control with its own storage,
selections, rendering or editing policy, attach a `TextInputClient` to a
node:

<!-- no-test -->
```php
$c->column()->fill()
    ->handleTextInput($app->input)
    ->handleInput(fn(InputEvent $ev) => $app->input->handleInput($ev))
    ->draw(fn(Painter $p, array $r) => $app->input->draw($p, $r))
    ->label('Document')
    ->role(Role::TEXT_FIELD);
$c->end();
```

The primitive connects your state to the platform's input methods and
text queries. Your client owns its buffer, selection model, marked text and
undo history: a rope, a persistent tree or an incremental paragraph store;
MyPHP does not copy a whole document on each edit or choose formatting
behavior. `handleInput()`, `textCaret()`, `input()` and `textarea()` remain
available. `textCaret()` is useful for terminals and other widgets that
need a candidate-window location but do not provide a text document.
`inputMethod(false)` turns input methods off for either while keys still
type text, which then reaches `replaceText()` with no composition, as a
modal editor's normal mode wants.

The `TextState` of `input()` and `textarea()` holds the whole text as a
string, replaced on each edit. For indexed storage, use `TextBuffer` with
`$c->textInputBuffer()` or `$c->textAreaBuffer()`:

```php
// Keep the buffer across frames.
$document = new TextBuffer(implode("\n", array_map(fn($i) => "Line $i", range(1, 1000))));
$view = function (Context $c) use ($document) {
    $input = $c->textAreaBuffer($document, width: 0, height: 0)->fill()->font('monospace');
    if ($c->changed($input)) {
        // Length and version queries read tree summaries without flattening the text.
        $version = $document->version();
    }
};
```

`$c->textInputBufferBase()` and `$c->textAreaBufferBase()` provide the
unstyled variants. They share the string controls' selection, styling,
clipboard, input method and undo behavior. Native edits update the buffer
immediately, and `$c->changed()` reports them when the widget builds. A
programmatic buffer edit refreshes the control and clears its stale
selection and undo state; use `Window::update()` to schedule such changes
and redraw the view.

`TextBuffer` is a balanced tree of bounded UTF-8 chunks. It indexes bytes,
runes, UTF-16 units and newline counts. Small edits copy affected chunks
and tree paths; they do not copy the whole document or shift every
following paragraph's absolute offsets. `snapshot()` shares unchanged
chunks and remains valid after edits; `restore()` adopts one at once.

`replace($start, $end, $text)` and `slice($start, $end)` count runes, as
`TextState::textSelection()` does. `replaceUTF16()`, `textForRange()`,
`utf16Offset()` and `runeOffset()` bridge native ranges and keep surrogate
pairs whole. `string()` materializes the document; `writeTo($stream)`
streams its chunks to a file. The [text-buffer
example](../../examples/text-buffer) shows a 100,000-line editable
document, and the [text-input example](../../examples/text-input) a small
application-owned text field, with composition, pointer selection,
Edit-menu commands and custom rendering.

## Client contract

Keep the client across frames. `handleTextInput()` makes the node
focusable, with the text cursor; its size, appearance and other input
behavior are yours.

| Method | Purpose |
|---|---|
| `textForRange($range)` | Return the requested text and the actual clamped or adjusted range. |
| `selection()` | Report the primary native selection and its direction, a `TextInputSelection`. Additional carets stay in application state. |
| `markedRange()` | Report preedit in the current document, or null when none exists. |
| `replaceText($range, $text)` | Commit text in an explicit range; a null range means preedit if present, otherwise the selection. Clear preedit according to your editing policy. |
| `setMarkedText($range, $text, $selected)` | Replace preedit, retaining its marked range. `$selected` is relative to the newly marked text. |
| `unmarkText()` | Clear marked status while retaining document text. Also called when focus is lost, the client changes, or the node goes. |
| `boundsForRange($range)` | Return the node-relative rectangle of the text or caret, and the range it is of, or null for unavailable text. Scrolling the node into view, as the focus does, shows the caret's bounds rather than its box when they are available. |
| `indexForPoint($x, $y)` | Hit-test a node-relative point and return a document offset, or null. |

Ranges (`TextInputRange`) count **UTF-16 code units**, matching native
text services. This is an interop coordinate system, not a storage format.
A surrogate pair must stay whole, and your editing policy should respect
Unicode grapheme boundaries. `textForRange()` returns its adjusted range so
the platform knows what text it received.

```php
/** A field of one line over a plain string: ASCII alone, so a byte is a UTF-16 unit. */
final class AsciiField implements TextInputClient
{
    public string $text = '';
    public int $caret = 0;
    public ?TextInputRange $marked = null;

    public function textForRange(TextInputRange $r): array
    {
        $r = new TextInputRange(max(0, min($r->start, strlen($this->text))), max(0, min($r->end, strlen($this->text))));
        return [substr($this->text, $r->start, $r->end - $r->start), $r];
    }
    public function selection(): TextInputSelection { return new TextInputSelection(new TextInputRange($this->caret, $this->caret)); }
    public function markedRange(): ?TextInputRange { return $this->marked; }
    public function replaceText(?TextInputRange $r, string $s): void
    {
        $r ??= $this->marked ?? new TextInputRange($this->caret, $this->caret);
        $this->text = substr($this->text, 0, $r->start) . $s . substr($this->text, $r->end);
        $this->caret = $r->start + strlen($s);
        $this->marked = null;
    }
    public function setMarkedText(?TextInputRange $r, string $s, TextInputRange $selected): void
    {
        $r ??= $this->marked ?? new TextInputRange($this->caret, $this->caret);
        $this->text = substr($this->text, 0, $r->start) . $s . substr($this->text, $r->end);
        $this->marked = new TextInputRange($r->start, $r->start + strlen($s));
        $this->caret = $r->start + $selected->end;
    }
    public function unmarkText(): void { $this->marked = null; }
    public function boundsForRange(TextInputRange $r): ?array { return [[$r->start * 7, 0, max(1, ($r->end - $r->start) * 7), 16], $r]; }
    public function indexForPoint(float $x, float $y): ?int { return max(0, min((int)round($x / 7), strlen($this->text))); }
}

$field = new AsciiField();
$view = function (Context $c) use ($field) {
    $c->column()->size(200, 24)->handleTextInput($field)->label('Name')->role(Role::TEXT_FIELD)
        ->draw(fn(Painter $p, array $r) => $p->text($r[0], $r[1] + 12, $field->text, 13, $p->theme()->text));
    $c->end();
};
```

Callbacks run on the main thread, before the next frame. They must not
wait for work requiring that thread. After a native mutation, MyPHP asks
for a frame and reads the latest selection and caret before the next input
event. Stale native references cannot modify an unfocused, replaced or
disposed client.

On macOS the client supplies `NSTextInputClient` selection, marked
ranges, substring queries, range bounds and point hit testing. GTK uses
bounded surrounding text, and forwards surrounding deletions and preedit
and commit callbacks. Changing the client resets native preedit so a
candidate cannot move to a newly focused field.

Keyboard navigation, pointer selection, clipboard policy and Edit-menu
commands come through `handleInput()`. They belong to your control. This
permits an editor to choose multiple selections, custom keymaps, grouping
and history without overriding behavior built into a framework-owned
editor.

## Retained text geometry

`$c->shapeText($text, $width, $font)` and `$c->shapeRichText($spans,
$width, $font)` produce a `TextLayout` using Core Text or Pango. A positive
width wraps text; zero only breaks at newlines. A `TextStyle`'s
`lineHeight` sets the height of the lines as a multiple of the size, as
`lineHeight()` does for a text (about 1.8 suits CJK prose). Cache layouts
by paragraph or line and replace only those affected by an edit.

```php
$view = function (Context $c) {
    $layout = $c->shapeText('A paragraph of text to lay out.', 160);
    [$cx, $cy] = $layout->caret(5);
    $offset = $layout->indexAt(40, 8);
    $rects = $layout->selectionRects(new TextInputRange(0, 9));
    $text = $c->theme->text;
    $c->column()->size(160, 60)->draw(fn(Painter $p, array $r) => $p->textLayout($layout, $r[0], $r[1], $text));
    $c->end();
};
```

Layout offsets are UTF-16 too. `caret()` and `indexAt()` snap to whole
graphemes; `previousBoundary()` and `nextBoundary()` support logical
navigation and deletion. `selectionRects()` accepts multiple ranges and
keeps the separate visual pieces of bidi selections. It supplies geometry
without inventing a single-range selection model or choosing an editor's
navigation policy.

`$c->shapeRichText()` lays spans out as its arguments say, as the
paragraphs of a rich text editor or of a book:

- `align` aligns the lines within the width: `Node::START`, `CENTER` or
  `END`.
- `justify` stretches every line the width wraps to the width, but the
  last of each paragraph, widening the spaces between its words and the
  room after Han characters and kana. `align` places the last lines, and a
  line with nothing to widen, as a long word broken across lines.
- `indent` moves the text's first line in by that many points from the
  side its paragraph starts at, and wraps it that much narrower, as CSS's
  `text-indent`; lines after a newline are not indented.
- `noBreakWords` lets a word wider than the width overflow it rather than
  break.

```php
$view = function (Context $c) {
    $font = new TextStyle();
    $font->size = 17;
    $font->lineHeight = 1.5;
    $layout = $c->shapeRichText([new Span('Call me Ishmael. Some years ago, never mind how long precisely.')], 280, $font, justify: true, indent: 17 * 1.2);
};
```

The layout does all of this itself, from the lines the system's engine
breaks, so a paragraph wraps, stretches and indents alike on every
platform.

A soft hyphen (U+00AD) in any text is a place a line may break at, as many
EPUBs have them: it shows as a hyphen where a line breaks there, and as
nothing, taking no room, elsewhere, as in browsers. MyPHP does not
hyphenate words by itself.

`lines()` returns the lines of a layout, from the top, as `TextLine`s: each
one's box (`rect`, its x including the alignment and the indent), its
`baseline`, `ascent` and `descent`, the UTF-16 offsets it holds (`start`
and `end`, a newline ending it left out), and whether it reads right to
left (`rtl`). A reader that paginates finds how many lines of a paragraph
fit above a page's bottom, and the offset the next page starts at:

```php
$view = function (Context $c) {
    $layout = $c->shapeText(str_repeat('Words that wrap onto several lines. ', 20), 200);
    [$top, $pageBottom, $nextPageStarts] = [0.0, 120.0, null];
    foreach ($layout->lines() as $line) {
        if ($top + $line->rect[1] + $line->rect[3] > $pageBottom) {
            $nextPageStarts = $line->start;
            break;
        }
    }
};
```

A span with an `objectWidth` makes its object replacement characters
(U+FFFC) inline objects: boxes standing on the baseline, which lines break
around and which draw nothing; `objects()` gives their boxes, where an
editor draws its images, a reader its inline images and a math renderer
its formulas. A span's `baselineShift` raises its text or its objects that
many points above the baseline, or lowers them when negative, as
superscripts and subscripts are.

For visual navigation, keep a caret's position with its affinity
(`TextLayout::DOWNSTREAM` chooses the following logical text's edge,
`UPSTREAM` the preceding one; the edges may differ at a bidi boundary or a
wrap). `caretAt()` paints that edge, `positionAt()` preserves it when
hit-testing, and `moveCaret($index, $affinity, $direction)` moves one
visual grapheme left (negative) or right (positive).
`selectionRanges($anchor, $caret)` maps a visual gesture to logical UTF-16
ranges, which can have gaps in mixed-direction text; use the same ranges
for copy, deletion and replacement in your buffer.

The built-in controls use these visual edges and range sets too. Copy
joins the selected fragments in logical order; replacement preserves text
in the gaps, and undo restores the visual selection as well as the text.
