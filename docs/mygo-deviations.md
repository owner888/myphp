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
- **Text wraps at the room it has** (v0.81, wrapping since v1.24)
  - mygo: a `Text` wraps at the width layout gives it, its height found
    at that width (`heightAt`, intrinsic sizes).
  - myphp: the same, by a second pass: the layout measures and arranges,
    texts arranged narrower than their lines wrap at the width they got
    (`Layout::wrapTexts`, made rich text), and it measures and arranges
    again; a text that wraps shrinks in a row to its widest word
    (`Node::minContentWidth`: mygo lays the text out at a width of 1 with
    `NoBreakWords` each frame; a plain text here takes it from the line
    that measured it, its carets between `Text\LineBreaks`'s breaks, which
    the line cache keeps, as a layout a text a frame was dear in PHP; rich
    text asks the text system as mygo does), and a container not stretched is no wider
    than its room, down to its content's least (fit-content).
    `richText(wrap: true)` still wraps at the container's last-frame width.
  - Why: myphp's layout measures before it arranges; the second pass gives
    mygo's result without its intrinsic sizing, at the cost of a second
    layout in a frame where text wraps.
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
    `$grow`); `draw` takes a `Painter` with the window's theme and the
    box, as mygo's since v1.27 (the `DisplayList` until then); colors are
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
    a disabled node dims as mygo's (only containers did until v1.26);
    `Cursor` a string-backed enum named as CSS names them.
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
  - myphp: the image loads in a fiber of its own (`App::go`), where
    `Image`'s decoding waits off the main thread, the description showing
    until it is done (v1.31); it is fitted to the width its row had last
    frame.
  - Why: myphp has no percentage sizes or aspect ratios yet.
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
- **A file's directory, on macOS** (v1.33)
  - mygo: a write noticed on the directory of a file watched marks the
    file written, which kqueue, naming nothing, gives for a sibling's
    write too: a sibling created or written is reported as the file's.
  - myphp: only a write named for the file does; one kqueue gives the
    directory only has the file looked at, and the file's own descriptor
    reports its writes.
  - Why: the system test of a sibling not reported failed on macOS.
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
    mapped into Display P3, keeping a wide color outside sRGB
    (`WideColor`, since v1.25); no `EffectColor`, as myphp
    has no effects of an app's own.
  - Why: PHP has no package functions.
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
    `Window::update`; PHP's `Random\Randomizer` (Xoshiro256**, the
    piano's `mt_rand`) where mygo seeds Go's PCG, so the farm, mayoi's art
    and band, the piano's hammers and the gallery's charts are alike but
    not mygo's draw for draw (the confetti and the chat draw unseeded in
    both); selects choose
    indexes and inputs edit `TextState`s; the Languages test runs in the
    zero region; `Dialog::message` runs after the click (`App::later`),
    its own loop; the wide-gamut card's left column draws the sRGB twins
    (`WideColor::srgb`).
  - Why: myphp's scoping, PHP's threads, myphp's controls, and no region
    setter for tests; Go's generators, ported for v1.27, were dropped in
    v1.28 as the developer asked: PHP's engines are of the same quality
    for an app (Go's PCG-DXSM and PHP's PcgOneseq128XslRr64 differ only
    for many close-seeded streams in parallel), and the port drew a
    number some 170 times slower than the engine's.
