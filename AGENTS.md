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
bin/myphp          CLI: init (templates), dev (run, restart on change, TS client), build (.app), micro (spc + micro.sfx)
src/               App (whenReady/run/quit/theme/menu/userDataPath/later/after), Window (+ on(event), CloseEvent,
                   FileDropEvent, WindowState),
                   WindowOptions, Menu, MenuItem, Dialog + *Options, Tray (+ TrayOptions), GlobalShortcut,
                   Notification (+ Options), Ipc (bound services, window controls), Channel (+ ChannelClosed:
                   streaming to pages), TypeScript (client), autoload.php (PSR-4)
src/Native/        ObjC (runtime bridge: msg, defineClass, block/noopBlock/callBlock, addr/import), Carbon (hot
                   keys), Drop (NSDraggingDestination methods for file drops), Metal (MTKView, one quad pipeline,
                   buffer ring, atlas texture), CoreText (shaping, glyph bitmaps, caret x), View (MTKView subclass:
                   events into UI\Input, NSTextInputClient), WebView + bridge.js (window.myphp), Appearance
                   (dark/light, accent), Pasteboard, ImageIO (decode to RGBA), Mem (calloc/free, address memcpy)
src/UI/            Node, Context (column/row/text/muted/rich/link/button/checkbox/radio/switch/slider/
                   select/stepper/segmented/tabs/collapsible/progress/spinner/badge/divider/spacer/
                   input/textarea/image/box/list; clicked/doubleClicked/changed/hovered/focused/shortcut; ->tooltip()/at()/
                   round()/material()/align()/alignSelf()/justify()/center(); rows center across,
                   columns stretch, as mygo), Glass / Blur / ScrollEdge (backdrop materials), Font (style
                   bits), Span (rich text run), RichLayout (spans wrapped at a width), Layout (flex),
                   DisplayList (quads with clip), Painter (tree -> list), Text + Atlas (glyphs R8, images RGBA)
                   + TextMetrics, Image (decoded once, atlas slot on first paint), TextState (editing model,
                   undo), TextLayout (wrapped lines of a text area),
                   Input, Runtime (last frame's rects, clicks/hover/focus, scroll routing, thumb drag, passes),
                   Scrollable + ScrollState + ListState (anchor, selection, scrollTo), Scrollbar, Glide, Theme, Content
examples/          native (inbox over a million rows), form (fields, text area, IME), desktop (menus, dialogs, tray,
                   shortcuts, notifications), webview (Notes service, channels, myphp.ts), logview, notes,
                   photos (real tools — a log viewer, Markdown notes, a photo browser — the API's proving
                   ground), glass (Liquid Glass over a photo), widgets (the small controls)
run.sh             runs an example with the php -d flags; docs/architecture.md  design notes, measurements, pitfalls
```

## Architecture, in short

- **Immediate mode**, as mygo's `ui`: each frame rebuilds the tree, `Layout`
  measures and arranges, `Painter` emits quads. Frames run on demand
  (`Metal::requestFrame`), paced by MTKView on the main thread; no NSTimer
  or CVDisplayLink into PHP. Nodes have no parent pointers (acyclic: no GC).
- **Last frame answers this frame's questions**: `Runtime` keeps rects by
  node id (creation order, or xxh64 of the key path under `Context::key`;
  list rows are keyed by index); `clicked()/hovered()/focused()` hit-test
  them while building; a click that changed state re-runs the build (≤3 passes).
  `rich(..., wrap: true)` wraps at last frame's width (a frame follows when
  it differs); `->focusable()` puts any node in the Tab order; `->size(w, h)`
  fixes a container's size over its content's.
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
  and glass share one pipeline and one instanced draw (kind 0–8), so draw
  order is list order; clip rect per quad. A backdrop quad (glass, blur,
  hard scroll edge) splits the draw: what is below it is copied and blurred
  (half size, two passes) into the backdrop it samples, then the pass resumes.
- **Atlases start over when full** (glyphs or images): `Text::$repaint` makes
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
  the page's window controls; `WindowOptions::$preload` runs before the page.
  `MYPHP_GENERATE=path` makes `App::run` write the TypeScript client.
- **FFI discipline**: one FFI instance per bridge (structs are not shared
  across instances; foreign pointers pass `ObjC::import`); `objc_msgSend`
  cast per "ret:args" signature (`ObjC::MAP`), never variadic; callbacks
  kept alive in holder structs; blocks via `ObjC::block`; `App::wait` makes a
  block-delivered result synchronous; PHP is entered on the main thread only.

Measured on an M-series Mac: 9 000 nodes rebuilt, laid out and painted in
~4–6 ms per frame at 60 fps; a million-row list ~1.3 ms, 8 MB flat.

## Projects and bundles

`myphp init` writes myphp.json, boot.php (framework from `./myphp` in a
built app, else the checkout) and app.php; `myphp build` makes `<Name>.app`
with a C launcher that execs this php (development) or, `--launcher micro`,
a phar combined with phpmicro by static-php-cli (release, one static
binary); `myphp micro` fetches spc into ~/.myphp and builds micro.sfx once.
static-php-cli is the one outside tool, for release builds only.

## Working on it

- PHP ≥ 8.3 with `ext-ffi`; `./run.sh <example>` and `bin/myphp dev` set
  `ffi.enable=1` and OPcache JIT. `bin/myphp` stays dependency-free PHP.
- Before handing back: `php -l` on every changed file; pure-PHP checks
  where they exist; ask the developer to run GUI examples.
- New ObjC calls: selector and types from Apple's headers, signature letters
  in `ObjC::MAP`, structs made by `ObjC::$c->new`/`ObjC::rect`, variadic
  methods (`arrayWithObjects:`) avoided.
- Record each solved problem in `docs/architecture.md` (one paragraph) so
  the next session does not rediscover it.

## Roadmap

Done: Metal rects/glyphs/clipping; CoreText shaping + atlas; mouse, wheel,
focus, single-line fields with input methods; App/Window, menus, dialogs
(sheets), pages with IPC + TS client; virtualized lists with scroll bars,
glides, keys and selection; text areas (wrapping, undo, caret blink);
theme; images; rich text (bold/italic/mono, spans); checkbox, radio, switch, slider, select, stepper, segmented, tabs, collapsible, progress, spinner, badge, link, tooltips; soft shadows, rings, Liquid Glass, Blur, ScrollEdge; tray, global shortcuts, notifications, window events + state;
channels, page window controls, preload; file drops; CLI + .app.
Remaining: Linux (GTK4/WebKitGTK) and Windows (Win32/WebView2/D3D11)
bridges; what building real apps turns up (see examples/logview, notes, photos).
