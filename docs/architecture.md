# Architecture notes

Companion to AGENTS.md: the reasoning behind its rules, with the numbers.

## Why ext-ffi and not an extension

mygo calls Cocoa, GTK and Win32 through `purego` (dlopen + typed calls +
callbacks) so that it stays pure Go. `ext-ffi` is the same tool in PHP:
`dlopen`, typed calls through `FFI::cast` to function-pointer types, and PHP
closures as C callbacks (libffi closures). No C of our own to build or ship.

Measured (PHP 8.3, x86_64 container; Apple Silicon is faster):

| call | cost |
|---|---|
| C function, no args | 35 ns |
| C function, struct argument | ~125 ns |
| C → PHP closure, struct argument | ~115 ns |
| 10 000 rects (6 floats each) written into an FFI `float[]` | 0.6 ms |

The FFI boundary is never the frame budget's problem.

## Why immediate mode survives in PHP

mygo rebuilds the element tree, lays it out and paints every frame it needs
(`ui/runtime.go: runFrame`), with a cheaper `repaintFrame` when only
animated drawings moved. The worry for PHP was per-frame object churn.
Measured in `examples/native` on an M-series Mac, NSTimer at 60 Hz:

| nodes per frame | build | layout | paint (CoreGraphics) | total |
|---|---|---|---|---|
| 2 706 | 1.8 ms | 0.7 ms | 0.4 ms | ~3 ms |
| 9 006 | 2.8 ms | 1.1 ms | 0.2 ms | ~4.2 ms |

60.0 fps at both sizes; memory flat (4 MB and 6 MB). Nodes hold no parent
pointer, so a frame's tree has no cycles and reference counting frees it:
the cycle collector never ran. Headroom to the 16.6 ms budget is about
30 000 nodes per frame, before virtualized lists cut the count further.

## Rules that fell out of the PoC

- One `FFI` instance per OS bridge. Structs from one instance cannot be
  passed to functions of another ("incompatible argument"), so NSRect,
  CGRect and friends live where they are used.
- `objc_msgSend` is never declared variadic. On arm64, variadic arguments
  go to the stack; objc_msgSend wants them in registers. Cast its address
  to an exact function-pointer type per signature instead.
- A PHP closure passed as a C function pointer is backed by a libffi
  closure that dies with its CData. Keep every callback in a struct field
  held for the life of the process (`ObjC::$keep`).
- A pointer returned by a function of another FFI instance does not stay
  valid as a CData: on PHP 8.5.11 `MTLCreateSystemDefaultDevice()`'s result
  read as a `float` (and `var_dump` bus-errored) by the next method call,
  with the instance kept alive in a property. `ObjC::import($p)` rebuilds
  the pointer from its integer address as a fresh CData in the ObjC scope;
  every foreign pointer goes through it before being stored.
- `FFI::cast` from a pointer CData to a scalar, struct or array type
  dereferences the pointer (that is how `cast('float[n]', $buffer)` writes
  into GPU memory). So `cast('unsigned long', $obj)` reads the object's first
  word, the isa, not its address; `ObjC::addr()` goes through a `void*[1]`
  slot instead.
- There is no pointer arithmetic on CData (`$p + 1` is a TypeError), and
  the static `FFI::new()/cast()` forms are deprecated in PHP 8.5. `Native\Mem`
  works on integer addresses: `addr()` reads a pointer's value through the
  slot trick, `copy()` casts `int` back to `unsigned char*` for `FFI::memcpy`.
- Callbacks run only on the main thread. PHP's engine is not re-entrant
  from another thread: CoreAudio render callbacks, CVDisplayLink and
  dispatch queues must not call into PHP. Use NSTimer / run-loop sources.
- Intel Macs return NSRect through `objc_msgSend_stret`; unsupported for
  now, the bridge refuses to boot there.

## Metal path

`Native\Metal` compiles one MSL library at startup (rect vertex/fragment
pair with a rounded-rect SDF for anti-aliased corners), builds one pipeline
with source-alpha blending, and allocates a ring of four shared
`MTLBuffer`s. `DisplayList` writes 12 floats per rect straight into the
current buffer's `contents` cast to `float[]`; `present` encodes one
instanced triangle-strip draw (4 vertices × n instances) and commits. The
viewport size goes in through `setVertexBytes:` as two floats, in points,
so coordinates are in points on any backing scale.

## Text path

`Native\CoreText::shape` builds a CFAttributedString with the system UI
font and a CTLine, then reads each run's glyph ids, positions and the font
CoreText actually chose (fallback fonts included, so CJK works without any
font list of ours). `rasterize` draws one glyph with `CTFontDrawGlyphs` into
an alpha-only CGBitmapContext at the backing scale and flips the rows.
`UI\GlyphAtlas` shelf-packs those into a 2048² R8 image; `Native\Metal`
re-uploads the whole image when it is dirty (a few times at startup).
`UI\Text` caches a shaped line as a list of glyph quads in points,
snapped to the device-pixel grid; `measure()` feeds `Context::text`, and
`paint()` appends `kind = 1` quads to the display list. The Metal fragment
shader samples the atlas for those and runs the rounded-rect SDF for the
rest, in one pipeline and one draw per frame.

