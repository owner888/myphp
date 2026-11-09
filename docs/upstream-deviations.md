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
    function; the text, selection and placeholder, and selecting text
    (`InputEvent::SELECT`, v1.122), but not the runs' formats.
  - Why: myphp's `editItems()` knows text fields and selectable text,
    and its `AccessNode` has no runs.

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
- **Keys through the text system's selectors** (v0.82, v1.123)
  - mygo: `textSelectionKey` reads keys and modifiers.
  - myphp: AppKit's selectors (`moveRightAndModifySelection:`…); Home and
    End are told from Cmd and the arrows by the key event before the
    selector. Left and Right move by visual graphemes (`visualNext`, the
    text layout's `moveCaret`) since v1.123, as mygo's.
  - Why: myphp's keys come as selectors.
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

- **Setters under other names** (v0.85, v1.123)
  - mygo: `AlignItems`, `Radius`, `MinWidth`/`MinHeight`, `Width`/`Height`,
    `Border(width, color)`, `Gap`, `Grow`, `Padding(v...)` on any element.
  - myphp: since v1.123 `alignItems()`, `radius()`, `minWidth()`/
    `minHeight()` and `border($width, $color)` (within the node's size);
    still `size($w, $h)` and the `$gap` and `$grow` properties or
    `column($pad, $gap, $grow, $color)` beside mygo's `width()`,
    `height()`, `gap()`, `grow()`.
  - Why: `size()` and the builders' arguments are what every widget and
    example builds with.
- **Insets and percentages** (v1.03, v1.07)
  - mygo: `Top`, `Right`, `Bottom`, `Left`, `Start`, `End` and their
    `…Percent`s place an absolute element in its parent's padding box and
    move a relative one; `WidthPercent` and the other percentages keep an
    element in the flow.
  - myphp: the same since v1.07 (`absolute()`, `top()`…, `widthPercent()`
    in the flow); `at($x, $y)` stays myphp's own, placing from the
    content box. `grow()` takes fractions.
  - Why: `at()` came before the insets; the widgets place with it.
- **Element styles** (v1.07)
  - mygo: `Shadow(x, y, blur, spread, color)` and `InsetShadow` with a
    Gaussian blur (sigma half the blur), continuous (squircle) corners,
    clips with rounded corners, pixel-snapped boxes, borders over clipped
    children (`borderOver`), dividers before absolute children, `Debug`
    walking the subtree once, `Disabled` dimming any element, and
    `Cursor` as a `uint8` enum.
  - myphp: the same `shadow()` and `insetShadow()` (`$rgb` and `$alpha`
    for the color) through two new quad kinds, a box (14) and a box
    shadow (15), each with a second slot; continuous corners since
    v1.133, as mygo's on darwin; clips with rounded corners since v1.133,
    through the list's mask, one at a time (a rounded clip inside
    another, or one through a transform, cuts by its rectangle); boxes snapped to whole pixels since v1.132 (`DisplayList::$pixels`); borders under clipped children; dividers
    after all children; a nested `debug()` outlines its own subtree;
    a disabled node dims as mygo's (only containers did until v1.26);
    `Cursor` a string-backed enum named as CSS names them.
  - Why: the display list's quads hold one radius and rectangular clips,
    and a quad goes through one mask; myphp's widgets drew their
    disabled look before.
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
## Tool tips

On macOS a node's tip (`tooltip()`, SwiftUI's `help()`) is the system's:
the window gives AppKit the tip of the node under the pointer
(`Platform\NativeTips`, the view's `toolTip`), which shows it at the
pointer, after its own delay, in the system's look, as SwiftUI's do, where
mygo draws a tip of its own, centered over its node. A tip a view builds
itself (`Context::tooltipBase`) is still drawn, and so are all tips on the
other platforms; a tip of the keyboard focus shows on those alone.

## Scrolling

- **ListState and GridState methods** (v0.84)
  - mygo: `ScrollTo(row, align)`, `ScrollIntoView(row)`, `ScrollToEnd()`,
    `Visible()`, `AtEnd()`; `GridState.ScrollTo(item, align)`.
  - myphp: `scrollTo($row, $smooth, $align)`, `scrollToEnd()`,
    `scrollIntoView($row)`, `visible()`, `atEnd()` (v1.122);
    `GridState::scrollTo($item, $align)`.
  - Why: myphp's scrolls may glide, which `$smooth` asks for.

## Menus

- **What a menu looks at under the pointer** (v0.83)
  - mygo: the hit chain from the innermost element out
    (`menuTarget(chain)`).
  - myphp: the nodes with a menu, the text inputs, the selectable text and
    containers, and the Unselectable nodes under the point, the last built
    first (`menuChain`).
  - Why: myphp has no hit chain; the build order stands in for it.
- **Windows** (v0.83)
  - mygo: Windows opens context menus on the release, with Undo and
    Delete in a text input's.
  - myphp: macOS's menus only.
  - Why: myphp runs on macOS.

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

- **HTTP from PHP** (v1.152)
  - mygo: a Go app, its native UI too, makes requests with Go's
    `net/http` in a goroutine; the plugin has no Go-side API.
  - myphp: `MyPHP\Http::get()` / `request()`, returning an
    `HttpResponse` (status, body, headers, `ok()`, `json()`): libcurl's,
    driven by the run loop (`Native\CurlLoop`), suspending a fiber of
    `App::go()` while it waits, the run loop turning outside one.
  - Why: PHP has neither goroutines nor a non-blocking HTTP client of its
    own; native UI needs one that leaves the app drawing.

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
    `clip()` (its radius last, optional, since v1.133), `transform()`, `fillPath()`,
    `strokePath()`, `icon()`; colors 0xRRGGBB with an opacity.
    `Node::draw()` and `drawOver()` hand a draw the painter and the node's
    box, as mygo's, since v1.123; the tree painter's own helpers became
    `paintIcon` and `paintFocusRing`.
  - Why: what a game draws with, first; the display list has no tint;
    `clip()`'s radius came after its callback.

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
    there; the Open Recent menu role.
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
- **Arguments left out** (v1.152)
  - mygo: a missing argument, or a `null`, is the parameter's zero value.
  - myphp: a missing argument takes the parameter's default; a `null`
    (an optional argument the client's caller left out: JSON has no
    `undefined`) takes it too when the type takes no `null`; Go has no
    defaults, PHP no zero values.
  - Why: PHP's parameters have defaults where Go's have zero values.
- **Trusted pages** (v1.02)
  - mygo: trust is decided as a page's navigation commits;
    `PageOptions.TrustedOrigins`.
  - myphp: as each call comes, from the page's URL;
    `PageOptions::$trustedOrigins`; pages that are not trusted get mygo's
    error with myphp's names.
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
    (progress, checkbox, switch, slider), `start()`/`end()` insets,
    attach anchors, menus opening toward the start, the chevrons; not
    yet: text fields and areas (the caret and the lines from the left);
    the variable is `MYPHP_DIRECTION` (`ltr` or `rtl`).
  - Why: ported in steps; the rest needs TextState laid out right to
    left first.
- **A line's end where the direction changes** (v1.178)
  - mygo: the caret at a line's end is the end of its last character
    (`CaretAt`), so an English line ending in Arabic or Hebrew has it in
    its middle; a hit past the line's right edge takes the nearest stop,
    the start of that right-to-left text.
  - myphp: a line's end is at its edge in the paragraph's direction, as
    CoreText's offset of it, and a hit past that edge is the line's end
    (`Text\Layout::lineEnd`, `positionAt`).
  - Why: what is typed at the end shows at that edge; with mygo's, a tap
    after the text put the caret at the line's right and the typing in
    its middle (the text input example on iOS).

## Inspector

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
    `AttachContent`, as the window makes its view itself; the page goes
    in the content's tree for VoiceOver (v1.122), inside a node of its
    own as mygo's.
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
    `clear()` (pending ones too) are mygo's. mygo's `ShowContext` (its
    #952), which stops waiting for the answer, has none: nothing waits.
  - Why: UNUserNotificationCenter answers on a background queue, where
    PHP cannot run.
- **Windows**
  - mygo: `SetSkipTaskbar`, `SetIcon`, `SetMenu`, `SetAutoHideMenuBar`,
    which do nothing on macOS; window state captured 300 ms after it
    settles.
  - myphp: none of the four yet, as they wait for Linux and Windows;
    the same state, timed with `App::after`.
  - Why: the Mac is done first.
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

- **Not ported**: TestHeldWhileOccluded and TestFocusChangeFrames (the
  window draws a frame whenever it becomes or stops being the key window;
  TestRepaintFrames since v1.113, frames that only paint again),
  TestIndicatorsRepaint's pixels, TestTrackScrollList
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
  myphp lays out again once the texts that need it wrap. The second
  layout gives them the widths of the first: a wrapped text, and a row
  that wraps, ask for their max-content width as mygo's intrinsic sizes
  do, their height alone following their lines.
- **A row's wrap width** (v1.152)
  - mygo: `Wrap()` takes no width: the layout measures the lines at the
    width it gives the row.
  - myphp: `wrap($width)` takes the width the row is measured at before
    its own is known (0: on one line, then wrapped at the width it got in
    the second layout).
  - Why: the two layouts above; the first knows no width yet.

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

- **Media\Player and Context::movie**
  - mygo: no film player; `ui.Video` plays the frames a decoder gives it
    (myphp's `Context::video`).
  - myphp: `Media\Player` plays Matroska, WebM, MP4, MOV and M4V (H.264,
    HEVC, and VP9 and AV1 where the Mac decodes them in hardware; AAC,
    AC-3, E-AC-3, MP3, Opus) with the system's decoders, and
    `Context::movie` shows its picture under the window's content, through
    a hole of its node, as web views show; macOS only.
  - Why: the developer asked for a video player as fast as the system
    plays video; mygo has none to follow, so the names follow mygo's way
    of naming (`Player`, `Track`, `Packet`); `Context::movie`, as
    `Context::video` is mygo's `ui.Video` (v1.118).
- **Subtitles** (v1.34): `Player::subtitleTracks`, `setSubtitleTrack`,
  `loadSubtitles`, `subtitles`, `setSubtitleDelay` and `Media\Subtitles`
  are myphp's too, as IINA's behavior has them (files beside the film
  loaded): mygo has no film player.
- **The picture and the sound, a video player** (v1.38):
  `Player::setAspectRatio`, `setCrop`, `setRotation`, `setAudioDelay`,
  `Context::mediaPlayer`, `MediaPlayerState` and `MediaPlayerOptions` are
  myphp's, as IINA's window has them: mygo has no film player.
- **Going on where a film was left** (v1.39): `Media\PlaybackPositions`,
  `Player::path` and `MediaPlayerOptions::$resume` are myphp's, as IINA's
  "resume last playback position": mygo has no film player.
- **The playlist, picture in picture** (v1.40): `Media\Playlist`,
  `Player::durationOf`, `MediaPlayerState::open`, `setPlayer`, `next`,
  `previous`, `togglePictureInPicture` are myphp's, as IINA's; mygo has
  no video.
- **Chapters, the playlist's loop, shuffle and sort** (v1.41):
  `Media\Chapter`, `Source::chapters`, `Player::chapters`, `chapter`,
  `MediaPlayerState::playChapter`, `$loop`, `nextLoop`, and
  `Playlist::shuffle`, `unshuffle`, `shuffled`, `sortByName`,
  `sortByPath` are myphp's, as IINA's, and `Player::awake`,
  `setKeepAwake` and `MediaPlayerOptions::$keepAwake` (the display kept
  awake while it plays, as IINA's); mygo has no film player.
- **The player's events, keyframe seeks, Now Playing** (v1.46): the
  events play, pause, rate, seek and track of `Player::on`, the `$exact`
  of `Player::seek` and `MediaPlayerState::seekBy`, and
  `MediaPlayerOptions::$nowPlaying` are myphp's, as AVPlayer's and IINA's,
  and `Player::step`, `snapshot`, `MediaPlayerState::frameStep`,
  `screenshot` (IINA's frame steps and screenshots; its `$screenshots`
  went in v1.61, the settings saying where they go);
  mygo has no film player.
- **yt-dlp, two sources, AV1 in software** (v1.52): `Media\YtDlp`,
  `Merged`, `CacheBudget`, `CacheMember`, `Player::title`, `Player::playlist`, `Player::$hardwareDecoding`,
  `Playlist::title` (and its titles), the headers of `HttpBytes`,
  `Download` and `Hls`, `Native\Dav1d` and `SoftVideo` are myphp's, as
  mpv's ytdl_hook, its audio-file and its libdav1d decoding (IINA's);
  mygo has no film player.
- **The equalizer** (v1.55): `Player::setEqualizer`, `equalizer`,
  `EQUALIZER_BANDS`, `MediaPlayerState::EQUALIZER_PRESETS`, `$equalizer`,
  `$equalizerPreset` and `setEqualizer` are myphp's, as IINA's audio
  equalizer (its presets, its filter for mpv); mygo has no film player. Flat
  turns it off (IINA adds the filter all the same, of no effect).
- **The equalizer's profiles** (v1.56): `Media\EqualizerProfiles`
  (equalizer-profiles.json in the app's user data, as IINA's
  userEQPresets) and MediaPlayerState's profile methods are internal,
  the pop-up's; the profiles keep the order they were saved in (IINA's,
  a Swift dictionary's, have none). `Dialog::prompt`'s detail and check
  (IINA's quickPromptPanel) are internal too.
- **Online subtitles** (v1.60): `MediaPlayerOptions::$openSubtitlesKey`
  and `Context::mediaPlayerSettings` are myphp's, as IINA's OpenSubtitles
  (Find Online Subtitles) and its Subtitles pane; mygo has no film player. With
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
  again), `Context::mediaPlayerWelcome` and `Context::mediaPlayerHistory`
  (IINA's welcome and playback history windows) are myphp's; mygo has no
  video. `MediaPlayerState::$screenshots`, `setSubtitleLanguages` and
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
  `MediaPlayerState::loadAudio` and `Merged::add` are myphp's, as mpv's
  audio-add (IINA's Load External Audio); mygo has no film player.
- **Secondary subtitles** (v1.53): `Player::secondarySubtitleTrack`,
  `setSecondarySubtitleTrack`, `secondarySubtitles`,
  `setSecondarySubtitleDelay`, `secondarySubtitleDelay`,
  `MediaPlayerState::$secondarySubtitlesVisible` and
  `toggleSecondarySubtitles` are myphp's, as mpv's secondary-sid,
  secondary-sub-delay and secondary-sub-visibility (IINA's); mygo has no
  video. Image subtitle tracks are not taken as secondary ones (mpv draws
  them where they are).
- **MPEG-TS and HLS** (v1.51): `Media\Ts`, `Hls`, `HlsStream`,
  `Fragment`, `PackedAudio`, `Download`, `Elementary`, `Bits`, `Cache`, `Native\Aes` and
  `Source::choose` are myphp's, as FFmpeg's mpegts and hls demuxers
  (mpv's, IINA's); mygo has no film player.
- **MP4 over HTTP** (v1.50): `Media\Mp4` is myphp's, as FFmpeg's mov
  demuxer (mpv's, IINA's); mygo has no film player.
- **Films over HTTP** (v1.49): `Media\Bytes`, `FileBytes`, `HttpBytes`,
  `Source::ready`, `Player::remote`, `bufferingPercent`, `cacheBytes`,
  `cacheSpeed`, the buffering event, `MediaPlayerState::$opening` and
  `byteCount`, and `Native\CurlLoop` are myphp's, as mpv's network
  cache and IINA's; mygo has no film player.
- **Boundaries and the A-B loop** (v1.48): `Player::at`,
  `MediaPlayerState::abLoop`, `moveLoopPoint`, `$loopA` and `$loopB` are
  myphp's, as AVPlayer's and IINA's; mygo has no film player.
- **The seek bar's thumbnails** (v1.47): `Media\Thumbnail`,
  `Player::thumbnail`, `Source::keyframe`, `MediaPlayerState::$thumbnails`,
  `thumbnailAt`, `$thumbnailCache`, `Media\ThumbnailCache` and
  `Runtime::afterFrame`
  are myphp's, as IINA's; mygo has no film player.
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
  and \clip); mygo has no film player.
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
  are myphp's, as IINA (libass) shows ASS; mygo has no film player.
- **Image subtitles** (v1.43): `Media\Pgs`, `Media\VobSub`,
  `Media\SubtitlePicture`, `Player::subtitlePictures` and
  `subtitlePlane` are myphp's, as IINA (mpv) shows them; mygo has no
  video.
- **The player's menus** (v1.135): `UI\MediaPlayerMenus` (IINA's
  Playback, Video, Audio and Subtitles menus, File's items of the film,
  Window's Log Viewer), `MenuItem::$alternate` (NSMenuItem's alternate,
  IINA's slight steps under Option), `MenuItem::$onOpen` (NSMenuDelegate's
  menuWillOpen:, as IINA brings its menus up to date) and
  `MenuItem::setSubmenu` are myphp's; mygo has no film player, and its
  menu items neither alternates nor a call as they open. Of IINA's items,
  left out so far: Video's Lock Window Aspect Ratio (Deinterlace since
  v1.141, the filters since v1.140, Delogo and Crop's Custom since
  v1.142); Find Online Subtitles from has OpenSubtitles alone (assrt.net and
  shooter.cn since v1.139). The film's picture is its one track, shown always:
  Cycle Video Tracks and Video Track's <None> are disabled, as is <None>
  of the sound. IINA's hidden menu of a second key of an item's is left
  out: the window's keys carry out every binding. A key without Command
  or Control goes to a focused field before the menus take it (IINA
  favors the arrows only).
- **The picture's adjustments** (v1.136): `Player::setVideoEqualizer`,
  `VIDEO_EQUALIZER`, `setFlip`, `setMirror`, `reversed`, `hdr`,
  `Renderers::setPictureFilters` and `MediaPlayerState::setVideoEqualizer`,
  `setFlip`, `setMirror`, `setHdr`, `hdrAvailable` are myphp's, as IINA's
  sidebar and Video > Flip; mygo has no film player. Core Image's filters
  stand for mpv's equalizer: its colors close to mpv's, not the same
  arithmetic (mpv's brightness, contrast, saturation and hue are of its
  YUV to RGB matrix). A screenshot and picture in picture show the
  picture as the file has it, neither filtered nor reversed. The HDR
  switch shows for a film its container says is PQ or HLG (mpv reads the
  decoder's). mpv's video-zoom and video-pan, of no IINA menu or panel,
  are left out.
- **The Inspector and the audio device** (v1.137):
  `UI\MediaPlayerInspector` (and its `window()`), `MediaPlayerMenus::inspector`,
  `MediaPlayerCommands::propertyValue`, `Player::bitrate`, `fileFormat`,
  `videoDecoder`, `audioDecoder`, `VIDEO_OUTPUT`, `AUDIO_OUTPUT`,
  `fileSize`, `MediaPlayerState::setAudioDevice` and `$audioDevice` are
  myphp's, as IINA's Inspector and Audio > Audio Device; mygo has no film
  player. The Inspector is a window of the HUD's material, not IINA's
  utility panel: it does not float, nor hide as the app does (the menus
  stay the player's while it is in front, as the panel's). mpv's
  statistics the player has no means to know are N/A: the A/V sync,
  dropped and mistimed frames, the estimated frame rates, the pixel
  format, the audio's decoded format, a file's comment and editions, the
  colorspace beyond whether it is HDR; Forced is never on (no track says
  it). IINA shows mpv's names (its decoders, vo and ao, FFmpeg's
  demuxers); MyPHP, which plays without mpv, shows its own: VideoToolbox,
  libdav1d, AudioToolbox, AVSampleBufferDisplayLayer and
  AVSampleBufferAudioRenderer, its readers Matroska, MP4, MPEG-TS, HLS
  and AVFoundation. The watch table takes the properties the player
  gives by mpv's names (`propertyValue`), Error of the rest.
- **assrt.net and shooter.cn** (v1.139): `Media\Assrt`, `Media\Shooter`,
  `MediaPlayerState::PROVIDERS`, `onlineProvider`, `canSearchOnline` and
  `searchOnlineSubtitles`'s source are myphp's, as IINA's sources of
  subtitles online; mygo has no film player. IINA's plugins' sources are
  left out (myphp has no plugins of IINA's). The settings keep
  opensubtitles.com's account under its source, where IINA's new window
  says the old support is deprecated: myphp's OpenSubtitles is of the
  REST API and logs in from there. assrt.net's search answers now in
  IINA's fields (id, native_name…), now in its site's (fileid,
  video_chinese_name…), on which IINA's reading fails: each row is read
  by those it has.
  assrt.net's rows show when a subtitle
  was uploaded as a date, as OpenSubtitles' do (IINA shows assrt.net's
  text). A shooter.cn subtitle's file is named by the server's
  Content-Disposition, else the link asked (IINA's, by Just, the link
  after redirects).
- **The plugins' last part** (v1.145): `PlayerPlugins\InputApi`,
  `WsApi`, `WsMessage`, `WebSocketServer`, `DevTool`, `Plugins`' GitHub,
  update and store functions, `UI\MediaPlayerPluginStore`,
  `MediaPlayerDevTool`, the playlist's menu and mpv's hooks of a film
  opening (`Media\Opening`) are myphp's, as IINA's; mygo has no film
  player. Added to the framework: `Context::keysReleased`,
  `Context::buttonPressed`, a key's repeat to `takeKeys`, a key's release
  asking for a frame. The developer tool runs PHP (the developer's
  choice), not JavaScript. The store lists myphp's official plugins of
  PHP and myphp's repository's plugins.json, not IINA's of JavaScript,
  whose plugins do not load (an entry not of PHP is refused). A mouse
  button's release a plugin takes keeps the picture's action from it; a
  double click's action is the second press's, as myphp's picture's (IINA's
  the release's). The plugins' mouse down of the right and other buttons is
  where they went down on the picture. Of mpv's hooks there are the three
  of a film opening; a hook is over as it returns (its next may be left
  out), and the film's properties it sets are those the player has a use
  for (others logged); an EDL is read only as a single URL (a subtitle's
  wrapper), not as fragments joined. The store's texts IINA leaves in
  English are English here too. The playlist's menu has no subtitles
  section (myphp keeps no subtitles matched to the playlist's entries)
  and acts on the entry clicked (one chosen at a time). Online Media
  switches a format by opening the page again (core.open, IINA's
  loadfile); its yt-dlp is the user's or its own (no bundled one).
  Reload All Plugins tells no iina.mpv-initialized again (there is no
  mpv to tell of).
- **The plugins' views** (v1.144): `PlayerPlugins\WebPane`,
  `OverlayApi`, `SidebarApi`, `StandaloneWindowApi`, the player's
  plugins panel and the details sheet's Settings and Help tabs are
  myphp's, as IINA's; mygo has no film player. Added to the framework:
  `Page`'s 'message' event (a page posting to the app), `Page::loadFile`'s
  folder to read, `Node::hitAreas`, `WebView::setAlpha`,
  `Window::setFullSizeContentView` and `setTitleBarHidden`, and the
  window stacking its materials with its web views as painted (mygo's
  stackWebViews stacks the web views alone). An overlay's clickable parts
  are its elements of data-clickable as its page reports their
  rectangles (IINA hit-tests the page at each click). The pages of the
  overlay, the sidebar and the standalone window go where they link but
  to the plugin's allowed domains, as IINA's decidePolicyFor does; the
  settings page alone stays in the plugin's folder. The plugins panel is on the window's left, as
  IINA's leading sidebar; the About tab is native, as myphp's About
  sheet of v1.143.
- **Player plugins** (v1.143): `PlayerPlugins\*` (`Plugins`, `Plugin`,
  `Instance`, `Iina` and its APIs), `UI\MediaPlayerPluginsPage`,
  `MediaPlayerMenus::plugin`, `MediaPlayerSettings::$showPage`,
  `MediaPlayerState`'s plugins, events and the methods they call,
  `Runtime::$window`, `Playlist::insert` and `move` are myphp's, as IINA's
  plugins; mygo has no film player. By the developer's choice they are
  PHP, not IINA's JavaScript: IINA's own plugins do not run, and a
  plugin runs in the app's process, so its permissions keep what its
  APIs do, not what PHP lets it do. IINA's promises are values returned
  (waiting in a fiber), their rejections thrown; JavaScript's require is
  PHP's own, and an entry is read again by Reload All Plugins, but a
  class a plugin declared stays as first loaded (PHP declares a class
  once). Without mpv: mpv's properties are those MyPHP gives, set and
  command a key binding's, hooks none (addHook throws). Its
  preferences are JSON, not a plist. core.window.frame is MyPHP's, its
  top left from the main screen's; core.getVersion's mpv is MyPHP's
  player; setUIVisibility(false) hides the controls (IINA's sets its
  disableUI to the argument). utils.exec finds a program on the PATH.
  The permissions to agree to are the alert's text, not a list beside
  it; Conflicting key shortcuts… lists the plugins and keys under it.
  Not yet (v1.145 has them): the playlist's menu items of registerMenuItemBuilder, input, websockets, GitHub, the store,
  updates and the developer tool.
- **The interactive mode** (v1.142): `UI\MediaPlayerInteractive`,
  `MediaPlayerState::enterInteractive`, `exitInteractive`,
  `interactiveDone`, `toggleDelogo`, `hasLabel` and the window's
  `fixedSize` ask are myphp's, as IINA's interactive mode; mygo has no
  film player. A click beside the picture does nothing (IINA's goes on to
  the window's click actions); the subtitles do not show over the
  picture meanwhile (mpv draws them into IINA's). The selection's height
  is scaled by the picture's height (IINA's origin by its width, the same
  where the pixels are square); an edge dragged past the other one
  becomes it (IINA's box turns inside out). The quick settings' Crop has
  no Custom yet.
- **Deinterlace** (v1.141): `Native\Deinterlacer`,
  `Player::setDeinterlace`, `deinterlaced` and
  `MediaPlayerState::setDeinterlace` are myphp's, as IINA's Deinterlace;
  mygo has no film player. mpv deinterlaces with a motion-adaptive filter
  (bwdif or yadif, at the field rate); MyPHP blends each frame's lines
  ([1 2 1] / 4, FFmpeg's pp lb), at the frame rate, every frame alike
  (mpv's deinterlace=yes), decoded on the main thread while it is on.
- **Video and audio filters** (v1.140): `Media\Filter`, `FilterPresets`,
  `VideoFilters`, `AudioFilters`, `Lut3d`, `Native\Biquads`,
  `AudioChain`, `UI\MediaPlayerFilters`, `Player::setVideoFilters`,
  `setAudioFilters`, `filteredSize`, `MediaPlayerState::setFilters` and
  the rest are myphp's, as IINA's Filters windows; mygo has no film
  player. Without mpv and FFmpeg, MyPHP does the filters of IINA's
  presets and a few of FFmpeg's audio ones (volume, equalizer, bass,
  treble, highpass, lowpass, pan); any other, and FFmpeg's expressions
  (iw/2), are IINA's error. Sharpen and blur are Core Image's unsharp
  mask (a Gaussian a quarter of the matrix, not FFmpeg's matrix), delogo
  blurs the logo's rectangle (FFmpeg interpolates it from its edges),
  lut3d reads .cube files alone and interpolates trilinearly whatever its
  interp. Of IINA's own filters, the interactive mode's custom crop and
  delogo are in the lists, labeled (since v1.142); Crop's ratios, Flip and
  the equalizer are not, MyPHP's own settings of the player. The sheets are
  taller than IINA's (New Filter 450 by 300, its parameters all in sight;
  Save Filter and Edit Filter for MyPHP's taller fields); a key
  with Command recorded goes to the menus first where one has it.
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
  the interpreter (shaderir/interp) is not ported, nor the HLSL backend,
  which a renderer on Windows would need. Of the GLSL backend only what
  `glsl.Fragment` reaches is ported (`Glsl\Glsl::fragment`,
  `uniformLanes`), not `glsl.Compile`, a whole program with its entry
  points, which mygo's renderers do not draw with.
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
  - Why: nothing holds a draw to its box, and games and charts paint past
    them.

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

## Videos in native UI, animated GIFs first (v1.103)

- **Decoding**
  - mygo: a goroutine decodes two frames ahead; a late decoder wakes the
    window as it has the frame due.
  - myphp: the player decodes on the main thread as the playhead reaches a
    frame, during the frame that shows it; the frames due meanwhile pass
    over as mygo's do.
  - Why: PHP has no goroutines; ImageIO decodes a GIF's frame in well under
    a frame's time.
- **The decoder's end**
  - mygo: `Next` returns `io.EOF` after the last frame; a decoder that is an
    `io.Closer` is closed with its player.
  - myphp: `next()` returns null after the last, throws for a frame that does
    not decode; a decoder with a `close()` method has it called.
  - Why: PHP has no error values.
- **GIFs**
  - mygo: decoded in Go (image/gif) into memory at once, a byte a pixel,
    each frame drawn over the ones before as its disposal says.
  - myphp: ImageIO composes each frame as it plays (macOS only).
  - Why: the developer chose ImageIO.

## The system's proxy (v1.103)

- **The API**
  - mygo: package `netproxy`, `Proxy` (an `http.Transport`'s) and
    `Transport`, which apps' own Go clients can use.
  - myphp: internal (`Native\NetProxy`): myphp's own requests use it, the
    updater's, the fetch and WebSocket plugins', and a film's over HTTP.
  - Why: myphp has no HTTP client type for apps to give it to.
- **The environment**
  - mygo: Go's `ProxyFromEnvironment` reads it.
  - myphp: libcurl reads it (`http_proxy` in lowercase only, `HTTPS_PROXY`,
    `ALL_PROXY`, `NO_PROXY`); that one of mygo's variables is set decides
    that it does.
  - Why: curl's own reading, which its users know.
- **Redirects**
  - mygo: the transport asks the proxy of each request, a redirect's too.
  - myphp: the proxy of the URL a request starts at serves its redirects.
  - Why: curl follows redirects itself, on the handle's proxy.

## Sessions and picture in picture (v1.112)

- **The constructors of sessions**
  - mygo: package functions `NewSession`, `NewEphemeralSession` and
    `DefaultSession`, and `SiteData` constants.
  - myphp: static methods of `Session` of those names in lowerCamel
    (`Session::newSession`, `newEphemeralSession`, `defaultSession`), and
    `SiteData::COOKIES`….
  - Why: PHP has no package functions to autoload.
- **Cookies**
  - mygo: `Cookies` is a `CookiesModule` value, as `Session.Cookies` is.
  - myphp: `Cookies` stays a class of static methods, which call the
    default session's `CookiesModule`.
  - Why: apps already call `Cookies::list()`.
- **Picture in picture's state**
  - mygo: `IsPictureInPictureActive` asks the page's
    `document.pictureInPictureElement` when WebKit's flag says no.
  - myphp: what the bridge last told of it (at `pageshow`, and as a video
    goes in or out).
  - Why: the same answer without waiting for an asynchronous call.
- **Context menus**
  - mygo: `Page.OnContextMenu`, and package constants `ContextMenuReload`….
  - myphp: `Page::on('contextMenu', …)`, as the page's other events, and
    `ContextMenuEvent::RELOAD`….
  - Why: myphp's pages take their listeners by on(); PHP has no package
    constants to autoload.

## Automation (v1.116)

- **Turning it on**
  - mygo: an app imports `plugins/automation` behind a build tag, and
    builds without the inspector leave it out; it listens when
    `MYGO_AUTOMATION` is set.
  - myphp: `App::use(Automation::plugin())`, which the app calls in the
    runs it is driven in; it listens when `MYPHP_AUTOMATION` is set. An
    app run from its project's files in development (`App::isDev()`, not
    `App::isPackaged()`) with the variable set gets the plugin from
    `App::run` without adding it, so that `myphp screenshot` drives any
    app; a bundle `myphp build` made, `-debug` or not, only if the app
    added it.
  - Why: PHP has no build tags nor imports that run code; a run from the
    project's files is a development run, as a build with the inspector.
- **resize**
  - mygo: the protocol has no request that resizes a window.
  - myphp: `resize` {window, width, height} sets the content's size, as
    `Window::setContentSize`, for `myphp screenshot -size`.
  - Why: mygo's CLI has no screenshot command; an agent checks a layout
    at other sizes.
- **myphp screenshot**
  - mygo: no such command.
  - myphp: runs the app once from its files with the plugin on a socket of
    its own, captures its window once idle, writes its tree, quits it; on
    Linux without a display, under an Xvfb it starts. `-theme light|dark`
    sets `MYPHP_THEME`, which `App::run` applies as `Theme::setSource` in a
    run from the project's files in development (as the plugin above);
    `-data dir` sets `MYPHP_DATA_HOME`, which `App::path` reads in place of
    `~/Library` in such a run on the Mac, and on Linux `XDG_CONFIG_HOME`,
    `XDG_CACHE_HOME`, `XDG_DATA_HOME` and `XDG_STATE_HOME` under it.
  - Why: coding agents see the app they wrote from the command line, in
    either appearance, without touching the user's data.
  - A web page is captured once `idle` (mygo's: loaded, answering a
    script) and then its calls of PHP methods are answered and two frames
    drawn, twice in a row (`window.__myphp.calls()`, the calls waiting
    without a channel), so that what renders from their replies shows;
    `-tree` on a page saves the PNG alone with a warning; SIGTERM, SIGINT
    and SIGHUP end the app and Xvfb before the command exits.
- **Serving**
  - mygo: a goroutine a connection, each request served as it comes.
  - myphp: the socket polled on the run loop every 5 ms; `idle` waits
    without holding other connections, but `eval` holds them while the
    page answers.
  - Why: PHP runs the app on one thread.
- **Modifiers of key events**
  - mygo: a key event's `mods` are ignored; its accelerator gives them.
  - myphp: they join the accelerator's.
  - Why: an event's fields are not dropped silently.

## The media player (v1.118)

- **Its names**
  - mygo: no player of films; `ui.VideoPlayer` plays the frames of a
    decoder (`Context::video`'s, myphp's `UI\VideoPlayer`).
  - myphp: the player laid out as IINA's is `UI\MediaPlayer` (its state
    `MediaPlayerState`, its options, settings and parts `MediaPlayer…`),
    built by `Context::mediaPlayer`, `mediaPlayerSettings`,
    `mediaPlayerWelcome` and `mediaPlayerHistory`; it shows a
    `Media\Player` with `Context::movie`.
  - Why: `VideoPlayer` and `Context::video` are mygo's names for its
    videos, which myphp's take; the films' player is myphp's own, called
    MediaPlayer as players commonly are (MoviePlayer until v1.137).

## A part of the window in another theme (v1.121)

- **Building it**
  - mygo: `ui.Themed(c, t).Children(func() {…})`; `SetTheme` called inside
    still themes the window; the column's text takes `t.Font` too.
  - myphp: `$c->themed($t, $pad, $gap, $grow)` … `$c->end()`; the window's
    theme is the app's (`App::setTheme`), not set while building; myphp's
    themes have no font family of their own.
  - Why: myphp's containers open and end rather than take a closure;
    its themes are set outside the view.

## Decimal input (v1.124)

- **What it reads and when**
  - mygo: `DecimalInput` reads the typed text after the input
    (`afterInput`), trims Unicode spaces, panics on bounds or a step that
    are not decimals; its value's arithmetic is math/big's.
  - myphp: `decimalInput` reads it as it builds, as `numberInput` does,
    marking the error in the same frame; trims ASCII spaces; throws
    `InvalidArgumentException` with mygo's message; adds and compares
    signed digit strings itself (no bcmath or gmp to count on).
  - Why: myphp's inputs apply what is typed as they build; PHP has no
    big numbers of its own.

## The code editor's hover as VS Code's (v1.127)

- **Showing it**
  - mygo: the pointer rests 600 ms before the hover is asked for; the
    hover shows at once.
  - myphp: 300 ms (`Hover::DELAY`, VS Code's editor.hover.delay); it
    fades in over 100 ms (`Hover::FADE_IN`), unless the desktop asks for
    less motion; another word's hover while one shows does not fade
    again.
  - Why: the developer asked for VS Code's.

## PHP in the code editor (v1.128)

- **Its tokens**
  - mygo: PHP is its lexer of C-like languages: `#` always a comment,
    heredocs code, `->`'s names variables.
  - myphp: `PhpLexer` adds heredocs and nowdocs, attributes' `#[`, tags
    and properties after `->`; `enum`, `exit`, `die` keywords and the
    scalar types types.
  - Why: the developer asked for PHP done as a PHP editor does it.

## Interactive glass lit by the pointer (v1.129)

- **Hover**
  - mygo: interactive glass grows while pressed, and nothing more.
  - myphp: it also lights up while the pointer is over it, within 150
    ms: a veil of white at 10% over it in a dark theme, of black at 5%
    in a light one; at once with Reduce Motion.
  - Why: the developer asked for it, as the buttons around show they can
    be clicked.

## The gallery against mygo's (v1.132)

- **A switch's label**
  - mygo: `Switch` has none; `Label` names it for assistive technology.
  - myphp: `switch($label, …)` shows its label after the track; `''`
    shows none, the track alone, and `label()` names it.
  - Why: myphp's own since before; the gallery now passes `''`, as mygo's.

## Upstream's October 31 and November 1 (v1.134)

- **The video example**
  - mygo: `examples/video`, a GIF looping behind the window's content.
  - myphp: `examples/wavelength`, the same, its GIF decoded by ImageIO
    (`GifDecoder`, macOS only, as its test runs).
  - Why: `examples/video` is myphp's film player.
- **Placeholder colors and alphas**
  - mygo: `PlaceholderColor(Color)`, a transparent one hiding it.
  - myphp: `placeholderColor(int $rgb, float $alpha = 1.0)`, an alpha of
    0 hiding it.
  - Why: myphp's colors are 0xRRGGBB with an alpha apart.

## The loading plugin (v1.138)

- **Options**
  - mygo: `loading.Options{Size, Color ui.Color, Duration time.Duration,
    Paused, Easing, Cap, Sweep, Direction, Origin}`, at most one per call
    (two panic).
  - myphp: `new Options(size:, color: ?int, duration: float, paused:,
    easing:, cap:, sweep:, direction:, origin:)`, a nullable parameter:
    the color 0xRRGGBB, null for the theme's text; the duration in
    seconds; the choices enums (`Easing`, `Cap`, `Sweep`,
    `RippleDirection`, `WaveOrigin`) of mygo's constants' names.
  - Why: PHP's named arguments make one Options; myphp's colors are
    0xRRGGBB and its times seconds.
- **Names**
  - mygo: `loading.Arc` … `loading.Wave`, `loading.Loading`, `All()`
    of `Spinner{Name, New}`.
  - myphp: `Loading::arc` … `Loading::wave`, `Loading::loading`,
    `Loading::all()` of `Spinner($name, $new)`.
  - Why: myphp's lowerCamel of mygo's names.
- **The tests**
  - mygo: loading_test.go counts the pixels of rendered frames, and
    TestColor reads the color at the middle.
  - myphp: tests/loading_test.php compares the quads and transforms
    painted, places each quad's outline through its transform against
    the box, and takes the colors of the quads.
  - Why: Headless has no rasterizer.

## The platform layer (v1.147)

- **The backend's handler**
  - mygo: `backend().Run(handler)` hands the backend its handler as it
    runs.
  - myphp: the handler is given when the backend is made
    (`new Darwin\Backend(new AppHandler)`).
  - Why: Secrets and synchronous dialogs turn the run loop before
    `App::run`, and their callbacks reach the handler.
- **Timers and waiting**
  - mygo: goroutines and channels; no timer or step in the interface.
  - myphp: `Backend::timer()` (`App::after`) and `Backend::step()`
    (`App::wait` turns the run loop until a result comes).
  - Why: PHP has one thread; a block-delivered result is waited for on
    the run loop.
- **Dialogs**
  - mygo: dialogs answer through callbacks.
  - myphp: `Platform\Dialogs` returns the answer.
  - Why: myphp's Dialog API was synchronous already (`App::wait`).
- **Menu snapshots**
  - mygo: role, label, accelerator, checked, enabled, submenu.
  - myphp: also `alternate` (an item shown with Option), `HEADER`
    items, `opens` (menuWillOpen calls back) and `replaceSubmenu`.
  - Why: the player's menus need them, as IINA's.
- **Windows**
  - mygo: `platform.Window` as the desktop needs it.
  - myphp: also the player's: video hosts and materials, the layer
    under the materials, legacy full screen, popover flyouts.
  - Why: the video player and the plugins' views draw in them.
- **Pages' handlers**
  - mygo: a `PageHandler` interface the page passes.
  - myphp: public closure properties of `Platform\Page`
    (`onMessage`, `onNavigate`, ...).
  - Why: the WebKit bridge had them already; one closure each is what
    PHP's FFI callbacks hold.
- **Fonts**
  - mygo: the text engine shapes, rasterizes, places pens and baselines.
  - myphp: `Platform\Fonts` rasterizes, places and shapes a line;
    `Fonts::engine()` is the `Text\Engine` laying out paragraphs.
  - Why: myphp's line cache shapes single lines with Core Text apart
    from the paragraph engine.

## The Linux backend (v1.147)

- **Keys and modifiers**
  - mygo: the surface sends platform keys (KeyLeft, KeyEnter…) and
    modifiers (ModCtrl, ModAlt, ModSuper, ModShift), the shortcut
    modifier being Control off macOS.
  - myphp: the core's input speaks macOS's key codes, modifier flags and
    AppKit's selectors, so Linux reports Control as Command (the shortcut
    modifier), Alt as Option, Super as Control, and binds the editing keys
    to AppKit's selectors.
  - Why: myphp's input was macOS's alone; mapping at the edge keeps the
    core's shortcuts and text system as they are. A shortcut of native UI
    written for a menu (`Runtime::accelerator`) is so Command as Ctrl off
    macOS, Control as Super.
- **The Edit menu's keys**
  - mygo: the application menu's items, in a menu bar of each window; the
    shortcuts of every item with a role reach the content first.
  - myphp: the content takes Control and X, C, V, A, Z (Y) as editing
    commands itself, menu bar or not, and only the editing roles'
    shortcuts reach it before the menu bar's.
  - Why: myphp's content tells GTK it took every key, so Quit's Control+Q
    and the like would never reach their items.
- **Menu items macOS alone has**
  - mygo: no alternate items; a header item is an ordinary item.
  - myphp: alternate items (myphp's, shown while Option is held) are left
    out, and a header is an item that cannot be chosen.
  - Why: GTK has neither.
- **A page's failed load**
  - mygo: WebKitGTK's error page shows, and the page tells a navigation
    and a finished load of it after the failure.
  - myphp: no error page: didFailLoad alone, as on macOS.
  - Why: the core's events stay what macOS's WKWebView tells.
- **Picture in picture on Linux**
  - mygo: none, WebKitGTK having none.
  - myphp: the same; its calls throw `Platform\Unsupported`, the option
    warns.
  - Why: as mygo.
- **The stores' calls wait**
  - mygo: AppController's NewDataStore, ClearBrowsingData, ListCookies,
    SetCookie, DeleteCookie, ClearCookies and CompileContentRules take a
    done function; ListCookies takes a URL whose cookies it lists.
  - myphp: they return once the engine is done (App::wait), as the core's
    calls did before; listCookies lists them all and the core filters by
    URL (Cookies::matchesURL), as it did.
  - Why: the core's API waits anyway; the move kept macOS's code as it was.
- **JavaScript panels on Linux**
  - mygo: WebKitGTK shows alert(), confirm() and prompt() its own way,
    inside the page, and its own file chooser.
  - myphp: the app's GTK dialogs, through the page's onPanel, as macOS's
    sheets; a file input chooses no folder (WebKitGTK tells none).
  - Why: the page's panels stay the core's on every platform.
- **A renderer a window**
  - mygo: a GL renderer a surface too.
  - myphp: the same, the atlases' dirty rows shared out among them
    (`Gl::$pending`), as myphp's atlases tell one range.
  - Why: myphp's atlases were Metal's, of one device.
- **Orca and the formats of text**
  - mygo: a text's runs (AccessTextRun) are its ATK run attributes, which
    Orca reads as bold, italic, underlined.
  - myphp: the whole text is one run of the default attributes.
  - Why: myphp's tree (AccessNode) tells no runs yet; the rest of the
    port (`Platform\Linux\Access`) is upstream's.
- **The app's desktop entry, without one it was launched from**
  - mygo: named after the executable (`desktopEntryID`), which the portal
    is told and global shortcuts' session is named after.
  - myphp: named after the app (`App::name()`, its program name), in
    `Portal::desktopEntryId`.
  - Why: the executable is php.

## Linux packages (v1.151)

- **What a Linux app is made of**
  - mygo: one executable, `<slug>`, with the resources, the desktop
    entry, the icon and the MIME package next to it; what mygo.json says
    of the app is linked into it (`-X` ldflags).
  - myphp: the launcher `<slug>` (a C program that execs php, or
    phpmicro with the app as a phar), `app/` (the app's PHP and the
    framework's src), `frontend/`, and `info.json`, which holds what the
    app's Info.plist would, by the same keys (`Build\Linux::info`,
    `Native\Bundle::plist`); `app`, `frontend` and `info.json` are
    reserved names among the resources.
  - Why: a PHP app is its sources and an interpreter; the runtime reads
    one dictionary of the app on both systems.
- **Linux targets need a Linux machine**
  - mygo: any platform builds from any machine (no cgo).
  - myphp: linux/amd64 and linux/arm64 build on Linux alone: the exec
    launcher runs the php that built the app, and is compiled with cc,
    or `<triple>-gcc` for the other architecture; `-launcher micro`
    builds for this machine's system and architecture, as on macOS.
  - Why: the launcher is compiled C and names a php of this machine.
- **The packages' dependencies are worked out**
  - mygo: GTK, then WebKitGTK unless `linux.webview` is false, then
    `linux.depends`.
  - myphp: GTK, libsecret (App's secrets), WebKitGTK when `linux.webview`
    says so or, unset, when the app has a frontend or its PHP makes a
    page (`html:`, `url:`, `PageOptions`, a web view), the app indicator
    when its PHP makes a tray icon, `php<version>-cli`, `-intl`,
    `-mbstring`, `-xml` and `-curl` of the php the exec launcher runs (none
    for `-launcher micro`), then `linux.depends`; the PKGBUILD makes
    Debian's PHP packages Arch Linux's `php`.
  - Why: mygo links what it uses into its executable; myphp's app needs
    an interpreter and loads every library at run time over FFI, which
    the packages must declare.
- **No install.sh, no signed update on Linux**
  - mygo: every Linux build gets `install.sh` (an install in
    `~/.local/<slug>.app` that updates itself), and with updates the
    archive is signed and a manifest written.
  - myphp: neither: the archive is written unsigned, and a build with
    updates warns that Linux apps do not update themselves yet.
  - Why: myphp's updater replaces a macOS bundle alone so far.
- **The Linux icons are decoded in PHP**
  - mygo: Go's image/png decodes any PNG.
  - myphp: `Build\Png` reads PNGs of every color type and depth that are
    not interlaced; an interlaced icon stops the build, saying so.
  - Why: no ImageIO on Linux, and Adam7 is rare in app icons.
- **A packaged app's program name**
  - mygo: GTK names the program after the executable, `<slug>`.
  - myphp: a packaged app sets its program name (`g_set_prgname`) and its
    desktop entry's id (`Portal::desktopEntryId`) to its launcher's,
    `<slug>`, so the window, the themed icon and the portal match the
    installed entry; an app run with php keeps its name (the entry above).
  - Why: the process is php's; the launcher is the executable mygo's
    would be.
## The media player on Linux (Linux, step 4)

- **Linux's sound output**
  - mygo: PulseAudio's simple API (else ALSA), written from a goroutine
    that waits in `pa_simple_write` while the device holds enough.
  - myphp: PulseAudio's asynchronous API on a threaded main loop of its
    own, no callback, polled under its lock (`Native\Pcm`), written from a
    10 ms timer only what `pa_stream_writable_size` takes; ALSA opened
    non-blocking. The app's sound (`Audio\Output`) and the player's go
    through it.
  - Why: PHP has one thread, so a wait in a write is the UI's; the simple
    API tells no room, and its writes waited 80 ms a tick.
- **The player on Linux** (myphp's own: mygo has no player)
  - VA-API decodes into surfaces copied to memory (av_hwframe_transfer_data)
    and converted by libswscale, not drawn zero-copy (DRM PRIME into EGL
    images): GTK 3's GL area is GLX on X11, and the EGL path, Wayland's
    alone, is not written; no GPU here tested either.
  - The video filters' effects and the video equalizer work on the frames
    as they are decoded (libavfilter's eq, hue, unsharp, negate, lut3d,
    delogo, mpv's own), not on the shown layer as Core Image's do: frames
    decoded before a change keep the old look (a third of a second), the
    picture shown while paused filtered again at once. unsharp's size is
    the decoded picture's pixels, as mpv's, not the layer's points.
  - Deinterlacing is bwdif, a frame a frame (macOS: Native\Deinterlacer).
  - The film's media controls (MPRIS) offer play, pause, play/pause, stop,
    next, previous and the position; MPRIS has no skips, rates or loop,
    which macOS's Now Playing has.
  - The app's own sound (Audio\Output) plays on the default device: it
    takes none, as mygo's does not.
  - FFmpeg 7 (libavcodec 61) is taken where it is, else 6 (60); another
    major version does not load. 7 was tested with a minimal build of 7.1
    here, not a distribution's.

## The system's symbols on Linux (Linux, step 4)

- **Image::symbol on Linux** (myphp's: mygo draws no system icons)
  - myphp: the SF Symbol names the core draws (the video player's, the log
    viewer's) map to the GTK icon theme's symbolic icons, the freedesktop
    Icon Naming Spec's names in Adwaita's set (`Linux\Symbols::NAMES`: a
    name, and a second where the first may be missing), recolored to the
    color asked; SF Symbols' weight is not drawn (GTK has none); an icon
    is a square of the size in pixels, where an SF Symbol is as wide as
    its glyph.
  - A name of no freedesktop meaning, or whose icons the theme lacks,
    draws as a transparent square of its size, told once in the log
    (warn, "linux"): a gap that shows, not another icon. Not mapped:
    `playpause`, `playpause.circle`, `chevron.forward.2`,
    `chevron.up.chevron.down`, `chevron.left.forwardslash.chevron.right`,
    `pip.enter`, `pip.exit`, `pip.swap`, `doc.viewfinder`,
    `doc.text.viewfinder`, `document.viewfinder`, `text.viewfinder`,
    `viewfinder.circle.fill`, `cpu`, `crop`, `aspectratio`,
    `slider.vertical.3`,
    `slider.horizontal.below.square.and.square.filled`,
    `sun.lefthalf.filled`, `moonphase.first.quarter`, `pencil.slash`,
    `paintbrush.pointed.fill`, `shadow`,
    `textformat.characters.arrow.left.and.right`,
    `character.textbox.badge.sparkles`, `sparkles`, `book.and.wrench`,
    `macwindow`, `macwindow.on.rectangle`, `macwindow.and.cursorarrow`,
    `macwindow.and.pointer.arrow`, `menubar.rectangle`,
    `menubar.dock.rectangle`, `filemenu.and.selection`,
    `cursorarrow.click`, `pointer.arrow.click`, `app.badge`,
    `app.badge.checkmark.fill`, `bolt.badge.a`, `bolt.badge.automatic`,
    `circle.fill`, `rectangle.fill`, `square.stack.3d.up`,
    `square.2.layers.3d.top.filled`, `square.dashed`,
    `square.dashed.inset.filled`, `square.dotted`,
    `circle.dashed.inset.fill`, `circle.dashed.inset.filled`,
    `inset.filled.circle.dashed`, `inset.filled.bottomthird.rectangle`,
    `inset.filled.topleft.rectangle`,
    `inset.filled.topthird.middlethird.bottomthird.rectangle`,
    `rectangle.bottomthird.inset.fill`,
    `rectangle.bottomthird.inset.filled`,
    `rectangle.connected.to.line.below`, `capsule.on.rectangle`,
    `capsule.on.rectangle.liquid.glass`, `liquid.glass`, `geometry.size`,
    `text.below.photo`, `list.and.film`, `list.clipboard`,
    `point.3.filled.connected.trianglepath.dotted`,
    `arrow.up.left.circle`, `arrow.up.left.circle.fill`,
    `arrow.up.and.down`, `arrow.up.and.down.and.arrow.left.and.right`,
    `arrow.right.and.line.vertical.and.arrow.left`,
    `arrow.up.left.bottomright.rectangle`,
    `arrow.up.left.and.down.right.and.arrow.up.right.and.down.left`.
  - Why: Linux has no SF Symbols; the theme's icons are the system's, as
    SF Symbols are on macOS; an icon of another meaning in their place
    would say something else.
- **Packages of an app that draws symbols**
  - myphp: `Build\Linux::usesSymbols` (Image::symbol, or the player's and
    the log viewer's views in the app's PHP) adds `adwaita-icon-theme` and
    `librsvg2-common` to the .deb's Depends (`adwaita-icon-theme` and
    `librsvg` to the PKGBUILD's): the symbolic icons are SVGs, which
    GdkPixbuf reads only with librsvg's loader.

## The system's symbols on Android

- **Image::symbol on Android** (myphp's: mygo draws no system icons)
  - myphp: Android has no icons to ask for by name, so the SF Symbol names
    (`Label(systemImage:)`, `Tab(systemImage:)`, `Image(systemName:)`, the
    core's) draw as Google's Material Symbols, Android's own icons:
    `Android\MaterialSymbols` holds the outlined icons, 24 at weight 400,
    filled where the SF name is, as paths (`NAMES` maps the SF names,
    those of `Linux\Symbols::NAMES` and more, `ICONS` the paths), which
    `Android\Symbols` draws with myphp's SVG renderer in the color asked.
    SF Symbols' weight is not drawn; an icon is a square of the size.
  - A name of no mapping draws as a transparent square of its size, told
    once in the log (warn, "android"), as on Linux.
  - Their license is Apache 2.0 (`src/Platform/Android/MaterialSymbols.LICENSE`):
    they are Google's, from github.com/google/material-design-icons.
  - Why: Android's design is Material; an SF Symbol would not be Android's,
    and a gap would leave the tabs and sidebars of the declarative views
    without their icons.

## System materials off macOS (Linux, step 4)

- **Node::systemMaterial where the system has none**
  - mygo: the node opens a hole the system's material shows through
    (macOS's NSGlassEffectView and NSVisualEffectView); elsewhere nothing
    is under it.
  - myphp: where the window's system has no materials
    (`Platform\Window::hasSystemMaterials`: Linux), myphp's own stand in,
    told once a window in the log (debug, "ui"): glass is
    `Plugins\Glass\Glass`, clear-glass its Clear style, the vibrancy
    materials `Plugins\Glass\Vibrancy` (a Blur of 20 points veiled light
    or dark as the theme, or $dark, says; HUD and full screen UI always
    dark). They blur what the window painted under them, a video's picture
    as well; macOS keeps the hole and the system's views.
  - Why: a hole with nothing under it shows black; the player's bars and
    the log viewer's buttons stand on the system's glass.

## Android platform (v1.148)

- **Android platform backend**
  - mygo: has macOS, Linux, and Windows desktop backends; no mobile or
    touch platform support.
  - myphp: adds Android as a first-class GPU-rendered platform backend
    (`src/Platform/Android/`, target `aarch64-linux-android`, minSdk 31).
    A thin Java wrapper (`MyphpActivity` with `SurfaceView`) hosts a C
    launcher (`libmyphp.so`) running embedded `libphp.so` (cross-compiled via
    NDK with `ext-ffi`, `opcache`, `mbstring`, `zlib`). All rendering and
    event handling will happen on a dedicated PHP worker thread driven by
    `ALooper` and `AChoreographer`.
  - Why: mobile support extends myphp's immediate-mode GPU UI to portable
    devices while maintaining the same `Platform\Backend` interface contract.

## Network (v1.149)

- **NetworkStatus reports interface type**
  - mygo: `NetworkStatus` reports `Online` and `Metered` booleans.
  - myphp: `NetworkStatus` adds `public readonly string $type = 'other'`
    ('wifi', 'cellular', 'wired', 'other', 'none') and convenience methods
    `isWifi()`, `isCellular()`, `isWired()`. `Network::onChanged` fires
    whenever the interface type changes (e.g. Wi-Fi to 4G/cellular) even
    if both remain online.
  - Why: modern mobile and desktop clients need Flutter-like connectivity
    awareness to refresh connections and show network state immediately
    when switching between Wi-Fi and mobile hotspots.

## Tray (v1.149)

- **Tray supports system vector icons and dual-line titles**
  - mygo: `Tray` supports PNG images and single-line text titles.
  - myphp: `Tray` adds `setSystemIcon(string $name)` (Apple SF Symbols on
    macOS, e.g. 'dot.radiowaves.left.and.right') and `setDualLineTitle(string $top, string $bottom)`
    for compact dual-line status monitoring (macOS NSAttributedString with
    exact vertical center alignment).
  - Why: modern system-level monitoring utilities (network stats, thermal,
    battery) on macOS require crisp resolution-independent SF Symbols and
    compact two-line metrics that match Apple's native status bar baseline.


## The phone (v1.173)

mygo has no mobile platform, so none of this is mygo's; it is myphp's own,
kept together in `src/UI/ContextMobile.php`, `Window::back` and the two
mobile backends. The sizes are UIKit's, the one design on every platform.

- **The safe area and the keyboard**: `Platform\Window::safeAreaInsets()`
  (top, right, bottom, left; zeros on the desktop) and `keyboardHeight()`
  reach the view as `Context::$safeArea` and `$keyboard`. `safeArea($fn)`
  pads a column by them, the keyboard taking the bottom's place while it
  is up. On Android the keyboard is apart from the bars
  (`WindowInsets.Type.ime()`); on iOS it follows
  `UIKeyboardWillChangeFrameNotification`.
- **Input methods on iOS**: the Metal view is a `UITextInput` whose
  document is the marked text alone (as Android's `BaseInputConnection`):
  what a Chinese or Japanese keyboard composes shows in the field,
  underlined (`TextState::setMarked`), a backspace edits the composition,
  and the candidate chosen commits as typed text. Its caret geometry is
  the field's caret, which the window tells each frame, so a hardware
  keyboard's candidates show by it.
- **`tabView($items, &$index, $content)`**: SwiftUI's TabView with the
  sidebarAdaptable style — the tabs where Apple puts them, by the screen
  being touched and the window's size (`tabsPlacement()`): a `tabBar`
  across the bottom on a phone (a touch screen under 600 by 500 points,
  either way round), a `segmented` of the labels across the top on a
  tablet upright (iPadOS's), a `sidebar` of the items on a tablet on its
  side and on the desktop. The content builds its own `navigationBar` and
  `safeArea`; with the tabs across the top, the top inset is theirs.
- **`Node::preferredFont($style)`, `Font::preferred()`, `Theme::textScale()`**
  (v1.175): UIKit's text styles (`Font::LARGE_TITLE` … `CAPTION2`) at
  the HIG's size and weight, iOS's table on a touch screen
  (`Runtime::$touch`), macOS's on the desktop, both times the theme's
  text size over the default 13 points (`textScale()`), so larger text
  scales them. mygo sizes text in points alone; this is for apps written
  against Apple's names (`docs/swiftui.md`).
- **`Haptics`, `Platform\Shell::haptic($kind)`** (v1.175): UIKit's
  feedback generators for apps — `impact('light'|'medium'|'heavy'|'soft'|
  'rigid')`, `selection()`, `success()`, `warning()`, `error()` — played by
  the iOS launcher's `myphp_ios_haptic_feedback` (codes in
  `iOS\Shell::CODES`), Android's `performHaptic(int)` with
  `View.HapticFeedbackConstants` (`Android\Shell::CONSTANTS`, honoring the
  user's haptics setting), the Mac's `NSHapticFeedbackManager`
  (`Darwin\Shell::PATTERNS`), and nothing on Linux. mygo has none.
- **The phone's settings** (v1.175): iOS's `Platform\iOS\Theme::preferences()`
  reads Dynamic Type (`myphp_ios_text_scale`, the body style's size over
  17) and Reduce Motion, and the launcher raises the theme-changed poll on
  `UIContentSizeCategoryDidChangeNotification` and
  `UIAccessibilityReduceMotionStatusDidChangeNotification`; Android's
  configuration event carries `font_scale` and `reduce_motion` (the
  animator scale at 0), kept by `Platform\Android\Theme::setConfiguration`.
  Both now reach `App::systemChanged()` through the app handler
  (`themeChanged`), as the desktop's do, so `Theme::follow` applies —
  before, iOS's change reached no one and Android fixed the theme with
  `App::setTheme`, which an app could not undo.
- **`section($header, $rows, $footer)`, `cell($title, $detail, $disclosure)`**
  (v1.175): UITableView's insetGrouped style and a value1 cell — the
  group of the theme's surface rounded `$groupRadius` (iOS 26's 26 on a
  phone, 10 on the Mac), inset 16, hairline separators from 16 in (16
  short of the end on a phone) drawn over the group under each row but
  the last (UIKit's separatorInset); the header as written, muted, in the
  body style on a phone (iOS 26's) and the footer 13 points, muted, 16
  in; a cell `$rowHeight` high (52 on a phone, iOS 26's), padded 16, title and detail
  17, the chevron `disclosure()`'s. SwiftUI's Section and LabeledContent.
- **The platform's text size** (v1.176): mygo's `defaultFontSize()` (13
  on macOS, 14 elsewhere) is `Theme::defaultFontSize()`, 17 on iOS and
  Android (the HIG's body, the one design on both), applied by
  `App::systemTheme()` rather than by `Theme::light()`, so a headless test
  measures 13 on every platform; `Theme::$baseFontSize` keeps what the
  system theme started from, which `textScale()` measures `fontSize`
  against. Before, every platform started from 13.
- **A tap plays no haptic** (v1.176): the phone backends no longer play
  one on every press (UIKit's and Material's buttons do not), and
  Android's `performHapticFeedback()` — a vibration ignoring the user's
  setting, its errors swallowed — is gone; an app asks with `Haptics`.
- **`swipeActions(&$action, $content, ...$actions)`** (v1.176): UITableView's
  trailing swipe actions, on mygo's `draggable` along the horizontal axis
  (bounds 48 past the buttons, a snap to closed, open or the full swipe):
  buttons 74 points at least or the label plus 32, 17-point labels, the
  gray `textMuted` and the red `danger`; the row's content is the press
  target, so its `clicked()` is the row's, and the tap that closes an open
  row is consumed by the widget. The buttons are built only while the row
  is open, in a strip clipped to the room the content left.
- **Directional lock on a touch screen** (v1.176): a press on a node
  dragged along one axis alone (`Runtime::pressDragAxes`) decides by its
  first 8 points — along the axis the drag takes the finger, across it
  the container scrolls (`cancelTouchPress`), until then nothing moves —
  as UIScrollView's directional lock; the desktop's pointer has no scroll
  to compete with. In both phone Windows' touch-moved handlers.
- **The phone's theme** (v1.176): `Theme::phone($dark)` — UIKit's
  system colors, 17-point text, radius 12, and the control sizes
  `$controlHeight` (44: buttons, fields, selects; a phone's button a
  capsule), `$switchWidth` × `$switchHeight` (iOS 26's 63 × 28, its knob a capsule), `$segmentedHeight`
  (32), and `$groupBackground` / `$groupSurface` — which
  `App::systemTheme()` takes on iOS and Android. The fields are 0 and
  null in mygo's light and dark themes, which the controls read as mygo's
  measured sizes, so the desktop is unchanged.
- **iOS 26's bars** (v1.176): `tabBar` a capsule of `systemMaterial('glass')`
  56 high floating 16 in and 8 over the safe area's bottom (its band
  `tabBarInset()`), the chosen item on a capsule of its own;
  `navigationBar` clear, the way back a 44-point circle of glass with the
  chevron alone, the trailing items in a capsule of glass, and with a
  `ScrollState` its title fading in as `largeTitle()` (34 bold, 52 high)
  goes under it.
- **iOS's system materials** (v1.176): `Node::systemMaterial()` is
  UIKit's on iOS — `glass` and `clear-glass` UIGlassEffect's styles on
  iOS 26 (looked up at run time), the chrome and ultra-thin blurs before
  it; the other names a UIBlurEffectStyle (`Platform\iOS\Window::MATERIALS`).
  Unlike macOS, where the materials are under the window's view and show
  through its holes, they go over the Metal view, so they blur the page
  it shows (what the bars float over): `Runtime::$overLayer` makes the
  painter leave a material's node, and what it holds, to `$overPaints`,
  painted in the launcher's clear view over the materials, which takes no
  touches; the node leaves no hole. `belowMaterials()` needs no layer of
  its own there (`$underLayer` is false). Android has no system material
  for a GL surface and keeps `Plugins\Glass`.
- **The interactive pop gesture** (v1.176): on a touch screen a top-level
  `Router` with a page to go back to builds a 20-point edge draggable
  along the horizontal axis over its page (`Router::popGesture`); the page
  follows it over the page before (built inert, as the slide shows it),
  let go past a third it goes back with the animations of that frame
  disabled, the gesture having been the transition.
- **Pull to refresh** (v1.176): `ScrollState::$pull`, `$overscrolls`,
  `$released`; `Runtime::scrollAt` adds a touch screen's drag past the top
  of an overscrolling ScrollState to `$pull` instead of scrolling, and the
  phone Windows push `['scrollEnd', x, y]` as a finger that scrolled lifts;
  `refreshable($state, &$refreshing, $fn)` shows the pull rubber-banded
  (`Motion::rubberband`), a spinner in the room, refreshing past 60.
- **The log's six levels, and on phones** (v1.176): `Logger`'s levels are
  `VERBOSE`, `DEBUG`, `INFO`, `WARNING`, `ERROR`, `FAULT` (`verbose()` …
  `fault()`): IINA's four with info and fault, as the system logs have them, written in a line
  as `[subsystem][W]`; IINA's logLevel setting maps through `Logger::IINA`.
  On iOS and Android a line goes to the system log at its level
  (`myphp_ios_log`: os_log's debug, info, default, error, fault;
  `myphp_android_log`: logcat's V to F) rather than to the standard error,
  which goes nowhere there. `myphp run` prints a device's log with its
  level as one of those letters (E, F, W, I, D, V), colored.
- **View files and hot reload** (v1.176): `Content::file($path, $state)`
  is a window's view from a file that returns it, run bound to `$state`
  (its scope too; a `stdClass` without one, as PHP binds no closure to an
  internal class's scope). In development it loads the file again when the
  file `MYPHP_HOT_RELOAD` names (or `.myphp-reload` beside the entry) lists
  it, checked three times a second — what `myphp dev` and `myphp run` write
  on a save or `r` — refusing a file that declares a named class or
  function (`ViewFile::declared`), keeping the view it had when the file
  fails, and telling the other files changed. mygo's Go has no such
  thing; its `dev` rebuilds. The examples keep their views in `view.php`.
- **Images on Android** (v1.177): `Image::fromBytes()` decodes with
  AImageDecoder (`Native\ImageDecoder`, the launcher's `image_decoder.c`
  on a thread of its own: PNG, JPEG, WebP, HEIF, GIF's first frame,
  upright by EXIF, scaled by the decoder to fit `$max`), as Core Image on
  the Apple systems; a fiber waits for it.
- **A Router's table of pages** (v1.177): `Router::view()` and
  `Route::view()` take, besides mygo's function of the Route, an array of
  patterns and pages (`Router::pages`): the first pattern matching shows
  its page, `'*'` any other path, none a "No page for" text and a warning;
  a page a closure or a view file (`ViewFile`, bound to `Context::$state`,
  which `Content::file` sets, and hot reloaded), given the Context, the
  pattern's parts by name and type and the Route by its type
  (`Route::params()` is new). `ViewFile` is what `Content::file` loads
  with.
- **`myphp run`'s and `myphp dev`'s keys** (v1.176): Flutter's single keys
  without Enter (`r` hot reload, `R` restart, `c`, `q`, `h`), the terminal
  set back on exit and on Ctrl+C (pcntl). `myphp run` on a phone copies
  the changed PHP files into the installed app (Android: a debuggable APK
  and `run-as`; the iOS simulator: the installed bundle), with
  the activity copying its assets once an install (a stamp of the APK's
  lastUpdateTime) rather than at every start, so that what was copied in
  stays until the next install.
- **`navigationBar($title, ?$router, ?$trailing, ?$largeTitle)`**: 44
  points under the safe area's top, which it extends into; the title 17
  semibold centered; margins 16 (the rest under "iOS 26's bars" above).
  `Router::previousTitle()` is new.
- **`sheet(&$open, $fn)`**: a UISheetPresentationController's — rises
  from the bottom (0.35 s), its top corners rounded 10, a 36×5 grabber,
  content padded 16 over the safe area's bottom, at most the window less
  the top inset and 10; the window dimmed 0.4 and inert as under `modal`;
  a tap outside or Escape closes it (`dismissed()`).
- **`actionSheet(&$open, $title, ...$actions)`**: UIAlertController's
  action sheet — a group with corners 13 at 8 from the edges, the title
  13 muted, rows 57 high in 20 points (accent; "!" first marks a
  destructive one, in `danger`), a separate bold Cancel 8 below, 8 over
  the safe area's bottom; returns the action's index, `SHEET_DISMISSED`
  (-2) for Cancel, a tap outside or Escape, -1 otherwise, as
  `alertDialog`.
- **`Window::back()`**: the system's Back (Android's button and gesture,
  `OnBackInvokedDispatcher` on API 33+, `onBackPressed` before), one step
  a press: a field with the keyboard loses the focus (the keyboard goes),
  else an Escape (a dialog, a popover, a menu closes), else a `Key::BACK`
  (the router shown goes back a page: `Router` now takes Back and Forward
  only with a page to go to), else the app ends (`App::quit`; the
  activity finishes as PHP's run loop ends). `Runtime::$back` is called
  for a Back nothing took, as `$escape` is for an Escape.
- **`Dialog::open` on the phone** is the system's picker: SAF's
  `ACTION_OPEN_DOCUMENT` or, with filters naming only pictures and films,
  the photo picker (`ACTION_PICK_IMAGES` on API 33+, PHPicker on iOS);
  `UIDocumentPickerViewController` otherwise. What is chosen is a copy
  under its name in the app's cache (`open/<id>/`), since a content: URI
  or a security-scoped URL is no path and the name matters to an upload.
  `Dialog::save` on Android is `ACTION_CREATE_DOCUMENT`, the path
  `/proc/self/fd/N` written through; on iOS, and `prompt`, `login` and a
  directory anywhere, `Platform\Unsupported` — never an empty answer.
  `Platform\FileTypes` maps the filters.

## The Windows backend (Windows, stage 2)

- **The renderer** (v1.178)
  - mygo: `gpu/d3d11` draws its scene ops (14 float4s an instance, clips
    as scissor rectangles, its own shader.hlsl compiled ahead of time),
    with three swap chain buffers a quarter larger while a window resizes
    and DirectComposition for a composed window.
  - myphp: `Native\D3d` draws myphp's DisplayList as `Native\Gl` does,
    `D3dShader` being `GlShader`'s HLSL line for line, compiled at the
    first renderer's start; one swap chain of two buffers resized with
    the window. App shaders' HLSL is mygo's hlsl backend's
    (`UI\Shader\Hlsl`, its fragment library byte for byte), in the cbuffer
    of b2 and textures t6 to t9 as mygo's, around `D3dShader`'s tail.
  - Why: myphp's renderers share the DisplayList and its shader with the
    OpenGL and Metal ones; mygo's scene ops are not myphp's.
