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
  - myphp: `margin()`, `marginX()`, `marginY()` with automatic margins
    since v0.85; no `marginStart()` or `marginEnd()`.
  - Why: the Markdown view needed only those; the others come with
    right-to-left layout.

## Rich text

- **Value types as classes** (v0.86)
  - mygo: `Text`, `Attributes`, `ParagraphStyle` are comparable values;
    a format's closure gets a pointer to change.
  - myphp: classes; a `Text` never changes what it holds and hands out
    copies (`attributesAt()`, `paragraphStyleAt()`, `paragraphs()`,
    `runs()`), a format's closure gets a copy to change, equality is
    `equals()`; runs are `[runes, Attributes]` pairs.
  - Why: PHP objects are references.
- **Colors** (v0.86)
  - mygo: `Attributes.Color` and `Background` are `ui.Color`s, zero for
    none.
  - myphp: `[0xRRGGBB, alpha]` or null, as the Markdown theme's.
  - Why: myphp's colors are ints.
- **Constructors and enums** (v0.86)
  - mygo: `NewText`, `TextOf`, `NewDocument`, `Text{}`; `ListKind` and
    `FormatState` constants; `ParseMarkdown`, `ParseHTML`, `ParseRTF`,
    `Format`, `RTF` in the package; `Text.String()`; `MarshalJSON` and
    `UnmarshalJSON` the json package's.
  - myphp: `Text::new()`, `Text::of()`, `new Document($text)`,
    `new Text`; the enums `ListKind` and `FormatState` with mygo's case
    names; `RichText::parseMarkdown()`, `parseHTML()`, `parseRTF()`,
    `RichText::FORMAT`, `RichText::RTF`; `Text::string()`;
    `marshalJSON()` and the static `Text::unmarshalJSON()`, which throws
    on what is not JSON. Several results are arrays: `selection()`,
    `paragraphRange()`; `paragraphs()` and `runs()` are generators keyed
    by the rune each starts at. Alignment is `Params::START`, `CENTER`,
    `END`.
  - Why: PHP has no package functions to autoload, no multiple results.
- **No lock** (v0.86)
  - mygo: `Document`'s methods take a mutex, safe from any goroutine.
  - myphp: none; its fields are public, `@internal`, for the editor.
  - Why: PHP runs the app on one thread.
- **The HTML parser** (v0.86)
  - mygo: `golang.org/x/net/html`, a whole HTML5 tree builder.
  - myphp: `HtmlNode::parse`, a small one: tags, attributes, entities,
    raw text, void elements and the end tags HTML implies, without the
    adoption agency that reparents misnested formatting.
  - Why: no dependency; a paste's HTML is well formed enough.

- **The editor's layouts** (v0.87)
  - mygo: `ui.ShapeRichTextWith`, `ui.TextLayout` (UTF-16 offsets) and
    `Painter.TextLayout` are the ui package's, which the editor uses.
  - myphp: the editor's own `ParaLayout` (`@internal`, rune offsets),
    painted by `Painter::paintSpans`, which rich text nodes share; images
    by `Painter::picture`.
  - Why: `MyPHP\UI\TextLayout` is the legacy wrapping of text fields;
    a public shaped-layout API is to be decided.
- **The editor's options and look** (v0.87)
  - mygo: `EditorOptions.Font` is a `ui.Font`; `Editor` returns an
    element the app fills (`Fill()`); an attachment's `Draw` takes a
    `*ui.Painter`; theme colors are `ui.Color`s.
  - myphp: `$font` and `$fontSize`; the app sizes the node (`size()`,
    `$grow`); `draw` takes the `DisplayList` and the box; colors are
    `[0xRRGGBB, alpha]`.
  - Why: myphp's builders and colors.
- **macOS only** (v0.87)
  - mygo: Ctrl in place of Command elsewhere, Ctrl+L, E, R align, Alt
    and Ctrl move by words and paragraphs as Windows and GTK do.
  - myphp: macOS's keys alone.
  - Why: myphp runs on macOS.
- **The editor's menu and accessibility** (v0.87)
  - mygo: the context menu is `EditItems()`; assistive technology reads
    the runs' formats (`AccessTextRun`) and can select text
    (`InputSelect`).
  - myphp: Cut, Copy, Paste and Select All built by the editor's menu
    function; the text, selection and placeholder only.
  - Why: myphp's `editItems()` knows text fields and selectable text,
    and its `AccessNode` has no runs nor a text selection action.

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
  - myphp: `Pasteboard::writeData` and, since v0.87, `readData`
    (`Runtime::$readClipboardData`); no `Formats`, `Flush`, `Close` or
    callback.
  - Why: only Copy and the rich text editor's Paste needed them so far.

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

