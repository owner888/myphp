# AGENTS.md — MyPHP

Entry point for coding agents and contributors. `docs/architecture.md` has
the reasoning and the numbers behind the rules here.

## Rules

1. **This file stays under 200 lines.** Check with `wc -l AGENTS.md` after
   editing it; move detail to `docs/` rather than growing it.
2. No third-party packages without asking the developer first. Small things
   are written here instead of pulled in. `composer.json` has no `require`
   beyond `php` and `ext-ffi`, and that is the intent.
3. Git is the developer's: do not commit, push, branch, tag or export
   patches. Change files, run checks, report which files changed.
4. Files that go into git never carry the developer's name. Write "the
   developer" (English) or 「开发者」 (Chinese) when a reference is needed.
5. Do not run GUI examples from a sandbox or container: they need a
   display and the Apple frameworks. Ask the developer to run them and
   report the output; `php -l` and pure-PHP tests can run anywhere.
6. Keep the codebase small and literal. No speculative abstractions,
   no silent fallbacks, no magic numbers chosen to make a symptom go away.
   When something is unclear, ask before changing.
7. Delete dead code when you touch it; do not comment it out.

## What this is

A port of the ideas in mygo (`github.com/egoist/mygo`, a pure-Go desktop
framework) to PHP 8.3+: windows with either a system webview or native UI
drawn by us on the GPU, reaching the OS through `ext-ffi` only. mygo's
`purego` role is played by `FFI::cdef` / `FFI::cast` / PHP closures as
callbacks. There is no C extension of our own and there will not be one.

Reference checkout of mygo, if present: `../../other/mygo` relative to this
repo (`AGENTS.md`, `docs/architecture.md`, `ui/runtime.go` are the parts
worth reading first). When porting a behaviour, read mygo's version first
and keep the semantics; rename to PHP conventions, do not redesign.

## Layout

```
bin/myphp               the CLI: init (templates), dev (run + restart on change + TS client), build (.app), micro (setup notes)
src/autoload.php        PSR-4 for MyPHP\ without Composer; projects require it through their boot.php
src/App.php             the application: whenReady(), run(), quit(); shared Metal device and text engine
src/Window.php          a native-UI window: NSWindow + View, per-window Runtime/Input, frame = build/layout/paint
src/WindowOptions.php   title, size, position, hidden, disableResize, content | html | url
src/Menu.php            menu bar and context menus: roles, accelerators, clicks by tag -> PHP closure
src/MenuItem.php        an item: label, click, accelerator, role, checkbox, submenu; set* update the native item
src/Dialog.php          open/save panels and message boxes: sheets of a parent window, else app-modal
src/*DialogOptions.php  FileFilter, Open/Save/MessageOptions, MessageResult
src/Ipc.php             bound services: bind(), handle() one bridge message, emit() events to pages
src/TypeScript.php      the generated client (MYPHP_GENERATE=path): services, interfaces, events
src/Native/ObjC.php     Objective-C runtime bridge (macOS, Apple Silicon)
src/Native/Metal.php    Metal renderer: MTKView, one quad pipeline, buffer ring, atlas texture
src/Native/CoreText.php text shaping (CTLine) and glyph rasterization (CTFontDrawGlyphs)
src/Native/Mem.php      calloc/free, integer addresses, memcpy by address (no pointer arithmetic in FFI)
src/Native/View.php     MTKView subclass: mouse/scroll/key events into UI\Input; NSTextInputClient over the focused field
src/Native/Pasteboard.php the general pasteboard, plain text
src/Native/WebView.php  WKWebView with the bridge user script and script message handler
src/Native/bridge.js    window.myphp in every page: call(), on(), __deliver(); mygo's message shapes
src/UI/Node.php         one element of a frame's tree
src/UI/Context.php      tree building: column/row/text/button/box
src/UI/Layout.php       flex layout: measure (bottom-up), arrange (top-down)
src/UI/DisplayList.php  a frame's quads (rects and glyphs), written into the GPU buffer
src/UI/Text.php         shaped-line cache; measure() for layout, paint() to the list
src/UI/GlyphAtlas.php   8-bit shelf-packed glyph atlas the renderer uploads when dirty
src/UI/TextMetrics.php  what Context needs from a text engine
src/UI/Input.php        events since the last frame, mouse position, the focused field for the text input client
src/UI/TextState.php    a field's text, selection and composition; byte offsets; the text system's commands
src/UI/Runtime.php      last frame's rects by node id; clicked()/hovered(); scroll routing; rebuild passes
src/UI/Scrollable.php   what the wheel drives: ScrollState (offset) or ListState (anchor row)
src/UI/ScrollState.php  a scrolling container's offset, owned by the app
src/UI/ListState.php    a virtualized list's anchor row, offset, measured heights and estimate
src/UI/Theme.php        colors and metrics widgets draw with; light()/dark(); withAccent()
src/Native/Appearance.php dark-or-light and the accent color from NSApp's effectiveAppearance
src/UI/Content.php      Content::view(fn(Context)) — what a window shows
src/UI/Painter.php      tree -> display list: fills, labels (centered in buttons), scroll clips
examples/native/        an inbox: App::whenReady -> new Window(WindowOptions(content: Content::view(...)))
examples/desktop/       menu bar with roles and custom items, context menu, the three dialogs
examples/webview/       Notes: a bound service, a page calling it, an event; myphp.ts is its generated client
run.sh                runs an example with the right php -d flags
docs/architecture.md  design notes and measurements
```

