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
src/Native/ObjC.php   Objective-C runtime bridge (macOS, Apple Silicon)
src/UI/Node.php       one element of a frame's tree
src/UI/Context.php    tree building: column/row/text/button/box
src/UI/Layout.php     flex layout: measure (bottom-up), arrange (top-down)
examples/native/      native window, immediate mode at 60 fps, prints stats
examples/webview/     WKWebView window with a JS <-> PHP bridge
run.sh                runs an example with the right php -d flags
docs/architecture.md  design notes and measurements
```

Namespace `MyPHP\`, PSR-4 from `src/`. Examples use a two-line autoloader so
they run without Composer.

## Architecture, in short

- **Immediate mode**, as mygo's `ui`: every frame the view function rebuilds
  the tree, `Layout` measures and arranges it, paint emits drawing commands.
  Frames are requested only when something changed, or a timer/animation is
  due. Lists are virtualized so the per-frame node count is what is visible.
- **Nodes have no parent pointers.** A frame's tree is acyclic, reference
  counting frees it, the cycle collector never runs mid-frame. Keep it so.
- **The GPU gets one buffer per frame.** Paint writes a display list into
  an FFI `float[]`; the renderer uploads it once. No per-rect FFI calls in
  the final renderer (the CoreGraphics path in `examples/native` is a stand-in).
- **Text goes through the OS shaper** (CoreText on macOS) with a glyph atlas,
  cached by font, size and string; a cache hit costs nothing per frame.
- **One FFI instance per OS bridge**, holding all its types: structs from
  different instances are incompatible.
- **Callbacks on the main thread only.** PHP must never be entered from
  another thread (no CoreAudio/CVDisplayLink/dispatch callbacks into PHP).
- **`objc_msgSend` is cast per signature, never declared variadic** (arm64
  calling convention). Signatures are "ret:args" letter strings, see
  `ObjC::MAP`. Struct returns (NSRect) use plain `objc_msgSend` on arm64.

Measured on an M-series Mac: 9 000 nodes rebuilt + laid out + painted in
~4 ms per frame at 60 fps, memory flat. Budget is 16.6 ms; the language is
not the bottleneck, the architecture is what keeps it that way.

## Working on it

- PHP ≥ 8.3 with `ext-ffi`; run examples with `./run.sh native|webview`,
  which sets `ffi.enable=1` and OPcache JIT (`opcache.jit=1255`).
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

1. Metal renderer: CAMetalLayer, rect/rounded-rect/texture pipelines,
   display list in one vertex buffer per frame (mygo: `internal/gpu/metal`).
2. CoreText text: shaping + glyph atlas (mygo: `internal/text`, `internal/scene/atlas.go`).
3. Input: mouse, keyboard, scroll wheel from NSView into hit testing.
4. Window/app API shaped like mygo's `App`, `NewWindow`, `WindowOptions`.
5. Webview IPC: bind PHP services, generate a TypeScript client from them.
6. Linux (GTK4/WebKitGTK) and Windows (Win32/WebView2/D3D11) bridges.