- **Examples of the same name** (v1.27)
  - mygo: `native` tours the desktop from a page, `notes` is rich text
    notes, `drag-drop` drags between two windows.
  - myphp: the same, ported; what myphp had under those names before is
    `inbox` (the list of a million rows) and `markdown-notes`; `drag`
    (draggable nodes) stays, mygo having no example of its own for them.
    The notes' welcome note is made on the first frame, which measures
    its chip (mygo shapes text without a window).
  - Why: each of mygo's examples runs beside its twin.
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
  - myphp: published on npm since v1.28 (with the plugins' page sides,
    `@myphp-plugins/*`, since v1.29, all at the framework's version), and
    `myphp init -template web` still writes `file:<MyPHP>/packages/...` of
    the MyPHP it runs from; myphp-runtime's exports are its TypeScript
    sources, which Vite, tsc and Bun take as they are, not a dist/; myphp-cli
    has no binaries: it runs `$MYPHP_CLI_BINARY`, the bin/myphp of the
    checkout it is part of, else the project's Composer-installed
    `vendor/owner888/myphp/bin/myphp`. The repository's tsconfig.json
    resolves myphp-runtime to its sources for the plugins' clients and the
    tests.
  - Why: the CLI is PHP, part of the framework, which Composer installs.
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
  - myphp: a text area wraps a paragraph at a time too since v1.26
    (FieldLayout, tests/field_layout_test.php), those out of view one line
    each until they are wrapped, where mygo estimates their heights by the
    average of those measured; its lines are all as high, so there is no
    tree of heights to test, nor mygo's editor buffer.
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
  layout; myphp's is `borderWidth(w)->borderColor(c)`, as in
  webview_test (`border()` ringed the box without room until v1.27; it
  takes its width now too, as mygo's, and a plain button is a point
  wider each side for its border).
- **Not ported**: the pixel tests (TestIconDrawsItsShapeOnce,
  TestPictureFollowsTheTextColor, TestPainterDrawsSVGs, TestWebViewHole's
  and TestWebViewOpacity's pixels, TestTransformedNativeDragPreviewHotspot's
  preview, TestTransformUpdatesDamageAndTransitionGeometry's damage,
  TestWebViewNotOpaque: the headless text painter draws no pixels nor
  subpixel glyphs); the wide-gamut tests then, ported since by
  wide_color_test (Wide colors, v1.25); TestUnhandledEscape is
  flyout_test's.

## Choosing, tables, widgets and inline text, their tests (v1.20)

- **Not ported**: TestListMovesWithoutChoosing (Ctrl with the arrows,
  Linux's and Windows'; myphp is macOS's), the other platforms' branches of
  TestListChoosesSeveral and TestListPageKeys, TestSelectionOfAnotherKeyPanics
  (Go's generic `Selection[K]`; myphp's keys are ints or strings),
  TestInlineLink's focus ring and TestInlineStyles' pixels (the headless
  painter draws none), TestTooltipHidesOnPress (tooltips_test).
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

## Outlines, repaints, scrolling, selections and following, their tests (v1.22)

- **Not ported**: TestRepaintFrames, TestHeldWhileOccluded and
  TestFocusChangeFrames (every frame of myphp builds the view, Paint-only
  frames, v1.14; the window draws a frame whenever it becomes or stops
  being the key window), TestIndicatorsRepaint's pixels, TestTrackScrollList
  (TrackScroll on a list, v1.15), TestDividers' pixels (style_test has the
  dividers of a column and a row).
- **Paint order, not pixels**: the headless painter composes no pixels,
  so selection_paint_test checks the order of the quads (a highlight over
  the backgrounds it covers, under its glyphs; a composition's underline
  and the caret over the glyphs) where mygo's tests read the image.
- **`width(0)`** leaves the width to the content, as `size(0, …)` does;
  mygo's `Width(0)` is a width of nothing. A column closing to nothing
  takes `maxWidth(0)` (motion_more_test).
- **Dividers** are centred on the boundary of two rows, a 1-point divider
  from half a point above it; mygo's fill the pixels below it.

## Pastes, text lines, inline objects and buffers, their tests (v1.23)

- **Not ported**: TestModifiersHeld (physical_key_test has it), the
  allocation counts of TestMeasureTextAgain, TestControlsSayTheLanguage
  and the TextBuffer's chunk ownership and concurrent snapshots (Go's
  memory and goroutines), TestDecodeBitmapOfWebPAndBMP (ImageIO decodes
  both; the headless runtime has no decoder), mygo's frame_test.go
  (handles' generations and storage reuse, Go's) and editor_input_test.go
  and textbuffer_input_test.go (mygo's editor client internals; myphp's
  fields keep a TextState).
- **Cmd+V in the tests** is the Edit menu's Paste (`['edit', 'paste']`),
  as the window gets it: the menu's key equivalent sends `paste:`.

## Text wrapping at its width (v1.24)

- **Two layouts a frame where text wraps** (see Text wraps at the room it
  has, v0.81): mygo finds a text's height at its width as it lays out;
  myphp lays out again once the texts that need it wrap.

## Wide colors (v1.25)

- **A color is an int**
  - mygo: `Color` carries its wide twin beside R, G, B and A (`SRGB()`
    drops it).
  - myphp: the low 24 bits are the sRGB twin; a wide color holds the id
    of its extended sRGB components above them (`WideColor`), so what
    reads a color's bytes reads its twin; `WideColor::srgb` drops it. The
    last 4096 wide colors made are kept; an older one draws its twin, as
    mygo's past what a scene counts.
  - Why: myphp's colors are ints throughout.
- **What draws its sRGB twin**: the second color of a gradient, the
  ground of stripes and a box's border (packed into one float each), a
  tinted image's tint, canvases, the native drag's image of the last
  frame, and SVG pictures, rasterized in sRGB; mygo draws the second
  color and the border wide too.
- **The switch**: `Native\Metal::useWide` draws a view's frames into
  RGBA16Float drawables in extended sRGB while a frame within two seconds
  had a wide color and the screen shows Display P3, and gives the layer
  its own color space back after; mygo's GPU renderer the same, its CPU
  renderer sRGB.