## SQLite

- **Contexts and goroutines** (v0.90)
  - mygo: `Open`, `Execute`, `Query` and `Transaction` take a
    `context.Context`, which cancels them (a progress handler in C); a
    `DB` is safe from any goroutine, its operations serialized.
  - myphp: no context: an operation runs to its end on the main thread,
    and a busy lock waits for `busyTimeout` only.
  - Why: PHP runs the app on one thread.
- **Values** (v0.90)
  - mygo: `[]byte` is a BLOB, `string` TEXT; integers of any size,
    `json.Number` and unsigned ones bind.
  - myphp: a string is TEXT and must be UTF-8; a `Blob` is a BLOB, in
    and out; `int`, `float`, `bool` and null bind.
  - Why: PHP's strings are bytes; PHP has one integer type.
- **Errors and options** (v0.90)
  - mygo: `*Error` with `Code`, `ExtendedCode` and `Message`;
    `ErrClosed` a sentinel; `BusyTimeout` a `time.Duration`; `sqlite.Open`
    a package function.
  - myphp: `Error` (getCode() the primary code, `$extendedCode`,
    `$sqliteMessage`) and `ErrClosed` exceptions; `$busyTimeout` in
    seconds; `Sqlite::open()`, `load()`, `libraryPath()`.
  - Why: PHP's exceptions and durations; no package functions.
