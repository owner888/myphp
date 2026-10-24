# AGENTS.md — MyPHP

Entry point for coding agents and contributors. `docs/architecture.md` has
the reasoning, the numbers and the pitfalls behind the rules here.

## Rules

1. **This file stays under 200 lines.** Check with `wc -l AGENTS.md` after
   editing. When it nears the limit, first condense what is here; only
   when nothing more can be condensed, move detail to `docs/`.
2. No third-party packages without asking the developer first; small
   things are written here instead. `composer.json` requires only `php` and
   `ext-ffi`, and that is the intent.
3. Git is the developer's: no commit, push, branch, tag or patch export.
   Change files, run checks, report which files changed.
4. Files that go into git never carry the developer's name: write "the
   developer" / 「开发者」 when a reference is needed.
5. GUI examples run only on the developer's Mac (display, Apple
   frameworks): ask them to run and paste output. `php -l` and pure-PHP
   checks run anywhere.
6. Small and literal: no speculative abstractions, no silent fallbacks, no
   magic numbers that make a symptom go away. Unclear? Ask before changing.
7. Delete dead code when you touch it; never comment it out.
8. `bin/myphp` stays executable: after any change, `chmod 755 bin/myphp`
   and check `ls -l` before handing back (file syncs drop the bit).
9. **UI is 1:1 with mygo** (the developer's rule): every size — padding,
   gap, height, icon, stroke, radius, color role — comes from mygo's
   source (`t.Space(n)`, the SVG viewBox), never guessed or approximated.
   Before drawing a control, read its mygo function and copy the numbers.
10. **Public API takes mygo's names** (the developer's rule): lowerCamel of
    the Go name, never one of our own. Anything that is not mygo's yet —
    left out, done differently, named otherwise — goes in
    `docs/mygo-deviations.md` as it is found; take it out once it matches.

## What this is

