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

- **The editor's layouts** (v0.87, v1.08)
  - mygo: `ui.ShapeRichTextWith`, `ui.TextLayout` (UTF-16 offsets) and
    `Painter.TextLayout` are the ui package's, which the editor uses.
  - myphp: `UI\TextLayout` (UTF-16 offsets), `Context::shapeText` and
    `shapeRichText`, `Painter::textLayout` since v1.08, for apps; the
    editor keeps its own `ParaLayout` (`@internal`, rune offsets); the old
    wrapping of text fields is `UI\FieldLayout`.
  - Why: the editor came first and works in runes.
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
- **Inherited disabled** (v0.82, v1.07)
  - mygo: `IsDisabled` walks the element's parents; `disabled()` also
    reads the last frame's state, for an element disabled after it was
    built.
  - myphp: `Node::isDisabled()` is the node's own flag or that of the
    container it was built in (`$disabledIn`, set as it is added); the
    runtime keeps last frame's for `clicked()`. A container disabled after
    its children were built does not disable them.
  - Why: myphp's nodes hold no parent pointers.
- **The clipboard's data** (v0.82)
  - mygo: darwin `clipboard.go` writes, reads, flushes and closes data,
    with a released callback.
  - myphp: since v1.00 `Clipboard` writes, reads, lists and flushes, with
    `ClipboardOptions::$onRelease`; no `Close`.
  - Why: quitting flushes it, which is what `Close` is for in mygo.

## Box model and setters

- **Older setters under other names** (v0.85)
  - mygo: `AlignItems`, `Radius`, `MinWidth`/`MinHeight`, `Width`/`Height`,
    `Border(width, color)`, `Gap`, `Grow`, `Padding(v...)` on any element.
  - myphp: `align()`, `round()` (and mygo's `radius()` too),
    `min($w, $h)`, `size($w, $h)`,
    `border(?$color, $width)` (drawn over the padding, not inside it),
    the `$gap` and `$grow` properties or `column($pad, $gap, $grow,
    $color)`; mygo's names came only where myphp had none (`margin`,
    `borderWidth`, `borderColor`, `background`, `shrink`, `paddingX`,
    `paddingY`, `paddingStart`, `borderStartWidth`).
  - Why: written before the naming rule; renaming them is a sweep over
    the widgets and examples, to be decided.
- **Insets and percentages** (v1.03, v1.07)
  - mygo: `Top`, `Right`, `Bottom`, `Left`, `Start`, `End` and their
    `…Percent`s place an absolute element in its parent's padding box and
    move a relative one; `WidthPercent` and the other percentages keep an
    element in the flow.
  - myphp: the same since v1.07 (`absolute()`, `top()`…, `widthPercent()`
    in the flow); `at($x, $y)` stays myphp's own, placing from the
    content box. `grow()` takes fractions.
  - Why: `at()` came before the insets; the widgets place with it.
- **The automatic least size** (v1.07)
  - mygo: a flex item shrinks no smaller than its min-content size
    unless `MinWidth(0)` says so, as CSS.
  - myphp: the same; a basis of 0 in a row of long text needs
    `minWidth(0)` as in mygo (the grid view's cells, the diff's editors).
  - Why: kept as mygo; noted as a change from myphp before v1.07.
- **Element styles** (v1.07)
  - mygo: `Shadow(x, y, blur, spread, color)` and `InsetShadow` with a
    Gaussian blur (sigma half the blur), continuous (squircle) corners,
    clips with rounded corners, pixel-snapped boxes, borders over clipped
    children (`borderOver`), dividers before absolute children, `Debug`
    walking the subtree once, `Disabled` dimming any element, and
    `Cursor` as a `uint8` enum.
  - myphp: the same `shadow()` and `insetShadow()` (`$rgb` and `$alpha`
    for the color) through two new quad kinds, a box (14) and a box
    shadow (15), each with a second slot; circular corners; rectangular
    clips; boxes unsnapped; borders under clipped children; dividers
    after all children; a nested `debug()` outlines its own subtree;
    only containers dim when disabled (widgets look disabled their own
    way); `Cursor` a string-backed enum named as CSS names them.
  - Why: the display list's quads hold one radius and rectangular clips;
    myphp's widgets drew their disabled look before.
- **Input queries** (v1.07)
  - mygo: `PointerPosition`, `DragVelocity`, `DragEnded`,
    `PressedOutside`, `OverlayShortcut`, `Dismissed` are methods of the
    element; `TrackScroll(&s)`; `SetTextSelection` on the input;
    `TextRange` with a `Weight`; `TrackLines` lays lines out as needed.
  - myphp: `Context::pointerPosition($n)` (and the others) as myphp's
    `clicked($n)`; `pressedOutside` reports a press once a frame;
    `overlayShortcut` ranks overlays by last frame's order; the scroll
    state is given with `scroll($state)`; `TextState::setTextSelection`;
    `TextRange` with a color alone; `TextLines` reads the area's last
    layout, every top 0 before its first paint.
  - Why: myphp's queries are the context's; its inputs measure their
    text in one font, so a bolder run would move the caret off.
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

## File watches

- **Waiting** (v0.93)
  - mygo: `WatchFiles(ctx, path, opts)` runs a goroutine per watch, which
    debounces and looks while the caller does something else; `Next(ctx)`
    blocks, its context canceling the wait; canceling the watch's context
    stops it; `Done()` is a channel, `Close()` returns an error; io.EOF
    ends it.
  - myphp: `App::watchFiles($path, $opts)` with no context; the watch
    looks only inside `next(float $timeout = INF)`, which returns null at
    the end (io.EOF), `[]` when the timeout passed (the context's
    deadline), and throws the error that stopped it; `done(): bool`;
    `close(): void`. What changed while `next()` wasn't called comes in
    one batch, where mygo's goroutine had already taken a first one.
  - Why: PHP runs the app on one thread.
- **Durations, stats and handles** (v0.93)
  - mygo: `Debounce` and `MaxDelay` are durations; modification times
    have nanoseconds; the scan holds the root through `os.Root` and lists
    directories through the handle it checked.
  - myphp: seconds as floats; PHP's `lstat` gives whole seconds, so a
    same-size change within a second shows only when the system reports
    the write (it does, for files watched); paths are checked by `lstat`
    before and after a directory is listed.
  - Why: PHP's stat API; PHP has no openat.
- **Linux** (v0.93)
  - mygo: one inotify instance for the whole app.
  - myphp: an instance per watch.
  - Why: Linux is where myphp's tests run, not its apps; simpler.