CoreText hands each CTLine new CTFont objects for its fallback fonts, so a
font's identity is its `CFHash` (equal fonts hash alike), not its address:
keyed by address, every line of CJK put fresh copies of the same glyphs in
the atlas until it filled, and retained a font per line. The shaped-line
cache is swept every 15 frames of lines unused for 30 frames; a line is a
flat float list (6 values per glyph), since a nested array per glyph made a
line ~6 KB and fast scrolling through a million rows reached 60 MB.

Two things bit on the way: a CGBitmapContext's first row in memory is the
top of the image although its coordinates run y-up, so no flip is needed
when copying into a top-down atlas (flipping the CTM as well rendered every
glyph upside down); and in a PHP double-quoted string `"$i："` swallows the
full-width colon into the variable name, since identifiers may contain
bytes ≥ 0x80 — write `{$i}` before any CJK punctuation.

Measured with text (`examples/native 1000`, 9 006 nodes, ~1 200 quads of
which ~1 000 glyphs): 4.9–5.5 ms per frame at 60 fps; one 23 fps second
when a whole screen of new strings is shaped and rasterized at once.

## Input path

`Native\View` subclasses MTKView; `mouseDown:` and friends, `scrollWheel:`
and `keyDown:` are PHP closures that convert the event point into the
flipped view's coordinates, append to `UI\Input` and call
`setNeedsDisplay:`. The view is paused with `enableSetNeedsDisplay`, so a
frame happens only after an event or an explicit `Metal::requestFrame`.
`UI\Runtime::frame` drains the events, runs the build (again if a click
changed state, at most three times), and records every node's rect by id
for the next frame's hit tests. Wheel deltas go to the smallest scrolling
container under the cursor; `Layout` clamps the offset and shifts children;
paint pushes the container's rect as a clip, which travels with each quad
into the shader (`float4 clip`, discard outside). An NSTrackingArea with
`mouseMoved` keeps hover state current.

## Lists

`Context::list($state, $count, $row)` is the virtualized list. `ListState`
keeps the anchor row and how far the view's top is below the anchor's top,
as mygo's `ListState` does, so a row growing or rows appearing above do not
move what is in view. Building, the list makes rows from the anchor until
their known-or-estimated heights reach 1.5 views (Context carries the
window size for this); laying out, `Layout` records each built row's
measured height in the state and computes the gap, if any, between the
last built row and the view's bottom; a gap sets `Runtime::$needsFrame`
and the next frame, with better estimates, fills it. The wheel moves the
offset and renormalizes it across row boundaries; past the end it pulls
back so the last rows fill the view. mygo instead builds the missing rows
during layout, so it never shows a gap; that is the remaining difference.

## Page path

`Native\WebView` builds a WKWebViewConfiguration with a user script
(`bridge.js` with the window's config spliced in, injected at document
start) and a script message handler named `myphp`. The page's
`window.myphp.call` posts `{t:"call", id, k, m, a}` as JSON; the handler
closure hands the body to `Ipc::handle`, which checks the secret, finds
"Service.method" among the bound services, calls it with the JSON
arguments and returns `{t:"reply", id, k, ok, v|e}`; `WebView::deliver`
evaluates `window.myphp.__deliver(...)` with it. Events are the same in
the other direction. Unlike mygo, calls run on the main thread one after
another: a slow method stalls the UI, so slow work belongs in a subprocess
or a later message. `TypeScript::generate` reflects the services: PHP
types map to TS (int/float → number, class → interface of its public
properties, PHPDoc `Note[]`/`list<T>`/`array<K,V>` for arrays), docblocks
become TSDoc, declared events get typed listeners.

## Menus and dialogs

`Menu::build` turns MenuItems into NSMenuItems. An item with a role gets
the system selector sent down the responder chain with a nil target
(`copy:`, `performClose:`, `terminate:`...), so Edit works in web views
and the standard windows menu is NSApp's. An item with a click closure
gets the shared `MyPHPMenuTarget` and its uid as the item's tag; the
target's `menuClicked:` looks the item up and calls the closure with the
key window. Accelerators are parsed from mygo's "CmdOrCtrl+Shift+Z" form
into a key equivalent and an NSEvent modifier mask. Menus are built with
`setAutoenablesItems:NO`, so enabled state is what the item says.
Dialogs use NSOpenPanel/NSSavePanel/NSAlert with `runModal`, which blocks
the PHP call until the panel closes; a parent window is accepted but not
yet used, since a sheet's completion handler is an ObjC block, and blocks
are a struct this bridge does not build yet.

## Open items

- `-[NSView displayLinkWithTarget:selector:]` returned a link that never
  ticked after `addToRunLoop:forMode:`; NSTimer at 1/60 s is used meanwhile.
- The atlas never evicts and is re-uploaded whole; text is rasterized at
  the main screen's backing scale at startup and not again on a change.
- Node ids are creation order, so UI that appears conditionally shifts the
  ids after it and a click can land on the wrong node for one frame; mygo
  keys elements. Keyboard events are queued but nothing consumes them yet.
- A screen of never-seen strings costs a shaping burst (one dropped-frame
  second at 1000 rows); the shaped-line cache grows without bound (46 MB
  resident for 3 000 lines). mygo bounds its text cache per frame.
- The CoreGraphics + NSTimer path (since deleted) ran 50–58 fps for its
  first seconds and 60 after, with identical PHP time per frame: NSTimer is
  not locked to the display. MTKView pacing fixed it.
