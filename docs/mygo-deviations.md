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
  - myphp: since v1.00 `Clipboard` writes, reads, lists and flushes, with
    `ClipboardOptions::$onRelease`; no `Close`.
  - Why: quitting flushes it, which is what `Close` is for in mygo.

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
- **Percentages and fractional grow** (v1.03)
  - mygo: `Left`, `Top`, `Right`, `Bottom` and their `…Percent`s, `WidthPercent`, `HeightPercent` on any element, absolute or relative.
  - myphp: `leftPercent`, `topPercent`, `widthPercent` and `heightPercent` take a node out of the flow (as `at()`), against its parent's content box, and its margins move it; no `Right` or `Bottom`; `grow()` takes fractions, as the `$grow` property now does.
  - Why: what the charts' marks need, ported first.
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
    `MYPHP_UPDATER_PRIVATE_KEY`; `myphp keygen --out` writes
    `myphp-update.key` in `~/Library/Application Support/myphp/update-keys`.
  - Why: a PHP app has no link step; Info.plist is in the signed bundle.
- **What the build publishes** (v0.95)
  - mygo: `mygo build -upload` uploads to a GitHub release or S3
    (`updates.s3`); Linux and Windows archives and `install.sh`; deltas
    patch files of up to 256 MiB.
  - myphp: macOS bundles only; no upload (publish the files yourself), and
    `updates.s3` is refused; deltas patch files of up to 32 MiB.
  - Why: myphp builds macOS apps; PHP's bsdiff takes ~70 bytes of memory a
    byte.
- **App** (v0.95)
  - mygo: `App.Version` from the build, `IsDev` false in production builds,
    `Relaunch` starts the executable it started from.
  - myphp: `App::version()` from Info.plist (myphp.json's version),
    `App::isDev()` true outside a bundle, `App::relaunch()` starts the
    bundle's executable, or the script again with the PHP running it.
  - Why: a PHP app is a project or the bundle `myphp build` made.

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
  - myphp: the window's `willNavigate` event (`Window::on`, a
    `NavigateEvent`), as myphp has no `Page`; `Power::onSuspend`,
    `onResume`, `onLockScreen`, `onUnlockScreen`, without `KeepAwake` and
    `IsOnBattery` yet.
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
    `theme()`, `fill()`, `stroke()`, `shadow()` (not cut out under the
    shape), `line()`, `text()`, `image()`, `imageRegion()` (flipped, not
    tinted: `ImageOptions` has `flipX` and `flipY`), `focusRing()`,
    `clip()` (square corners), `transform()`, `fillPath()`,
    `strokePath()`, `icon()`; colors 0xRRGGBB with an opacity.
    `Node::draw()` still hands a draw the display list and the node; the
    tree painter's own helpers became `paintIcon` and `paintFocusRing`.
  - Why: what a game draws with, first; the display list has no tint,
    dashes nor rounded clips.

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
- **Painter's gradients** (v0.99)
  - mygo: `FillGradient` at any angle, with `Start` and `End`.
  - myphp: `fillGradient()` at 0, 90, 180 and 270 degrees, without
    `start` and `end`; others throw.
  - Why: the display list draws gradients along a quad's axes.

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
  - myphp: the same on `Window` (`findInPage()`, `print()`,
    `printToPDF()`, `setPermissionHandler()`, the events `willDownload` and
    `downloadDone`), as myphp has no `Page`; the default grants trusted
    pages (WindowOptions' `trustedOrigins`, since v1.02); `PageSize::letter()`…
    static methods.
  - Why: myphp's window holds its page.

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
    `CloseEvent`), `App::onActivate`, `App::$quitOnLastWindowClosed`
    turned off; the events `myphp:document-state` and
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
    `devUrl`, `devCommand`, `buildCommand`, `frontendDist`; `myphp dev`
    stops the shell of devCommand, which may leave a server it started
    behind; macOS only, no WebView2 origins. The web template loads "/".
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
    `I18n::rightToLeft(App::locale())`, rows, columns, wrapping rows,
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
    `Spinner`…); the styles myphp's nodes have (a shadow is `0 dy blur
    color`, borders solid); the box model's margin and padding drawn
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
