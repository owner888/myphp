# Deviations from mygo

myphp is a 1:1 port of mygo. This file lists every place where it is not,
yet: what mygo does, what myphp does instead, and why. Each entry is to be
checked again later and either fixed or kept on purpose. Add an entry when
a port leaves something out or does it differently; take it out when the
port matches.

Format: **area — short name** (version found), then mygo / myphp / why.

## Text

- **Text and rich text are two kinds** (v0.81)
  - mygo: one text element; every `Text` is laid out by the text system.
  - myphp: `Node::TEXT` is measured by `TextMetrics::measure` (size,
    color, Font bits, an underline); only a style that needs more
    (`TextStyle::needsLayout`), `maxLines()`, `noWrap()` or a selection
    makes it a `RICH` node laid out by `MyPHP\Text\System`
    (`Node::toRich`).
  - Why: the tests and widgets were written against the old metrics
    (7 points a character, 16 a line in the headless stub).
- **Text does not wrap at the room it has** (v0.81)
  - mygo: a `Text` wraps at the width layout gives it.
  - myphp: text is one line, cut by `singleLine()`; `richText()` wraps at
    a `$width` given, or with `wrap: true` at its container's last-frame
    width, a frame late after a resize.
  - Why: the layout measures before it arranges and text is measured as
    it is built; wrapping during layout needs a measure pass that asks the
    text for its height at a width.
- **`singleLine()` keeps maxLines at 0** (v0.81)
  - mygo: `SingleLine` sets `single` and `maxLines = 1`.
  - myphp: only the single-line flag; the painter cuts it with an
    ellipsis at its arranged width.
  - Why: `maxLines` makes text a rich text laid out without a width, which
    loses the cut at the arranged width.
- **`children()` only on rich text; `Context::within`** (v0.81)
  - mygo: `Children(fn)` on every element.
  - myphp: containers take theirs between `column()`/`row()` and `end()`;
    `children()` exists on `richText()` alone and throws elsewhere.
    `Context::within` (public, `@internal`) pushes the node while the
    closure runs, as a node cannot reach the context's stack.
  - Why: the builder's stack is the Context's.
- **Underlines do not skip descenders** (v0.81)
  - mygo: `coreText.decorate` cuts the underline around the ink of
    glyphs that cross it (`inkInBand`), as AppKit does.
  - myphp: `System::decorate` draws the whole run.
  - Why: needs glyph outlines from CoreText; not ported yet.
- **Whole-word line breaks on macOS** (v0.81)
  - mygo: the CoreText engine's `noBreakWords` breaks by its own
    word-boundary rules.
  - myphp: `Native\TextEngine` approximates them (`lineBreaks`).
  - Why: ported in a hurry; to be compared with mygo's code.
- **Span style bits** (v0.81)
  - mygo: `Span` has `Font`, `Weight`, `Italic`.
  - myphp: `Span` keeps `$style` (`Font::BOLD`, `ITALIC`, `MONO`) beside
    them, mapped to weight 700, italics and the `monospace` family.
  - Why: existing apps and examples use the bits.
- **Colors with alpha** (v0.81)
  - mygo: `Color` carries its alpha (`SelectionColor(c)`, a span's
    `Color` dimmed by `Alpha`).
  - myphp: colors are 0xRRGGBB with a separate alpha:
    `selectionColor($rgb, $alpha)`, `textColor($rgb, $alpha)`,
    `Span::$alpha`.
  - Why: myphp's colors are ints throughout.
- **Hit-testing text inside a paragraph** (v0.81)
  - mygo: the hit chain (`hitChain`) finds an inline element by its
    frags.
  - myphp: `Runtime::under` checks a node's frags after its rect.
  - Why: myphp has no hit chain; same result.
- **Grid and flex margins** (v0.81)
  - mygo: `Margin`, `MarginX`, `MarginY`, `MarginStart`, `MarginEnd`,
    automatic margins.
  - myphp: none.
  - Why: not ported yet; it comes with the Markdown view, which uses it.

## Selecting text

- **A Selectable text alone is a container of itself** (v0.82)
  - mygo: a text with `Selectable` and no container has its own
    read-only editor, its selection shown while it has the focus
    (`textScope` 0).
  - myphp: it is a scope of its own id, through the shared
    `TextSelection`.
  - Why: myphp has no editor per text; what the user sees is the same.
- **The focus on a press on nothing focusable** (v0.82)
  - mygo: `pointerDown` gives the focus to the focusable, editable or
    selectable node under the press, or to none.
  - myphp: `pressAt` keeps the focus there was, except that a press on
    nothing focusable takes it from selectable text.
  - Why: clearing it always closed a combobox's options before the click
    on one chose it; myphp's popups do not keep the focus as mygo's do.
- **Keys through the text system's selectors** (v0.82)
  - mygo: `textSelectionKey` reads keys and modifiers, and the read-only
    editor moves by visual graphemes (`visualNext`) in bidi text.
  - myphp: AppKit's selectors (`moveRightAndModifySelection:`…); Home and
    End are told from Cmd and the arrows by the key event before the
    selector; Left and Right move by logical graphemes.
  - Why: myphp's keys come as selectors; visual movement to be ported.