Namespace `MyPHP\`, PSR-4 from `src/`. Examples use a two-line autoloader so
they run without Composer.

## Architecture, in short

- **Immediate mode**, as mygo's `ui`: every frame the view function rebuilds
  the tree, `Layout` measures and arranges it, paint emits drawing commands.
  Frames run on demand: an event calls `Metal::requestFrame`, nothing else
  draws. Lists are virtualized so the per-frame node count is what is visible.
- **Lists are virtualized** (`Context::list`, as mygo's `List`): the app's
  `ListState` holds the anchor row and the view's offset below its top;
  only rows for 1.5 views of estimated height are built, heights are
  learned from layout, unknown rows count as the average. The per-frame
  cost of a million-row list is the rows in view.
- **Colors come from the theme**, never literals in widgets or examples:
  `$c->theme` (App::theme()) is light or dark after the system with its
  accent color, re-read on the `effectiveAppearance` KVO; `Context::text`
  defaults to `theme->text`, `muted()` to `textMuted`, buttons to the
  accent with hover/pressed shades. A window clears to `theme->background`.
- **Keys go through `interpretKeyEvents:`**, never parsed by hand: the input
  context calls `insertText:`, `setMarkedText:` and `doCommandBySelector:`
  on the view, which edits `Input::$focus` (the focused field's TextState)
  at once and answers `selectedRange`/`markedRange`/`firstRectForCharacterRange:`
  from it, so input methods compose in place with their candidate window
  at the caret. Focus moves in Runtime: click, Tab/Shift-Tab, Escape.
- **Interaction answers from last frame's layout**: `Runtime` keeps rects by
  node id (creation order); `clicked()`/`hovered()` hit-test against them
  while this frame builds. A click that changed state re-runs the build, up
  to three passes, as mygo does. Scrolling containers (`->scroll($state)`)
  get wheel deltas routed by hit test and clip their children.
- **Nodes have no parent pointers.** A frame's tree is acyclic, reference
  counting frees it, the cycle collector never runs mid-frame. Keep it so.
- **The GPU gets one buffer per frame.** Paint writes a `DisplayList`
  straight into a shared `MTLBuffer` (cast to `float[]`), four buffers in a
  ring; one instanced draw call per frame. No per-rect FFI calls.
- **Frames are paced by MTKView**, which calls `drawInMTKView:` on the main
  thread at the display's rate. No NSTimer, no CVDisplayLink into PHP.
- **Text goes through the OS shaper** (CoreText on macOS) with a glyph atlas,
  cached by size and string (`UI\Text`); a cache hit is one array lookup.
  Glyph quads and rects share one pipeline and one draw call (`params.y`
  picks the branch), so draw order is the list order.
- **One FFI instance per OS bridge**, holding all its types: structs from
  different instances are incompatible, and a pointer another instance
  returned is stored only after `ObjC::import()` rebuilt it in this scope.
- **Callbacks on the main thread only.** PHP must never be entered from
  another thread (no CoreAudio/CVDisplayLink/dispatch callbacks into PHP).
- **Pages talk to PHP as in mygo**: the bridge posts `{t:"call", id, k, m, a}`
  through a WKScriptMessageHandler, `Ipc::handle` runs the method on the
  main thread and `WebView::deliver` evaluates `window.myphp.__deliver(reply)`;
  events are `{t:"event", n, p}`. Every window has its own secret `k`.
  `App::run` with `MYPHP_GENERATE=path` writes the TypeScript client and
  returns, as `mygo generate` does; `./run.sh gen` runs it for the example.
- **Blocks are `ObjC::block('lp', fn)`**: a global block literal whose invoke
  is a PHP closure, for completion handlers. An API that only delivers its
  result through a block is made synchronous with `App::wait(fn() => ...)`,
  which runs the run loop until the block fired (Dialog sheets do this).
- **`objc_msgSend` is cast per signature, never declared variadic** (arm64
  calling convention). Signatures are "ret:args" letter strings, see
  `ObjC::MAP`. Struct returns (NSRect) use plain `objc_msgSend` on arm64.

Measured on an M-series Mac: 9 000 nodes rebuilt + laid out + painted in
~4 ms per frame at 60 fps, memory flat. Budget is 16.6 ms; the language is
not the bottleneck, the architecture is what keeps it that way.

## Projects and bundles

`myphp init` writes myphp.json, boot.php (framework from `./myphp` in a
built app, else the checkout) and app.php; `myphp build` makes `<Name>.app`
with a C launcher that execs this php (development) or, `--launcher micro`,
a phar combined with phpmicro by static-php-cli (release, one static
binary). Details in docs/architecture.md, Packaging. static-php-cli is the
one outside tool, for release builds only.

## Working on it

- PHP ≥ 8.3 with `ext-ffi`; run examples with `./run.sh native|webview|...`
  and projects with `bin/myphp dev`; both set `ffi.enable=1` and OPcache
  JIT (`opcache.jit=1255`). `bin/myphp` stays dependency-free PHP.
- Before handing back: `php -l` on every changed file; run pure-PHP checks
  in `tests/` if they exist for the area; ask the developer to run GUI
  examples and paste the stats line.
- When adding an ObjC call, find the selector and argument types in Apple's
  headers, add the signature letters, and pass structs created by
  `ObjC::$c->new(...)` or `ObjC::rect(...)`, never from another FFI instance.
- Keep every PHP callback alive: `ObjC::defineClass` does this for class
  methods; any other function pointer handed to C needs the same holder.
- Record a problem you hit and solved in `docs/architecture.md` under
  "Open items" or "Rules that fell out of the PoC", one paragraph, so the
  next session does not rediscover it.

## Roadmap

1. Metal renderer: rects, rounded rects and glyph atlas done; images and
   clipping next (mygo: `internal/gpu/metal`).
2. CoreText text: shaping + atlas done; atlas eviction, dirty-rect uploads,
   and re-rasterizing on a backing-scale change remain (mygo: `internal/text`).
3. Input: mouse, wheel, clipping, keyboard focus, single-line text fields
   with input methods and the Edit menu done; multi-line text, undo,
   caret blink, stable ids for conditional UI, scroll glide remain.
4. App/Window API: whenReady/run/quit, WindowOptions subset, show/hide/
   close/setTitle/center/size, menu bar and context menus with roles and
   accelerators, open/save/message dialogs (as sheets too), page evaluate
   with results, whenPageReady done; tray, notifications, global
   shortcuts, window events (OnClose, OnResize), window state remain.
5. Virtualized lists: done; keys for rows that come and go, FollowEnd,
   selection, keyboard navigation and a scroll bar remain.
6. Webview IPC: bind, call, reply, events, TypeScript client done;
   channels (streaming), a dev server/front-end toolchain, window controls
   from the page, the preload script remain.
7. Linux (GTK4/WebKitGTK) and Windows (Win32/WebView2/D3D11) bridges.