- **Not ported**: TestPictureOfAWideColor (myphp's pictures are keyed by
  the color, a wide one by its id), TestWideFramesOnGPU (Metal's, tested
  on a Mac), TestEffectColor (no effects of an app's own), the pixel
  tests.

## Images off the main thread (v1.31)

- **Decoding**
  - mygo: `DecodeBitmap` decodes PNG, JPEG, GIF, WebP and BMP with Go's
    decoders, whole, on the goroutine that calls it; a JPEG comes upright
    as its EXIF orientation says.
  - myphp: `Image::fromBytes` and `fromFile` decode what ImageIO reads
    (HEIC too), upright as the file's orientation says, whatever its
    format, scaled down with Lanczos to `$max` pixels on a side, by
    CoreImage on an NSOperationQueue's thread; in a fiber they wait while
    the app runs, outside one they block until done.
  - Why: PHP runs the app on one thread; fibers are its goroutines (the
    developer's choice), and Objective-C messages sent through
    NSInvocations take the work to another thread with no PHP there.
- **Not ported**: mygo's `exifOrientation`, `tiffOrientation` and
  `orient` (CoreImage orients), and with them the parser half of
  TestDecodeBitmapTurnsPhotosUpright; `tests/image_test.php` decodes a
  turned JPEG instead, on macOS.

## Video (v1.33)

- **Media\Player and Context::video**
  - mygo: no video; `Bitmap.Update` shows frames an app decodes itself.
  - myphp: `Media\Player` plays Matroska, WebM, MP4, MOV and M4V (H.264,
    HEVC, and VP9 and AV1 where the Mac decodes them in hardware; AAC,
    AC-3, E-AC-3, MP3, Opus) with the system's decoders, and
    `Context::video` shows its picture under the window's content, through
    a hole of its node, as web views show; macOS only.
  - Why: the developer asked for a video player as fast as the system
    plays video; mygo has none to follow, so the names follow mygo's way
    of naming (`Player`, `Track`, `Packet`, `video()`).
- **Subtitles** (v1.34): `Player::subtitleTracks`, `setSubtitleTrack`,
  `loadSubtitles`, `subtitles`, `setSubtitleDelay` and `Media\Subtitles`
  are myphp's too, as IINA's behavior has them (files beside the film
  loaded): mygo has no video.
- **The picture and the sound, a video player** (v1.38):
  `Player::setAspectRatio`, `setCrop`, `setRotation`, `setAudioDelay`,
  `Context::videoPlayer`, `VideoPlayerState` and `VideoPlayerOptions` are
  myphp's, as IINA's window has them: mygo has no video.
- **Going on where a film was left** (v1.39): `Media\PlaybackPositions`,
  `Player::path` and `VideoPlayerOptions::$resume` are myphp's, as IINA's
  "resume last playback position": mygo has no video.
- **The playlist, picture in picture** (v1.40): `Media\Playlist`,
  `Player::durationOf`, `VideoPlayerState::open`, `setPlayer`, `next`,
  `previous`, `togglePictureInPicture` are myphp's, as IINA's; mygo has
  no video.
- **Chapters, the playlist's loop, shuffle and sort** (v1.41):
  `Media\Chapter`, `Source::chapters`, `Player::chapters`, `chapter`,
  `VideoPlayerState::playChapter`, `$loop`, `nextLoop`, and
  `Playlist::shuffle`, `unshuffle`, `shuffled`, `sortByName`,
  `sortByPath` are myphp's, as IINA's, and `Player::awake`,
  `setKeepAwake` and `VideoPlayerOptions::$keepAwake` (the display kept
  awake while it plays, as IINA's); mygo has no video.
- **The player's events, keyframe seeks, Now Playing** (v1.46): the
  events play, pause, rate, seek and track of `Player::on`, the `$exact`
  of `Player::seek` and `VideoPlayerState::seekBy`, and
  `VideoPlayerOptions::$nowPlaying` are myphp's, as AVPlayer's and IINA's,
  and `Player::step`, `snapshot`, `VideoPlayerState::frameStep`,
  `screenshot` (IINA's frame steps and screenshots; its `$screenshots`
  went in v1.61, the settings saying where they go);
  mygo has no video.
- **yt-dlp, two sources, AV1 in software** (v1.52): `Media\YtDlp`,
  `Merged`, `CacheBudget`, `CacheMember`, `Player::title`, `Player::playlist`, `Player::$hardwareDecoding`,
  `Playlist::title` (and its titles), the headers of `HttpBytes`,
  `Download` and `Hls`, `Native\Dav1d` and `SoftVideo` are myphp's, as
  mpv's ytdl_hook, its audio-file and its libdav1d decoding (IINA's);
  mygo has no video.
- **The equalizer** (v1.55): `Player::setEqualizer`, `equalizer`,
  `EQUALIZER_BANDS`, `VideoPlayerState::EQUALIZER_PRESETS`, `$equalizer`,
  `$equalizerPreset` and `setEqualizer` are myphp's, as IINA's audio
  equalizer (its presets, its filter for mpv); mygo has no video. Flat
  turns it off (IINA adds the filter all the same, of no effect).
- **The equalizer's profiles** (v1.56): `Media\EqualizerProfiles`
  (equalizer-profiles.json in the app's user data, as IINA's
  userEQPresets) and VideoPlayerState's profile methods are internal,
  the pop-up's; the profiles keep the order they were saved in (IINA's,
  a Swift dictionary's, have none). `Dialog::prompt`'s detail and check
  (IINA's quickPromptPanel) are internal too.
- **Online subtitles** (v1.60): `VideoPlayerOptions::$openSubtitlesKey`
  and `Context::videoPlayerSettings` are myphp's, as IINA's OpenSubtitles
  (Find Online Subtitles) and its Subtitles pane; mygo has no video. With
  no preferred language set, the system's are searched (IINA alerts and
  searches English); the languages are the codes of BCP 47 (zh-Hans, en),
  not IINA's ISO 639-2; Logout forgets the password too (IINA leaves it
  in the Keychain). Search Online asks TMDB first, the developer's choice
  (agc_player's way, IINA has none): the file's name read as agc_player's
  MetaVideo reads it (`Media\MetaVideo`, `Media\Tmdb`, internal), the
  subtitles of the film's TMDB id (a show's season and episode), IINA's
  search by hash and name only where that finds none.
- **The player's settings and windows** (v1.61): `Media\PlayerSettings`
  (IINA's preferences by IINA's names and defaults: `default`, `get`,
  `set`, `onChange`; settings.json in the user data, replacing v1.60's
  SubtitleSettings and its file, so an OpenSubtitles account logs in
  again), `Context::videoPlayerWelcome` and `Context::videoPlayerHistory`
  (IINA's welcome and playback history windows) are myphp's; mygo has no
  video. `VideoPlayerState::$screenshots`, `setSubtitleLanguages` and
  `setHardwareDecoder` are gone (`setSetting`), and so is examples/video's
  MYPHP_VIDEO_SOFTWARE (Settings > Video). The settings window has IINA's
  General, Video and Subtitles pages so far. General's receive beta
  updates is left out (no beta feed); Check for Updates is myphp's own
  setting where IINA's is Sparkle's, its interval taken at the next
  launch (the updater's is set as it starts). The app-wide ones (new
  windows, quit after the last, at launch, Open Recent) are the app's to
  follow, as examples/video does: the library's player has one window.
- **The player's settings pages, the log, key bindings, Live Text**
  (v1.62): IINA's settings window whole (UI, Video, Audio, Subtitles,
  Network, Control, Key Bindings, Advanced, Utilities after v1.61's
  General); `MyPHP\Logger` and `Context::logViewer` (the app's log as
  IINA's Logger and Log Viewer: the developer's choice, a log of the
  app's, of which the player is one subsystem) are myphp's; mygo has no
  video and no log of its own. Left out or done otherwise, where myphp
  has no mpv:
  - UI: the initial position's X and Y pop-ups are labelled as they
    place (IINA's are crossed); music mode's long titles do not scroll;
    a screenshot with its subtitles takes them at the window's size.
  - Video: the ICC profile, tone mapping's options and the dedicated GPU
    are left out (the system manages the colors and tone maps; Apple
    silicon has one GPU).
  - Audio: audio threads, the AVFoundation driver and S/PDIF are mpv's;
    gapless audio opens the next audio file 5 s before the end (weak and
    strong alike: the system's renderer, no device kept open).
  - Subtitles: the font is a family
    (no face list: the bold and italic switches choose the face); the
    secondary subtitles are always text at the top (their override level
    is kept, unused); a new default encoding reads the files loaded
    next (IINA reloads those loaded); a subtitle file's language named
    in English (movie.English.srt) matches no preferred language.
  - Network: the seconds to prefetch bound HLS only (a file over HTTP by
    bytes); the cache off means no pause for it; RTSP's transport (no
    RTSP) and the Online Media plugin's advice (no plugins) are left out;
    the seeking throbber is the buffering one begun by a seek.
  - Control: a trackpad's seek does not pause as it begins (no phases);
    the seek type's Auto is exact for a local file, by keyframes over the
    network (IINA measures its first seek).
  - Key bindings: mpv's commands and properties the player has the means
    for (contrast, brightness, gamma, saturation, video-zoom and pan,
    panscan, deinterlace, edition, flip, mirror, open-url and
    save-downloaded-sub are logged and do nothing); no key sequences;
    no-osd and the other prefixes are taken off, the messages shown all
    the same.
  - Advanced: mpv's OSD, its config folder and options are left out;
    logging is the app's (Logger), on and off at once, not at the next
    launch.
  - Utilities: the browser extensions (IINA's, of iina:// links) are left
    out; Set as Default works for a built app only.
  - Live Text is myphp's overlay of Vision's text (VisionKit's
    ImageAnalysisOverlayView is Swift's): choose, copy, show all; no
    look-up, translation or data detectors; not of a picture cropped or
    turned. The strings keep IINA's name where IINA's say it.
- **External audio** (v1.55): `Player::loadAudio`,
  `VideoPlayerState::loadAudio` and `Merged::add` are myphp's, as mpv's
  audio-add (IINA's Load External Audio); mygo has no video.
- **Secondary subtitles** (v1.53): `Player::secondarySubtitleTrack`,
  `setSecondarySubtitleTrack`, `secondarySubtitles`,
  `setSecondarySubtitleDelay`, `secondarySubtitleDelay`,
  `VideoPlayerState::$secondarySubtitlesVisible` and
  `toggleSecondarySubtitles` are myphp's, as mpv's secondary-sid,
  secondary-sub-delay and secondary-sub-visibility (IINA's); mygo has no
  video. Image subtitle tracks are not taken as secondary ones (mpv draws
  them where they are).
- **MPEG-TS and HLS** (v1.51): `Media\Ts`, `Hls`, `HlsStream`,
  `Fragment`, `PackedAudio`, `Download`, `Elementary`, `Bits`, `Cache`, `Native\Aes` and
  `Source::choose` are myphp's, as FFmpeg's mpegts and hls demuxers
  (mpv's, IINA's); mygo has no video.
- **MP4 over HTTP** (v1.50): `Media\Mp4` is myphp's, as FFmpeg's mov
  demuxer (mpv's, IINA's); mygo has no video.
- **Films over HTTP** (v1.49): `Media\Bytes`, `FileBytes`, `HttpBytes`,
  `Source::ready`, `Player::remote`, `bufferingPercent`, `cacheBytes`,
  `cacheSpeed`, the buffering event, `VideoPlayerState::$opening` and
  `byteCount`, and `Native\CurlLoop` are myphp's, as mpv's network
  cache and IINA's; mygo has no video.
- **Boundaries and the A-B loop** (v1.48): `Player::at`,
  `VideoPlayerState::abLoop`, `moveLoopPoint`, `$loopA` and `$loopB` are
  myphp's, as AVPlayer's and IINA's; mygo has no video.
- **The seek bar's thumbnails** (v1.47): `Media\Thumbnail`,
  `Player::thumbnail`, `Source::keyframe`, `VideoPlayerState::$thumbnails`,
  `thumbnailAt`, `$thumbnailCache`, `Media\ThumbnailCache` and
  `Runtime::afterFrame`
  are myphp's, as IINA's; mygo has no video.
- **A layer under the materials** (v1.45): `Node::belowMaterials` is
  myphp's, as the video player's subtitles show under its control bar's
  glass (IINA's); mygo has no system materials.
- **Partial frames** (v1.66): as mygo's, a frame draws only the
  rectangles that changed since its drawable was drawn, and nothing
  when it is the last one's; but what changed under a transform or a
  mask, or a canvas or an image's pixels, draws the frame whole here,
  where mygo bounds them (its ops' transformed bounds, its atlases'
  changed rectangles, the backdrops of effects); a frame with glass is
  drawn whole too.
- **Memos instead of comparing nodes** (v1.65): mygo's incremental
  layout compares each node with last frame's and keeps its measure;
  myphp keeps what the app marks as built from the same
  (`Context::memo($key, $deps, $build)`): the subtree placed again
  without building, measuring, recording or painting it, while nothing
  reaches it (docs/architecture.md, v1.65). Comparing a node in PHP costs
  about as much as laying it out, so mygo's way would save little here.
  The app names what a part shows ($deps), as React's memo.
- **Outlines of text** (v1.45): `Node::textOutline` and `textShadow` are
  myphp's, for subtitles; mygo's text has neither. So are `textBlur`,
  `textCutOut`, `clipMask`, `Span::$ghost` and `DisplayList::setMask`
  (v1.64, `@internal` to the video player, for ASS's \blur, \be, outlines
  and \clip); mygo has no video.
- **ASS's effects** (v1.64): drawings, \frx \fry \fax \fay, \blur \be,
  \clip and \iclip of rectangles and drawings and the \kf sweep are
  drawn as libass draws them, each run with its own; what is left: a
  glyph is cut out of its own outline only, where libass cuts the
  line's fill out of the line's outline (a wide outline under the next
  glyph's half clear fill shows through it); BorderStyle 3's box blurs
  by a feathered edge of 1.64 sigmas, not a Gaussian; a drawing \pbo
  puts below the baseline is moved there without making its line
  taller.
- **ASS in its styles** (v1.44): `Media\Ass`, `AssLine`, `AssRun`,
  `Subtitles::styled`, `assFile`, `assPacket` and `Player::subtitleLines`
  are myphp's, as IINA (libass) shows ASS; mygo has no video.
- **Image subtitles** (v1.43): `Media\Pgs`, `Media\VobSub`,
  `Media\SubtitlePicture`, `Player::subtitlePictures` and
  `subtitlePlane` are myphp's, as IINA (mpv) shows them; mygo has no
  video.
- **Menu headers** (v1.41): `UI\Menu::header` and `MenuItem::HEADER`
  are myphp's, for the playlist's sort menu as IINA's; mygo's menus have
  items, separators and submenus only.
- **System materials, SF Symbols, the traffic lights** (v1.35):
  `Node::systemMaterial`, `Image::symbol`, `Window::setWindowButtonVisibility`,
  `Window::setAspectRatio` and `Player::videoTrack` are myphp's: the developer asked for the video
  example laid out as IINA's, which draws in the system's glass and
  symbols; mygo has neither. The two window methods take Electron's names.

## Fixes and small features from mygo (v1.67)

- **A combobox's and a token field's placeholder**
  - mygo: `Placeholder` on the element a combobox or a token field
    returns.
  - myphp: a `$placeholder` parameter of `Context::combobox` and
    `tokenField`.
  - Why: the node they return is the box around the field, whose own
    placeholder would not reach the field inside; a parameter says where
    it goes.
- **Audio's latency**
  - mygo: `Interactive` mixes about 40 ms ahead.
  - myphp: `Latency::Interactive` about 85 ms (4 AudioQueue buffers of
    1024 frames), `Latency::Playback` about 256 ms (12).
  - Why: PHP fills the buffers on the main thread, which building a
    frame keeps busy; fewer of them run dry meanwhile.
- **A drag's end** (`Transfer\Result`)
  - mygo: `Result{Operation, Canceled, Escaped, Point, Err}`.
  - myphp: `new Result($operation, $canceled, $error, $escaped, $point)`:
    the two new ones after the error, which the constructor took third
    already; named arguments read the same.
  - Why: the order PHP code calling it had stays.
- **Not taken**: `Element.MaximizeButton` (Windows 11's snap layouts
  over a maximize button the view draws) and `FlyoutOptions`'s corners on
  Windows: macOS only here; FSEvents' `NoDefer` (#370): myphp's watches
  are kqueue's and inotify's, which tell at once.

## Layout and text (v1.68)

- **Columns shrink only what asks**
  - mygo: a column's children shrink as CSS's do, down to their content.
  - myphp: a column's children keep their heights, as before, except
    those that set a least height (`minHeight`), which shrink to it.
  - Why: myphp's lists and views count on columns that do not shrink
    them (see "A list as high as its rows"); a split's pane asks.
- **Wrapping columns**: mygo stretches the items of a column that wraps
  to their line; myphp wraps rows only.
- **Hidden** (`Node::hidden(bool)`): as mygo's, but focus that was on
  a node hidden goes where the runtime puts focus that lost its node,
  and a form's labels or a toolbar do not lay out apart for it.
- **Text fields' words**: mygo's buffer moves by letters, digits and
  underscores; myphp's moved by runs between spaces, and now moves as
  mygo's.
- **Taken in v1.70**: `TextLayout.Lines`, justified and indented
  paragraphs, soft hyphens and baseline shift (a8f25ed), rich text's
  paragraph indent and spacing (7e510df), Markdown's inline math
  (610df69).

## Feel (v1.69)

- **Scroll events without the flag**: mygo's events say whether they are
  precise; myphp's `['scroll', x, y, dx, dy]` without the sixth item
  scrolls as a touchpad's, at once, as the tests and apps pushing them
  did before notches glided.
- **A pinch none takes**: mygo's InputZoom goes to the handler under the
  pointer or nowhere; myphp's goes on to `Context::magnified` (the video
  player's pinch) when no node takes it.
- **Drag regions' point**: mygo reads a drag's point from its CGEvent;
  myphp from NSEvent's `mouseLocation` as the monitor sees the drag,
  where mygo falls back to the same.

## Drawing vocabulary (v1.70)

- **Radial gradients of two colors**
  - mygo: `RadialGradient` spreads `Stops`, as many as 32.
  - myphp: `RadialGradient` goes from `$from` to `$to`, each with its
    alpha, as myphp's `LinearGradient` does, or through `$stops` since
    v1.72.
- **Zoom**
  - Glyphs are rasterized in one atlas for every window, at the largest
    backing scale times zoom among them; a window less zoomed samples
    them down, as a window on a lesser display did.
  - A flyout's anchor stays in the window's points, as the app gives it;
    `MaximizeButton` (Windows) has no counterpart.
  - `Headless::rect` returns the window's points, as mygo's `Tester.Find`.
- **Paths**: the dash phases of a path trimmed twice add up, where mygo
  keeps the last trim's; the stroke's style lives on the `Path`, as
  mygo's.
- **Soft hyphens**: where a line may break again is myphp's rough cut of
  UAX #14 (`LineBreaks`, which now breaks after U+00AD), not go-text's
  segmenter. Justification needs no `UseJustify`: PHP links nothing.
- **Inline objects** draw in the transform of the text holding them,
  not of an inline node inside it, which myphp's rich text has no
  transform of its own for.
- **Markdown's inline math**: `$inlineMath` returns an `InlineObject` or
  null for none, where mygo returns a pair.
- **Visible paragraphs**: a paint that moves them sets the runtime's
  `needsFrame`, the window then drawing another; the document has no
  lock, PHP running the app on one thread.

## Drawn menus and menu icons (v1.71)

- **Keys in one frame**: mygo has an element take one key a frame, the
  others waiting for the next (d728256), so that a Down and an Enter
  typed quickly reach a drawn menu in order; myphp's passes take a Down
  and then an Enter of one frame in that order, but an Enter coming
  before a Down is taken after it.
- **A toolbar's overflow**: myphp's overflow menu presses the controls
  it lists and opens no menu button's menu from it, so mygo's
  `askMenuLater` has nothing to do.
- **Headers** (`Menu::header`, myphp's own) show in a drawn menu as
  muted text, chosen by none.
- **Menu icons**: `MenuIcon::of` checks the PNG signature and its IHDR,
  not the whole image, which AppKit then reads; one it cannot read shows
  no image. Icons are macOS's only: the drawn menus show none, as mygo's.

## Gradient stops, conic gradients, element blur (v1.72)

- **Stops' alpha**: a `GradientStop` has a color and an `$alpha` of its
  own, as myphp's colors are an RGB and an alpha; a `RadialGradient`
  keeps `$from` and `$to` as a two-stop shorthand beside `$stops`.
- **Where stops travel**: mygo's renderers upload a frame's stops into
  a float texture; myphp's go in the display list, a slot after the box
  for each, which the fragment shader reads from the quads' buffer.
- **Element blur**
  - mygo draws a blur through its effect machinery (`Effect`, which
    myphp has none of) and blurs the canvas as an image op, averaging
    squares of a wide blur on the GPU first; myphp renders the canvas
    at a fraction of the pixels instead and blurs it as it renders it,
    the window sampling it up.
  - A clip mask inside a blurred node keeps its place in the window,
    not in the canvas's pixels; web views and videos inside one show
    where they are, unblurred.
- **Strokes in a conic gradient** do not fade with a trimmed length as
  mygo's `StrokePathConicGradient` does, as myphp's
  `strokePathGradient` does not either.

## Choosing by dragging over rows, blocks of cells (v1.73)

- **How chosen cells look**: the block's cells are drawn as myphp's
  chosen rows are, under the theme's selection color, where mygo fills
  them with the accent and its text color, as its chosen rows.
- **The row a drag began on**: mygo keeps building it while it is
  scrolled away, to keep the press; myphp's press stays the runtime's,
  so the row is built only when in view.
- **Rows chosen across passes**: mygo's fix keeping the row Shift
  extends from through a second build pass has nothing to fix in
  myphp, whose `Selection` keeps its pivot by its item's key.
- **Linking**: mygo registers list code the first list builds, so that
  apps without lists link none; PHP loads classes as they are used.

## Occlusion culling (v1.74)

- **Occluders**: mygo looks under every opaque fill; myphp only under
  those of 40,000 square points or more, and of a rect or a box, as the
  look costs PHP more than a small one saves.
- **Not taken**: drawing large shadows and border rings as strips around
  their empty interiors, the unblended pipeline for opaque fill cores,
  fragment pipelines specialized by batch class and compiled on a
  worker. myphp's quads draw in one instanced call a pass, which strips
  with scissors of their own would split; Apple's GPUs blend an opaque
  pixel at little more than writing it.

## The terminal and the clipboard (v1.75)

- **The request**: `$onClipboardRequest` gets the `ClipboardRequest`
  itself, whose `allow()` and `deny()` answer it; PHP runs the app on one
  thread, so there is no lock around the answer, and a request answered
  later asks for a frame through the terminal's redraw.
- **The prompt**: one shows a pass; a second read waiting behind an
  answered one gets its prompt in the next frame.

## Distribution (v1.76)

- **Senders' donation**: mygo shows a sender's notification once the
  donation of its interaction completes; the system calls that
  completion on another queue, where PHP cannot run, so myphp donates it
  and updates the content from the intent at once, the update needing
  nothing of the donation. A notification shown, closed or cleared is
  therefore never waiting on a donation, which mygo's cancels.

## Hints-only watches, FSEvents (v1.77)

- **FSEvents' delivery**: mygo has its streams call back on a dispatch
  queue; PHP cannot run there, so myphp schedules them on the main run
  loop in a private mode that the watch runs when it polls. Notices then
  wait in the stream until `next()` is called, up to 4096 held before the
  watch looks at everything, rather than in a channel mygo spills into.
- **Listing a hints-only watch's directories**: mygo reads getdents'
  types through the directory's descriptor and opens each directory
  beneath the root through it (`os.Root`); myphp reads readdir's `d_type`
  by path, checking each directory's identity by lstat, as its scan does.

## Live chart lines (v1.78)

- **`Chart::minMax`'s output**: mygo appends to the slices it is given
  and returns them, so that a view keeping them reduces without
  allocating; PHP arrays are values, so myphp returns `[xs, ys]` new.
- **`strokePolyline` on every renderer**: mygo draws capsules only on a
  GPU that isn't degraded and strokes a path on its CPU renderer; myphp
  always draws through Metal's quads, so an opaque line is capsules
  always. Its color's alpha is the `$alpha` parameter, as `strokePath`'s.

## Markdown in linear time (v1.79)

- **How linear time is checked**: mygo counts the parser's steps
  (`Document.steps`) and its allocations, and checks that they grow
  linearly; myphp times four times the input against once, as its parser
  test did, since counting would add a step to every loop of the parser
  that nothing but the test reads.

## Shaders (v1.80)

- **No CPU renderer**: mygo interprets a shader on the CPU where no GPU
  draws (its CPU renderer, ui.Tester); myphp draws only with Metal, so
  the interpreter (shaderir/interp) is not ported, nor the GLSL and HLSL
  backends, which myphp's renderers would need on Linux and Windows.
- **Compiling**: `Shader::compile` throws `Compiler\ParseError` (or
  InvalidArgumentException) where mygo returns an error, so there is no
  MustCompile. It takes tens of milliseconds in PHP, where mygo takes
  about one; compile once, as the app starts. Syntax errors are the first
  only, without " (and N more errors)"; errors that print a syntax node
  print it as `&ast.Kind{...}`, where Go prints its pointers.
- **Metal's pipeline**: mygo starts building a shader's library on a
  goroutine as it compiles; PHP has none, so the first frame drawing a
  shader waits for it (Metal keeps it on disk for later launches).
- **Colors**: myphp's colors are ints, so an int set in
  `Options::$uniforms` to a vec3 or vec4 is a color (0xRRGGBB, alpha 1);
  `Values::color` takes the color and its alpha.
- **The backdrop**: read at half size when blurred (the glass's backdrop),
  and at full size, as it is, when not; mygo blurs and samples it at the
  sizes its renderers choose.
- **Constants**: integers beyond 64 bits, and fractions whose terms do
  not fit, are floats, where Go keeps them exact.
- **The examples drawing with shaders** (wind, blackhole, chat-glow)
  - mygo: each asks `Painter.Accelerated()` and draws without its shaders
    where the frame draws on the CPU (nothing, a line of text, a disc, a
    plain "Thinking…", no edge glow), and its tests count the pixels the
    CPU renderer draws; wind rasterizes its atlas with `ui.Render`
    between the window's frames (`Window.Update`); wind's composer and
    chat-glow's field are `TextInput`s given their own padding, corners,
    background and border; blackhole's scene canvas is the window's
    pixels at its quality, whatever their number; wind's hint names ⌘R
    or Ctrl+R by platform.
  - myphp: no `Accelerated()` and no CPU paths: the examples always draw
    with their shaders, and their tests check what the display lists
    give the GPU (the shaders' draws, their images, their uniforms); the
    draw asks for the atlas and the next frame's view makes it with
    `Context::shape`, `fontMetrics` and `renderGlyphs`; the fields are
    a `textInputBase()` in a box of that look, which paints the
    placeholder while the field is empty and takes the input's border
    colors (`input()` keeps its own look whatever its setters say, and
    the base has no placeholder); the scene renders at most
    `Canvas::MAX` pixels a side, as at a lower quality, on a window
    larger than that (1200 × 760 points at 2 pixels a point is); ⌘R.
  - Why: myphp draws on Metal only (No CPU renderer, above); ui.Render
    and the shaping functions are the view's here; myphp's field and
    canvas as they are; macOS.

## Large canvases and styled inputs (v1.81)

- **Canvas's size** (the v1.03 entry's 2048)
  - mygo: `ui.NewCanvas` of any size its renderer's textures take.
  - myphp: at most `Canvas::MAX`, 8192 pixels a side (mygo's
    maxEffectLayer, what every GPU's textures allow); one larger than a
    page of the canvases' atlas (2048) renders into a texture of its own
    and draws as an image through a shader of the painter's; far smaller
    than its pixels it has no smaller levels, as any canvas.
  - Why: myphp's canvases live in the image atlas's pages, which its
    quads sample.
- **The examples drawing with shaders** (the v1.80 entry): wind's
  composer and chat-glow's field are `input()`s given their padding,
  corners, background and border, as mygo's `TextInput`s; blackhole's
  scene is the window's pixels up to `Canvas::MAX` a side, as at a lower
  quality past it. Blackhole's caption says its shaders are written in
  PHP, where mygo's says Go: the app, and the compiler of its shaders,
  are PHP here.

## A terminal's clears, copies and drops (v1.84)

- **Clear**
  - mygo: `terminal.Clear` has ClearScrollback too, deprecated, which
    OnClear is no longer asked since the context menu's Clear clears as
    Command-K does.
  - myphp: `Clear` has ClearScreen alone.
  - Why: myphp never had the context menu ask ClearScrollback; no case
    nothing asks.
- **Dropped files' quoting**
  - mygo: `quotingFor` takes PowerShell's quoting for programs other than
    shells on Windows, and cmd.exe's where PowerShell is missing.
  - myphp: `ShellQuote::forProgram` by the program's name alone: pwsh,
    powershell, cmd, else a POSIX shell's.
  - Why: macOS first; the Windows rules come with a Windows backend.

## Painting skips what shows nowhere (v1.86)

- **Drawings of the app's own and the skipping**
  - mygo: an element's Draw and DrawOver paint within its ink; one that
    paints past its box may be skipped where its box is out of view.
  - myphp: a node with draw, drawOver or drawObjects is noCull, and so are
    the nodes around it; painting goes on into them as before.
  - Why: myphp's draw callbacks get the display list itself, with nothing
    holding them to their boxes, and games and charts paint past them.

## A terminal tells of commands that finished (v1.87)

- **Command finished notifications**
  - mygo: none.
  - myphp: `Options::$notifyOnCommandFinish`, `$notifyOnCommandFinishAction`
    and `$notifyOnCommandFinishAfter`, Ghostty 1.3's options, by OSC 133.
  - Why: the developer asked for Ghostty's.

## A shell integration for zsh and bash (v1.88)

- **Shell integration**
  - mygo: none; shells start as they are.
  - myphp: `Options::$shellIntegration` (on): zsh and bash started alone
    read myphp's integration, which marks their commands (OSC 133).
  - Why: the developer asked for command finished notifications, which
    zsh and bash give no marks for without one.

## Japanese (v1.89)

- **The strings myphp has beyond mygo's**
  - mygo: every string of its own in Japanese.
  - myphp: ja.json holds mygo's strings and the terminal's; the video
    player's, its settings' and the IINA-ported windows' (myphp's own,
    which mygo has not) show in English in Japanese.
  - Why: mygo's translation covers its strings; myphp's player strings
    were translated to Chinese alone so far.

## Now Playing and the media keys (v1.93)

- **The artwork on macOS**
  - mygo: `NowPlayingInfo.Artwork` shows in Control Center, as an
    `MPMediaItemArtwork` whose handler decodes the image when asked.
  - myphp: `NowPlayingInfo::$artwork` is kept and `NowPlaying::info()`
    gives it back, but macOS does not show it.
  - Why: the artwork's handler is called on a background thread, where
    PHP cannot run, and a block calling no PHP cannot hand back an image.
- **The commands' type**
  - mygo: `MediaCommand`, a uint32 type of bits.
  - myphp: `int` bits, constants of `MediaCommand` (`MediaCommand::PLAY`).
  - Why: PHP's enums cannot be combined into sets.
- **`NowPlaying::info()`**
  - mygo: `Info() (NowPlayingInfo, bool)`.
  - myphp: `info(): ?NowPlayingInfo`, null when nothing is shown.
  - Why: PHP has one return value.
- **`Player::onEnd`**
  - mygo: each listener on a goroutine of its own.
  - myphp: on the main thread, once the output mixed the player's last
    frames.
  - Why: PHP has no goroutines; the mixer runs on the main thread.
- **Linux and Windows**
  - mygo: MPRIS on Linux, SystemMediaTransportControls on Windows, the
    Media Session in browsers.
  - myphp: macOS only, as the rest of it.
  - Why: myphp runs on macOS.