mygo (`github.com/egoist/mygo`, a pure-Go desktop framework) ported in
spirit to PHP 8.3+: windows with a system webview or native UI we draw on
the GPU, reaching the OS through `ext-ffi` only (`FFI::cdef`/`cast` and PHP
closures play `purego`'s part). No C extension of our own, ever.
Reference checkout: `../../other/mygo` (read its `AGENTS.md`,
`docs/architecture.md`, `ui/runtime.go` first). Port behaviour by reading
mygo's version and keeping its semantics; rename to PHP, do not redesign.

## Layout

```
bin/myphp          CLI: init, dev (restart on change, TS client, --lang), generate, i18n check, build (.app), micro; bin/cldr-tables
src/               App (whenReady/run/quit/theme/menu/path/later/after; onOpenURL/File, single instance, recent
                   documents, open at login), Dock, Shell, Screen, Theme, Window (+ on(event), CloseEvent, FileDropEvent,
                   WindowState(+Store), newWebView), Page (+ PageOptions, on(event), eval, find, PDF, downloads, permissions),
                   WebView (Context::webView: a hole in the Metal view), Event, Network, Clipboard, Secrets, Cookies, WindowOptions,
                   Menu, MenuItem, Dialog + *Options, Tray (+ TrayOptions), GlobalShortcut, Notification (+ Options), Process (a child polled on the run loop), Ipc (bound services,
                   window controls), Channel (+ ChannelClosed: streaming to pages), TypeScript (client), Protocol (+ Request/Response), autoload.php,
                   Locales (the language the app shows, the regional formats: App::locale/setLocale/setLanguages/
                   region/on*Changed), Region, I18n (tags, parents, plurals, dates, numbers, names; ext-intl + CldrTables),
                   Message, Catalog, L10n (MyPHP's own strings, src/l10n: a library catalog "myphp"), Native/I18nGen (accessors)
src/Native/        ObjC (runtime bridge: msg, defineClass, block/noopBlock/callBlock, addr/import), SystemLocale (AppleLanguages,
                   the locale's formats), Carbon (hot keys), Drop (NSDraggingDestination methods for file drops), Metal (MTKView, one quad pipeline,
                   buffer ring, atlas texture), CoreText (shaping, glyph bitmaps, caret x), View (MTKView subclass:
                   events into UI\Input, NSTextInputClient), WebView + bridge.js (window.myphp), Appearance
                   (dark/light, accent), Pasteboard, ImageIO (decode to RGBA), Mem (calloc/free, address memcpy),
                   Curl/Zlib (system libs), Fibers (App::go), Update/ (Updater: Ed25519, tar, bsdiff, Publish), FileWatch/
src/Text/          the text system (mygo's internal/text): System, Layout, Engine (Native\TextEngine, StubEngine); src/Audio/ mygo's audio (Mixer, Stream, Output → Native\AudioQueue, Wav, Vorbis/ in PHP, SystemDecoder)
src/UI/Markdown/   Parser (mygo's ui/markdown/parser) and the views: Markdown::view/stream, ViewState, SpanBuilder, Theme, Reveal
src/Plugins/Terminal/ mygo's plugins/terminal: Terminal (+ Options, Theme, Keybind, View), Vt (libghostty-vt over FFI, mygo's
                   build: Library finds it), Pty (/dev/ptmx; spawn.php in a PHP of its own becomes the program)
src/Plugins/Sqlite/ mygo's plugins/sqlite (Sqlite::open, DB, Blob; plugin(), client/); Fetch/, WebSocket/ (Native\Curl); Watch/ (App::watchFiles); Updater/ (Checker, Session in fibers, page.html; Native/ in native UI); Game/ (Game::view, Camera, Gamepad/); Glass/
src/UI/Svg/        the SVG renderer in PHP (mygo's internal/svg): Elem (XML + style sheets), Style, Compiler → Node/Shape/
                   Gradient/ClipDef/MaskDef, Path, Matrix, Scanner, Polys, Stroker, Rasterizer (mygo's vec), Layer, Renderer,
                   Doc (pixels/rotated), Mask (a UI\Path's mask for Text::pathQuad), Colors
src/UI/            Node, Direction, Inspector* (dev tools), Track, Path, SVG (over Svg\Doc: icon masks, pictures), Canvas (render to texture), Chart/ (scales, plot, marks), Context (column/row/grid/text/muted/richText/link/button/primaryButton/toggle/toolbar/checkbox/radio/switch/slider/
                   select/stepper/segmented/tabs/collapsible/split/table/sidebar(+Section/Item)/tree(+Item)/accordion(+Item)/rating/rangeSlider/combobox/autocomplete/tokenField/dateInput/calendar/timeInput/colorPicker/colorWell/findBar/form/field/fieldset/checkboxGroup/breadcrumbs/alertDialog/gridView/icon/avatar/titleBar/modal/popover/
                   menuButton/toast/progress/meter/numberInput/spinner/badge/divider/spacer/input/searchField/
                   textarea(minLines, maxLines)/image/box/list; clicked/doubleClicked/changed/hovered/focused/shortcut; ->tooltip()/at()/
                   round()/material()/align()/alignSelf()/justify()/center()/cursor()/shadow()/border()/margin()/padding()/borderWidth()/background()/block()/draw()/drawOver()/singleLine()/
                   min()/focusGroup()/wrap()/grid()/columnStart()/rowStart()/columnSpan()/rowSpan()/dragWindow();
                   rows center across,
                   columns stretch, as mygo), Material (+ MaterialBuilder, LinearGradient: Plugins/Glass/), Font (style
                   bits), Span (rich text run), TextStyle (inherited), Layout (flex, margins),
                   DisplayList (quads with clip), Painter (tree -> list; an instance draws as mygo's Painter), Text + Atlas (glyphs R8), ImageAtlas (RGBA pages, a texture array)
                   + TextMetrics, Image (decoded once, atlas slot on first paint), TextState (editing model,
                   undo, readOnly, onPaste/onTextKey hooks), TextLayout (wrapped lines of a text area),
                   Input, Runtime (last frame's rects, clicks/hover/focus, scroll routing, thumb drag, passes),
                   TextMetrics/TextPainter (what layout, then the painter, need of a text engine), Scrollable + ScrollState + ListState (anchor, choice, reorder, scrollTo), Selection (several rows, by key), GridState (a grid view: choice, reorder), Scrollbar, Glide, Theme, Content
examples/          native (inbox over a million rows), form (fields, text area, IME), desktop (menus, dialogs, tray,
                   shortcuts, notifications), webview (Notes service, channels, myphp.ts), logview, notes,
                   photos, http (real tools — a log viewer, Markdown notes, a photo browser, an HTTP client
                   over curl — the API's proving ground), files (a file manager: sidebar, breadcrumbs, grid or table of
                   the folder, one Selection shared by both, find bar, image preview, Trash), glass (Liquid Glass over a
                   photo), widgets (the small controls), titlebar, game (Plugins\Game), piano (Audio)
tests/             headless tests: php tests/run.php [name] — Headless.php (a fake TextPainter of 7×16 cells, events as the
                   view sends them, paint into an FFI buffer, the widgets and files examples without their windows), *_test.php
                   (check()/same() assertions, no PHPUnit; svg_test reads the renderer's pixels); php tests/bench.php [frames] times build/layout/record/paint
run.sh             runs an example with the php -d flags; docs/architecture.md  design notes, measurements, pitfalls
```

## Architecture, in short

- **Immediate mode**, as mygo's `ui`: each frame rebuilds the tree, `Layout`
  measures and arranges, `Painter` emits quads. Frames run on demand
  (`Metal::requestFrame`: `draw` performed from the run loop, never from
  MTKView's dispatch-queue paths); no CVDisplayLink into PHP. Nodes have no parent pointers (acyclic: no GC).
- **Last frame answers this frame's questions**: `Runtime` keeps rects by
  node id (creation order, or xxh64 of the key path under `Context::key`;
  list rows are keyed by index); `clicked()/hovered()/focused()` hit-test
  them while building; a click that changed state re-runs the build (≤3 passes).
  `richText(..., wrap: true)` wraps at last frame's width (a frame follows when
  it differs); `->focusable()` puts any node in the Tab order; `->size(w, h)`
  fixes a container's size over its content's; `->fit(Node::COVER…)`/`->grayscale()` on images; `->justifyItems()`/`->justifySelf()` in grids; `->reverse()` (mygo's Reverse); `->basis(0)` with grow shares
  the room equally and keeps content from widening the parent (mygo's Basis).
- **Scrolling on either axis**: `->scroll($state, horizontal:, vertical:)`;
  a container takes the room it is given on the axes it scrolls and its
  content's size on the other; wheel dx/dy, thumbs and drags per axis.
- **Lists are virtualized** (`Context::list`, mygo's `List`): `ListState` =
  anchor row + offset; rows for 1.5 views of estimated height are built,
  heights learned from layout. A million rows cost the rows in view.
  `selected` (-1) turns on click/arrow/Home/End choice; `scrollTo` glides
  (`Glide::TAU`), thumbs show on hover and drag; bar geometry in `Scrollbar`;
  `followEnd` keeps a list at its end as rows are appended (chat, log).
- **One GPU buffer per frame**: `DisplayList` writes 16 floats per quad into
  a shared `MTLBuffer` (ring of four); rects, glyphs, images, shadows, rings
  and glass share one pipeline and one instanced draw (kind 0–9), so draw
  order is list order; clip rect per quad. A backdrop quad (glass, blur,
  hard scroll edge) splits the draw: what is below it is copied and blurred
  (half size, two passes) into the backdrop it samples, then the pass resumes.
- **Atlases start over when full** (the glyph atlas; an image page): `Text::$repaint` makes
  `Window` paint the frame again, so nothing points at slots that are gone;
  `App::rescale()` (windowDidChangeBackingProperties) re-rasterizes glyphs
  at the largest backing scale among open windows (`Uniforms.atlasScale`).
- **Text through CoreText** with a glyph atlas; shaped lines cached by size
  and string (`UI\Text`), swept by frame age; fonts keyed by `CFHash`.
- **Keys through `interpretKeyEvents:`**: the input context calls
  `insertText:`/`setMarkedText:`/`doCommandBySelector:` on the view, which
  edits `Input::$focus` (the focused `TextState`) at once and answers the
  NSTextInputClient queries from it (the class declares the protocol).
- **Theme, never literals**: `$c->theme` is light/dark after the system with
  its accent (KVO on `effectiveAppearance`); widgets default to it.
- **Pages talk as in mygo**: `{t:"call", id, k, m, a}` via a
  WKScriptMessageHandler, `Ipc::handle` on the main thread, reply through
  `window.myphp.__deliver`; events `{t:"event", n, p}`; per-window secret.
  A `Channel` parameter streams `{t:"chan", c, k, p|end}` (page: `new
  myphp.Channel()`, `chan-close` back); a Generator return streams each
  yield one run-loop turn apart (`App::later`). `myphp:window.*` calls are
  the page's window controls; `PageOptions::$preloadScript` runs before the page.
  `MYPHP_GENERATE=path` makes `App::run` write the TypeScript client. Only trusted pages call (`Native\Frontend`).
  PHP plugins (`App::use`): service "plugin:<name>", page side its client/ (`bun test`, real
  bridge.js via Plugins/fake-myphp.ts); `CallContext` param = the page's life; `Channel::wait`; generators wait on flow control.
- **FFI discipline**: one FFI instance per bridge (structs are not shared
  across instances; foreign pointers pass `ObjC::import`); `objc_msgSend`
  cast per "ret:args" signature (`ObjC::MAP`), never variadic; callbacks
  kept alive in holder structs; blocks via `ObjC::block`; `App::wait` makes a
  block-delivered result synchronous; PHP is entered on the main thread only.
  `FFI::new/cast/type` only on an instance (static: deprecated since 8.3);
  never `?:` on a string that may be `"0"` (`mb_chr`, `substr`, `basename`).

Measured on an M-series Mac: 9 000 nodes rebuilt, laid out and painted in
~4–6 ms per frame at 60 fps; a million-row list ~1.3 ms, 8 MB flat.

## Projects and bundles

`myphp init` writes myphp.json, boot.php (framework from `./myphp` in a
built app, else the checkout) and app.php; `myphp build` makes `<Name>.app`
with a C launcher that execs this php (development) or, `--launcher micro`,
a phar combined with phpmicro by static-php-cli (release, one static
binary), with the native libraries of the plugins the app names
(mygo-plugin.json) in Resources; `myphp micro` builds micro.sfx once.
static-php-cli is the one outside tool, for release builds only.

## Working on it

- PHP ≥ 8.3 with `ext-ffi`; `./run.sh <example>` and `bin/myphp dev` set
  `ffi.enable=1` and OPcache JIT. `bin/myphp` stays dependency-free PHP.
- Before handing back: `php -l` on every changed file; pure-PHP checks
  where they exist; ask the developer to run GUI examples. php tests/run.php
  runs the headless tests (Layout, Runtime, Context, Painter: no window):
  run them after changing src/UI, and add a check when fixing a bug there;
  php tests/bench.php after a change that could cost per frame, against
  the numbers in docs/architecture.md.
- New ObjC calls: selector and types from Apple's headers, signature letters
  in `ObjC::MAP`, structs made by `ObjC::$c->new`/`ObjC::rect`, variadic
  methods (`arrayWithObjects:`) avoided.
- Record each solved problem in `docs/architecture.md` (one paragraph) so
  the next session does not rediscover it.

## Roadmap

Done: Metal rects/glyphs/clipping; CoreText shaping + atlas; mouse, wheel,
focus (ring on keyboard focus only), shortcuts, single-line fields with
input methods; App/Window, menus, dialogs (sheets), pages with IPC + TS
client; virtualized lists with scroll bars, glides, keys and selection;
text areas (wrapping, undo, caret blink); theme; images (paged atlas);
rich text (bold/italic/mono, spans); layout alignment as mygo; checkbox,
radio, switch, slider, select, stepper, segmented, tabs, collapsible, split, table,
sidebar, tree, accordion, rating, range slider, combobox, autocomplete, token field, date input, calendar, time input, color picker and well, find bar, form, checkbox group, breadcrumbs, alert, grid view, grid layout, icons (SVG), avatars, toolbar, toggle, modal, popover, menu button, toasts, search field, number input, progress, meter, spinner, badge, link, tooltips; soft shadows, rings, Liquid
Glass, Blur, ScrollEdge; tray, global shortcuts, notifications, window
events + state; child processes; channels, page window controls, preload; file drops; CLI + .app; app services; documents; protocol; canvas, charts; right to left; inspector; audio; mygo's element and layout API; widgets and context; app, page, menus, web views in native UI.
Remaining: Linux (GTK4/WebKitGTK) and Windows (Win32/WebView2/D3D11)
bridges; what building real apps turns up (see examples/logview, notes, photos, http).