- **The watch plugin** (v0.93)
  - mygo: `watch.Plugin`, `watch.New(Options)`; `Resolve` returns
    `(Root, bool)`; `Resolve` and `Allow` take a `context.Context`; the
    service's `Watch` blocks and returns `{}` or `{code, message}`; a call
    not from a page is denied; the page side is `@mygo-plugins/watch`.
  - myphp: `Watch::plugin()`, `Watch::new(Options)`; `$resolve` returns a
    `Root` or null; both take the `CallContext`; `plugin:watch.watch` is a
    generator yielding `Channel::wait` between polls; the page side is
    `src/Plugins/Watch/client/index.ts` (`@myphp-plugins/watch`), the same
    API and wire.
  - Why: plugins are PHP (the developer's rule); one thread.

## WebSocket

- **The client** (v0.94)
  - mygo: Go's `net/http` makes the handshake: `Options.Client`, a
    `User-Agent` of Go's; `Allow` gets the `*http.Request`; durations.
  - myphp: libcurl through FFI only connects, and the plugin writes the
    handshake; no `Client` option and no `User-Agent`; `Options::$allow`
    gets a `Request` (URL, headers); `$closeTimeout` in seconds.
  - Why: as fetch, libcurl is the system's HTTP client through ext-ffi.
- **The service** (v0.94)
  - mygo: `Connect` and `Send` block on their goroutines; the page side is
    `@mygo-plugins/websocket` over `mygo-runtime`, whose `Channel` takes an
    onmessage function.
  - myphp: `plugin:websocket.connect` and `send` are generators
    (`send` without a channel, waiting with `Channel::wait`); the page
    side is `src/Plugins/WebSocket/client/index.ts`
    (`@myphp-plugins/websocket`), the same API and wire, setting the
    channel's `onmessage`.
  - Why: plugins are PHP (the developer's rule); one thread.

## Updates

- **Waiting and canceling** (v0.95)
  - mygo: `Updater.Check(ctx)` and `Update.Install(ctx, progress)` block
    on their goroutine; the context cancels them; `progress` gets int64s.
  - myphp: `Updater::check(?Closure $canceled)` and
    `Update::install(?Closure $progress, ?Closure $canceled)`, which wait
    in a fiber (`App::go`, new) or turn the run loop; `$canceled` returns
    true to stop them.
  - Why: PHP runs the app on one thread; fibers are its goroutines (the
    developer's choice).
- **Where the feed and key are** (v0.95)
  - mygo: `-ldflags -X` puts them in the executable; `mygo.config.ts` or
    `mygo.json` configures them; `MYGO_UPDATER_PRIVATE_KEY`;
    `mygo-update.key` in `mygo/update-keys` of the config directory.
  - myphp: `myphp build` writes them in Info.plist (`MyPHPUpdateFeed`,
    `MyPHPUpdateKey`); `myphp.json`'s `updates`;
    `MYPHP_UPDATER_PRIVATE_KEY`; `myphp keygen -out` writes
    `myphp-update.key` in `~/Library/Application Support/myphp/update-keys`.
  - Why: a PHP app has no link step; Info.plist is in the signed bundle.
- **What the build publishes** (v0.95)
  - mygo: `mygo build -upload` uploads to a GitHub release or S3
    (`updates.s3`); Linux and Windows archives and `install.sh`; deltas
    patch files of up to 256 MiB.
  - myphp: macOS bundles only; `myphp build -upload` uploads the disk
    images and updates as mygo's (v1.12); deltas patch files of up to
    32 MiB.
  - Why: myphp builds macOS apps; PHP's bsdiff takes ~70 bytes of memory a
    byte.
- **App** (v0.95)
  - mygo: `App.Version` from the build, `IsDev` false in production builds,
    `Relaunch` starts the executable it started from.
  - myphp: `App::version()` from Info.plist (myphp.json's version),
    `App::isDev()` false when Info.plist has `MyPHPProduction` (what
    `myphp build` writes but with `-debug`, v1.12) or MYPHP_ENV=production,
    `App::relaunch()` starts the bundle's executable, or the script again
    with the PHP running it.
  - Why: a PHP app is a project or the bundle `myphp build` made; it has
    no link step to set a flag in.

- **The updater plugin** (v0.96)
  - mygo: package functions `updater.Plugin`, `New`, `CheckForUpdates`,
    `MenuItem`, `AutomaticChecks`…; `Options.Interval` a duration and
    `Icon` bytes, default icon.png among the resources; `Strings` formats
    with Go's verbs (`%[2]s`); `LastCheck` the zero time when never;
    `native.Plugin` and `native.New` for the window in native UI.
  - myphp: static methods of `Plugins\Updater\Updater` (`plugin()`,
    `new()`, `checkForUpdates()`, `menuItem()`…) and of
    `Plugins\Updater\Native\Native` (`plugin()`, `new()`); `$interval` in
    seconds, `$icon` PNG bytes, default the project's icon.png
    (`Resources/app/icon.png`); `Strings` formats in sprintf's syntax
    (`%2$s`); `lastCheck()` null.
  - Why: PHP's packages are classes.
- **The native update window** (v0.97)
  - mygo: links inside release notes keep the styles of their text (a
    bold word in a link); plain buttons have a faint shadow (0, 1, 1,
    rgba(0, 0, 0, .08)); its theme's border is white or black at 15 %
    opacity; the checkbox, progress bar and focus ring take the window's
    theme.
  - myphp: a link shows its text in the link's style alone; no shadow
    under plain buttons (myphp's bordered faces have none); the border is
    that color mixed over the window's background; the checkbox's box,
    the progress bar's track and the focus ring are painted in the app's
    theme (the painter's), the rest in the window's.
  - Why: a link is one text in myphp's paragraphs; myphp's theme colors
    have no alpha, and its painter takes the app's theme.
- **Reverse** (v0.97)
  - mygo: `Reverse()` on rows and columns (and wrapping rows), check
    boxes, radio buttons, progress bars; the layout also follows the
    window's direction (`Direction`, right to left).
  - myphp: `Node::reverse()` on rows and columns that do not wrap, check
    boxes, radio buttons and progress bars; myphp lays out left to right
    only, so a mirrored window reverses its rows itself (the update
    window does).
  - Why: what the update window needs; the direction of windows waits.
- **Core additions it needed** (v0.96)
  - mygo: `Page.OnWillNavigate(NavigateEvent)`; `Power` with
    `OnSuspend`, `OnResume`, `OnLockScreen`, `OnUnlockScreen`, `KeepAwake`,
    `IsOnBattery`.
  - myphp: the page's `willNavigate` event (`Page::on`, a
    `NavigateEvent`; on the window until v1.09); `Power::onSuspend`,
    `onResume`, `onLockScreen`, `onUnlockScreen`, and since v1.09
    `keepAwake`, `isOnBattery`, `idleTime`.
  - Why: what the updater needs, ported first.

- **The game plugin** (v0.98)
  - mygo: package `game`: an interface `Game` (`Update`, `Draw`, an
    optional `Rate()`), functions `View` and `IntegerFit`, the constant
    `Rate`; rects are `ui.Rect`s, times `time.Time`s; `IntegerFit` goes
    with a `ui.Canvas` the game draws its world into. Package `gamepad`
    reads pads on macOS, Windows (XInput), Linux (evdev) and in browsers.
  - myphp: an abstract class `Plugins\Game\Game` that games extend, with
    `update()`, `draw()`, `rate()` to override, and the static `view()`,
    `integerFit()` and `RATE`; rects are `[x, y, w, h]`, the frame's time
    nanoseconds (`Painter::now()`); a game draws into a `UI\Canvas`
    (v1.03) as mygo's do. `Gamepad\Gamepad::pads()` reads
    GameController.framework only; `Button` and `Axis` are enums.
  - Why: a PHP package is classes, and an interface has no static
    methods; myphp runs on macOS only.
- **Painter** (v0.98)
  - mygo: `ui.Painter`, what every element's `Draw` gets: fills,
    gradients, strokes (dashed too), shadows cut out under the shape,
    lines, text, rich text and text layouts, images and parts of bitmaps
    (flipped, tinted), focus rings, clips with rounded corners,
    transforms, paths, icons, effects, `Now`, `AnimationFrame`, `After`,
    `Scale`, `Visible`, `Theme`.
  - myphp: a `MyPHP\UI\Painter` instance over a display list with
    `now()`, `animationFrame()`, `after()`, `scale()`, `visible()`,
    `theme()`, `fill()`, `stroke()`, `strokeDashed()`, `shadow()`,
    `fillGradient()`, `line()`, `text()`, `image()`, `imageRegion()` (flipped, not
    tinted: `ImageOptions` has `flipX` and `flipY`), `focusRing()`,
    `clip()` (square corners), `transform()`, `fillPath()`,
    `strokePath()`, `icon()`; colors 0xRRGGBB with an opacity.
    `Node::draw()` still hands a draw the display list and the node; the
    tree painter's own helpers became `paintIcon` and `paintFocusRing`.
  - Why: what a game draws with, first; the display list has no tint
    nor rounded clips.

- **The glass plugin** (v0.99)
  - mygo: `glass.Glass{Style, Tint, Interactive}` with `Tint` a color
    with its alpha, `glass.Paint`, `glass.Blur{Radius, Mask}`,
    `glass.ScrollEdge`; each has `String()` for the inspector; materials
    take four radii; the effects are the plugin's own shaders (Metal,
    HLSL, GLSL) and their CPU twins, which `p.Effect` runs.
  - myphp: `Plugins\Glass\Glass(style, tint, tintAlpha, interactive)`,
    `Glass::paint()`, `Blur(radius, mask)`, `ScrollEdge`; no `String()`
    (no inspector); one radius; the shaders stay core's (`Native\Metal`),
    which the painter's `glass()`, `blurLevel()` and `hardBlur()` reach.
  - Why: myphp's colors and corners; one renderer, Metal, whose pipeline
    is built once.

- **App services** (v1.00)
  - mygo: methods of `App` (`OnOpenURL`, `RegisterURLScheme`,
    `RequestSingleInstanceLock`, `AddRecentDocument`, `SetOpenAtLogin`…)
    returning errors; `Network`, `Clipboard`, `Secrets`, `Cookies`
    singletons whose methods are safe from any goroutine and wait off the
    main thread; `mygo dev` runs a development bundle, so deep links work
    there; pages get the network's state; the Open Recent menu role.
  - myphp: static methods of `App`, `Network`, `Clipboard`, `Cookies` and
    `Secrets` (`Secrets::service()` returns a `SecretsModule`), throwing;
    they run on the main thread, and the Keychain may block it while the
    system asks the user; deep links need a bundle `myphp build` made;
    `unregisterURLScheme` throws (the scheme is the bundle's on macOS, as
    mygo says); no page event of the network yet; the Open Recent role
    came with the documents (v1.01).
  - Why: PHP has one thread.
- **Pages' services** (v1.00)
  - mygo: `Page.FindInPage`, `StopFindInPage`, `Print`, `PrintToPDF`,
    `SetPermissionHandler` (the default trusts `PageOptions.TrustedOrigins`
    and the app's own origin), `OnWillDownload`, `OnDownloadDone`; sizes
    `PageLetter`… as variables.
  - myphp: the same on `Page` (`findInPage()`, `print()`, `printToPDF()`,
    `setPermissionHandler()`, the events `willDownload` and
    `downloadDone`; on `Window` until v1.09); the default grants trusted
    pages (PageOptions' `trustedOrigins`); `PageSize::letter()`… static
    methods.
  - Why: PHP has no package variables of a struct type.

## Documents

- **One Document class** (v1.01)
  - mygo: `NewDocuments[T]`, `Documents[T]`, `Document[T]`, generic over
    the content; `Read(io.Reader)` and `Write(io.Writer, T)` run on
    goroutines; methods safe from any goroutine; errors `ErrCanceled` and
    `ErrDocumentChanged`; `DocumentService[T]` types the content for the
    TypeScript client.
  - myphp: `new Documents(DocumentsOptions)`, `Document` with a `mixed`
    content; `read(string $bytes)` and `write($content): string` run on
    the main thread, as everything does; a new document's content is null
    without `new` (Go's zero value); the exceptions `DocumentCanceled` and
    `DocumentChanged`; the client's content is `unknown`.
  - Why: PHP has no generics and one thread; bytes are strings.
- **The flows** (v1.01)
  - mygo: dialogs asked on goroutines while the main thread runs on;
    `Revert()` reads the file again, File > Revert asks first.
  - myphp: a close or a quit that must ask is canceled, and the asking
    runs on the next turn of the run loop (`App::later`), its dialogs
    sheets that turn the run loop (`App::wait`) until answered; the same
    `revert()`, the menu's asking first.
  - Why: no goroutines; the window's and the app's delegate return first.
- **Files and their changes** (v1.01)
  - mygo: stamps of nanoseconds; a file moved to another volume is copied
    with `writeFileSafely`; watches are goroutines; Linux and Windows
    replace files their way, mark edited titles with `*`, and get a File
    menu of their own.
  - myphp: stamps of whole seconds (PHP's `stat()`), so another app's
    write of the same size within the second of the document's goes
    unseen; PHP's `rename()` copies across volumes; the watches are looked
    at every 0.25 s on the run loop; macOS only.
  - Why: what PHP gives; the Mac is the platform.
- **The app's side** (v1.01)
  - mygo: `App.OnBeforeQuit` with `QuitEvent.PreventDefault`,
    `App.OnActivate`, `App.OnWindowAllClosed`; pages get
    `mygo:document-state` and `mygo:document-reload`, which mygo-runtime's
    `onDocumentState`/`onDocumentReload` take; a page's `<title>` doesn't
    name a document's window; files on the command line open in
    development too.
  - myphp: `App::onBeforeQuit` with `QuitEvent::cancel()` (as
    `CloseEvent`), `App::onActivate`, `App::onWindowAllClosed` (since
    v1.09; a flag before); the events `myphp:document-state` and
    `myphp:document-reload`, taken with `window.myphp.on`; pages don't
    name myphp's windows anyway; files on the command line are the
    bundle's (`DeepLink`), so `examples/documents` opens its own.
  - Why: myphp's names and runtime; without a bundle the app declares no
    file types.

## Protocol and the frontend

- **Handlers** (v1.02)
  - mygo: `Protocol.Handle(scheme, http.Handler)` and `HandleFunc`,
    returning errors; handlers run on goroutines and stream through
    `http.Flusher`; `FileServer(fs.FS)` is Go's file server.
  - myphp: `Protocol::handle($scheme, Closure(ProtocolRequest):
    ProtocolResponse)`, throwing; handlers run on the main thread, a body
    that is an iterable streams a chunk a run loop turn (a page waiting
    for one waits for the app); `Protocol::fileServer($dir)` serves a
    directory, with single ranges, without If-Modified-Since; content
    types are sniffed from a short list of Go's.
  - Why: no http.Handler in PHP (the developer chose classes of myphp's
    own over PSR-7, which would be a dependency); one thread.
- **The frontend** (v1.02)
  - mygo: the scheme `mygo`; `SetFrontend(fs.FS)`; `mygo build` embeds
    frontendDist in the binary; mygo.config.ts or mygo.json; `mygo dev`
    stops the dev server's whole process group; WebView2's
    http://<scheme>.localhost origins.
  - myphp: the scheme `myphp`; `App::setFrontend($dir)`; `myphp build`
    copies frontendDist to the bundle's Resources/frontend; myphp.json's
    (or myphp.config.ts's) `devUrl`, `devCommand`, `buildCommand`,
    `frontendDist`; `myphp dev` stops the shell of devCommand, which may
    leave a server it started behind; macOS only, no WebView2 origins.
    The web template loads "/".
  - Why: myphp's names; PHP files are not compiled into anything.
- **Trusted pages** (v1.02)
  - mygo: trust is decided as a page's navigation commits;
    `PageOptions.TrustedOrigins`.
  - myphp: as each call comes, from the page's URL;
    `WindowOptions::$trustedOrigins` (and FlyoutOptions'); pages that
    are not trusted get mygo's error with myphp's names.
  - Why: myphp's WebView reports no commit; a call is when it matters.

## Canvas and charts

- **Canvas** (v1.03)
  - mygo: `ui.NewCanvas`, a scene image the frame's renderer draws first,
    on the GPU or the CPU; anything a painter draws, glass and blurs too;
    its text at the canvas's scale.
  - myphp: `new UI\Canvas($w, $h)`, at most 2048 pixels a side and 16384
    quads a drawing, kept in two pages of the image atlas's texture array
    after the images'; rendered into a texture of its size and copied to
    its place (a pass may not read its own target); glass and backdrop
    blurs draw nothing in a canvas; its text is the window's glyphs, a
    pixel a point; when the pages fill, canvases are placed anew and
    rendered again from what they were last drawn with; `bitmap()` has
    no pixels on the CPU (`pixels()` throws) and no smaller levels.
  - Why: myphp's one Metal pipeline and atlases.
- **Charts** (v1.03)
  - mygo: package `ui/chart`: functions over `ui.Element`, `Span`,
    `Tick`, `Linear`, `Band`, `Time` (Go times), `Axes{Label}`,
    `Stack`, `Pie`; marks are boxes with children; `Select` keeps the
    focus through Escape; `docs/ui/chart.md`.
  - myphp: `UI\Chart\Chart`'s static methods (`plot`, `rect`, `point`,
    `ruleX`, `ruleY`, `anchor`, `select`, `x`, `y`, `line`, `area`,
    `nearest`, `extent`, `stack`, `pie`, `polar`, `sector`, `sliceAt`),
    the classes `Span`, `Tick`, `Linear`, `Band`, `Time` (over
    `DateTimeImmutable`), `Axes`, `Segment`, `Slice`, and the enums
    `Side`, `Curve`, `Stacking`; marks are closed nodes, an anchor takes
    what goes in it; `Band::index()` is null outside the bands; Escape
    clears a selection and lets the focus go, as every control's in
    myphp; the axes' label slots are a millionth of a point, as
    `size()`'s 0 leaves a side to the content; no chart guide yet.
  - Why: PHP has no free functions in a package to autoload; myphp's
    immediate-mode nodes and focus.

## Right to left

- **Direction** (v1.04)
  - mygo: `ui.Direction`, a window laid out right to left in a language
    written so, `Element.Direction`, logical edges (`PaddingStart`…,
    `MarginStart`…, `BorderStartWidth`…, `Start`/`End` insets), every
    control mirrored (text fields and areas, popups opening toward the
    start, the chevrons of trees, collapsibles and sidebars, a table's
    header scrolling with it, a color picker's saturation), the
    environment variable `MYGO_DIRECTION`.
  - myphp: `UI\Direction`, `Node::direction()`, `Context::direction()`,
    the same logical edges, the window's direction from
    `I18n::direction(App::locale())`, rows, columns, wrapping rows,
    grids and sideways scrolling mirrored (the offset counted from the
    right, the wheel as left to right, the scroll bar on the left), text
    starting at the right, and the controls whose arrow keys go along
    the line (rating, slider, range slider, tree items, calendar,
    segmented, grid view, split) and whose parts sit on a side
    (progress, checkbox, switch, slider); not yet: text fields and
    areas (the caret and the lines from the left), `Start`/`End`
    insets and attach anchors, popups opening toward the start, the
    chevrons, a table header's scrolling, the color picker; the
    variable is `MYPHP_DIRECTION` (`ltr` or `rtl`).
  - Why: ported in steps; the rest needs TextState laid out right to
    left first.

## Inspector

- **Developer tools** (v1.05)
  - mygo: `PageOptions.DevTools` (`DevToolsAuto`, `DevToolsEnabled`,
    `DevToolsDisabled`), `Page.OpenDevTools`, `CloseDevTools`,
    `IsDevToolsOpened`, `ToggleDevTools`; the menu role `toggleDevTools`
    in the View menu with `reload`, `forceReload` and the zoom roles.
  - myphp: the enum `DevTools` (`Auto`, `Enabled`, `Disabled`) as
    `WindowOptions::$devTools` and `FlyoutOptions::$devTools`; the
    methods on `Window`, for a page or native UI; the role
    `toggleDevTools` alone (Alt+Cmd+I), no View menu, reload or zoom
    roles.
  - Why: myphp's options and windows have no page object of their own;
    the other View roles are not ported yet.
- **Inspector** (v1.05)
  - mygo: `ui/inspector*.go`; production builds leave it out
    (`mygo_noinspector`, unless `MYGO_INSPECTOR=1` or `-debug`);
    `Ctrl+Shift+I` and `Ctrl+Shift+C` outside macOS; tags are widget
    names, else roles, else kinds; styles with gradients, stripes,
    dashed borders, inset shadows with spread, attach anchors,
    `aspect-ratio`, `max-width`, dividers; `MYGO_FRAME_STATS`.
  - myphp: `UI\Inspector`, `InspectorPanel`, `InspectorDetails`,
    `InspectorOverlay`; a bundle keeps the files, which a window
    without developer tools never loads; macOS keys alone; tags are
    roles, else kinds (`Button`, `TextInput`, `TextArea`, `Box`,
    `Spinner`…); the styles myphp's nodes have; the box model's margin and padding drawn
    solid; Computed gives `scroll-top`, `scroll-top-max` and
    `scroll-left` for `scroll-width` and `scroll-height`; the display's
    rate from `NSScreen.maximumFramesPerSecond` (macOS 12), 60 without
    it; building's time takes in every pass of a frame; `MYPHP_STATS`.
  - Why: PHP has no build tags, and a file not loaded costs nothing;
    myphp's nodes.
- **Duplicate keys** (v1.05)
  - mygo: two elements with one key under one parent; a Tester panics,
    apps log it once.
  - myphp: two nodes with one key in one scope (a keyed container's),
    as myphp's ids are; the headless tests throw `LogicException`
    (`Runtime::$strict`), apps `error_log` it once; the widgets that key
    nodes inside them key their own container, unless the app did, so
    that two of them in a scope do not share their nodes' ids: toolbar,
    range slider, calendar, time input, color picker, breadcrumbs,
    table; a diff viewer keys its sides `old` and `new`.
  - Why: myphp's keys are a scope's, and keying found where two such
    widgets met: a date input's calendar beside a calendar, a color
    well's picker beside a picker, a diff's two find bars.

## Audio

- **Audio** (v1.06)
  - mygo: package `audio` (`DecodeSound`, `ReadSound`, `NewSound`,
    `OpenStream` of an `io.ReadSeeker`, `NewPlayer`, `NewDecoder`,
    `RegisterFormat`, `SetVolume`, `Volume`, `Err`, `ErrFormat`), times
    as `time.Duration`; mixing on the output's goroutine and streams
    decoding on goroutines of their own, safe from any goroutine; the
    output plays without an app; an Audio Queue of 4 buffers of 512
    frames, about 40 ms ahead; `Decoder.Read` fills a slice and returns
    `io.EOF` at the end.
  - myphp: `MyPHP\Audio\Audio`'s static methods of those names,
    `Sound`, `Player`, the interface `Decoder` (`read(int $frames)`
    returns the samples, an empty list at the end; `setPosition` throws
    where it cannot go; a `close()` method is called with the player's),
    `ErrFormat` an exception; `openStream` takes a path or a seekable
    stream; times in seconds as floats; samples kept as 32-bit floats in
    strings. The Audio Queue calls back on the main thread's run loop,
    where PHP mixes, 4 buffers of 1024 frames, about 85 ms ahead, so
    audio plays while the app runs (App::run, or App::wait) and a main
    thread busy for longer than that underruns; streams decode a little
    each time the output mixes, and as they are created, moved and
    started; a player dropped while paused closes at once (PHP frees it
    then); the stand-in for a device that does not open is driven by the
    app's timers.
  - Why: PHP has one thread, which the system's audio thread cannot
    call into.
- **Formats** (v1.06)
  - mygo: WAV built in; `audio/vorbis` (jfreymuth/oggvorbis) and
    `audio/mp3` (go-mp3) register themselves when imported; MP3 keeps
    the encoder's delay and padding.
  - myphp: WAV, and Ogg Vorbis decoded in PHP (`Audio\Vorbis\Decoder`:
    floors of type 1, as every encoder since libvorbis 1.0 writes; type
    0 throws), built in; MP3, AAC and ALAC in M4A, FLAC, AIFF and CAF
    through AudioToolbox's ExtAudioFile (`SystemDecoder`), which leaves
    the delay and padding out, on macOS; no packages to import.
  - Why: the developer chose the system's decoders, and Vorbis written
    in PHP, over new packages.

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

## Widgets and context (v1.08)

- **Package functions**
  - mygo: `ShapeText`, `ShapeRichText`, `RegisterFont`, `FontFamilies`,
    `Oklch` are functions of package ui; `Oklch` returns a `Color` that
    keeps a wide-gamut twin; `Painter.EffectColor` gives an effect's
    shader both.
  - myphp: `Context::shapeText` and `shapeRichText` (the text system is
    the window's), `Font::register` and `Font::families`, `Oklch::rgb`
    mapped into Display P3, then into sRGB, which myphp draws alone; no
    `EffectColor`, as myphp has no effects of an app's own.
  - Why: PHP has no package functions; one renderer, sRGB.
- **Element handles and actions**
  - mygo: `Element.Bind(*Handle)`, `Handle.Focus` (`RequestFocus`),
    `CancelFocus`, `Focused`, `FocusWithin`, `Bounds`, `OnShortcut`;
    `OnClick`, `OnChange`, `OnSubmit`, `OnShortcut` on elements and on
    the context; `FocusBind(&field, value)` with `FocusedValue[T]`;
    `List(c, s, n)` without a row builder, then `ItemKey` and
    `Rows(func(ListRow))`.
  - myphp: `Node::bind(Handle)`, the same methods on `Handle` taking a
    `Context`; `Node::onClick` and the others, `Context::onShortcut($key,
    $mods, $fn)` in myphp's order of key and modifiers;
    `Node::focusBind(&$field, $value)` with null for no focus, its field
    told apart by the reference to it; `list($state, $count, $row)` with
    `ListState::$key` as before, no `ListRow`.
  - Why: myphp's queries take the key first; PHP has no generics, and a
    row's index is all its closure needs.
- **Toasts, lists and the rest**
  - mygo: `Toast.Timeout` a `time.Duration`; `ListState.FollowAnimation`
    glides as rows come or grow; `RepeatForever`, `TransitionAuto`…;
    `MeterLevels` a struct.
  - myphp: `Toast::$timeout` in seconds; `ListState::$followAnimation`
    glides as rows come, not as the last row grows; the constants
    `KeyframeOptions::REPEAT_FOREVER` and `Router::AUTO`, `FADE`, `NONE`
    as before; `MeterLevels` a class.
  - Why: myphp's durations are seconds; the growth of a row is measured
    only after it is laid out.
- **Bases**
  - mygo: `ButtonBase`, `CheckboxBase` … `TooltipBase`, built on elements
    whose children a closure builds; `SelectBase[T]`; the text inputs edit
    a `*string`; the buffer bases a `*TextBuffer`, through the editor's
    own buffer.
  - myphp: `Context::buttonBase()` … `tooltipBase()` open a container to
    build in until `end()`, the parts classes (`TabsParts`,
    `SelectParts`…) make theirs so; `selectBase(&$selected)` of any value;
    `textInputBase` and `textAreaBase` edit a `TextState`, as `input()`;
    the buffer bases a `TextState` kept in step with the buffer, an edit
    of the text written to it as the replacement of what changed.
  - Why: myphp's immediate mode builds in order; its inputs edit
    `TextState`s.
- **Web views in native UI** (v1.09)
  - mygo: `ui.WebView(c, v)` shows a `NativeWebView` in an element whose
    children paint over it; `Content.AttachContent` connects content to
    a window; a web view moves into the accessibility tree of the
    content.
  - myphp: `Context::webView($v)` is a leaf node, what paints over it
    painted after it (an absolute node, an overlay); no
    `AttachContent`, as the window makes its view itself; the page is
    VoiceOver's own, beside the content's elements.
  - Why: myphp's leaves take no children; its windows build their own
    views.

## App, window, page and menus (v1.09)

- **The page**
  - mygo: `Window.Page()` returns a `Page` with `LoadURL`, `LoadFile`,
    `Eval`, `EvalContext`, `EvalAs[T]`, `OnDOMReady`… methods, each event
    its own `On…`; `WindowOptions.Page` a `PageOptions`.
  - myphp: `Window::page()` returns a `Page` with the same methods,
    `eval()` decoding to PHP's values (no `EvalContext`: a call waits,
    the run loop turning, until the page answers or goes; no `EvalAs`),
    the events by name, `Page::on('domReady', $fn)` as `Window::on`;
    `WindowOptions::$page` and `FlyoutOptions::$page`; `capture()` for
    `CapturePage` of a page (`Window::capturePage` for both).
  - Why: PHP has no generics nor contexts; myphp's events go through
    `on()`.
- **Events of the app**
  - mygo: `NewEvent[T](name)` with `Emit`, `EmitPage`, `Broadcast`;
    `RunOnMain`.
  - myphp: `new Event($name, $payloadType)`, the type a PHP class or
    type name for the TypeScript client; no `RunOnMain`: everything runs
    on the main thread (`App::later` defers).
  - Why: PHP has no generics and one thread.
- **App, Dock, Shell, Screen, Theme**
  - mygo: `App.Exit`, `SetActivationPolicy`, `Focus`, `Hide`, `Show`,
    `IsHidden`, `SetBadgeCount`, `ShowAboutPanel`, `ClearBrowsingData`,
    `Path`/`SetPath` returning errors, `App.Dock`; `Shell.OpenExternal`…;
    `Screen.Displays()`, `Theme.Preferences()` with a `color.NRGBA`
    accent.
  - myphp: static methods of `App`, `Dock`, `Shell`, `Screen`, `Theme`,
    throwing; `App::path(PathName::Resources)` is mygo's (Contents/Resources,
    with the platform directories merged, since v1.12), the project's
    resources directory unbundled; `PathName::Exe` the bundle's
    executable, else PHP's;
    `ThemePreferences::$accent` 0xRRGGBB or null; `App::webViewAvailable`
    answers once the app runs.
  - Why: PHP's statics.
- **Notifications**
  - mygo: `Notification.Show` waits for the system and returns
    `ErrNotificationsDenied` when the user does not allow them.
  - myphp: `show()` returns at once and tells no denial; `onClick`,
    `clear()` (pending ones too) are mygo's.
  - Why: UNUserNotificationCenter answers on a background queue, where
    PHP cannot run.
- **Windows**
  - mygo: `SetSkipTaskbar`, `SetIcon`, `SetMenu`, `SetAutoHideMenuBar`,
    which do nothing on macOS; window state captured 300 ms after it
    settles.
  - myphp: none of the four yet, as they wait for Linux and Windows;
    the same state, timed with `App::after`.
  - Why: the Mac is done first.
- **Menus and trays**
  - mygo: `Menu.Items()`, `Append`, `Insert`, `PopupAt(win, x, y)`,
    `MenuItem` with `Hidden`, `ToolTip`, radio items; the Format, View,
    Speech, Services, Window and Help roles; `Tray.Destroy`.
  - myphp: the same (`Menu::popupAt`, `Tray::destroy`, renamed from
    `remove`); a radio item knows its group from the menu or the item
    holding it.
  - Why: mygo's names.
- **Page runtime**
  - mygo: mygo-runtime's `preferences()`, `onPreferences`, `locale()`,
    `direction()`, `onLocaleChange`, `once`, `onFileDrop`; the bridge
    tells the drop's place, the page's own drops keep working.
  - myphp: `window.myphp.preferences`, `locale`, `dir`, `once`, the
    events `myphp:preferences`, `myphp:locale`, `myphp:file-drop` and
    `myphp:title-bar`, the CSS variables `--myphp-*`; files dropped on a
    page are the window's, as before.
  - Why: myphp has no runtime package; its drops are native.


## Languages, messages and catalogs (v1.10)

- **Package i18n**
  - mygo: package functions (`i18n.Normalize`, `Match`, `Parent`,
    `Direction` returning a `Dir`, `FormatDate(locale, t, DateOptions)`,
    `FormatNumber(locale, any, NumberOptions)`, `Months`, `Weekdays`,
    `DayPeriods`, `Autonym`, `FormatLocales`, `PluralCategory`…), the
    `Width` and `Style` constants, `Plural.String`, `ParsePlural`,
    `NewOperands` returning an error, `SetLocaleFunc` and `Locale`.
  - myphp: static methods of `I18n`; `Width`, `Style`, `Dir` and
    `Plural` enums (`Plural::tryFrom` reads a keyword, a `Plural`'s value
    is it); `Operands::of` throws; `DateFormatter`, `NumberFormatter`,
    `DateOptions`, `NumberOptions` as mygo's; `I18n::locale()` reads
    `App::locale()`, so there is no `SetLocaleFunc`; `I18n::match`,
    `likelyScript` and `pattern` were myphp's own: `match` now finds
    mygo's matches by CLDR's parents, `pattern` is
    `DateFormatter::pattern()`, `rightToLeft` is `direction()`.
  - Why: PHP has no package functions; ICU, through ext-intl, has the
    dates, numbers and names, but no plural rules, so they are mygo's
    tables (`Native\CldrTables`).
- **Time zones and other values**
  - mygo: zones other than UTC written as GMT offsets ("GMT+8"); values
    of any Go type (fmt.Sprint for the rest).
  - myphp: ICU's names of zones ("Singapore Standard Time" in a full
    time); values are PHP's: a `Throwable` its message, a `Stringable`
    or an enum its string, arrays as JSON.
  - Why: ICU names zones; PHP's values.
- **Messages**
  - mygo: `ParseMessage` returning an error, `MustParseMessage`,
    `Message.Format(locale, map[string]any)`.
  - myphp: `Message::parse` throws a `SyntaxError` (`$source`, `$offset`,
    `$error`); `format($locale, $args)`; no `MustParse`, as a throw is
    the panic.
  - Why: PHP's exceptions.
- **Catalogs**
  - mygo: `NewCatalog(fs.FS, source)`, `NewLibraryCatalog`,
    `Catalog.Message` returning `(m, lang, ok)`, `Register`,
    `Languages`; MyGo's strings as generated functions
    (`l10n.Cancel()`), their translations in packages an app imports.
  - myphp: `new Catalog($dir, $source)`, `Catalog::library($dir,
    $source, $namespace)`, `message()` returning `[$message, $lang]` or
    null, `I18n::register`, `I18n::languages`; MyPHP's strings are
    `L10n::in($locale)->get($key, $args)`, every translation shipped in
    `src/l10n`, changed by an app's keys "myphp.<key>" (`L10n::add` is
    gone), and their keys are MyPHP's ("menuQuitApp").
  - Why: PHP reads files, not embedded file systems; MyPHP's strings
    were `L10n` before catalogs, and the framework is shipped whole.
- **Generated accessors**
  - mygo: `mygo generate` writes catalog_gen.go, a package whose
    functions say the messages (`locales.FilesSelected(3)`,
    `locales.In("de").FilesSelected(3)`), registered as it is imported;
    the TypeScript module imports `mygo-runtime/i18n`; `mygo dev -lang`.
  - myphp: `myphp generate` (and `dev`, `build`) writes `<dir>/<Dir>.php`,
    the class `Locales` for locales: `Locales::filesSelected(3)`,
    `Locales::in('de')->filesSelected(3)` (a `LocalesMessages`),
    registered as the app requires the file; its methods are named as
    the keys, which may not differ in case alone; the TypeScript module
    imports `myphp-runtime/i18n` (v1.13); `myphp dev -lang de`.
  - Why: PHP has no packages: a class is the package, and the file the
    import.

## Code editor, Markdown and transfer (v1.10)

- **Enums of options**
  - mygo: `IndentStyle`, `LineNumbers`, `Whitespace`, `CursorShape`,
    `Severity`, `CompletionKind`, `DiffLayout`, `DiffInline`,
    `DiffIndicators`, `DiffSide` (ui/editor) and `RevealUnit`
    (ui/markdown) are `uint8` types.
  - myphp: int-backed enums with mygo's constant names as cases
    (`IndentStyle::IndentTabs`, `DiffSide::DiffOld`, whose value indexes
    the sides), `CompletionKind::icon()` and `token()`; the class that
    draws a side of a diff, mygo's unexported diffSide, is `DiffSideView`.
  - Why: myphp's enums, as the terminal's and rich text's.
- **Kinds that stay integers**
  - mygo: `TokenKind`, `Command` (`String`, `CommandNamed`) and the
    parser's `Kind`, `InlineKind`, `Task`, `Align` are `uint8` types;
    `Extensions` a bitmask.
  - myphp: integer constants (`Token::KEYWORD`, `Command::SELECT_ALL`,
    `Block::HEADING`, `Inline::LINK`), `Command::name`/`named`,
    `Block::kindName`, `Inline::kindName`; `Extensions` constants
    without the Ext prefix (`Extensions::TABLES`, `DEFAULT`).
  - Why: they index arrays (the theme's token styles, the key map, the
    parser's tables) and run in the lexers' and the parser's inner loops,
    where an enum is an object to look up.
- **Package functions**
  - mygo: `editor.LanguageFor`, `LanguageNamed`, `Languages`,
    `DefaultBindings`, `DefaultTheme`, `NewTokenHighlighter`;
    `markdown.View`, `Stream`, `Slug`, `FileImages`, `DefaultCodeBlock`;
    `parser.Parse`, `PlainText`, `NormalizeLabel`, `Entity`;
    `transfer.New`, `NewItem`, `TextData`, `URLData`, `FileData`,
    `Data.Items`, `Data.Files`.
  - myphp: `Languages::for`, `named`, `all`, `Keymap::defaults`,
    `Theme::defaults`, `new TokenHighlighter`; `Markdown::view`,
    `stream`, `slug`, `fileImages`, `defaultCodeBlock`;
    `Document::parse`, `Inline::plainText`, `Document::normalizeLabel`,
    `Scan::entity`; `new Data`, `new Item`, `Data::text`, `Data::url`,
    `Data::files`, `$data->items`, `$data->paths()` (as `Data::files`
    makes data).
  - Why: PHP has no package functions; a static and a method cannot
    share a name.

## Examples and what they needed (v1.11)

- **The context's time and size**
  - mygo: `Context.Now()` a `time.Time`, `Size()` two `float32`s,
    `After(d)`, `AnimationFrame()`, `Element.Pressed()`.
  - myphp: `Context::now()` in nanoseconds (as `Painter::now()` and
    `Runtime::$now`), `size()` an array, `after($seconds)`,
    `animationFrame()`, `pressed($node)`.
  - Why: myphp's times are nanoseconds and its durations seconds; its
    queries are the context's.
- **Styled buffer controls, sidebar icons**
  - mygo: `TextInputBuffer` and `TextAreaBuffer` styled as `TextInput`;
    `SidebarItem` takes an `*SVG`.
  - myphp: `textInputBuffer()` and `textAreaBuffer()` around the bases;
    `sidebarItem()` takes an `SVG`, a glyph or null.
  - Why: as mygo; the bases came first.
- **Glyphs**
  - mygo: `ui.Shape(s, Font)` and `Font.Metrics()` are package
    functions; `Painter.Glyphs`; `Font.Antialiased` draws without the
    font smoothing macOS adds; `ui.Render(view, w, h, scale)` draws a
    frame of any view into an `image.RGBA` with its CPU renderer.
  - myphp: `Context::shape($s, $font)` and `fontMetrics($font)` (the text
    system is the window's), `Painter::glyphs()`; glyphs always draw
    without font smoothing, so there is no `Antialiased`;
    `Context::renderGlyphs($glyphs, $x, $y, $w, $h, $scale)` gives the
    coverage of glyphs only, a byte a pixel, rasterized by Core Text at
    that scale.
  - Why: myphp's headless runtime paints into a display list, without a
    CPU renderer to draw a whole view; what effort-slider rasterizes is
    letters.
- **Painter and images**
  - mygo: `ImageOptions.Tint` a `Color`; `Painter.MeasureText(width,
    spans...)` and `RichText(x, y, width, spans...)`.
  - myphp: `ImageOptions::$tint` (0xRRGGBB) and `$tintAlpha`, a tinted
    image not rounded (its quad's radius carries the tint); the same
    `measureText()` and `richText()`.
  - Why: an image quad has no free slot; `imageRegion()` rounds nothing.
- **Element queries and scrolling**
  - mygo: `Element.Dragged()` returns dx, dy and whether it is held;
    `ui.Scroll(c)` and `ScrollBoth(c)` keep their offsets themselves.
  - myphp: `Context::dragged($node)` returns `[dx, dy]` or null;
    `Context::scroll()` and `scrollBoth()` keep a `ScrollState` in the
    node's local state, `Node::scroll($state)` takes the app's.
  - Why: myphp's queries are the context's; scrolling took the app's
    state first.
- **Radio groups, editable text, trimmed paths**
  - mygo: `RadioGroup(c, fn)` a column, `.Row()` making it a row;
    `EditableText(c, &value)`; `Path.Trim(from, to)` trims the path as
    it is flattened, again on each call.
  - myphp: `radioGroup($fn, row: true)`; `editableText($value)`, which in
    a list's row reads the row's click without taking it
    (`Runtime::clickOver`) and gives the focus back through handles;
    `Path::trim()` replaces the path's commands with the lines it keeps
    (a quarter point long at most on curves), so a second trim trims
    what the first kept, and notes the length kept, by which
    `strokePath()` fades a stroke shorter than its width.
  - Why: myphp's builds are immediate, its clicks taken by the first
    node asking; its paths are their commands, which the masks and the
    atlas keys read.
- **Lists' labels and headers**
  - mygo: `ListState.Label` and `Header`; the header of the section at
    the top is cached between frames (`listHeader`).
  - myphp: `ListState::$label` and `$header` as mygo's; the header above
    the anchor is found scanning up from it each frame; a header above
    the rows built is built out of the flow and painted over them; a
    list whose rows are all built and short of it follows `justify()`
    (END for a chat).
  - Why: myphp's lists build from their anchor; the cache was not
    needed for the gallery's 200 messages.
- **The examples**
  - mygo: keys are scoped by the parent element; goroutines update the
    window (`Window.Update`); `math/rand`'s PCG seeds the games' maps;
    the gallery's Select, inputs and Languages test use strings and a
    region (`Tester.SetRegion`).
  - myphp: keys are scoped by keyed containers, so the gallery prefixes
    them ("tile 0", "item 3"); timers (`App::after`) and
    `Window::update`; `Random\Randomizer` with Xoshiro256**, so the farm,
    the street and the confetti differ from mygo's; selects choose
    indexes and inputs edit `TextState`s; the Languages test runs in the
    zero region; `Dialog::message` runs after the click (`App::later`),
    its own loop; the wide-gamut card draws both columns in sRGB.
  - Why: myphp's scoping, PHP's threads and random engines, myphp's
    controls, and no region setter for tests.
- **Constants of the examples in tests**
  - mygo: an example's tests are in its package.
  - myphp: `Headless::load()` defines an example's constants in its
    namespace (`MyPHP\Examples\Piano\WHITE_WIDTH`), a line of several
    staying a `const`.
  - Why: two examples name theirs alike (`WINDOW_W`).

## The command line and packaging (v1.12)

- **Commands**
  - mygo: init, install-skills, vet, migrate-ui, generate (gen), i18n
    check, dev, build, keygen, doctor, version, help; flags of Go's flag
    package; a console of spinners and colored steps.
  - myphp: the same but vet and migrate-ui, plus micro; flags as Go's
    (`-o`, `--o`, `-o=dist`); its steps printed as lines ("myphp: Signed
    …"), the summary of a build as mygo's.
  - Why: vet and migrate-ui check Go code (build lifetimes, goroutine
    captures, the UI value API's migration); PHP has neither. One line a
    step reads the same in a terminal and a log.
- **myphp.json**
  - mygo: `identifier` (default com.mygo.<name>), `main` (a Go package),
    mygo.json or mygo.config.ts; `linux` and `windows` options.
  - myphp: `identifier` (default com.myphp.<name>; it was `bundleId`),
    `entry` (the PHP file the app runs, default app.php) and `framework`
    (the checkout); myphp.json or myphp.config.ts (v1.13); no `linux` or
    `windows` yet. The
    other fields are mygo's, with its defaults: name, version, copyright,
    icon (resources/icon.png), out (dist), resources, macos.{icon,
    minimumSystemVersion 12.0, signingIdentity "-", entitlements,
    helperEntitlements, infoPlist, dmgTitle, notarize}.
  - Why: a PHP app has no package.
- **build**
  - mygo: compiles the app for any GOOS/GOARCH (`-platform`), universal
    with its own lipo; Windows, Linux, web builds; the inspector left out
    of production binaries by a build tag.
  - myphp: darwin/arm64, darwin/amd64 and darwin/universal: the exec
    launcher compiled with cc for them (`-arch`), the micro launcher for
    this Mac's only (micro.sfx is of its architecture); the app's PHP files
    and the framework's copied into Resources/app, its hidden files, the
    resources directory and the output left out; `MyPHPProduction` in
    Info.plist turns App::isDev() off, which keeps the inspector off.
    Info.plist has mygo's keys (myphp wrote LSApplicationCategoryType and
    a minimum of macOS 11.0 before).
  - Why: PHP is not compiled; the exec launcher runs the PHP that built
    the app, whatever the architecture it is for.
- **The icon**
  - mygo: decodes the PNG with Go's image/png, encodes each size with
    image/png (filtered rows).
  - myphp: ImageIO decodes it, premultiplied (CoreGraphics draws into
    nothing else), and mygo's resize runs on that; each size is written
    as an 8-bit RGBA PNG of unfiltered rows. The colors of nearly
    transparent pixels may differ by the 8-bit premultiplication.
  - Why: no PNG decoder in PHP without the gd extension; ImageIO ships
    with macOS.
- **Uploads**
  - mygo: S3 with net/http; .exe, .deb and install.sh among the uploads.
  - myphp: S3 with the system's curl; the disk images, archives, deltas
    and manifests (the other installers come with Linux and Windows).
  - Why: a PUT of a file with headers is one curl command; libcurl through
    FFI would need a read callback for the body.
- **Resource directories**
  - mygo: a misnamed platform directory "would ship to every platform:
    name the resources of one platform after its GOOS and GOARCH".
  - myphp: "… after its system and architecture".
  - Why: PHP has no GOOS and GOARCH; the names are the same.

## Development and the page packages (v1.13)

- **myphp dev**
  - mygo: compiles the app and runs it as "<name> Dev" (identifier
    "<identifier>.dev") from .mygo/dev, a bundle on macOS; rebuilds on
    changes to the Go code, the configuration, the icon, the resources
    and the catalogs, skipping the relaunch when the binary and what it is
    made of did not change; `-web` develops native UI in browsers (wasm);
    labeled, colored output of the app.
  - myphp: the same bundle in .myphp/dev, whose launcher runs the
    project's files in place (its Contents/Resources holds the merged
    resources and the plugins' natives); its fingerprint is the contents of
    what it is made of; no `-web`; the app's output as it is; the old
    `-watch 0` is gone, and changes to HTML, CSS and JavaScript are the dev
    server's, as mygo's.
  - Why: PHP has no WebAssembly build of the framework.
- **Signals**
  - mygo: SIGINT and SIGTERM quit the app through a goroutine.
  - myphp: a dispatch source on the main queue for each (Native\Signals),
    which takes them from their default action.
  - Why: PHP has no threads, and pcntl is not in every PHP that runs an app.
- **The bundle the app runs from**
  - mygo: the executable's path tells it; `PathResources` is
    Contents/Resources.
  - myphp: the exec launcher sets `MYPHP_BUNDLE` (and passes its arguments
    on), the micro launcher's phar is in the bundle; `App::path(PathName::Resources)`
    is Contents/Resources as mygo's (it was Contents/Resources/app/resources).
  - Why: the process the exec launcher starts is php, whose path is not the
    bundle's.
- **myphp-runtime and myphp-cli** (packages/runtime, packages/cli)
  - mygo: npm packages, built into dist/; mygo-cli's platform packages
    hold prebuilt binaries of the CLI, which mygo-cli runs.
  - myphp: not published: projects depend on them from the checkout
    (`file:<checkout>/packages/...`), which `myphp init -template web`
    writes; myphp-runtime's exports are its TypeScript sources, which Vite,
    tsc and Bun take as they are; myphp-cli runs the checkout's bin/myphp
    (or `$MYPHP_CLI_BINARY`). The repository's tsconfig.json resolves
    myphp-runtime to its sources for the plugins' clients and the tests.
  - Why: publishing is the developer's call; a PHP CLI needs no binaries.
- **window.myphp**
  - mygo: `window.mygo` is the `Runtime` of mygo-runtime's types.
  - myphp: the same shape (`channel(onmessage)` in place of the `Channel`
    class and `isCallError` it had, which myphp-runtime exports), and the
    generated client imports `call`, `event` and the types it uses from
    myphp-runtime, as mygo's does; boot.php of a project finds MyPHP in
    `$MYPHP_HOME`, which myphp dev and generate set.
  - Why: myphp's names.

## Widgets, keys and lists (v1.14)

- **Password fields**
  - mygo: `TextInput(...).Password()` and the `password` of its editor.
  - myphp: `Node::password()` on an `input()`, which sets
    `TextState::$password` for the frame (`Context::input` clears it each
    build, so a field shows its text again in a frame that asks no more);
    `TextState::shown()` is what it paints, bullets for a password.
  - Why: myphp's fields keep their state in `TextState`, not in an editor
    of the node.
- **Paint-only frames**
  - mygo: a frame whose state did not change paints again without building
    the view (repaintFrame): drawings, indicators, a content transition, a
    draggable at rest.
  - myphp: every frame builds the view.
  - Why: an optimisation; it is not behaviour an app sees, and PHP's
    build of a frame is what it is measured by (MYPHP_STATS).
- **List keys**
  - mygo: `ListState.Key`, with the anchor, the choice and the rows' state
    kept by key across rows coming and going.
  - myphp: the same, `ListState::$key`.
  - Why: (none: the same behaviour).
- **Table sort**
  - mygo: headers sort the rows only with `ListState.Sort` or `SortBy` set
    (pointers the app gives); the table's `Changed` reports a click.
  - myphp: a sortable column's header sorts whether or not the app set
    `ListState::$sort` (null is none), `$sortBy` (a list, null for none)
    adds Cmd- and Shift-clicks; `sortChanged()` reports a click, as before,
    and so does the table's `changed()`.
  - Why: PHP has no pointers to tell whether the app asked for a sort;
    `sortChanged()` was myphp's already.
- **Table columns**
  - mygo: `ListState.Columns` (a `TableLayout` of `Order` and `Widths`).
  - myphp: `ListState::$columns`, a `TableLayout` of `$order` and `$widths`;
    the old `$columnWidths` is gone.
  - Why: myphp's names.
- **What an empty list shows**
  - mygo: the children of a List (`.Children`) show while it has no rows.
  - myphp: `list(..., $empty)`, a builder of what it shows without rows.
  - Why: myphp's `list()` ends its node before it returns it.
- **Frame statistics**
  - mygo: `MYGO_FRAME_STATS` logs frames over a threshold with the
    allocations Go counted.
  - myphp: `MYPHP_FRAME_STATS`, the same parts of a frame, with the memory
    PHP came to hold (`memory_get_usage`) and its collector's runs in place
    of allocation counts; `MYPHP_STATS`, a line a second, stays.
  - Why: PHP counts no allocations.
- **Keyboard scrolling**
  - mygo: a key no element claimed as a shortcut scrolls, from the keys as
    they come.
  - myphp: a key nothing took during the build scrolls, after it
    (`Runtime::scrollKey`); a focused field keeps them.
  - Why: myphp's shortcuts are asked for while the view builds, not
    registered before the keys come.

## Lists, the tests of list_test.go (v1.15)

- **ListState::scrollTo**
  - mygo: `ScrollTo(row, align)`, at once, Start, Center or End.
  - myphp: `scrollTo($row, $smooth = true, $align = Node::START)`: a glide
    to the top unless `$smooth` is false, at once for CENTER and END.
  - Why: myphp's scrollTo glided before mygo's Align came.
- **A list as high as its rows**
  - mygo: a list without a size is as high as its rows, which flexbox
    shrinks into the room a column has.
  - myphp: only in a scrolling container or with a largest height
    (`maxHeight`); elsewhere it takes the room it is given.
  - Why: myphp's columns shrink nothing, so a list that big would
    overflow every window instead of scrolling.
- **TrackScroll on a list**
  - mygo: `TrackScroll(&ScrollState)` gives a list a place in points too.
  - myphp: a list keeps its place by its `ListState` alone; a scroll
    container takes its `ScrollState` with `scroll()`.
  - Why: myphp's scroll() was that already; the ListState says it all.
  - Not ported for this: TestListEndShowsTheEnd's and
    TestListTrackScrollBeforeTheFirstFrame's tracked states, and
    TestListHeights, which tests mygo's tree of heights (myphp keeps them
    in a map and a bit set).

## Text fields, input clients and toasts, their tests (v1.16)

- **Tests of mygo's own editor, not ported**
  - mygo: textarea_test.go tests its buffer and the text area laid out a
    paragraph at a time (TestBuffer*, TestHeights,
    TestTextAreaLaysOutAsWholeText, TestTextAreaSelectsAsWholeText,
    TestTextAreaSharesValue, TestTextAreaShowsThroughPadding).
  - myphp: a text area lays out its whole text (TextState::layout), with
    none of that to test.
  - Why: myphp's fields are TextStates, not mygo's editor.
- **Tests of mygo's platform input layers, not ported**
  - mygo: TestTextInputClientNativeEndBeforeCommit and
    TestTextInputSurroundingUnicodeAndReversedSelection test its
    InputComposition and GTK's surrounding text; TestTextLayoutUTF16*
    and TestTextLayoutVisualCaretUTF16 its ShapeText.
  - myphp: the view is the NSTextInputClient and calls the app's client
    as AppKit calls it; text is shaped by MyPHP\Text.
  - Why: those layers are mygo's, for Linux and Windows.
- **A text area's view on the app's text**
  - mygo: the view moves to the caret when the caret moves.
  - myphp: the same, the painter now asks the caret alone (it asked the
    text's length too, so a log growing brought the caret back).
  - Why: (none: the same behaviour).

## Primitives, styles and the window, their tests (v1.17)

- **onTextKey**
  - mygo: `OnTextKey(fn(TextKey) bool)`, a TextKey with `Text()` and
    `SetText()` valid while fn runs (a kept one panics); a shortcut around
    the focus takes Escape before it.
  - myphp: `TextState::$onTextKey`, fn(int $keyCode, string $chars, int
    $mods, TextState $s): bool, reading and setting `$s` itself; the keys
    that type text go to the field, as mygo's (`Input::textKey`, which the
    view and the headless harness call); shortcuts, asked as the view
    builds, see what fn left.
  - Why: myphp's field is its TextState; its shortcuts are not registered
    before the keys come.
- **Not ported**: TestPathsLookTheSameWhateverDrewBefore and
  TestStrokesAreAsWideAsAsked (mygo's rasterizer of masks; myphp's paths are
  CoreGraphics' on macOS), TestPasteDuringFrame (GTK's clipboard),
  TestOnTextKeyComposer's kept TextKey, TestKeyOnAWidget (mygo panics on a
  key given a radio button; myphp keys any node), TestRecycledStateIsFresh
  and TestKeyLeavesNoStateBehind (mygo's arena of states; myphp's nodes are
  built anew each frame), TestTextInputFollowsTheFocusAtOnce (between
  frames, the view sets the input client at the frame), TestIconRotates and
  TestReviewedEdges (pixels; the headless text engine draws no icons),
  TestDemoRenders and TestListScrollsAndSelects (the demo is ported as
  ui_more_test's window and the list tests).

## Right to left, indicators, dates and comboboxes, their tests (v1.18)

- **Regional formats in the tests**
  - mygo: `Tester.SetRegion` gives a tester the system's formats (en-US
    in 12 hours with the app in Chinese, say).
  - myphp: the tests set the language shown (`App::setLocale`), whose
    CLDR formats the controls take without the system's (`Context::formats`);
    Chinese then shows 24 hours, without a half of the day.
  - Why: the formats are the system's (`App::region`), which a headless
    window has none of; a hook for the tests alone would be code no app
    runs.
- **Arrows in a right-to-left paragraph**
  - mygo: the word arrows and the line-end arrows go the way they point
    (`caretParagraphRTL`); Left and Right alone go to the visual neighbor.
  - myphp: the same for the word and line-end arrows (`TextState::paragraphRTL`);
    Left and Right go back and forth in the text.
  - Why: myphp's single-line field measures its text, without the shaped
    runs a visual move needs.
- **Not ported**: TestRTLTextAlign's custom widgets shaping text
  (ShapeRichTextWith with a direction, which `TextLayout::shape`'s `$rtl`
  is), TestSpinnerColor's pixels (spinner_test reads the
  colors painted), TestMeter's pixels (the meter's color is read off the node).

## Scopes, transforms, SVGs, colors and web views, their tests (v1.19)

- **A web view's children**
  - mygo: `WebView(c, v).Children(...)`: elements over the page, inside
    its node, which take the pointer there.
  - myphp: `Context::webView` is a leaf; what shows over the page is
    built after it, at() or absolute, as the popover and the button of
    webview_test.
  - Why: a leaf keeps `webView()` a call without an `end()`.
- **Borders in the tests**: mygo's `Border(w, c)` takes room in the
  layout; myphp's is `borderWidth(w)->borderColor(c)` (`border()` rings
  the box without room), as in webview_test.
- **Not ported**: the pixel tests (TestIconDrawsItsShapeOnce,
  TestPictureFollowsTheTextColor, TestPainterDrawsSVGs, TestWebViewHole's
  and TestWebViewOpacity's pixels, TestTransformedNativeDragPreviewHotspot's
  preview, TestTransformUpdatesDamageAndTransitionGeometry's damage,
  TestWebViewNotOpaque: the headless text painter draws no pixels nor
  subpixel glyphs); the wide-gamut tests past Oklch's sRGB
  (TestMixWide, TestWideShadowStripesGradientAndText, TestWideOnlyWhereItShows,
  TestWideDecorationsDividersAndTextBackground, TestThemeAccentTakesOklch,
  TestPictureOfAWideColor, TestWideFramesOnGPU, TestEffectColor), as myphp
  draws sRGB alone (Widgets and context, v1.08); TestUnhandledEscape is
  flyout_test's.

## Choosing, tables, widgets and inline text, their tests (v1.20)

- **Not ported**: TestListMovesWithoutChoosing (Ctrl with the arrows,
  Linux's and Windows'; myphp is macOS's), the other platforms' branches of
  TestListChoosesSeveral and TestListPageKeys, TestSelectionOfAnotherKeyPanics
  (Go's generic `Selection[K]`; myphp's keys are ints or strings),
  TestInlineLink's focus ring and TestInlineStyles' pixels (the headless
  painter draws none), TestTooltipHidesOnPress (tooltips_test).
- **A table's tall rows**: TestTableRows' long note is rich text with
  `wrap: true`, as text wraps only so (Text, v0.81).
- **The passes of a press**: mygo counts its build passes; myphp's
  selection_press_test counts the view's builds, two in the frame a press
  lands in.

## Bases, forms, feedback, toggles and themes, their tests (v1.21)

- **Not ported**: TestOwnThemeIgnoresPreferences (an app's own theme is
  the App's, and its check is of pixels), the closed window of
  TestListStateInputRequiresItsActiveContext (a headless runtime is not
  closed), TestLang's `SetRegion` (the regional formats are the App's, as
  datetime_test has them).
- **A table's focus**: mygo focuses the table itself, its rows' owner;
  myphp's table is its header over its rows' list, and the list, which its
  `ListState` shows, has the focus.
- **The language of text** naming none is the app's through the text
  system (`System::setLanguage`, set every frame), not written into each
  text's style: `textStyle()->lang` stays null where mygo's has the locale.
