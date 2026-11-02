# Text

`$c->text()` shows text that wraps at the width it gets; format it with
`sprintf()`:

```php
$n = 12; $size = '3 MB';
$c->text('Settings')->fontSize(20)->bold();
$c->text(sprintf('%d files, %s', $n, $size))->textColor($c->theme->textMuted);
```

Its optional arguments are the size, the color and `Font` style bits
(`Font::BOLD`, `Font::ITALIC`, `Font::MONO`): `$c->text('42', 56, style:
Font::BOLD)`. `$c->muted()` shows text in the theme's muted color.

## Style

`fontSize()`, `fontWeight()`, `bold()`, `italic()`, `font()`,
`lineHeight()`, `fixedLineHeight()` (in points, whatever the font size),
`textColor()`, `textAlign()`, `underline()`, `wavyUnderline()`,
`dottedUnderline()`, `strikethrough()`, `decorationColor()` and
`decorationThickness()` (of the underline and strikethrough),
`textBackground()` (a highlight behind the lines), `letterSpacing()` and
`fontFeatures()` style it, set on the text or on any node above it, whose
texts inherit them. `fontFeatures()` turns on OpenType features of the
font by tag, or sets them with `tag=value`: `fontFeatures('tnum')` gives
digits of one width for numbers that change, `fontFeatures('liga=0')` turns
ligatures off. `textOutline()`, `textShadow()` and `textBlur()` draw an
outline, a shadow and a blur of the glyphs.

Text in Arabic, Hebrew and the other scripts written so reads right to
left, mixed with text reading left to right as Unicode's bidirectional
algorithm says: a paragraph reads in the direction of its first strongly
directional character. `textAlign()`'s `Node::START` is the left, or the
right of a paragraph reading right to left, and of any text of a node laid
out [right to left](right-to-left.md), where paragraphs without a strongly
directional character, as numbers, read right to left too.

`singleLine()` keeps text on one line, cut with an ellipsis, `maxLines()`
limits it to a few, and `ellipsis(' →')` ends what they cut with another
mark; `noWrap()` keeps its lines whole, breaking them only at newlines.
`selectable()` lets the user select a text with the pointer, Shift and the
arrows, and copy it, as an error message or an identifier to paste
elsewhere.

Put `selectable()` on a container to share one selection across its text
and rich text descendants:

```php
$c->column(gap: 12)->selectable();
  $c->text('First paragraph.');
  $c->text('Second paragraph.');
$c->end();
```

A drag can cross paragraphs in either direction. Shift-click and Shift
with the arrows extend it, and dragging near a scroll container's edge
scrolls it. Cmd+C, the Edit menu's Copy, and the context menu copy the
selected text, joined with newlines in the order the paragraphs were
built. Select All selects the whole container, including built paragraphs
outside its viewport. Inline text and links inside a paragraph are copied
as part of that paragraph.

`copyData()` on the container sets what Copy writes from the parts of the
paragraphs selected, as a document joins them with blank lines as text and
writes HTML too to keep their formatting, and `selectedTexts()` returns
those parts, for a menu item copying them otherwise. A paragraph whose
text grows at its end keeps the selection.

Nested `selectable()` containers have separate selections. Buttons and
text inputs keep their own interaction; their labels do not join the
container's selection. Use `unselectable()` on a text or a container to
exclude its subtree; inline nodes follow their paragraph's selection.
Changing the selected text or removing an endpoint clears the shared
selection. A virtualized list only includes the paragraphs it has built,
so use a regular scroll container when selecting an entire document.

The highlight is the theme's `selection`; `selectionColor()` sets another
for a node and the text inside it, as text on a colored bubble needs one
that shows on it. A right-click on selectable text shows Copy and Select
All; a `contextMenu()` on the text or its selectable container replaces
that menu, and `Menu::editItems()` puts Copy and Select All in it.

## Rich text

`$c->richText()` mixes styles in one paragraph: each `Span` sets what it
changes (font, size, weight, italics, color, underlines, strikethrough,
their color and thickness, a background, letter spacing, features) over
the style of the text, and the spans wrap together. A `radius` rounds a
span's background, which then hugs its glyphs rather than filling the
line, as code within text shows:

```php
$t = $c->theme;
$c->richText([
    new Span('Saved '),
    new Span('report.pdf', weight: 600),
    new Span(' to '),
    new Span('Documents', color: $t->accent, underline: true),
]);

$c->richText([
    new Span('Run '),
    new Span('php tests/run.php', font: 'monospace', background: $t->surface, radius: 4),
]);

$c->richText([
    new Span('Did you mean '),
    new Span('recieve', wavyUnderline: true, decorationColor: $t->danger),
    new Span('? '),
    new Span('match', background: 0xfacc15, backgroundAlpha: 0.4),
]);
```

Colors are `0xRRGGBB` ints; a span's `alpha` and `backgroundAlpha` set
their opacity. `richText($spans, wrap: true)` wraps at the container's
width.

A span with an `objectWidth` makes its object replacement characters
(U+FFFC) inline objects: boxes `objectWidth` wide, `objectHeight` above the
baseline and `objectDescent` below it, which wrap with the words and which
the line makes room for, as a formula's subscripts need. `drawObjects()`
draws them, after the glyphs, in the text's clip: it gets each one's rune
in the paragraph and its box.

```php
$c->richText([
    new Span('where '),
    new Span("\u{FFFC}", objectWidth: 20, objectHeight: 12, objectDescent: 4),
    new Span(' is the mean'),
])->drawObjects(fn(Painter $p, int $at, array $r) => $p->fill($r, 0x2563eb, 2));
```

A span's `baselineShift` raises its text that many points above the
baseline, or lowers it when negative, as superscripts and subscripts are,
whatever the font has for them; the line makes room for it, and the span's
underline and strikethrough go with it:

```php
$c->richText([
    new Span('H'),
    new Span('2', size: 10, baselineShift: -3),
    new Span('O boils at 100 °C'),
    new Span('1', size: 10, baselineShift: 6),
]);
```

A soft hyphen (U+00AD) in a text is a place a line may break at: it shows
as a hyphen where a line breaks there, and as nothing elsewhere, as in
browsers. When the hyphen itself does not fit and the line has no other
place to break but within a word, the hyphen overflows the width.

## Elements within a sentence

Text nodes built in a rich text's `children()` continue its paragraph, as
HTML's inline elements do: each styles its own text over the paragraph's,
and keeps what nodes do (`clicked()`, `hovered()`, the keyboard focus, a
`tooltip()`, a `contextMenu()`), with its words, on every line they take,
as its area. A [link](link.md) inside is a link within the sentence, which
Tab reaches and assistive technology reads as a link:

```php
$app = new class { public bool $example = false; };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    $c->richText([])->children(function () use ($c, $t, $app) {
        $c->text('Read ');
        $c->link('the guide', 'https://example.com/guide');
        $c->text(' or ');
        if ($c->clicked($c->text('show an example')->textColor($t->accent))) {
            $app->example = true;
        }
        $c->text('.');
    });
};
```

Only text nodes (`text()`, `link()`, `richText()`) go inside a text; their
sizes, padding, borders and corners do not apply, and a `background()`
highlights their text.

## Fonts

Text is laid out and drawn by the system's own text engine (Core Text on
macOS, Pango on Linux) in the system's font (SF, the desktop's interface
font, as GTK apps have it), falling back to the system's fonts for other
scripts and emoji as native apps do, with right-to-left text in its order.
`font('monospace')` picks the system's monospaced font, and
`Font::register()` adds your own:

<!-- no-test -->
```php
Font::register(file_get_contents(__DIR__ . '/Inter.ttf'), 'Inter');
```

Then `font('Inter')` uses it. A list of families, as
`font('Inter, Noto Sans JP')`, draws with the first the system or the app
has, and what it lacks with the next that has it, before the system's own
choice.

`Font::families()` lists the families text can use, as a font setting
offers them: the app's registered ones first, under the names it gave them
(or their own when it gave none), then the system's, each once and sorted
without regard to case. Each `FontFamily` tells whether it is
`monospaced`, for a terminal's or an editor's font setting, and whether the
app `registered` it:

<!-- no-test -->
```php
$fonts = [];
foreach (Font::families() as $f) {
    if ($f->monospaced) {
        $fonts[] = $f->name;
    }
}
```

The first call asks the system, which takes a while with many fonts
installed (tens of milliseconds with hundreds), without holding up the
windows' text; later calls reuse the system's list until
`Font::register()`, so fonts installed while the app runs show after a
restart.

Text that a widget lays out itself, as in the cells of a terminal, is
shaped once with `$c->shape()`: see [drawing](drawing.md#shaping-text).
