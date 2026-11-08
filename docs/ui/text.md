# Text

`Text` shows a line or a paragraph, which wraps at the width it is given,
as SwiftUI's. Its string is the key of its translation in the app's
strings ([languages](../i18n.md)), its placeholders named arguments,
`Text('Count: {count}', count: $n)`, as close as PHP comes to SwiftUI's
`Text("Count: \(n)")` (or `args:`, an array, for a placeholder named
`text`, `args` or `verbatim`). Not `Text("Count: {$n}")`: PHP joins
that before `Text` sees it, a key of its own for every number, which no
translation has. `Text(verbatim:)` shows a string as it is, as data
should be.

```php
$app = new class { public int $files = 12; };
$view = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
    Text('Settings')->font(Font::largeTitle)->bold(),
    Text(verbatim: "{$app->files} files, 3.4 MB")->foregroundStyle(Color::secondary),
    Text('A long description that wraps, and is cut after two lines with an ellipsis.')->lineLimit(2),
)->padding()->frame(width: 260);
```

A key is an ICU message, so its placeholders choose between forms as
they are, with or without a translation in the strings:
`Text('{n, plural, one {# word} other {# words}}', n: $words)` shows
"1 word" and "4 words", and a catalog gives each language its own forms.

## Style

`->font(Font::headline)` sets a text style: `largeTitle`, `title`,
`title2`, `title3`, `headline`, `subheadline`, `body`, `callout`,
`footnote`, `caption`, `caption2`, at the platform's size for each and
scaled with the system's text size (Dynamic Type on a phone);
`Font::system(size: 28, weight: Weight::semibold)` a size of its own.
`->bold()`, `->italic()`, `->underline()`, `->strikethrough()`,
`->monospaced()`, `->fontWeight()`, `->foregroundStyle()`,
`->multilineTextAlignment()` and `->lineLimit()` are SwiftUI's. Set on a
stack, the font and the color go down to the texts in it.

## Runs of their own style

A string with `**bold**`, `*italic*`, `` `code` `` or `~~struck~~` shows
them so, as SwiftUI's Markdown in a `Text`; an array of texts is their
concatenation, each in its own style, as SwiftUI's `Text(a) + Text(b)`:

```php
$view = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
    Text('Saved **3** notes, *just now*'),
    Text([Text('Error: ')->bold()->foregroundStyle(Color::red), Text(verbatim: 'the disk is full')]),
)->padding();
```

Longer documents with headings, lists and links are `Markdown($text)`
([markdown](markdown.md)).

## Selecting and copying

`->textSelection(TextSelectability::enabled)` lets the user select a text
and copy it, as SwiftUI's; on a stack, every text in it.

## Fonts

Text is laid out and drawn by the system's own text engine (Core Text on
macOS and iOS, Pango on Linux, Android's) in the system's font (SF on Apple
platforms, Roboto on Android, the desktop's interface font on Linux),
falling back to the system's fonts for other scripts and emoji as native
apps do, with right-to-left text in its order. `FontDesign::monospaced` in
`Font::system()` picks the system's monospaced font. `MyPHP\UI\Font::register()`
adds a font of the app's own to the engine (for a `Representable`'s text).

## Accessibility

Assistive technology reads a text as it shows; `->accessibilityLabel()`
reads something else in its place.
