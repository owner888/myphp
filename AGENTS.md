# AGENTS.md — MyPHP

Entry point for coding agents and contributors. `docs/architecture.md` has the reasoning, the numbers and the pitfalls behind the rules here.

## Rules

1. **This file stays under 200 lines** (`wc -l AGENTS.md`): near the limit, condense what is here first; only then move detail to `docs/`.
2. No third-party packages without asking the developer; small things are written here (`composer.json`: `php`, `ext-ffi`).
3. Git is the developer's: commit/push/tag only when the developer explicitly requests a release; change files, run checks, report which changed.
4. Files in git never carry the developer's name: "the developer" / 「开发者」 when one is needed.
5. GUI examples run only on the developer's Mac (ask for the output): `myphp run -d <platform>` for every platform, as Flutter's; `php -l` and pure-PHP checks run anywhere.
6. Small and literal: no speculative abstractions, no silent fallbacks, no magic numbers that make a symptom go away. Unclear? Ask before changing.
7. Delete dead code when you touch it; never comment it out.
8. `bin/myphp` stays executable: `chmod 755 bin/myphp` after any change, checked with `ls -l` (file syncs drop the bit).
9. **Declarative SwiftUI is the sole public UI paradigm** (the developer's rule): apps, examples, templates and docs use SwiftUI's view names, modifiers and Apple HIG / platform metrics (`VStack`, `HStack`, `TabView`, `NavigationStack`, `ListView`, `SceneView`, `SpriteView`); the imperative `Context` builder is `@internal` engine IR, reached by apps only inside a `Representable`.
10. **PHP 8.4+ / 8.5 native idioms & performance first** (the developer's rule): leverage PHP's reference-counted CoW strings/arrays, `ext-ffi` preallocated `CData` buffers on hot per-frame paths, and single-thread `Fiber` concurrency; avoid per-frame closure allocations or unnecessary array/string packing.
11. **Native is native** (the developer's rule): a system view (glass, materials, controls) is used as the system draws it, its defaults left alone; never force its appearance, tint or style to chase a look.
12. **An arrow function copies what it reads as it is made**: a closure run later that must see a variable change (`CurlLoop::waitUntil`, `App::wait`, a stored `->key`) is `function () use (&$v)`; `$this` and objects are fine. A closure something keeps that holds its keeper is a cycle (a node's `draw` holding the node or the Context, a recursive `$walk`): `static`, the node its third argument, `$walk = null` when done; `tests/memory_test.php` checks.
13. **What comes and goes is keyed** (`->id()` / `Each($items, id: 'id')`): a node's id is its parent's mixed with its place there, so content shown or hidden renumbers the siblings after it. Key conditional or dynamic siblings so scroll, focus and animation state stay attached.
14. **Logs go through `MyPHP\Logger` at their level** (the developer's rule): `verbose` (step by step), `debug` (what it did), `warn` (went on without something), `error` (failed), with a subsystem; never `error_log`/`echo`/`fwrite(STDERR)`. Dev apps log debug up, `myphp build` warn up.
15. **Cross-platform HIG alignment** (the developer's iron rule): **Desktops (`macOS`, `Windows`, `Linux`) unified on macOS HIG** (`13pt` base font, `220pt` sidebar, unified toolbar row with quiet `28×28` borderless `sidebar.left` toggle, dark hairline split divider, neutral translucent selection pill, uniform `ButtonStyle` heights); **Tablets (`iPadOS`, `Android Pad`) unified on iPadOS HIG** (`17pt` UIKit font, `260pt` landscape sidebar with `>=44pt` touch rows & status-bar safeArea clearance, morphing to/from centered floating top glass capsule TabBar, `700pt` centered page sheets); **Phones (`iOS`, `Android`) unified on iOS HIG**.
16. **Nothing a user sees carries upstream's name** (the developer's iron rule): an example's, a template's or a doc sample's
    text, links and URLs are myphp's own (`https://github.com/owner888/myphp`), never mygo's or egoist's; credit stays in README and comments.
17. **Every feature change updates both GitHub and `smart-book` docs** (the developer's rule): whenever adding a feature or modifying existing behavior, check and sync both this repo's `docs/` (`README.md`, `bin/skills/myphp-app/`) and the `app/Views/myphp/docs/` of the `smart-book` checkout (both English and `zh-cn/`); a feature the Chinese docs lack altogether (English has it, `zh-cn/` does not) is added there too, not passed over because this change did not touch it.
18. **This repo is public: nothing of the developer's machine or identity in its files or its history** (the developer's rule): no user name, home path, private address, real node or key; commits are authored with the GitHub noreply address. `smart-book` is private and exempt. A leak already in the history is weighed against the tags and releases a rewrite would move before it is rewritten.

## What this is

A 5-platform native application and 2D/3D game framework for PHP 8.4+ / 8.5 (originally inspired by `github.com/egoist/mygo`, now evolved into a full SwiftUI declarative & 5-platform GPU engine): windows and mobile screens with native UI and 3D scenes drawn on the GPU (Metal, Direct3D 11, OpenGL 3.3, OpenGL ES 3.0) or a system WebView, reaching the OS through `ext-ffi` and direct JNI (`myphp ffigen` / `myphp jnigen`). No custom PHP C extension required.

## Layout

```
bin/myphp          CLI, as Flutter's, the one way to run and build on every platform: init, dev (restart on change, TS client, --lang), generate,
                   ffigen/jnigen (C, Apple ObjC & Android JNI bindings), i18n check, run -d android|ios|macos|linux (packages, installs, starts, follows the log; builds a phone's PHP runtime the
                   first time, scripts/ are its internals, never run by hand), devices, doctor, build (.app/.dmg, .deb, APK, .ipa), micro,
                   screenshot (runs the app once: a PNG of its window, its elements; Xvfb without a display); bin/cldr-tables
src/               App (whenReady/run/quit/theme/menu/path/later/after; onOpenURL/File, single instance, recent
                   documents, open at login), Dock, Shell, Screen, Theme, Window (+ on(event), CloseEvent, FileDropEvent,
                   WindowState(+Store), newWebView), Page (+ PageOptions, on(event), eval, find, PDF, downloads, permissions),
                   WebView (Context::webView: a hole in the Metal view), Event, Network, Clipboard, Secrets, Cookies, WindowOptions,
                   Menu, MenuItem, Dialog + *Options, Tray (+ TrayOptions: SF symbols, dual-line title), GlobalShortcut, Notification (+ Options), Process (a child polled on the run loop), Http (+ HttpResponse: requests, CurlLoop), Ipc (bound services,
                   window controls), Channel (+ ChannelClosed: streaming to pages), TypeScript (client), Protocol (+ Request/Response), autoload.php,
                   Locales (the language the app shows, the regional formats: App::locale/setLocale/setLanguages/
                   region/on*Changed), Region, I18n (tags, parents, plurals, dates, numbers, names; ext-intl + CldrTables),
                   Message, Catalog, L10n (MyPHP's own strings, src/l10n: a library catalog "myphp"), Native/I18nGen (accessors);
                   the core calls the system only through App::backend() (Platform\Backend, by PHP_OS_FAMILY)
src/Platform/      the interfaces of mygo's internal/platform (Backend, Window, Page, WebView, Dialogs, Fonts, Tray, ...);
                   Darwin/ (macOS), Linux/ (GTK 3 + GL), Android/ (EGL/GLES), iOS/ (Metal/UIKit) over src/Native/
src/Native/        ObjC (runtime bridge: msg, defineClass, block/noopBlock/callBlock, addr/import), Jni (Android direct JNI bridge), Build/FfiGen+JniGen, SystemLocale (AppleLanguages,
                   the locale's formats), Carbon (hot keys), Drop (NSDraggingDestination methods for file drops), Metal (MTKView, one quad pipeline,
                   buffer ring, atlas texture), CoreText (shaping, glyph bitmaps, caret x), View (MTKView subclass:
                   events into UI\Input, NSTextInputClient), WebView + bridge.js (window.myphp), Appearance
                   (dark/light, accent), Pasteboard, CoreImage (images off the main thread), ImageIO (icons), Mem (calloc/free, address memcpy),
                   Curl/Zlib (system libs), Fibers (App::go), Update/ (Updater: Ed25519, tar, bsdiff, Publish), FileWatch/, CoreMedia/Renderers/AssetReader/MediaTicker (Media\Player; Linux: Av*, Pcm)
src/Text/          the text system (mygo's internal/text): System, Layout, Engine (Native\TextEngine, StubEngine); src/Audio/ mygo's audio (Mixer, Stream, Output → Native\AudioQueue, Wav, Vorbis/ in PHP, SystemDecoder); src/Media/ video (Player; Matroska, Mp4, Ts, Hls in PHP; Context::video)
src/UI/Markdown/   Parser (mygo's ui/markdown/parser) and the views: Markdown::view/stream, ViewState, SpanBuilder, Theme, Reveal
src/Plugins/Terminal/ mygo's plugins/terminal: Terminal (+ Options, Theme, Keybind, View), Vt (libghostty-vt over FFI, mygo's
                   build: Library finds it), Pty (/dev/ptmx; spawn.php in a PHP of its own becomes the program)
src/Plugins/Sqlite/ mygo's plugins/sqlite (Sqlite::open, DB, Blob; plugin(), client/); Fetch/, WebSocket/ (Native\Curl); Watch/ (App::watchFiles); Updater/ (Checker, Session in fibers, page.html; Native/ in native UI); Game/ (Game::view, Camera, Gamepad/); Scene/ (5-platform 3D engine: SceneView, Mesh OBJ/GLB, GPU Instancing, PCF shadows, PointLight, ParticleEmitter3D, Tween3D); Glass/
src/UI/Svg/        the SVG renderer in PHP (mygo's internal/svg): Elem (XML + style sheets), Style, Compiler → Node/Shape/
                   Gradient/ClipDef/MaskDef, Path, Matrix, Scanner, Polys, Stroker, Rasterizer (mygo's vec), Layer, Renderer,
                   Doc (pixels/rotated), Mask (a UI\Path's mask for Text::pathQuad), Colors
src/UI/Declarative/ SwiftUI declarative views and modifiers (`View`, `VStack`, `HStack`, `ZStack`, `LazyVStack`, `LazyVGrid`, `ListView`, `Table`, `NavigationStack`, `NavigationSplitView`, `TabView`, `Form`, `Section`, `Picker`, `Toggle`, `TextField`, `Slider`, `DatePicker`, `Chart`, `Canvas`, `GeometryReader`, `TimelineView`, `KeyframeAnimator`, `Representable`, `functions.php`)
src/UI/Reactive/   Fine-grained PHP 8.4+ reactivity (`State<T>` with Property Hooks, `Observer` with `PhpToken` AST dependency tracking, `Scope` for retained `Computed` subtrees, zero reference cycles via `WeakMap`/`WeakReference`)
src/UI/            Node, Direction, Inspector* (dev tools), Track, Path, SVG (over Svg\Doc: icon masks, pictures), Canvas (render to texture), Chart/, Editor/ (CodeEditor, DiffView), RichText/, Shader/ (Kage -> MSL/HLSL/GLSL compiler), Context (`@internal` engine IR builder under Declarative views), Material (+ MaterialBuilder, LinearGradient: Plugins/Glass/), Font (style
                   bits), Span (rich text run), TextStyle (inherited), Layout (flex, margins),
                   DisplayList (quads with clip), Painter (tree -> list), Text + Atlas (glyphs R8), ImageAtlas (RGBA pages, a texture array)
                   + TextMetrics, Image (decoded once, atlas slot on first paint), TextState (editing model,
                   undo, readOnly, onPaste/onTextKey hooks), TextLayout (wrapped lines of a text area),
                   Input, Runtime (last frame's rects, clicks/hover/focus, scroll routing, thumb drag, passes),
                   TextMetrics/TextPainter (what layout, then the painter, need of a text engine), Scrollable + ScrollState + ListState (anchor, choice, reorder, scrollTo), Selection (several rows, by key), GridState (a grid view: choice, reorder), Scrollbar, Glide, Theme, Content
examples/          native (inbox over a million rows), form (fields, text area, IME), desktop (menus, dialogs, tray,
                   shortcuts, notifications), webview (Notes service, channels, myphp.ts), logview, notes,
                   photos, http (real tools — a log viewer, Markdown notes, a photo browser, an HTTP client
                   over curl — the API's proving ground), files (a file manager: sidebar, breadcrumbs, grid or table of
                   the folder, one Selection shared by both, find bar, image preview, Trash), glass (Liquid Glass over a
                   photo), widgets (the small controls), titlebar, game (Plugins\Game), scene3d/game3d (Plugins\Scene 3D), piano (Audio); mygo's own (v1.11): hello … gallery, run headless by examples_test.php
tests/             headless tests: php tests/run.php [name] — Headless.php (a fake TextPainter of 7×16 cells, events as the
                   view sends them, paint into an FFI buffer, the widgets and files examples without their windows), *_test.php
                   (check()/same() assertions, no PHPUnit; svg_test reads the renderer's pixels); php tests/bench.php [frames] times build/layout/record/paint
docs/              the guides (docs/ui/README.md: native UI, examples run by tests/docs_examples_test.php); docs/architecture.md  design notes, measurements, pitfalls
```

## Architecture, in short

- **Immediate mode**, as mygo's `ui`: each frame rebuilds the tree, `Layout`
  measures and arranges, `Painter` emits quads. Frames run on demand
  (`Metal::requestFrame`: `draw` from the run loop, paced by the view's
  display link, never from MTKView's dispatch-queue paths). A replaced tree's nodes lose their context (`Runtime::detach`): freed without the GC.
- **Last frame answers this frame's questions**: `Runtime` keeps rects by
  node id (creation order, or xxh64 of the key path under `Context::key`;
  list rows by their item's key, `ListState::$key`, else index); `clicked()/hovered()/focused()` hit-test
  them while building; a click that changed state re-runs the build (≤3 passes).
  Text wraps at the width layout gives it (`Layout::wrapTexts`); `->focusable()` puts any node in the Tab order; `->size(w, h)`
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
  PHP plugins (`App::use`): service "plugin:<name>"; `CallContext` param = the page's life; `Channel::wait`; generators wait on flow control.
- **FFI discipline**: one FFI instance per bridge (structs are not shared
  across instances; foreign pointers pass `ObjC::import`); `objc_msgSend`
  cast per "ret:args" signature (`ObjC::MAP`), never variadic; callbacks
  kept alive in holder structs; blocks via `ObjC::block`; `App::wait` makes a
  block-delivered result synchronous; PHP is entered on the main thread only.
  `FFI::new/cast/type` only on an instance (static: deprecated since 8.3);
  a pointer's address is `Mem::addr`, never a cast of `void*` to an integer (it reads through it); a slot
  for a pointer is an `unsigned long` of it (`->cdata` takes none); no `?:` on a maybe-`"0"` string (`mb_chr`, `substr`, `basename`).

Measured on an M-series Mac: 9 000 nodes rebuilt, laid out and painted in ~4–6 ms per frame at 60 fps; a million-row list ~1.3 ms, 8 MB flat.

## Projects and bundles

`bin/myphp` is mygo's CLI (`myphp help`; templates, skills in `bin/`);
myphp.json configures the project. `build` makes
`<out>/darwin-<arch>/<name>.app` (carrying a static PHP, phpmicro, prebuilt per platform: `ensureMicro`; or
`-launcher exec`: a C launcher that execs this php), signed, and a .dmg; on Linux
`<out>/linux-<arch>`: the app, its .tar.gz, a .deb, a PKGBUILD (`Build\Linux`); `dev` runs "<name> Dev"
from .myphp/dev. Work in `src/Native/Build/` (`tests/build_test.php`). Project `vendor/` (Composer packages) is bundled automatically across all 5 platforms. To release: `php scripts/version.php <v>`, commit, tag `v<v>` (`release.yml` creates the GitHub release). Slugs' pinyin: `php scripts/gen-pinyin.php` (Unihan).

## Working on it

- PHP ≥ 8.4 (8.5 recommended) with `ext-ffi`; `myphp run` and `myphp dev` set `ffi.enable=1` and OPcache JIT. `bin/myphp` stays dependency-free PHP.
- Before handing back: `php -l` on changed files; run only the tests of what you changed (`php tests/run.php <name>...`), never the whole suite (the developer's rule: all of it is CI's); ask the developer to run GUI examples; `php tests/bench.php` after per-frame changes. Include the tests that *read* a file you changed (`grep -l <file name> tests/*.php`: a test of a launcher's source, a script's text): `embed_ini` broke on a comment added to the Android launcher, and was seen only in CI.
- New ObjC calls: selector/types from Apple's headers, letters in `ObjC::MAP`, structs via `ObjC::$c->new`/`ObjC::rect`, no variadics (`arrayWithObjects:`).
- Record each solved problem in `docs/architecture.md` (one paragraph).
- **A phone is checked on a stock one**: a permissive SELinux (a rooted or custom ROM phone) and the iOS Simulator hide what a user's device refuses (OPcache's lock, the sandbox's `/tmp`, `fork`). Use an `Enforcing` `user`-build emulator image (Android TV's arm64 one) and, for iOS, a device; the details are in `docs/architecture.md`, "What a stock phone refuses".
- **4-platform app verification**: after feature changes on an app, run headless `tests/ui_test.php`, `myphp screenshot` / `myphp run -d macos`, `myphp run -d ios`, `myphp run -d android`, and on a Windows VM over SSH (`ssh win`, equipped with native PHP 8.4/8.5 + `ext-ffi` + MinGW because `myphp build` bundles the host Windows `php.exe`), run `ui_test.php`, `bin\myphp.cmd build -skip-installer`, and trigger `bin\myphp.cmd screenshot` in the logged-in desktop session (`schtasks /IT`) then `scp` the PNG back.
- CJK in the iOS Simulator from the Mac's keyboard: switch the input method inside the simulator (iOS's keyboard), not the Mac's input source.

## Roadmap

Done: macOS (Metal/Cocoa/WKWebView), Windows (D3D11/Win32/DirectWrite/WebView2), Linux (GTK 3/OpenGL/WebKitGTK), Android (EGL/GLES 3), iOS/iPadOS (Metal/UIKit), and 5-platform native 3D (`Plugins\Scene`). Not targeted yet: tvOS (needs its own `appletvos`/`appletvsimulator` `libphp.a` slice with `fork`/`system`/`popen` disabled), visionOS. Android TV shares Android's `libphp.so` and starts, but has no D-pad focus engine; Android is 64-bit only (the framework takes 64-bit PHP integers): both in `docs/architecture.md`, "What a stock phone refuses".
The UI is declarative, as SwiftUI's (docs/swiftui.md, docs/ui/views.md): every example, doc page and the skill show views; the builder (`$c->column()…`) is the engine's own, `@internal`, which apps reach only inside a `Representable`.
Remaining: what building real apps turns up (examples/logview, notes, photos, http, mobile, model-viewer).
With each new platform (Windows, Linux's bridges), update the app-writing skill `bin/skills/myphp-app/` with it: its description and opening (which GPU API draws where), "Verify before you say it is done" (how to run, screenshot and read the log there; `myphp devices`/`emulators`/`doctor` as they grow), and `references/` where its behavior differs.
