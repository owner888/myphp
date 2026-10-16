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

The quad kinds, by `params.y`: 0 a rounded rect, 1 a glyph, 2 an image,
3 a soft shadow (the rect inset by the blur, feathered over it), 4 a ring
(the band inside the edge), 5 glass, 6 a vertical alpha gradient, 7 a
backdrop blur, 8 the hard scroll edge, 9 a line: a capsule between
opposite corners of its bounding rect, inset by half the thickness,
`u, v` the signs of its direction (`DisplayList::line`). `Node::draw`
hands a node's face to a closure given the list, the laid-out node and
the theme, painted over the node's own face and under its children
(mygo's Draw): the search field's magnifying glass is a ring and a line
drawn that way, at mygo's MingCute proportions.

## Liquid Glass

`UI\Glass` is a material a container takes with `->material()`, shaped
by `->round()`, ported from mygo's `plugins/glass` (itself measured from
macOS 26's NSGlassEffectView). The painter emits a `kind = 5` quad (tint
in the color, style in `u`) and `DisplayList` notes its index and blur
sigma (`min(max(min(w,h)·0.035, 1), 10)` pixels; Clear: 1 pixel).
`Metal::present` then draws in passes: the quads before the glass, a
blit of the drawable into a snapshot texture (`framebufferOnly` is off
for this), two fullscreen passes of a 1-D Gaussian (horizontal into a
half-size texture, vertical into the backdrop; `blur_vertex` is a
fullscreen triangle), and the quads from the glass on, with the backdrop
as texture 2 and the render pass's load action switched to Load. Frames
without glass take the single pass they always did.

The fragment (`glass()` in the shader) is mygo's effect: `t`, the
distance inside the edge; within the bezel (36 points, at most half the
pane) the sample point moves toward the middle by
`refraction·glassLens(t)` along the smoothed edge normal (`glassNormal`,
corners half again as round), mirroring what is just inside the bezel;
the sample is toned by its luminance (`glassTone`: black and white map
to 54 % and 100 % in light mode, 15 % and 51 % in dark, Clear adds an
eighth, saturation kept or raised), tinted by the tint's alpha (× 0.88),
and lit along a one-pixel rim by how much the edge faces up or down;
coverage is the rounded rect's own SDF. Interactive glass grows 1.1 × 0.45
points while pressed. The theme's darkness reaches the shader through
`Uniforms.dark`. Nodes overlap through `->at(x, y)`, which takes a node
out of the flow and places it from its parent's top left, painted in
child order.

`UI\Blur` and `UI\ScrollEdge` ride the same backdrop (kinds 7 and 8, with
kind 6 a vertical alpha gradient): a blur quad samples the backdrop at
the pixel, mixes a color in by its alpha and shows by a weight that runs
from `u` at the quad's top to `v` at its bottom — a fading Blur mixes the
blurred and the sharp content, where mygo stacks blur levels, which reads
a little truer. The hard scroll edge saturates the sample by 1.25 and
lifts it 0.03 in the shader, under the theme's background toned (0.947
saturation, +0.03) at 82.45 %, with a half-point hairline along the other
edge; the soft one is only the background's gradient, 85 % to nothing, as
measured from macOS 27 by mygo.

## Text path

`Native\CoreText::shape` builds a CFAttributedString with the system UI
font and a CTLine, then reads each run's glyph ids, positions and the font
CoreText actually chose (fallback fonts included, so CJK works without any
font list of ours). `rasterize` draws one glyph with `CTFontDrawGlyphs` into
an alpha-only CGBitmapContext at the backing scale and flips the rows.
`UI\Atlas` shelf-packs those into a 2048² R8 image and remembers the
rows it changed (`dirtyFrom`, `dirtyTo`); `Native\Metal::upload` sends
those rows only (`replaceRegion:` from a row offset into the pixels), so
one new glyph costs a shelf's rows, not the 4 MB image.
`UI\Text` caches a shaped line as a list of glyph quads in points,
snapped to the device-pixel grid; `measure()` feeds `Context::text`, and
`paint()` appends `kind = 1` quads to the display list. The Metal fragment
shader samples the atlas for those and runs the rounded-rect SDF for the
rest, in one pipeline and one draw per frame.