- **Invisible and inherited disabled** (v0.82)
  - mygo: invisible, inert and disabled subtrees are left out
    (`collectSelectable`, `flagDisabled` on states).
  - myphp: no invisible nodes; a disabled container is seen as text is
    built (`Context::selectScope`), a disabled text as it is recorded.
  - Why: myphp's disabled is not inherited.
- **The clipboard's data** (v0.82)
  - mygo: darwin `clipboard.go` writes, reads, flushes and closes data,
    with a released callback.
  - myphp: `Pasteboard::writeData` alone (no `ReadData`, `Formats`,
    `Flush`, `Close`, callback).
  - Why: only Copy needed it so far.

## Box model and setters

- **Older setters under other names** (v0.85)
  - mygo: `AlignItems`, `Radius`, `MinWidth`/`MinHeight`, `Width`/`Height`,
    `Border(width, color)`, `Gap`, `Grow`, `Padding(v...)` on any element.
  - myphp: `align()`, `round()`, `min($w, $h)`, `size($w, $h)`,
    `border(?$color, $width)` (drawn over the padding, not inside it),
    the `$gap` and `$grow` properties or `column($pad, $gap, $grow,
    $color)`; mygo's names came only where myphp had none (`margin`,
    `borderWidth`, `borderColor`, `background`, `shrink`, `paddingX`,
    `paddingY`, `paddingStart`, `borderStartWidth`).
  - Why: written before the naming rule; renaming them is a sweep over
    the widgets and examples, to be decided.
- **Text takes no padding or border** (v0.85)
  - mygo: a text element has padding and borders as boxes do; a heading
    with a rule is the text with a bottom border.
  - myphp: text and rich text are leaves without them; the Markdown view
    puts a heading with a rule in a box that draws it.
  - Why: the text's origin is its node's corner throughout the painter and
    the selection.

## Markdown view

- **Colors** (v0.85)
  - mygo: `Theme` colors are `ui.Color`s with their alpha.
  - myphp: `[0xRRGGBB, alpha]` pairs; a span's background has its own
    opacity (`Span::$backgroundAlpha`), and borders take the color alone.
  - Why: myphp's colors are ints.
- **Images** (v0.85)
  - mygo: `LoadImage` runs on a goroutine of its own and the view shows the
    description until it is done; an image is at most 100% of the view's
    width (`MaxWidthPercent`) with its aspect ratio (`AspectRatio`).
  - myphp: the image loads on the main thread the first frame it is asked
    for; it is fitted to the width its row had last frame.
  - Why: PHP has no threads here; myphp has no percentage sizes or aspect
    ratios yet.
- **Right to left** (v0.85)
  - mygo: code reads left to right in a right-to-left interface
    (`Direction(LeftToRight)`), a quote's bar is on the side lines start.
  - myphp: left to right only.
  - Why: no right-to-left layout yet.

## Scrolling

- **ScrollIntoView inside a List** (v0.84)
  - mygo: a node inside a List's row scrolls the list to it, the list
    placing its rows anew from there and building those it lacked
    (`relayoutList`).
  - myphp: `Node::scrollIntoView()` scrolls the `ScrollState` containers
    around a node; a list scrolls to a row by its place
    (`ListState::scrollIntoView($row)`), which the app calls.
  - Why: myphp's lists are placed by their anchor as they are built; the
    relayout during layout is not ported.
- **ListState and GridState methods** (v0.84)
  - mygo: `ScrollTo(row, align)`, `ScrollIntoView(row)`, `ScrollToEnd()`,
    `Visible()`, `AtEnd()`; `GridState.ScrollTo(item, align)`.
  - myphp: `scrollTo($row, $smooth)` without an alignment, `end()`,
    `scrollIntoView($row)`, the public `$atEnd`, no `visible()`;
    `GridState::scrollTo($item)` puts the item's row at the top.
  - Why: written before the naming rule; to be renamed and aligned.

## Menus

- **The toolbar's overflow menu** (v0.83)
  - mygo: a toolbar's controls that do not fit go in a menu built as
    others are.
  - myphp: `Context::toolbar` pops a `MyPHP\Menu` of its own (`popupAt`),
    as the router's back button's history does.
  - Why: written before node menus; to be moved onto `Node::menu`.
- **What a menu looks at under the pointer** (v0.83)
  - mygo: the hit chain from the innermost element out
    (`menuTarget(chain)`).
  - myphp: the nodes with a menu, the text inputs, the selectable text and
    containers, and the Unselectable nodes under the point, the last built
    first (`menuChain`).
  - Why: myphp has no hit chain; the build order stands in for it.
- **Right to left, and Windows** (v0.83)
  - mygo: a right-to-left node's menu opens to the left, a menu button's
    at its bottom right; Windows opens context menus on the release, with
    Undo and Delete in a text input's.
  - myphp: macOS's menus only, left to right.
  - Why: myphp has no right-to-left layout and no other platform yet.

## Markdown parser

- **Test bounds** (v0.80)
  - mygo: pathological inputs within 1 s; 20 rounds of chunked appends;
    400 random edits.
  - myphp: 2 s; 5 rounds; 100 edits (ZZFUZZ runs more).
  - Why: PHP is slower; the full run would take minutes.