- **The page plugin** (v0.91)
  - mygo: `sqlite.Plugin` and `sqlite.New(Options)` for `mygo.Use`; the
    service's methods `Open`, `Execute`… take a `context.Context`; the
    page side is the npm package `@mygo-plugins/sqlite` over
    `mygo-runtime`; `Options.BusyTimeout` a duration.
  - myphp: `Sqlite::plugin()` and `Sqlite::new(Options)` for `App::use`;
    lowercase methods (`plugin:sqlite.open`) that take a `CallContext`;
    the page side is `src/Plugins/Sqlite/client/index.ts`
    (`@myphp-plugins/sqlite`) over `window.myphp.call`, the same API and
    wire values; `$busyTimeout` in seconds.
  - Why: plugins are PHP (the developer's rule), and PHP's methods are
    named in lowercase.

## Fetch

- **The HTTP client** (v0.92)
  - mygo: Go's `net/http`: `Options.Client` (its cookie jar, transport,
    `CheckRedirect`); `Allow` gets the `*http.Request`; the request's
    context cancels it; a `User-Agent` of Go's.
  - myphp: libcurl through FFI, the system's; no `Client` option and no
    cookies kept; `Options::$allow` gets a `Request` (method, URL,
    headers); the page going away, or closing the channel, stops it; no
    `User-Agent` unless the page sets one.
  - Why: PHP has no HTTP client of its own beyond extensions; libcurl is
    on every Mac, through ext-ffi alone (the developer's choice).
- **Waiting without blocking** (v0.92)
  - mygo: `Fetch` blocks on its goroutine, sending parts as they come.
  - myphp: `fetch()` is a generator that yields the parts, and
    `Channel::wait()` between them while nothing came.
  - Why: one thread (the developer's choice of the mechanism).

## Plugins

- **Plugins are PHP** (v0.91)
  - mygo: a plugin is a Go package: `mygo.Plugin{Name, Service, Setup}`
    for `mygo.Use`, its page side an npm package.
  - myphp: `MyPHP\Plugin(name, service, setup)` for `App::use`, only PHP
    plugins (the developer's rule), their page side a TypeScript client
    in the plugin's `client/`; "myphp" is reserved as well as "mygo".
  - Why: the developer's rule; `myphp:` names the framework's own calls.
- **The context of a call** (v0.91)
  - mygo: a bound method's first parameter is a `context.Context`, from
    which `CallerWindow` and `CallerPage` read the caller, canceled as the
    page navigates or its window closes.
  - myphp: a parameter typed `CallContext`, anywhere: `window()`,
    `done()`, `afterFunc()`; a document ends when the next one starts (the
    bridge's `{"t":"page"}`) or the window closes. There is no `Page`
    object yet, so no `CallerPage`.
  - Why: PHP has no contexts; myphp's pages are its windows' WebViews.

## Terminal

- **Package functions and results** (v0.88)
  - mygo: `terminal.New`, `View`, `Load`, `LibraryPath`,
    `DefaultKeybinds`, `DarkTheme`, `LightTheme`, `GhosttyTheme`,
    `GhosttyThemes`, `ParseGhosttyTheme` in the package, with errors;
    `Done()` a channel; `Close()` an error; `Size()` two results; data
    as `[]byte`; `View(c, term).Fill().AutoFocus()`.
  - myphp: static methods of `Terminal` (`Terminal::new()`, `view()`…),
    which throw; `done(): bool`; `close(): void`; `size()` an array;
    strings; the view is a `Node` the app sizes (`$grow`), and
    `Node::autoFocus()` (mygo's AutoFocus, added for it).
  - Why: PHP has no package functions to autoload, no multiple results.
- **Colors and enums** (v0.88)
  - mygo: `Theme` of `ui.Color`s, zero for unset, `Palette [16]`;
    `CursorStyle`, `CopyOnSelect`, `RightClickAction`, `PasteStatus` and
    `Action` integer constants.
  - myphp: `0xRRGGBB` ints, null for the unset cursor and selection
    colors, the palette a list; enums with mygo's constant names as cases.
  - Why: myphp's colors are ints.
- **One thread** (v0.88)
  - mygo: a goroutine reads the program, another writes its input; the
    callbacks run on them; `Conn` is an `io.ReadWriteCloser` that blocks.
  - myphp: the terminal polls on the run loop (4 ms while busy, up to
    50 ms when quiet) and the callbacks run on the main thread; `Conn`'s
    `read()` returns '' for nothing yet and null at its end, its
    `write()` takes what it can; a `resize(cols, rows)` method of a Conn
    is called as the size changes, not on a goroutine.
  - Why: PHP runs the app on one thread.
- **Starting the program** (v0.88)
  - mygo: forks and execs in Go (`pty.Start`).
  - myphp: `proc_open` starts `Pty/spawn.php` in `PHP_BINARY` with FFI,
    which becomes the program; in a static app (`--launcher micro`) the
    app itself, whose stub runs spawn.php on `--myphp-pty-spawn`.
  - Why: a PHP running AppKit cannot fork safely, and PHP has no exec.
- **Which plugins an app uses** (v0.88)
  - mygo: the CLI ships the native libraries of the packages
    `go list -deps` finds the app importing.
  - myphp: `myphp build` ships those of the plugins whose namespace
    (`MyPHP\Plugins\<Name>`) the app's PHP files name; one built for
    the Mac it runs on, not universal.
  - Why: PHP has no import graph to ask; myphp builds for one
    architecture.
- **macOS only** (v0.88)
  - mygo: Windows (ConPTY, PowerShell) and Linux; Control+Shift+C and V
    and the other keybinds of Linux and Windows; AltGr; text that comes
    without its key on Linux.
  - myphp: macOS's keys and shell; `OnKey` and `Keybind` take myphp's
    `Key` codes (by their place on the keyboard) and modifier masks.
  - Why: myphp runs on macOS.
- **Font and wheel** (v0.88)
  - mygo: `Font.Thicken` draws a thicker stroke on macOS; sizes in DIPs;
    a mouse wheel's line is 40 DIPs, which scrolls three rows a notch.
  - myphp: `$thicken` is kept but not drawn (the text system has no
    stroke option); sizes in points; a line is 10 points, as the
    window's wheel events have it, and still scrolls three rows.
  - Why: myphp's CoreText rasterizer and its wheel events.
- **The pointer when the window loses the keyboard** (v0.88)
  - mygo: a press in the view gets its release when the window loses the
    keyboard, which ends a selection being dragged.
  - myphp: the runtime sends no release then; the test of it is not
    ported.
  - Why: myphp's runtime does not do it yet.
- **Images on the clipboard** (v0.88)
  - mygo: `ClipboardFormats` lists a TIFF as `transfer.PNG`, which
    reading converts.
  - myphp: `Runtime::$clipboardFormats` (`Pasteboard::formats`) lists
    the types as they are, without converting TIFF.
  - Why: the pasteboard's `readData` does not convert images yet.
- **Tests** (v0.88)
  - mygo: checks colors in the pixels of the tester's image, registers
    Go's fonts to measure ink.
  - myphp: checks the colors the display list holds and the cells' sizes
    with the stub text engine.
  - Why: the headless harness has no rasterizer nor fonts.