Styles: `UI\Font` bits (BOLD, ITALIC, MONO) reach `CoreText::font`, which
takes the system or fixed-pitch UI font and applies the bold/italic
symbolic traits with `CTFontCreateCopyWithSymbolicTraits` (a font without
the variant keeps the base); fonts and shaped lines are keyed by size and
style. `Context::rich` lays spans (`UI\Span`: text, size, style, color,
underline, strikethrough, background) on one baseline, the tallest
ascent setting it; the painter draws each span's background, glyphs and
lines. With a `$width`, `RichLayout::wrap` breaks the spans into lines:
chunks are runs of spaces, single CJK characters or other runs, placed
greedily, a chunk wider than a line broken by character, newlines
honored; adjacent chunks of one span on a line merge into one piece so
they shape as one run. The width is the app's to pass (`$c->width` minus
its paddings), or, with `wrap: true`, the room its container had last
frame inside the container's padding (`Runtime::widthOf` of the parent,
the window's width on the first frame): the flex layout measures before
it arranges, so a node cannot know its width while it is built, and the
frame-old width is the immediate-mode answer; `Runtime::record` asks for
another frame when this frame's room differs, so a resize reflows one
frame late. The container's room, not the node's own width, is what to
wrap at: a node's width follows its lines, so wrapping at it never
narrows. A table cell's text wraps at the cell this way; `Node::min(w,
h)` is mygo's MinWidth/MinHeight, and a table row is at least Space(8)
high and as high as its tallest cell.

Buttons follow mygo's styleButton: `button()` is plain — a surface face
with a one-point border (`Node::border()`), the text color, shaded on
hover and press — and `primaryButton()` is the accent without a border,
for the main action; both pad Space(1.5) by Space(3.5).

`toggle(&$on, $label)` is a button kept pressed (`surfacePressed`) while
on, mygo's Toggle; `toolbar($fn)` is mygo's Toolbar: a centered row, gap
and padding Space(1), whose buttons and toggles take no face until
hovered (padding Space(1.5) by Space(2.5), no border). Controls that do
not fit the toolbar's last-frame width go, from the last, into a menu
under a » button at its end (mygo's layoutToolbar: the controls are
built and measured, then dropped from the row); choosing one calls
`Runtime::press($id)`, which makes `clicked()` report it in the next
frame's build, so the control's own code runs.

`Node::focusGroup($orientation, $selects)` is mygo's FocusGroup:
`Runtime::record` notes each focusable's group (groups inside a group
are part of it) and the member that is on; `moveFocus` treats a group
as one stop of Tab at its first member's place and enters it at the
member focused last, else the one on, else the first; inside, the arrows
the orientation names move among the members round, Home and End to the
ends, and with `$selects` the move activates the member, so a segmented
control or a tab list chooses as a radio group does. Toolbars are
horizontal groups; segmented controls and tabs select.

Every control's numbers are mygo's (AGENTS.md rule 9); `Node::padding(y,
x)` is mygo's Padding(y, x) where the two differ (`row()`/`column()`'s
one value is both). Fields pad Space(1.5) by Space(2.5) on the surface
(`Runtime::FIELD_INSET`, `FIELD_INSET_Y`); checkbox and radio are a
Space(4) box or disc with a Space(0.5) check or Space(1.5) dot; a
switch is Space(9) by Space(5) with a Space(0.5) inset knob; tabs pad
Space(2) by Space(3), Space(1) apart, underlined Space(0.5) high and
Space(1.5) in; a select is a plain button at least Space(35) wide with
mygo's chevron (a Space(2.5) box stroked 1.5); a tooltip pads Space(1.25)
by Space(2), radius Space(1.25), in the inverse colors, Space(1.5) above
its anchor (below when there is no room); the focus ring is 2 points
wide a point outside the node, as `Painter.FocusRing`.

`Node::single()` is mygo's SingleLine: in a row, single-line texts shrink
between them by what the row's children overflow (mygo's flex shrink);
in a column one is clamped to the column's width; and the painter cuts
what no longer fits at `indexForX` and ends it in "…". Sidebar items,
collapsible and tree labels are single; apps mark list titles and table
cells.

Controls write straight into the app's variables, by reference:
`checkbox($label, &$bool)`, `radio($label, &$value, $option)`,
`switch($label, &$bool)`, `slider(&$value, $min, $max)`,
`select($options, &$index)`, `stepper(&$value, $min, $max, $step)` (a
press steps once and keeps stepping while held, as AppKit's; `Runtime::stepper`
gives the frame's steps from the hold and the arrow keys),
`segmented($options, &$index)`, `tabs($labels, &$index)`,
`collapsible($title, &$open)` (returns $open; build the body in an if);
`Context::changed($node)` says whether this frame changed it (mygo's
Changed). The toggles are focusable nodes whose click flips the value;
the slider is set from `Runtime`, which records presses and drags inside
slider rects as a share of their width (`slid`) and Left/Right on a
focused slider as steps (`stepped`); the select pops an `NSMenu` under
the node's last-frame rect (`Menu::popupAt`); the popup runs its own loop
but AppKit sends the chosen item's action on a later run-loop turn, after
the popup returned, so the action records the choice in `Runtime`
(`chose`) and asks for a frame, and the next build's `select()` takes it
(`chosen`).

`link()` is accent text with an underline whose click calls
`Runtime::$openURL` (App::openURL → `NSWorkspace openURL:`); while it is
hovered `Runtime::$cursor` is 'pointer' and `Window::showCursor` sets
`NSCursor pointingHandCursor`. `progress()` fills a share of its width,
or, below zero, sweeps a third of the bar across every 1.5 s, asking for
a frame each time (`Runtime::$animating`). `->tooltip()` on any node:
`Runtime` notes when the pointer last moved, and once it rested on a node
with a tip for `TIP_DELAY` (0.6 s) sets `$tip`, which `Painter::tooltip`
draws last, below-right of the pointer and inside the root; the frame
that will show it is scheduled through `nextWake()`, which also carries
the caret blink.

Any node can take the keyboard focus with `->focusable()`: `Runtime`
lists it with the buttons and fields (Tab order, the innermost under a
press), marks it focused in `record`, and Enter or Space on it counts as
a click, as for a button. The ring shows only while the focus came from
the keyboard (mygo's `focusVisible`: Tab, the arrows, Enter, Space set
it; a press that moves the focus clears it), so a click leaves no ring
but Tabbing to a control shows one; `Node::$focusVisible` is what the
painter rings by, while fields keep their focused look either way.

`Context::modal(&$open, $fn)` is mygo's Modal: an absolute column over
the window, black at 0.4, centering a panel `$fn` fills; it is a
blocking node (`Node::block()`): `Runtime::record` numbers nodes in
order and remembers the number of the last blocker, and `hovered`,
`clicked`, `doubleClicked`, a press, the wheel, a thumb grab, a drag and
Tab reach only nodes numbered from the blocker on — what was built
before it is inert, as mygo's overlays make it, and a focus left behind
is dropped so Tab goes into the modal. Escape or a click on the backdrop
outside the panel sets `$open` false (`changed()`). `Node::shadow()` is a
soft shadow under a colored container, which the panel and toasts use.

`Context::sidebar(&$selected, $fn, $width)` is mygo's Sidebar: a
focusable scrolling surface column (its `ScrollState` and the item ids
it built last frame live in `Runtime::local`, state a composite keeps by
its node's id, as mygo's local state); `sidebarSection($title, &$open,
$fn)` is a muted bold title whose click shows and hides its items, with
the arrow showing under the pointer; `sidebarItem($id, $label, $icon,
$trailing)` is a keyed row, the accent while chosen in a focused sidebar
and `surfacePressed` when chosen without the focus, as AppKit's; a click
chooses it and focuses the sidebar, and Up, Down, Home and End choose
among last frame's items while it has the focus. Not ported: typing the
first letters of an item.

`Context::tree($fn)` and `treeItem($label, &$open, $children, $selected)`
are mygo's Tree: items are focusable rows Space(7) high, indented
Space(1 + 4·depth), with an arrow box Space(4) square whose click opens
or closes a branch (the click is taken there, so `clicked()` on the item
reports a click elsewhere on it); last frame's item ids and parents
live in `Runtime::local`, and the focused item moves the focus with Up
and Down, opens with Right (or goes to its first child), closes with
Left (or goes to its parent). `$selected` paints it the accent.

`meter($value, $lo, $hi, $warning, $critical)` is mygo's Meter: a
PROGRESS node Space(2) thick (the painter takes the thickness from the
node's own height now) filled with the success color, or warning and
danger past the levels, low ones bad when critical is below warning.
`numberInput(&$value, $lo, $hi, $step)` is mygo's NumberInput: a
Space(20) field over a `TextState` in `Runtime::local`, − and + beside
it; typing applies once numeric and in range, Up and Down step while it
has the focus (a focused single-line field leaves Up and Down to
`shortcut`, a text area only Escape), and the text shows the value
rounded to the step's decimals once the focus leaves.

`Context::popover($anchor, &$open, $fn)` is mygo's Popover: a bordered
panel built at the window's root (the stack is swapped for the root
while it builds) and placed below the anchor's last-frame rect, or above
it when the room below is short of the panel's last-frame height; a
press outside it and the anchor (`Runtime::pressed`, from the frame's
mouse-downs) or Escape closes it, and the press goes on to what is under
it — it does not block. `menuButton($label, $items)` drops an `NSMenu`
of closures under a plain button, on a click or Down while focused.
`searchField($state)` is an `input` with `Node::inset()` keeping room at
its ends for a glass and, while it holds text, a clear button, both
absolute texts over it; Escape clears it while it has the focus, which a
field now keeps on Escape (a control lets it go).

`Context::toast($message)` is mygo's Toast: `Runtime` keeps the newest
three with the seconds each has left; `Window` calls `Context::toasts()`
after the view, which ages them (their time stops while the pointer
rests on one), drops the spent and builds the rest at the bottom of the
window; `nextWake` wakes the window when the next one goes.

`Context::table($state, $columns, $count, $cell)` is mygo's Table's core: a
header row of `TableColumn`s (title, width or a share of the room, min
width, alignment, sortable) over a `list()` whose rows are Space(8) high
and hold one padded cell a column, keyed by column id; a click on a
sortable header sets `ListState::$sort` ([id, descending], `sortBy`
reverses on a second click, `sortChanged()` reports it) and the app sorts
its rows; an invisible grip at each header's right edge takes
`Runtime::dragged` and keeps the width in `ListState::$columnWidths`.
Not ported: moving columns, fitting one to its cells, section headers,
the header's context menu, sideways scrolling of a table wider than its
room.

`Context::split(&$size, $first, $second, $vertical)` is mygo's Split: two
panes and a one-point line between them, with an invisible handle a few
points wide placed absolutely over the line; `Runtime::dragged($id)`
(mygo's Dragged) reports how far the pointer moved since last asked
while the mouse is down from a press on that node, and the split moves
the line by it, clamped Space(10) from either edge; the line is
focusable and the arrows move it. `Node::cursor()` names the pointer
to show over a node (resize arrows here; links and fields set theirs).

`Context::shortcut($key, $mods)` is mygo's window-level Shortcut: the
view pushes every `keyDown:` as a `key` event (characters, key code,
modifiers) before `interpretKeyEvents:` turns it into a `command` or
`insert`; `Runtime` keeps the frame's keys and marks one taken when a
focused control handled what followed it (a field's typing and moves, a
button's Enter and Space, a list's arrows), so `shortcut` reports only
the rest. A shortcut that fires takes its key (one taker, as a click
has) and counts as consumed input, so the frame builds again and what
the key changed shows at once, not on the next event. `Key` holds the codes of the keys without characters and the
modifier masks.

Images ride the same draw: `ImageAtlas` packs them into pages, each an
RGBA `Atlas` the glyph atlas's size (so the shader divides by one size)
and a slice of a `texture2d_array` at texture 1 (`ImageAtlas::MAX`
slices allocated up front; a page's pixels are allocated when it opens
and uploaded with `replaceRegion:…slice:…` while dirty). A `kind = 2`
quad carries its slot origin in `u, v`, the slot's pixel size in the
color's `r, g` and the page in `b` (the vertex shader scales the uv by
the slot size instead of the quad size, so an image stretches to its
node). The fragment un-premultiplies what CG produced, applies the
quad's alpha and the same SDF for rounded corners. `Native\ImageIO`
decodes anything ImageIO reads (PNG, JPEG, HEIC, ICNS…) through a
CGBitmapContext, scaled down to fit `$max` pixels on a side; `UI\Image`
keeps the pixels until the first paint puts them in a page, then frees
them. An image goes on the first page with room, a new page opens while
there are fewer than MAX (an image up to a page's size has one to
itself), and past that the page drawn from least recently is emptied, so
a grid of thumbnails and a full-size photo stop evicting each other.

The glyph atlas may fill when an app has shown more distinct glyphs
than fit, and the image pages when more images than fit were drawn.
The glyph atlas starts over (`Atlas::reset`) at the first bitmap that
does not fit, an image page is emptied, and either sets `Text::$repaint`; `Window` then clears the display list and paints the
frame again, so the quads painted before the reset, which name slots that
are gone, never reach the GPU. `Image` keeps its pixels for this. A change
of backing scale — a window dragged to another display — arrives as
`windowDidChangeBackingProperties:`; `App::rescale()` picks the largest
scale among the open windows, `Text::rescale` resets the glyph atlas and
the line cache at it, and `Uniforms` carry two scales: `scale`, the drawn
view's pixels per point (clip rects, set per frame from its window), and
`atlasScale`, what the glyphs were rasterized at (uv extents); a window on
a lesser display samples the sharper glyphs down.

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

`Layout` places children across a container's axis as mygo does: a row
centers them vertically, a column stretches them to its width;
`Node::align()` changes that for a container's children (START, CENTER,
END, STRETCH), `alignSelf()` for one child, `justify()` packs them along
the axis when none grows, and `center()` does both. A child with no size
of its own across (a text area given 0) takes the room.

A `ScrollState` container scrolls on the axes `Node::scroll()` names:
`Layout::measure` gives it 2×pad on a scrolled axis (it takes the room it
is given) and its content's size on the other; `arrange` computes the
content size on both axes (main: the sum plus gaps; cross: the largest
child), sets `maxX`/`maxY` and the viewports, offsets the children, and
lets children keep their own size across a scrolled cross axis (a row
scrolling vertically gives its children the content height). `Runtime`
routes the wheel's dx and dy to the axes the container scrolls, and
thumbs (`Scrollbar::thumb` with `$horizontal`) sit along the right and
bottom edges, each draggable.

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

Each row is built under a key scope (`/L<list id>/<row>`), so the nodes of
row 7 keep their ids whatever the anchor is: a Reply button clicked while
a glide or momentum scroll moves the rows still lands on its own row. The
same mechanism serves `Context::key()`: inside a keyed container ids are
`xxh64` of the key path plus the node's order within it, unkeyed nodes
keep creation order, and the two never collide in practice (small ints
against 64-bit hashes).

With `ListState::$selected` set (−1 for none), a click on a row's first
node chooses it (`choose()` also reveals it), the list joins the focus
order so Up/Down/Page/Home/End move the choice through
`doCommandBySelector:` (`moveUp:` and friends), Enter and a double click
(`clickCount` from the press event) set `submitted()`. The chosen row is
painted with the selection color over its background. Scroll bars draw
only while the container is hovered or its thumb dragged (as mygo); the
thumb is the view's share of the content, estimated for lists from the
average row height, and dragging it scrolls by `range / travel` points per
pointer point. `scrollTo` on either state glides with an exponential ease
(`Glide::TAU` = 80 ms, frame-time based, stops when within half a point or
when the end pulls back); a list more than four views away first jumps to
three views short so a million-row jump does not walk every row. Trackpad
momentum is the system's: AppKit keeps sending `scrollWheel:` events after
the fingers lift, so the list needs no inertia of its own. `followEnd`
(mygo's FollowEnd): Layout notes each frame whether the last row's bottom
is in view (`atEnd`); when it was and the count grew, `Context::list` calls
`end()` before building, so appended rows keep the end in view, and the
user scrolling up (atEnd false) stops that until they return to the end.

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
or a generator (below). `TypeScript::generate` reflects the services: PHP
types map to TS (int/float → number, class → interface of its public
properties, PHPDoc `Note[]`/`list<T>`/`array<K,V>` for arrays), docblocks
become TSDoc, declared events get typed listeners.

Channels follow mygo's wire format. The page makes `new myphp.Channel()`
and passes it as an argument; `call()` numbers it (`toJSON` gives the
number) and `Ipc::handle` turns the number back into a `Channel` for each
parameter typed `MyPHP\Channel`. `send()` delivers `{t:"chan", c, k, p}`
at once, `close()` `{..., end: true}`, and the page's `chan-close`
(from `close()` or breaking out of `for await`) marks the channel closed
so the next `send()` throws `ChannelClosed`; a new page (`dom-ready`) or
the window closing closes them all. `chan-ack` is accepted and ignored:
mygo's back-pressure makes the sender wait, which the main thread cannot.
A method returning a `Generator` is pumped by `Ipc::pump`: one yield per
turn of the run loop through `App::later` (`performSelector:...afterDelay:
0` on a helper object, batching what was queued), each yield sent on the
first channel parameter, a closed channel thrown into the generator at its
yield, the generator's return value as the reply. Between yields the
window handles events, so a long stream does not freeze the UI the way a
plain method does. `TypeScript` writes `Channel<T>` from a
`@param Channel<T>` docblock and types a generator method's result from
`@return` (void without one).

`window.myphp.window` (minimize, maximize, unmaximize, toggleMaximize,
isMaximized, toggleFullScreen, close, setTitle) are calls to
`myphp:window.<Name>` that `Ipc::handle` routes to the window the message
came from, mygo's names; macOS's maximize is `zoom:`.
`WindowOptions::$preload` is a second WKUserScript at document start,
after the bridge, so it sees `window.myphp` and the page's scripts see
what it defines.

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
Dialogs use NSOpenPanel/NSSavePanel/NSAlert: `runModal` without a parent,
else `beginSheetModalForWindow:completionHandler:` with a block, and
`App::wait` spins the run loop until the block has delivered the
response, so the PHP call stays synchronous either way.

## Blocks

An Objective-C block is a struct the caller lays out: `{isa, flags,
reserved, invoke, descriptor}` with a descriptor `{reserved, size,
signature}`. `ObjC::block` fills one with `isa = &_NSConcreteGlobalBlock`
(dlsym from libSystem), `flags = BLOCK_IS_GLOBAL | BLOCK_HAS_SIGNATURE`,
`invoke` = a PHP closure through the usual holder struct (its first
argument is the block, dropped before the PHP closure sees the rest), and
the type encoding built from the signature letters. A global block is
never copied or freed by the runtime, so the CData behind it can simply be
kept; a `once` block is dropped at the next `block()` call after it fired,
never from inside its own invocation. Verified on Linux with a C shim
calling `invoke` directly before use on macOS.

## Text input

`Native\View::keyDown:` records the key for apps and hands the event to
`interpretKeyEvents:`. The input context answers through the
NSTextInputClient methods the view implements over `Input::$focus`, the
`TextState` of the field Runtime focused last frame: `insertText:` commits
text (with a replacement range when the input method asks),
`setMarkedText:` holds the composition in `$marked` (shown at the cursor,
underlined, not yet in `$text`), `unmarkText` commits it, and
`doCommandBySelector:` gets the key bindings as selectors
(`moveLeft:`, `deleteBackward:`, `insertNewline:`...), which
`TextState::command` applies; the rest (`insertTab:`, `cancelOperation:`)
go to Runtime for focus. The client's ranges are UTF-16 while TextState
uses bytes; `TextState::utf16Index/byteIndex` convert. Caret geometry
comes from CoreText (`CTRunGetStringIndices` with glyph positions give a
caret x per UTF-16 index), so `firstRectForCharacterRange:` can place the
candidate window, converted view -> window -> screen. The Edit menu's
`copy:`/`cut:`/`paste:`/`selectAll:` reach the view as first responder
and use `Native\Pasteboard`.

Two things the methods alone did not give: AppKit creates a view's
`inputContext` only when the class *declares* the protocol
(`class_addProtocol`, now `defineClass`'s fourth argument), and without
one `interpretKeyEvents:` still routes special keys to
`doCommandBySelector:` but sends characters to NSResponder's one-argument
`insertText:`, so letters vanished while Enter worked; and a function
declared to return `const char*` (`sel_getName`) already arrives as a PHP
string, not a CData. PHP callbacks returning structs by value
(NSRange, NSRect) work through libffi, verified with a C shim first.

### Text areas

`Context::textarea` edits the same `TextState` with `$multiline` set.
`TextLayout::wrap` breaks `display()` into byte ranges: paragraphs at
`\n` (the newline belongs to the line it ends), then greedy lines found
by binary search over `xForIndex` of the whole paragraph (one shaped
line per paragraph, cached by `Text`), backed off to the last space, or
between characters where there is none, so CJK wraps too. The layout is
cached on the state while text, size and width stay. `TextState::BY_LINE`
lists the commands `TextState::command` declines for a text area
(`moveUp:`, line ends, `insertNewline:`, paging) so they reach
`Runtime::areaCommand`, which answers from the layout: up and down keep
the caret's x and hit-test the neighbouring line. The painter keeps
`scrollY` so the caret's line is in view, paints only the lines that
show, and the selection per line (a stub past a line's end stands for its
newline). `Input::caretAt`, set by Runtime for a focused area, gives the
input method the caret rectangle by line. The wheel over an area moves
`scrollY` (Runtime lists areas with the scroll containers), the painter
clamps it and brings the caret into view only when the caret moved
(`caretSeen`), so scrolling away from the caret sticks; `scrollFraction`
is how far through the text the area is, for a preview that follows it.
A width or height of 0 makes the area grow into its container's room.

Undo lives in `TextState`: `insert()` and `setMarked()` snapshot text and
selection before changing them; consecutive typing (or deleting) within a
second is one step, 200 steps deep; `undo:`/`redo:` arrive from the Edit
menu through the view, like `copy:`. The caret blinks from `Runtime`:
it restarts whenever the focused field's text or selection changes, the
painter asks `caretVisible()`, and `Window` schedules the next toggle
with `View::wake` (`performSelector:withObject:afterDelay:`, cancelling
the previous request) — no timer runs while nothing blinks, and after 30
seconds at rest the caret stays on and the frames stop, as in mygo.

## System integration

`Tray` is an `NSStatusItem`: with a menu, `setMenu:` shows it on click;
without one, the item's button gets a PHP target with `sendActionOn:`
left and right mouse-up, and `NSApp currentEvent` tells which. Icons are
PNG bytes through `NSData dataWithBytes:length:` (a `const char*`
argument takes the PHP string directly) into `NSImage`, template or not.

`GlobalShortcut` goes through Carbon's `RegisterEventHotKey` (its own
`FFI::cdef` against Carbon.framework in `Native\Carbon`), as mygo does:
no accessibility permission, and the handler installed with
`InstallEventHandler` runs on the main thread inside NSApplication's
loop, so a PHP closure can be the handler (held in a struct field, as
`defineClass` holds methods). `EventHotKeyID` is passed by value; the
handler reads it back with `GetEventParameter`. Key names map to ANSI
virtual key codes; "Plus" is Shift+=.

`Notification` uses `UNUserNotificationCenter`, which serves only
processes running from a bundle with an identifier — `supported()`
checks `NSBundle mainBundle bundleIdentifier`, so `myphp dev` and the
exec launcher (whose process is the system php) report false and a built
bundle true. Its completion handlers run on a background queue, where
PHP must not run, so they get `ObjC::noopBlock()`: a global block whose
invoke is `getpid`, a C function that ignores its arguments. The first
`show()` asks for permission; the system drops notifications given
before the user answers. `MyPHPNotificationDelegate` (attached in
`applicationDidFinishLaunching:`, before the click that launched the app
is delivered) shows banners while the app is frontmost and reports clicks
to `App::onNotificationClick`, calling the handed-in completion blocks
with `ObjC::callBlock`. Those delegate methods arrive on the main thread.

File drops: `Native\Drop::methods` are the `NSDraggingDestination`
methods both view classes get (`MyPHPView`, and `MyPHPWebView`, a
WKWebView subclass made for this), `Drop::register` the
`registerForDraggedTypes:` with `public.file-url`. `performDragOperation:`
reads the pasteboard's file URLs (`readObjectsForClasses:options:` with
`NSPasteboardURLReadingFileURLsOnlyKey`), converts the drop point into
the view (flipped for the Metal view, from the bottom for the web view)
and hands `Window` a `FileDropEvent` for its 'fileDrop' listeners. On a
page window the drop goes to PHP, not to the page's own drop events, which
could not see paths anyway.

Window events come from `NSWindowDelegate` (one shared delegate, the
window found by the notification's object): `windowShouldClose:` runs the
'close' listeners with a `CloseEvent` whose `cancel()` keeps the window,
the rest map one to one. `WindowOptions::$stateKey` keeps the frame in
`window-state.json` under `App::userDataPath()` (~/Library/Application
Support/<name>): captured on move and resize while not minimized or full
screen, applied with `setFrame:display:` after creation, saved when a
window closes and in `applicationWillTerminate:`.

`Process` runs a child without blocking the UI, which has no threads: a
`proc_open` with non-blocking pipes, polled every 16 ms with
`App::after` (stdin written as the pipe takes it, stdout and stderr read
as they come), and `$done(out, err, code)` once it ends; `kill()` stops
it. Network and other slow work go through it — `examples/http` sends
requests with curl this way and stays live while they are in flight.

## Theme

`UI\Theme` carries mygo's palette (background, surface and its hover and
pressed shades, border, text and muted text, accent with shades and its
text, danger/warning/success, translucent selection and focus, radius,
spacing, font size) with `light()` and `dark()` defaults. `App::theme()`
builds it from `Native\Appearance`: dark when NSApp's
`effectiveAppearance` best-matches DarkAqua, the accent from
`NSColor.controlAccentColor` converted to sRGB under that appearance,
with hover/pressed shades derived by scaling. The app delegate observes
`effectiveAppearance` by KVO and every window re-clears to the new
background and redraws. `App::setTheme` fixes one instead.
`NSArray arrayWithObjects:` is variadic and so off limits to the bridge
(stack-passed on arm64); build arrays with `NSMutableArray addObject:`.

## Packaging

A macOS app bundle runs `Contents/MacOS/<Name>` with no arguments, and a
PHP script needs an interpreter and a path, so the executable has to be
one of two things. For development, `myphp build` compiles a 30-line C
launcher (needs the command line tools) that finds the bundle from its
own path with `_NSGetExecutablePath`, chdirs to `Resources/app` and execs
the php that built it with the same flags `dev` uses; the Dock entry is
the app's, but the process image is Homebrew's php, so `NSBundle
mainBundle` is not the bundle and the TSM/IMK log lines stay. For release,
`--launcher micro` packs `Resources/app` (project plus the framework's
`src`) into a phar whose stub requires the entry, and `spc micro:combine`
appends it to phpmicro, a static PHP with ext-ffi, with `ffi.enable=1`
and `memory_limit` embedded as INI (`-I`): one Mach-O, a proper main
bundle, signable and notarizable. The micro build carries no opcache: in
the micro SAPI opcache's `zend_get_file_handle_timestamp` takes a
`phar://` path as a pointer (SIGBUS at an ASCII address) and its JIT
segfaults on arm64 even on a plain file, so the static launcher runs the
interpreter without JIT; `dev` and the exec launcher keep the JIT flags
on the Homebrew php. Inside the phar, `boot.php` finds
`./myphp` and the autoloader; `bridge.js` is read through the phar
stream; `dlopen` only touches system frameworks, so nothing needs to be
on disk. `DirectoryIterator` returned nothing on the container's mount
during testing while `scandir` worked, so the CLI walks with `scandir`.

## Open items

- The glyph atlas starts over when full and is re-rasterized whole; an
  image page is emptied whole. Eviction per glyph or image would avoid
  the restart, at the cost of free-space bookkeeping the shelves lack.
- A screen of never-seen strings costs a shaping burst (one dropped-frame
  second at 1000 rows); the shaped-line cache is swept by age but the
  burst itself is CoreText's.
- Image decoding (`ImageIO`) runs on the main thread: a 12 MP HEIC takes
  a frame or two; `Process` is the only way off the thread, and it costs
  a child process. Apps decode between frames (`App::later`) meanwhile.
- `textarea` is a plain editor: no Tab indent, line numbers or syntax
  coloring; `rich` is read-only.
- NSTimer is not locked to the display (the CoreGraphics path ran 50–58
  fps with identical PHP time per frame); MTKView pacing is what gives 60.
