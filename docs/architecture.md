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
Measured in `examples/inbox` on an M-series Mac, NSTimer at 60 Hz:

| nodes per frame | build | layout | paint (CoreGraphics, the PoC) | total |
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
- A pointer-typed CData takes no `->cdata`: `$c->new('void*')->cdata =
  $p` throws "Attempt to assign field 'cdata' of non C struct/union"
  (`cdata` is a scalar's). A slot that must hold a pointer by value, as
  an NSInvocation's argument (`CoreImage::run`, v1.31), is an `unsigned
  long` of `ObjC::addr($p)`: the same bytes on arm64.
- There is no pointer arithmetic on CData (`$p + 1` is a TypeError). `Native\Mem`
  works on integer addresses: `addr()` reads a pointer's value through the
  slot trick, `copy()` casts `int` back to `unsigned char*` for `FFI::memcpy`.
- `FFI::new()`, `FFI::cast()` and `FFI::type()` are called on an instance
  (`$ffi->new()`; `FFI::cdef()` with no library for plain C types): their
  static forms are deprecated since PHP 8.3. PHP's default error level
  hides deprecations, so the terminal's first version used them anyway and
  only the developer's PHP showed it (in the terminal, from spawn.php);
  `tests/run.php` now reports every deprecation, warning and notice as a
  failure. `FFI::addr()`, `memcpy()`, `string()`, `sizeof()` and `isNull()`
  stay static.
- `?:` takes `"0"` for false: `mb_chr(0x30) ?: "\u{FFFD}"` drew every zero
  of the terminal as �. A string result that may be `"0"` (a character,
  `substr`, `basename`, `getenv`, a name) is checked with `=== false`,
  `=== ''` or `??` (for null), never `?:`.
- Callbacks run only on the main thread. PHP's engine is not re-entrant
  from another thread: CoreAudio render callbacks, CVDisplayLink and
  dispatch queues must not call into PHP. Use NSTimer / run-loop sources.
- Intel Macs return NSRect through `objc_msgSend_stret`; unsupported for
  now, the bridge refuses to boot there.

## Metal path

`Native\Metal` compiles one MSL library at startup (rect vertex/fragment
pair with a rounded-rect SDF for anti-aliased corners), builds one pipeline
with source-alpha blending, and allocates a ring of four shared
`MTLBuffer`s. `DisplayList` writes 16 floats per quad straight into the
current buffer's `contents` cast to `float[]`; `present` encodes one
instanced triangle-strip draw (4 vertices × n instances) and commits. The
viewport size goes in through `setVertexBytes:` as two floats, in points,
so coordinates are in points on any backing scale. A frame for a new
drawable size presents with the Core Animation transaction, as mygo's
renderer does (`setPresentsWithTransaction:` on the MTKView before it
hands out the drawable, then commit, `waitUntilScheduled`, `present` and
`CATransaction flush`), so the window's new size and the content drawn
for it reach the screen together; other frames present as soon as the
GPU has them. The window draws that frame at once from `windowDidResize:`
(as mygo's `setFrameSize:` asks for it), inside the same step of a live
resize or a zoom's animation, before AppKit commits the step — asked for
later, it would land in the next step's commit and the layer would
stretch the last frame meanwhile.

MTKView never draws by itself (paused, `enableSetNeedsDisplay` off): its
display link and its `setNeedsDisplay:` both draw from the main dispatch
queue, and a frame that waits for a sheet there (`App::wait`, under a
button's click) holds the serial queue the sheet's completion is to run
on. `Metal::requestFrame` instead performs `draw` from the run loop in its
common modes (coalesced): after a pause on its next turn, else at the next
tick of the view's own display link, added to the run loop (v1.30, below);
`windowDidResize:` calls `draw` directly; a frame asked for while one is
drawing (waiting) is drawn after it.

The quad kinds, by `params.y` (less the transform's entry, below): 0 a rounded rect, 1 a glyph, 2 an image,
3 a soft shadow (the rect inset by the blur, feathered over it), 4 a ring
(the band inside the edge), 5 glass, 6 a vertical alpha gradient, 7 a
backdrop blur, 8 the hard scroll edge, 9 a line: a capsule between
opposite corners of its bounding rect, inset by half the thickness,
`u, v` the signs of its direction (`DisplayList::line`), 10 and 11 a
gradient between two colors across or down the quad (the color picker).
`Node::draw`
hands a node's face to a closure given the list, the laid-out node and
the theme, painted over the node's own face and under its children
(mygo's Draw): the search field's magnifying glass is a ring and a line
drawn that way, at mygo's MingCute proportions.

### Transforms and opacity

A node's visual transform is mygo's transform.go: `Transform` composes
translation, scaling, rotation, skewing and, in 3D, `rotationX`,
`rotationY` and `perspective`, flattened into its parent's plane as an
`Affine` (mygo's `scene.Affine`: an affine map, or a projective one, with
`g` and `h`, in perspective); `Node::transform`, `translate`, `scale`,
`rotate` (an icon's artwork turns, any other node turns as a whole),
`skew`, `rotateX`, `rotateY`, `perspective`, around `transformOrigin`
(the box's center by default), and `backfaceHidden`. `Runtime::record`
works out each node's `world`, its transform after its ancestors', and
keeps those of the transformed nodes: the pointer reaches a node through
the inverse of its world (a singular one, as a scale of zero, takes none,
nor does a face turned away), its input is in its layout's coordinates,
and assistive technology and `inView` see its bounds as it shows.
`Context::layoutBounds`, `bounds`, `localToWindow` and `windowToLocal`
are mygo's.

The GPU draws each quad through a map, from a second buffer of eight
floats an entry (`DisplayList::$xforms`, entry 0 the identity): the quad's
kind carries its entry, as kind + 16 × entry, so a quad drawn as laid out
costs no more floats than before. The vertex shader maps the quad's
corners and puts the projective divisor in `w`, so the rounded rect's
distance field, worked out in the quad's own coordinates, is
perspective-correct; a transformed shape's quad grows by a point, for its
smoothed edge. `Painter` sets the node's world before it paints it
(`DisplayList::setWorld`, an entry written once a frame for each map) and
`DisplayList::transform($t, $ox, $oy, $fn)` draws through more, around a
point, for a draw closure (mygo's Painter.Transform). A clip pushed through
a transform is its bounds, as mygo's, and `visible()` is the clip in the
coordinates of what is drawn. `Node::opacity` makes a node and its
children translucent: each quad's alpha times the opacity in effect
(`DisplayList::setOpacity`); a node that is transparent paints nothing.

## Liquid Glass

A material (`UI\Material`, mygo's) fills a container in place of a
background color: `->material()` takes it, shaped by `->round()`, and the
tree painter calls its `paintMaterial()` with a `Painter` over the node's
box; a `MaterialBuilder` builds the material it paints as the node is
built (`Node::material` reaches the node's context), as interactive glass
follows the press. `Plugins\Glass` is mygo's `plugins/glass` (itself
measured from macOS 26's NSGlassEffectView): `Glass`, `Blur` and
`ScrollEdge` paint through the painter's `glass()`, `blurLevel()`,
`hardBlur()` and `fillGradient()`, the renderer's effects, whose shaders
are core's. `Painter::glass` emitsThe painter emits a `kind = 5` quad (tint
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
points while pressed, animated over 150 ms (`Context::animate`). The theme's darkness reaches the shader through
`Uniforms.dark`. Nodes overlap through `->at(x, y)`, which takes a node
out of the flow and places it from its parent's top left, painted in
child order.

`Blur` and `ScrollEdge` ride the same backdrop (kinds 7 and 8). A blur
is mygo's levels: varying along its mask (a `LinearGradient`'s alpha,
at any angle), it paints several quads, each blurring what is drawn
before it (the levels before included) by `√(hi² − lo²)` pixels, the
levels' `hi`s halving from the most down to 2 pixels, so that variances
add up to `hi` where a level shows wholly; a level shows by
`clamp((blur − lo) / (hi − lo))`, the blur wanted where the pixel lies
along the mask's line. That is linear in the quad's points, so the
painter hands the shader five numbers (kind 7: `color.xyz` the line's
`t = z + x·px + y·py`, `u + v·t` the weight); on a square box each level
covers only where its blur is wanted (`Blur::wanted`). Each level splits
the draw and blurs the whole drawable again, so a progressive blur costs
a pass a level (about log₂ of its radius in pixels). The hard scroll edge
saturates the sample by 1.25 and lifts it 0.03 in the shader, under the
theme's background toned (0.947 saturation, +0.03) at 82.45 %, with a
half-point hairline along the other edge; the soft one is only the
background's gradient, 85 % to nothing, as measured from macOS 27 by mygo.

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
style.

Rich text has a text system of its own, mygo's internal/text ported as
`MyPHP\Text`: `System::layout(Params)` lays out a text in a `Style`
(family, size, weight, italics, line height, letter spacing, OpenType
features, language) with `Span`s styling runs of it, wrapped at a width,
cut to `maxLines` with an ellipsis, aligned, right-to-left paragraphs
told by their first strong character; the `Layout` it returns has lines
of positioned glyphs and the caret geometry (carets, hit-testing,
selection boxes, visual carets with an affinity at bidi boundaries and
soft breaks, grapheme and word boundaries). Layouts are cached by their
parameters and swept after 240 frames unused. The `Engine` shapes and
breaks paragraphs: `Native\TextEngine` with CoreText's typesetter
(`CTTypesetterSuggestLineBreak`, `CTTypesetterCreateLine`), fonts by
family list (the generic families, named families by descriptor, a
cascade list for the rest), weights through `NSFont
systemFontOfSize:weight:`, a 14° shear where a family has no italic,
tracking and features as attributes; `StubEngine` (half an em a rune) is
the tests'. Glyphs rasterize into the same atlas, keyed by the CTFont
`CoreText::adopt` registers.

Text styles are mygo's: `Node::fontSize`, `fontWeight`/`bold`, `italic`,
`font`, `textColor`, `lineHeight`/`fixedLineHeight`, `textAlign`,
`underline`/`wavyUnderline`, `decorationColor`/`Thickness`,
`textBackground`, `strikethrough`, `letterSpacing`, `fontFeatures`,
`lang`, `selectionColor` set a `UI\TextStyle` the text inside the node
inherits: `Context::inheritedText` folds those of the nodes being built
over the theme's, and `text()` and `richText()` take it as they are built.
`text()` still measures with `TextMetrics::measure` (size, color, Font
bits, an underline); a style that needs more (`TextStyle::needsLayout`),
`maxLines()` or `noWrap()` makes it a rich text of one span
(`Node::toRich`). `Context::richText` lays its spans (`UI\Span`, mygo's: font,
size, weight, italics, color, underline, wavy underline, strikethrough,
decoration color and thickness, background with a radius, letter
spacing, features, language, inline objects) over the node's style;
`Layout::measure` lays it out once a frame (`Node::textLayout`) and the
painter draws mygo's textLayout: line and span backgrounds, glyphs in
their spans' colors, then underlines and strikethroughs where the fonts
place them (`System::decorate`; an underline skips descenders, v1.32).
`Node::children` on a rich text is mygo's Children on one: the text
built inside it (`Context::within` pushes the node while the closure
runs) continues its paragraph, each node's style a span over the paragraph's
(`Node::inlineSpans`); after arranging, `Layout::placeInline` gives each
the boxes of its words (`frags`), which are its area for the pointer
(`Runtime::under`), its focus ring and its bounds to assistive
technology (`accessInline`). A paragraph wraps at `$width`, or at its
container's last-frame room with `wrap: true`; otherwise at the width
layout gives it, as text does (v1.24, below).
The width is the app's to pass (`$c->width` minus
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
high and as high as its tallest cell. `Node::basis(b)` is mygo's Basis:
along the parent's axis the node counts as $b wide (or high) before the
room left is shared by grow, instead of its content's size — so children
of basis 0 and grow 1 share a row equally whatever they hold, and their
content never widens the parent. Without it the grid view's rows, built
at the window's width the first frame, widened their column for good and
the grid could never narrow (the file manager's grid ran under the
window's right edge).

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

`Node::singleLine()` is mygo's SingleLine: the painter cuts what no longer
fits at `indexForX` and ends it in "…"; in a column one is clamped to
the column's width. A row that overflows shrinks its children as CSS
flexbox and mygo's resolveFlexible do (`Layout::shrink`, every child's
shrink factor 1): each gives up width in proportion to its own, none
below its least width — `Node::$minMw`, the min-content width `measure`
works out: a single-line text's minW (0 unless `min()` set one), a
leaf's own size, a row's children's least widths plus gaps, a column's
widest, zero for what scrolls or wraps across — and what the frozen
ones cannot give the others share, round again. A container shrunk
arranges its children in the width left, which shrinks theirs: a table
cell holding a row of an icon and a single-line name truncates the
name (the file manager's long names ran into the next column before).
Sidebar items, collapsible and tree labels are single; apps mark list
titles and table cells.

Controls write straight into the app's variables, by reference:
`checkbox($label, &$bool)`, `radio($label, &$value, $option)`,
`switch($label, &$bool)`, `slider(&$value, $min, $max)`,
`select($options, &$index)`, `stepper(&$value, $min, $max, $step)` (a
press steps once and keeps stepping while held, as AppKit's; `Runtime::stepper`
gives the frame's steps from the hold and the arrow keys),
`segmented($options, &$index)`, `tabs($labels, &$index)`,
`collapsible($label, &$open, $fn)` (mygo's Collapsible: $fn builds the
panel, which grows into view as the arrow turns);
`Context::changed($node)` says whether this frame changed it (mygo's
Changed). The toggles are focusable nodes whose click flips the value;
the slider is set from `Runtime`, which records presses and drags inside
slider rects as a share of their width (`slid`) and Left/Right on a
focused slider as steps (`stepped`); the select opens a popup of its
options in the window, as mygo's (`selectBase`, v1.19).

`link()` is accent text with an underline whose click calls
`Runtime::$openURL` (Shell::openExternal → `NSWorkspace openURL:`); while it is
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

`meter($value, $lo, $hi, $levels)` is mygo's Meter (`MeterLevels`): a
PROGRESS node Space(2) thick (the painter takes the thickness from the
node's own height now) filled with the success color, or warning and
danger past the levels, low ones bad when critical is below warning.
`numberInput(&$value, $lo, $hi, $step)` is mygo's NumberInput: a
Space(20) field over a `TextState` in `Runtime::local`, − and + beside
it; typing applies once numeric and in range, Up and Down step while it
has the focus (a focused single-line field leaves Up and Down to
`shortcut`, a text area only Escape), and the text shows the value
rounded to the step's decimals once the focus leaves.

`accordion($fn)` / `accordionItem($title, &$open, $fn)` are mygo's
Accordion: a bordered box, headers padded Space(2.5) by Space(3) with
the disclosure arrow pointing down (up while open), a line above all
but the first, the panel padded Space(3) at the sides and bottom; the
box is a vertical focus group, so Up and Down move between headers.
`rating(&$value, $max)` is mygo's Rating: stars fontSize × 1.25,
Space(0.5) apart, a click sets or clears, the arrows and Home and End
move while it has the focus, the pointer previews at 0.6; the star is
the font's ★/☆ glyph rather than mygo's path, there being no polygon
fill. `rangeSlider(&$low, &$high, $lo, $hi, $step)` is mygo's
RangeSlider: Space(5) high, at least Space(25) wide, padded kw/2, two
focusable knob boxes placed over the drawn knobs (`Node::$ownRing`
keeps the painter's generic ring off them; `Painter::knob` draws them,
shared with the slider), a press on the track or a knob dragging the
knob nearest, the arrows moving the one focused by the step (a
hundredth of the range when 0).

`combobox(&$selected, $options)`, `autocomplete($state, $suggestions)`
and `tokenField(&$tokens, $suggestions)` share mygo's `comboboxBase`
(`Context::comboPopup`): a per-field object in `Runtime::local` holds
open, typed, highlight and the values shown, and the options are a
column built at the root under the field's last-frame rect, at most
Space(70) high and scrolling, items padded Space(1.5) by Space(2.5), the
highlight in the accent; typing opens them filtered (`matching`: prefix
matches first, then containing), Down and Up open or move, Enter with a
highlight chooses (`TextState::$submitted` is left for the caller
otherwise), Escape closes, the focus leaving closes. The combobox and
token field are mygo's `field`: a bordered surface row padded Space(1.5)
by Space(2.5), gap Space(1.5), holding an unframed `Node::INPUT` (no
`bordered`, insets of −FIELD_INSET, the text's own height) and, for the
combobox, a Space(2.5) chevron that toggles the options; the row's
`Node::$borderColor` is mygo's `inputBorder` (accent focused, border
mixed a quarter toward the text under the pointer). A click in the text
opening the options selects the choice, so the next key replaces it; a
chosen option becomes the text, selected; text matching no option goes
back to the choice on blur. The autocomplete is a framed `input` whose
suggestions show only while typed and non-empty, less one equal to the
text. The token field's chips are rows padded Space(0.25), Space(2) at
the start and Space(1) at the end (zero-width columns and the gap make
the asymmetry), surfacePressed, radius, holding the text and a
Space(3.5) box with a 1.25-point cross taking the token out; the field
wraps (`Node::wrap($width)`, below), Enter or a comma adds what was
typed, Backspace in an empty input takes out the last token
(`TextState::$leaveEmptyBackspace` leaves the key to `shortcut`).

`Node::wrap($width)` is mygo's Wrap for a row: `Layout::lines` breaks
the children where one would overflow `$width`, the width the row had
last frame (`Runtime::rectOf`); `measure` sizes the row by its lines and
`arrange` lays each line as a row of its own (growers share the line's
slack, the row's alignment applies per line), the lines stacked by the
gap. A width other than the one wrapped at asks for a frame, as rich
text does.

Overlays: a node placed with `at()` directly under the root (a popover,
a menu, the options above) is recorded in `Runtime::$overlays`, and
`Runtime::under` — the one hit test of `clicked`, `pressed`,
`doubleClicked`, `rightClicked`, `hovered`, `dragged`, the press
focusing and the wheel — says no for a point inside an overlay to any
node built before it, so a click on an option does not reach the field
under the popup.

`dateInput(&$date)`, `calendar(&$date)` and `timeInput(&$time)` take a
`DateTimeImmutable` and port mygo's `date.go` and `timeinput.go`,
writing the user's regional formats where mygo asks its i18n package
(`Context::formats`, below: 1/5/2026 or 05/01/2026, the language's
month and weekday names, weeks from the region's first day, the hour
cycle of the region with the language's names of the halves of the day,
before the hours where the language writes them so — 下午3:04). The date
input is a plain button's face (a bordered surface row padded Space(1.5)
by Space(3.5), at least Space(32.5) wide, focusable, shaded as a button
under the pointer) holding the date and a Space(3.5) calendar-page icon
at its ends; a click opens a `popover` holding `calendarGrid`, which
takes the focus as it opens (mygo's AutoFocus) and gives it back to the
button when a day is chosen. The grid is a focusable column, gap
Space(0.5), ringing itself (`ownRing`, `Painter::focusRing` in its
`draw`): a header row (‹ and › plain buttons padded Space(0.5) by
Space(2.5), the month bold and centered in the room between), the
weekday names in Space(8) boxes at fontSize − 2 muted, and six rows of
Space(8) by Space(7) cells at the theme's radius, the chosen day in the
accent, the cursor's in surfaceHover (as is the one under the pointer),
today bordered in the accent, neighbors' days muted. Its keys are
`shortcut`s while it has the focus (the arrows by a day and a week,
Page Up and Down by a month via `addMonths`, which normalizes as Go's
AddDate, Home and End), Enter or Space `Runtime::activated` — the
keyboard half of `clicked`, so a click on a cell is not also a choice
of the cursor. `calendar` is the grid with the date as its cursor
(every move chooses). The time input is a bordered surface row padded
Space(1.5) by Space(2) whose three segments (hours, minutes, AM/PM) are
focusable boxes padded Space(0.5) at radius Space(1), the focused one in
the accent (`ownRing`, no ring) and the row's border the accent while
any is; `:` sits between the first two padded 1 point, a Space(1) box
before the third. Up and Down step the segment focused (the hours round
their half of the day), Left and Right move the focus (`Runtime::focusOn`
with the ring), and digits typed — `Runtime::typed($id)`, what the text
system inserted while a control that is no field had the focus, taken
once — set it as mygo's rules do: a second digit makes two, or starts
anew past the top, and two digits (or one that can't begin two) move
on to the minutes; A or P at the third segment chooses the half.

`colorPicker(&$rgb, &$alpha)` and `colorWell(&$rgb, &$alpha)` port
mygo's `colorpicker.go` on an int rgb and a float alpha, the color kept
as hue, saturation and value in `Runtime::local` (a gray has no hue of
its own; `toHSV`/`fromHSV`/`hexOf`/`parseHex` are mygo's functions).
The picker is a Space(60)-wide column, gap Space(2.5): a Space(40)-high
focusable square (white→hue across, transparent→black down, the choice
ringed white 2 at Space(3.5) and black 0.3 outside, a crosshair cursor;
a press or drag — `dragged` binds the press — sets saturation and value
from the pointer, the arrows by a hundredth), two `channelSlider`s
(`slider` nodes Space(4) high with a `draw` of their own, which the
painter then uses instead of its slider: a track the closure paints in a
clip, a Space(3) white round knob with a 0.3 shadow of blur 3 that grows
a fifth while held, ringed when focused) for the hue (six gradients
between the pure hues) and the opacity (checkers under a gradient of the
color), a row of the color in a Space(7) bordered box over checkers and
an `input` of its hex (a parsable change sets the color, keeping the
text as typed; blur restores the hex), and twelve swatches in two rows
of six (`row`s with grow, Space(5) high, bordered 2 in the text color
when chosen — `Node::border($color, $width)`). The well is a focusable
bordered surface row padded Space(1) around a Space(9) by Space(5) swatch
at radius Space(1) over checkers, opening a `popover` padded to Space(3)
with the picker. Gradients are quad kinds 10 (across) and 11 (down),
`DisplayList::gradient2`: the quad's color at one end, the other's rgb
packed into u (exact in a float's 24-bit mantissa) and its alpha in v.
`checkers` is rects, Space(1.5) or Space(1.25) squares.

`findBar(&$open, $query, $matches, &$current)` is mygo's FindBar: a
keyed surface row padded Space(1.5) by Space(3), gap Space(2), a 1-point
border below (its `draw`), holding mygo's `field` with a magnifier and an
unframed input grown to at most Space(90) (the bar's width last frame
caps it), a status at least Space(18) wide ("3 of 12", "No matches"),
previous and next (`buttonFace` padded Space(1.5) by Space(2) around a
Space(3) chevron stroked 1.5; at half alpha and not focusable without
matches) and Done. Its local state holds `opening`, true as it opens:
the field takes the focus with the query selected. Enter steps forward,
Shift+Enter back (`Runtime::submitMods`, the modifiers of this frame's
Return), as do Cmd+G and Shift+Cmd+G; Escape in the field or Done close
it. Closed, it builds an empty box out of the flow under the same key
(so its state starts over as it opens) and returns null. `buttonFace`
is the plain button's look as a row — bordered surface, radius, shaded
under the pointer and while pressed, focusable — for buttons holding
more than a label (the date input, these).

`form($fn)`, `field($label, $fn, $description, $error)` and
`fieldset($legend, $fn)` are mygo's form.go: a form is a column, gap
Space(3), whose fields are rows (gap Space(3), aligned at the start) of
a right-justified label box and a column of the control and its texts;
the boxes are made as wide as the widest label as the form ends (labels
are measured as built), and each as high as its control was last frame
(mygo levels the label with the control's first line by baselines; this
centers it on the control). Outside a form a field is a column, gap
Space(1.5), the label bold (mygo's weight 500: bold is the one weight
besides regular). The control is the first focusable or focus group
inside; a click on the label focuses it (`focusIn`: in a group, the
member on, else the first), and presses a check box, switch or radio
(`Runtime::press`, mygo's clickLater). The description shows muted at
fontSize − 1, the error in the danger color, which an input then
borders in. A fieldset is a column, gap Space(3), under a bold legend,
Space(3) below what is above it.

`checkboxGroup($label, $fn)`, `breadcrumbs($items, &$chosen)` and
`alertDialog(&$open, $title, $message, ...$buttons)` are the rest of
mygo's feedback.go. The group is a column, gap Space(2): a check box
head (`Node::$mixed` paints a 2-point bar in place of the check while
some but not all are checked) over the boxes $fn builds, padded
Space(6) at the start; `checkbox()` appends a reference to its value to
`Context::$checks` while a group is being built, and the head's click
sets them all. Breadcrumbs are a row, gap Space(0.5), of items padded
Space(0.5) by Space(1.5) at the radius, at least Space(6) wide, marked
`single` so they shrink together with an ellipsis as room runs short
(`Layout` now stops a single-line shrink at the node's min width),
Space(3) chevrons stroked 1.2 between them; the items but the last are
focusable links, muted, surfaceHover under the pointer; the last is
bold (mygo's weight 500). The alert is a `block`ing backdrop at 0.4
holding a Space(75) panel (the window less Space(10) at most) padded
Space(5), gap Space(3), radius Space(2.5), shadow (10, 30, 0.3): the
title bold at fontSize + 1, the message muted, a Space(2) margin and
the buttons justified to the end, gap Space(2), the last primary and
focused as the alert opens; it returns the button's index the frame it
is clicked, `ALERT_DISMISSED` (−2) the frame Escape closes it, −1
otherwise, and a click outside does nothing.

`gridView($s, $n, $minWidth, $height, $item)` is mygo's GridView on a
`GridState` (a `ListState` of rows, `selects`, `selected`, `label`, the
columns): the columns are as many as fit items at least $minWidth wide
in the list node's width last frame (`Context::nextId` gives the id
before the node is built; the window's width the first frame), Space(2)
apart; the rows go through `list`, so only those in view are built, each
a row padded gap/2 above and below with the cells sharing the width
(grow 1, basis 0, as mygo's Grow(1).Basis(0).MinWidth(0); spacers fill
the last row's empty places so its items are as wide) at $height,
padded 2 for a border that shows only chosen (accent 2 on the accent
mixed to a fifth over the background while the grid has the focus, the
border color on surfacePressed otherwise; surfaceHover under the
pointer). The grid is focusable while it selects: a click chooses and
focuses it; the arrows move by one and by a row (Down from a row above
the shorter last lands on the last item), Home and End, Enter and
Space submit (`rows->submitted()`), and letters typed choose the next
item whose label starts with them — `Runtime::typed`, which adds up
within a second and takes Space as a letter then instead of a press
(mygo's typePause). Columns changing keep the first row in view on the
same items; Cmd and Shift choose several (`GridState::$selection`) and
items drag to reorder (`$reorder`). `examples/photos` is on it: only the
cells built want thumbnails. An `icon()` is a box with no face of its own whose draw paints the SVG's
paths at the node's alpha, so `fade()` dims it with the rest of a dragged
cell; it was built with alpha 0 at first, which painted nothing, the
headless paint check now reads the quad. `examples/files` is the file manager on
the grid and the table at once: both states share one `Selection` keyed
by path (`ListState::$key`, `GridState::$key`), so the choice follows
the entries through a sort, a filter by the find bar and the switch of
view; the folder's entries are read once per change (open, sort, query)
and kept, and the chosen ones are found by the choice's keys through an
index by path, not a pass over the entries each frame.

`Context::grid($cols, $gap, $rows)` / `Node::grid()` is mygo's Grid
(grid.go): `Layout::gridPlace` is mygo's gridPlace, placing the children
as CSS does — those given both a column and a row (`Node::columnStart`,
`rowStart`, from 1) first, then those given a row, then the rest in
order with a cursor going row by row past the cells taken, each
spanning `columnSpan` / `rowSpan` cells (negative: to the last line; a
span past the columns set widens the grid). `Track::fixed`, `fr` and
`fit` size the columns and the rows set (`Layout::sizeTracks`, mygo's
sizeTracks with an item's min and max content the one size our nodes
have: fixed tracks their points, fit tracks what the items spanning one
alone ask, then items spanning several fit tracks and no fr spreading
what they lack over them, fr tracks sharing what is left of the room —
or, when the grid's size is not set, as a grid in a row, the largest
share any item asks — and fit tracks sharing it when there are no fr
ones), the rows beyond fitting their content. A child is stretched
across its area, or placed down it as its `alignSelf` says. The color
picker's swatches are such a grid of six fr columns. `justifyItems()` on
the grid and `justifySelf()` on a child place a child in its cell across
(START, CENTER, END; STRETCH the default) as `alignSelf` does down —
mygo's gridJustify; a text counts as having no size of its own, so it
stretches too. Not ported: automatic margins.

Paths and icons follow mygo's path.go and svg.go: `UI\Path` holds
moveTo/lineTo/quadTo/cubeTo/close (and `circle`, four cubic arcs) in
points; `DisplayList::path($path, $rgb, $alpha, $width)` fills it (non-
zero winding) or strokes it $width wide with round joins and caps, drawn
as one glyph quad from a mask `Text::pathQuad` keeps in the glyph atlas
(one past 4095 pixels a side in tiles of 511, v1.68),
keyed by the shape relative to the pixel grid in quarters of a pixel
(a path looks the same wherever it is) and by the stroke's half width;
`Svg\Mask` rasterizes the mask in PHP with the SVG renderer's
rasterizer and stroker (below), so a path looks the same on every
platform and CoreGraphics is not needed. `UI\SVG` is mygo's SVG over
`Svg\Doc`, a port of mygo's internal/svg in `src/UI/Svg/`: `Elem`
parses the XML (XMLReader, non-strict) and cascades the style sheets
(type, class and id selectors, by specificity, then the style
attribute); `Style` computes each element's properties (fill, stroke,
opacities, rules, widths, caps, joins, dashes, color, display,
visibility, paint-order, clip-path, mask, stops; lengths with units and
percentages; colors by name, hex, rgb(), hsl()); `Compiler` turns the
elements into `Node`s — `Shape`s with their paints resolved (`Gradient`
linear or radial, objectBoundingBox or userSpaceOnUse, spread, href
chains), groups, nested svg (clipped to its viewport), use and symbol
(no self-use), `ClipDef`s and `MaskDef`s; `Path` parses path data (M L
H V C S Q T A Z, arcs as cubics, up to the first error) and the basic
shapes, with bounds and `flatten` (lines within a tenth of a pixel);
`Matrix` the transforms; `Stroker` outlines polylines (miter, round,
bevel joins; butt, round, square caps; dashes) as same-winding
polygons; `Rasterizer` is mygo's vec, font-rs's signed-area
accumulation into coverage (non-zero or even-odd); `Renderer` paints
coverage with colors and gradients (a LUT of 256) into `Layer`s of
premultiplied RGBA ints, groups with opacity, clips and masks through
layers of their own. `Doc::pixels($w, $h, $current, $stretch)` fits the
viewBox as preserveAspectRatio says; `rotated()` turns it in a larger
canvas. Text, embedded images, patterns, markers and filters are not
drawn (convert text to paths). `Context::icon($svg, $size, $color)` is
mygo's Icon, a box as high as the font size keeping the SVG's aspect
ratio, painted as one glyph from the coverage of the whole SVG (its
alpha, whatever its colors) that `Text::iconQuad` keeps in the atlas by
SVG, pixel size and turn — `->rotate($degrees)` turns the artwork
around its center in a mask as large as it then covers, to a sixteenth
of a degree — so the GPU draws it in any color without drawing the
shapes again; `Context::image($svg, $w, $h)` shows it in its own colors
(currentColor the text color) as a picture `Text::svgSlot` draws at the
node's pixel size into an `Image` (`Image::fromPixels`) kept in the
image atlas while frames draw it, and forgotten 30 frames after the
last (mygo's pictures). On PHP 8.3 the renderer draws a 24-point icon's
mask at 2× in 0.2 ms, at 96 px in 1 ms, a 256-px picture with a
gradient, a clip and a dashed stroke in ~65 ms — once, then cached.
`Node::fit()` is mygo's Fit for images and SVGs alike —
CONTAIN (the default: letterboxed, keeping the aspect ratio), COVER
(cropped to fill), FILL_BOX (stretched), SCALE_DOWN (its own size, or
contain when larger), NATURAL_SIZE (its own size, centered and cropped) —
`Painter::fitIn` giving the rect shown and the fraction of the picture
in it, which the painter samples from the slot (an SVG is drawn at the
fitted size, then cropped); `->grayscale()` draws an image through quad
kind 12, the image kind whose shader takes the BT.709 luminance, and an
icon in the gray of its color's luminance (`Theme::luminance`).
A bitmap shown at least twice smaller than its pixels is drawn from a
halving of it (`Image::smaller`, mygo's levels: each pixel the average
of the 2 × 2 it covers, made once and kept), so it samples smooth
instead of aliasing; the painter picks the level from how far the part
shown shrinks. `avatar($name, $image)` is mygo's Avatar: 2.25 × fontSize round, the
image cropped to it (fit COVER) or the initials (first letters of the first and
last words) at 0.4 × size, bold, white on the hue of the name's FNV-1a
hash at saturation 0.45 and lightness 0.55 (`Context::hsl`).

`WindowOptions::$titleBarStyle` 'hidden' and 'hiddenInset' are mygo's
TitleBarStyle: the window gets NSWindowStyleMaskFullSizeContentView, a
transparent title bar and a hidden title (hiddenInset also an empty
NSToolbar, which lowers the traffic lights as mygo does).
`Window::titleBar()`, handed to the Context each frame
(`Context::titleBar()`), is mygo's: the title bar's height (the frame
less the contentLayoutRect) and the right edge of the zoom button, zero
in other windows and in full screen. `Node::dragWindow()` is mygo's
DragWindow: the runtime records such handles, and a press on one that
lands on no focusable, slider, scrolling container or link inside it
goes to the window instead of the view — `performWindowDragWithEvent:`
with the window's current event, or on a double click the zoom or
minimize macOS's AppleActionOnDoubleClick asks for (mygo's StartDrag and
TitleBarDoubleClicked). `WindowOptions::$trafficLightPosition` [x, y]
is mygo's TrafficLightPosition: `Window::layoutTrafficLights` puts the
close button's top-left corner there and the other two after it at
AppKit's own gap, in a title-bar container (the button's
superview's superview) as high as the button plus twice y, so the bar
leaves as much room below the lights as above; AppKit lays the bar out
again on a resize (at each step of a zoom's animation), a title change
and an appearance change, so it runs after each: the window delegate
observes the container's `NSViewFrameDidChangeNotification`, besides
`windowDidResize:`, setTitle and themeChanged, and the method sets the
container's frame only when AppKit moved it. `titleBar()` then reports
height = button + 2y and left = x + the zoom button's right edge. The
view answers `mouseDownCanMoveWindow` with NO and `acceptsFirstMouse:`
with YES, as mygo's surface view: a press in the title bar's region over
a control reaches the control, not AppKit's window move and double-click
zoom. `examples/titlebar [style] [x,y]` draws such a bar.

`UI\Selection` is mygo's Selection for a list, table or grid that lets
the user choose several rows (`ListState::$selection`,
`GridState::$selection`): the keys of the items chosen
(`ListState::$key` / `GridState::$key` give a row its key; the index
without), with the pivot Shift extends from and the span it last
extended, as mygo's macOS rule: Shift chooses from the pivot to the row
in place of those it last chose, Cmd-click adds a row or takes it out
(the pivot moves there), a plain click or the keys choose alone, Cmd+A
all; `$selected` stays the row the keys move from. Mouse events carry
the modifier flags, `Runtime::$clickMods` is the modifiers of the click
`clicked()` last took (mygo's ClickModifiers), and the list's keys read
the AppKit selectors with `AndModifySelection:` for Shift and
`selectAll:` for Cmd+A; the grid takes Shift with the arrows and Cmd+A
as shortcuts. A grid cell the keys moved to without choosing rings
itself inset by 3. `examples/inbox` chooses several rows.

`ListState::$reorder` is mygo's Reorder, done inside `Context::list`
(`reorderRows`) with no drag-and-drop framework: a press on a row bound
by `Runtime::dragged` that goes 4 points (mygo's dragStart) drags the
rows chosen when the row is one of them, else that row
(`ListState::draggedRows`), kept in `ListState::$drag`; the rows fade
to 0.4 where they were, a copy of the row pressed (`Context::copy`, new
ids so the originals keep their clicks and hover) rides under the
pointer at 0.75 as an absolute node at the root, with an accent pill of
how many go with it when more than one (fontSize − 2, bold, 10 points
right and 14 above the pointer, as mygo's paintDrag), the drop line — a
2-point accent line with an 8-point dot — shows at the top of the row
the rows would go before (`dropAt`: the first row of last frame whose
middle is below the pointer, or past the last) through
`Node::drawOver`, which paints after the children (mygo's DrawOver),
and within 32 points of the list's edges it scrolls, faster nearer to
them (mygo's dragScroll, `Runtime::animate` keeping the frames coming).
The release calls $reorder with the rows and the row they go before,
unless a single row would land where it is, and is no click.

`GridState::$reorder` does the same for a grid (`reorderItems`, mygo's
GridState.reorder and dropAt): the drop goes before the cell under the
pointer, or after it past its middle, the nearest cell in that row when
between cells, past the last item below them; the line is vertical,
2 points wide, inset 4 at the top and bottom of the cell, in the gap
beside it. `dragGhost` and `dragScroll` are shared with the list.

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

`Context::toast($message)` is mygo's Toast, `addToast(Toast)` with an id
and an action: `Runtime` keeps the toasts with the time each showed;
`Window` calls `Context::toasts()` after the view, which closes the spent
(running their `onClose`) and builds the newest three at the bottom of
the window, unless the view built them with `toastViewportBase`;
`nextWake` wakes the window when the next one goes.

`Context::table($state, $columns, $count, $cell)` is mygo's Table: a
header row of `TableColumn`s (title, width or a share of the room, least
and largest width, alignment, sortable, fixed) over a `list()` whose rows
are Space(8) high and hold one padded cell a column, keyed by column id;
a click on a sortable header sets `ListState::$sort` (a `SortOrder`; a
second click reverses it, `sortChanged()` reports it), and with
`$sortBy` a Cmd- or Shift-click sorts by several; the app sorts its rows.
The end edge of a header resizes its column, a double click there fits
it to its cells, a drag on the header moves it; `ListState::$columns` (a
`TableLayout`) keeps the order and the widths. How, below (v1.14).

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
quad's alpha and the same SDF for rounded corners. `Native\CoreImage`
decodes anything ImageIO reads (PNG, JPEG, HEIC…) off the main thread,
upright and scaled down to fit `$max` pixels on a side (v1.31); `UI\Image`
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

Measured with text (`examples/inbox 1000`, 9 006 nodes, ~1 200 quads of
which ~1 000 glyphs): 4.9–5.5 ms per frame at 60 fps; one 23 fps second
when a whole screen of new strings is shaped and rasterized at once.

## Input path

`Native\View` subclasses MTKView; `mouseDown:` and friends, `scrollWheel:`
and `keyDown:` are PHP closures that convert the event point into the
flipped view's coordinates, append to `UI\Input` and call
`Metal::requestFrame`, so a frame happens only after an event or an
explicit request.
`UI\Runtime::frame` drains the events, runs the build (again if a click
changed state, at most three times), and records every node's rect by id
for the next frame's hit tests. Wheel deltas go to the smallest scrolling
container under the cursor; `Layout` clamps the offset and shifts children;
paint pushes the container's rect as a clip, which travels with each quad
into the shader (`float4 clip`, discard outside). An NSTrackingArea with
`mouseMoved` keeps hover state current.

A node that takes its input as it comes (`Node::handleInput`, mygo's
HandleInput: the code editor) gets it as it arrives, not at the next
frame: `Input::push` hands each event to `Runtime::deliver` first, which
turns it into an `InputEvent` relative to the node's last-frame rect and
gives it to the node under the pointer, the one a press went to (its
moves and release follow it wherever they are) or the one with the
focus (keys and Edit menu commands); only what the node leaves is queued
for the frame. The order of keys and of the text the input methods
commit through the node's `TextInputClient` (`Node::handleTextInput`)
is so kept: a key the node takes never reaches `interpretKeyEvents`, and
with `Node::inputMethod(false)` a key's characters go to the client as
typed. A press first focuses the innermost focusable node under it, as
mygo's does, unless it lands on a node that keeps the focus
(`Node::keepFocus`: a popup's rows). The runtime's clipboard
(`writeClipboard`, `readClipboard`) is the pasteboard in a window and its
own in tests; `after()` and `animationFrame()` ask for a frame later or
at once from a build or a paint (mygo's After and AnimationFrame).

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

## Scrolling into view

`Node::scrollIntoView()` is mygo's ScrollIntoView: the node's id goes in
the Context's `reveal`, and `Layout::layout` (the measure and arrange of
a frame, which the window and the headless runner call) scrolls the
`ScrollState` containers around each node asked for, innermost first, as
little as shows its box (`nearest`: what is larger than the viewport shows
from its start), arranging each container again at its new offset before
looking at the next one out, so a node added in this frame comes into
view in it. The runtime asks the same of the nodes Tab, a focus group's
keys and assistive technology bring the focus to (`Runtime::reveal`, the
ids taken by `takeReveals`), and the ids a pass asked for are kept for
the passes after it in the frame, which may not ask again. The rows of a
`ListState` scroll into view by their place (`ListState::scrollIntoView`),
as do a `GridState`'s items.

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
a glide or momentum scroll moves the rows still lands on its own row.
(Since v1.53 a row's nodes are numbered under its key's id, a node's id
its parent's mixed with its place, as mygo's: see that version.)

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

A window's page, or a web view's, is a `Page` (mygo's), which owns the
`Native\WebView`, its secret, the context of its document's calls, its
channels and its events. `Native\WebView` builds a WKWebViewConfiguration
with a content controller of its own (also for window.open()'s, whose
configuration WebKit hands over: messages and scripts never leak between
pages), its user scripts (`bridge.js` with the page's config spliced in —
platform, window, version, secret, locale, dir — the preferences as an
event, the title bar's room for a hidden title bar, the preload script,
all at document start, replaced together as they change) and a script
message handler named `myphp`. The page's
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
so the next `send()` throws `ChannelClosed`; a new document (the
navigation's commit) or the page going closes them all. Events sent
before the DOM is ready wait for it (`Page::event`, at most 1024). Flow control is mygo's: a channel
counts the bytes of the messages it sent, asks the page to acknowledge
one every half of a 1 MiB window (`"a":1`), and the page posts
`chan-ack` with the value's number once it takes it (from the iterator or
`onmessage`), not when it arrives. `send()` never waits, as mygo's on the
main thread doesn't; a generator does: `Ipc::pump` holds its next yield
while the channel is a window ahead (`Channel::full`) and goes on when an
acknowledgment makes room (`whenRoom`). A generator method without a
`Channel` may only yield `Channel::wait()`, for a method whose reply comes
later, as one of mygo's that blocks on its goroutine.
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
`PageOptions::$preloadScript` is a later WKUserScript at document start,
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

Context menus and menu buttons of the native UI are mygo's (menu.go,
`RuntimeMenu`): `Node::contextMenu($build)` and `Node::menu($build)` hand
their function to `Runtime::buildMenu` as the frame builds them. The
secondary button (or a Control-click, which the view turns into one until
its release, as mygo's ctrlClick) over a node with a context menu, a text
input or selectable text, the menu key or Shift+F10 on the focus, or a
menu button going down (or Enter, Space, Down, assistive technology's
press) asks the next frame for that node's menu: its function runs
collecting the items (`UI\Menu`, `UI\MenuItem`, a `MenuRun`), the runtime
keeps them as `MyPHP\MenuItem`s, and once the frame is done
`Runtime::$popupMenu` shows them; the window's pops an NSMenu at the point
after the frame (`Menu::popupAt` from `App::later`), each item
reporting its place. The choice reaches the function in the next frame,
where `chosen()` finds the item by its place and label, and the frame is
built again. A text input's or selectable text's menu, when no context
menu built one, is its editing commands (`editCommands`: Copy and Select
All, or Cut, Copy, Paste and Select All), which do their command without a
frame's function; a Selectable container's context menu replaces its
text's, and `editItems()` puts those items in it.

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

## Selecting text

`Node::selectable()` is mygo's Selectable: on a container, the text built
inside it shares one selection (`TextSelection`, mygo's textSelection),
from a rune of one paragraph to a rune of another in build order; on a
text alone the selection stays in its paragraph. `Context::add` notes
the container a text is in as it is built (`Node::$scope`; a control, an
Unselectable or a disabled node in between keeps it out), and such text
is laid out by the text system to find its runes. `RuntimeSelection`
(mygo's textselection.go) collects the frame's paragraphs in build order
as it records them, presses start a selection on them or in their
container around them (not on a control, nor on Unselectable nodes), a
double or triple click goes by words and lines, a drag near a scrolling
container's edge scrolls it, Shift with the arrows, Home and End extends
it across paragraphs (the keys without Shift are left to scroll), Cmd+A
and Cmd+C or the menus' Select All and Copy (the view's `copy:` and
`selectAll:` with no field focused push `['edit', …]`) select and copy,
the parts joined by newlines, or `copyData()`'s data through
`Runtime::$writeClipboardData` (`Pasteboard::writeData`, mygo's darwin
WriteData: one NSPasteboardItem an item, a provider giving its formats).
`selectedTexts()` gives the parts as `SelectedText`s. Each frame
(`syncTextSelection`) the endpoints must still be in the container's
paragraphs, and the text it holds may only have grown at its end, or the
selection clears; the painter highlights each paragraph's part, over the
backgrounds and under the glyphs, while the window is key (the window
sets `Runtime::$windowFocus`) and the focus is in that container. The
text inside a paragraph shares the paragraph's selection. A press on
nothing focusable takes the focus from selectable text, as mygo's
pointerDown does; elsewhere myphp keeps the focus there was.

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
With `$minLines` and `$maxLines` it is as high as its text wrapped at
its width, between the two, past which it scrolls (mygo's Lines: a
message field growing with what is typed); the width it grows at is
last frame's when it fills its row. `TextState::$readOnly` is mygo's
ReadOnly: the text is shown, selected and copied, from the keyboard
too, but `insert`, composition, undo and redo change nothing and no
caret is painted. `$onPaste` (fn(text): bool) sees a paste before it
is inserted and may take it; `$onTextKey` (fn(keyCode, chars, mods,
state): bool) sees a key in the view's keyDown before the text system
and the runtime see it, and a key taken reaches neither — Enter that
sends, Tab that accepts a completion (mygo's OnPaste, OnTextKey).
`examples/form` has a message field of one to four lines whose Enter
sends into a read-only log.
`FieldLayout::wrap` breaks `display()` into byte ranges: paragraphs at
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

`Window` has mygo's methods over `NSWindow`: shown with the keyboard or
without (`show`, `showInactive`, `hide`, `focus`, `blur`), minimized,
zoomed (`maximize`, `unmaximize`, `toggleMaximize`; maximized is zoomed
and not in full screen), in full screen, centered; its bounds and its
content's (`Rectangle`, in points from the top-left corner of the primary
screen, as mygo's: AppKit's frames are flipped by the primary screen's
height), size and position, the least and most it may be resized to;
whether it resizes, moves, minimizes, zooms (the zoom button enabled),
closes (style mask bits), stays on top (the floating level), its opacity,
its shadow, whether it lets the pointer through and keeps out of screen
captures; the file it shows (`setRepresentedFile`, the title bar's proxy
icon) and whether it is edited (the dot in the close button); the Dock
icon's progress (`setProgressBar`, `Native\Dock`: two custom NSBoxes, as
NSProgressIndicator does not draw in a Dock tile), the Dock icon bouncing
for attention (`flashFrame`) and all Spaces (`setVisibleOnAllWorkspaces`).
`close` asks the 'close' listeners, as the close button does, and
`destroy` does not; a child window (`WindowOptions::$parent`) stays above
its parent, as a child window of it, or, `modal`, is its sheet. Its
events are mygo's, 'show', 'hide', 'readyToShow' (once: its page ready,
or native UI drawn), 'maximize' and 'unmaximize' (as a resize changes the
zoomed state) among them. `WindowOptions` is mygo's as far as done: the
size is the window's unless `useContentSize`, the window is frameless or
lacks any of its controls, starts maximized or in full screen, on top,
translucent. A window's content view is a container (as mygo's: WebKit docks the
inspector beside the web view, in its superview, and AppKit draws a
broken title bar if that is the frame view), holding the page or the
Metal view and, behind it, the material of `setVibrancy`
(`NSVisualEffectView`, behind the window, active; mygo's material names).
With a material, or `WindowOptions::$transparent` (the window not opaque,
its background clear), the page draws no background and the Metal layer
is not opaque and clears to transparent: the root of native UI paints the
theme's background, as mygo's does, and a view taking it away
(`$c->root->color = null`, where `Context::vibrancy()` says the material
shows) shows what is behind. `setBackgroundColor` takes mygo's CSS colors
or light-dark() (`Background`), the window's own and the page's under-page
color, the appearance's on a theme change.
`Window::invalidate` asks for a frame and `update($fn)` runs
$fn first (mygo's Invalidate and Update); `Window::focused` and `byId`
find windows.

`Window::flyout(FlyoutOptions)` is mygo's NewFlyout: a borderless,
nonactivating `NSPanel` (MyPHPFlyout, the key window only when
`focusable`, never the main one; it stays as the app is deactivated,
transient, out of the window cycle) next to a rectangle of its parent's
content (`anchor`, as `Context::bounds` gives it), or of a tray icon
(`Tray::bounds`, at the menu bar's menu level). `Flyout::place` is
mygo's platform placement, GTK's for popups: on each axis the flyout
flips to the other side of the anchor or its other end where it does not
fit and the flipped one does, then slides back into the work area (an
`NSScreen`'s visible frame, `Native\Screens`, chosen by `workAreaFor`),
then shrinks to it. It is placed again as its parent moves or resizes,
on `setAnchor`, `setSize` and `setContentSize`, and shows as its child
window, which it stops being as it hides, giving the keyboard back. A
focusable flyout closes, as the user closing it ('close' listeners may
keep it), when it resigns the key window (unless to a flyout of its own,
or as its tray icon is pressed, whose click toggles it), on a press
outside the app (a global `NSEvent` monitor: a nonactivating panel would
not resign the key window then), and on a press on its anchor, which a
local monitor eats with its drags and release so that the release does
not open it again. Children close with their parent. A window with
`WindowOptions::$panel` is a MyPHPPanel, nonactivating from its creation
(the window server reads it from the initial style), on all Spaces and
over full screen apps, on top, never minimized, zoomed or in full
screen; showing or focusing it does not activate the app, and its
`cancelOperation:` does nothing, as NSPanel's would close it past
`windowShouldClose:`. A flyout or a panel closes, as the user closing
it, on an Escape its content leaves: one no control or `shortcut` took
in a frame of native UI (`Runtime::$escape`, called after the build), or
one the page's handlers did not `preventDefault` (the bridge posts
`{t: "escape"}`). A flyout with `FlyoutOptions::$popover` shows its
content view in an `NSPopover` (mygo's createPopover), through an
`NSViewController`, from the parent's content view (not flipped: the
anchor's y is turned) or the tray icon's button, on the edge its side
names; AppKit draws the material and the arrow, flips the side where the
screen has no room and animates. The popover makes its window as it
shows: the flyout's NSWindow is that window while it shows and null
otherwise, and the flyout is kept in `$open` by its popover. A focusable
flyout's popover is transient: `popoverShouldClose:` asks the 'close'
listeners (a press on its tray icon leaves it to the icon's click), and
`popoverDidClose:` closes the flyout unless `hide` or `destroy` closed
the popover. Native UI in it sees `Context::vibrancy()`.

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
the rest map one to one. `WindowOptions::$stateKey` keeps a `WindowState`
(mygo's: the normal bounds in top-left screen points, maximized, full
screen) in the `WindowStateStore` (`App::setWindowStateStore`, else
`window-state.json` under `App::path(PathName::UserData)`): captured 0.3 s
after the window last moved, resized or changed state, as zooming and
full screen resize it on the way, and as it closes; restored where it
shows on a display (100 by 50 points of a work area at least), else at
its size within the primary's, centered; saved when a window closes and
in `applicationWillTerminate:`.

`Process` runs a child without blocking the UI, which has no threads: a
`proc_open` with non-blocking pipes, polled every 16 ms with
`App::after` (stdin written as the pipe takes it, stdout and stderr read
as they come), and `$done(out, err, code)` once it ends; `kill()` stops
it. Network and other slow work go through it — `examples/http` sends
requests with curl this way and stays live while they are in flight.

## Draggable

`Context::draggable($n, $offset, DraggableOptions)` is mygo's Draggable:
the pointer moves a node from its place in the layout by an offset, [x,
y], which the app holds (or the node keeps, `DragRec::$own`). The runtime
keeps the left press (mygo's pressed state): the node it pressed, the
innermost under it last frame that takes the pointer (clickable,
focusable, draggable, a field, a link or a slider), and its moves
(`DragTrack`: the window's points from the press, `far` once
`Runtime::DRAG_START` away, the velocity of its last 100 ms at the
release, none after 50 ms still); a release is seen for one pass, as
mygo forgets the input a pass handled. The press holds the node where it
shows (catching it moving), then it follows the pointer in its parent's
axes (the inverse of the parent's last-frame world), past its bounds on
`Motion::rubberband` as long as the parent, unless rigid; let go, it goes
to its snap (`Motion::nearest`) of where the throw would carry it
(`Motion::project`, the velocity no faster than `MAX_FLING`), within its
bounds, on a spring starting at the pointer's velocity, or at once with
Reduce Motion. A release that dragged a draggable node is no click. The
offset joins the node's pose (`Node::$poseM`, before its transform), so a
node lifting as it is dragged stays under the pointer. The view stamps
presses, releases and moves with NSEvent's timestamp, so the speed of a
throw is the events', not the frames' that drain them.

Values dragged within a window are mygo's dragdrop: `Context::drag($n,
$value)` makes a source, which a press moving `DRAG_START` points starts
dragging (`Runtime::$valueDrag`); a press on a node inside it that only
takes clicks, as a button, gives the press to the source once it moves
(mygo's pressDragger), while fields, sliders and draggables keep it.
`drop($n, $type, $v)` and `dragOver` make a node take values of a type —
a class or interface (instanceof) or one of PHP's types as
`get_debug_type` names them — the innermost under the pointer taking it;
the release drops it there, which is no click, and Escape gives it up.
`Painter::dragCopy` paints the source's node again under the pointer at
three quarters' opacity, shifting its worlds; near the top or bottom
edge of a scrolling container under the pointer, the content scrolls
(mygo's dragScroll). Lists and grids keep their own reordering.

Drags the system carries are mygo's data_drag and drop. `MyPHP\Transfer`
holds their data: a `Data` of `Item`s, each of `Representation`s by
format (a MIME type), given as bytes or made lazily by a provider, at
most once per snapshot, a failure kept; `materialize` reads the formats
taken alone. `Context::dragData($n, Data, DragOptions)` and
`dragDataFrom($n, fn() => Data)` make a native source: the press moving
`DRAG_START` points calls the runtime's `startDataDrag`, and the drag is
the system's from then on (`$valueDrag['native']`: no copy painted, the
release no click); its end calls `DragOptions::$done` once. A source
also given `drag($n, $value)` offers that value to this process's typed
targets (`drop`, `dragOver`) as it is. With no `preview`, its image is
the source's visible part of the last frame at one pixel a point, at
most 512 (mygo's dataPreview): `Metal::lastFrame` draws the last list
again off screen, the ring keeping it, and reads it back. Escape, the
source no longer built or the window closing cancel it.

`MyPHP\DataDrag` is mygo's core dataSource: the one drag on, a random
session key that finds its value and data inside this process alone
(`resolve`, for each drag over a window), the start after the input
being handled (`App::later`), its end told once, an effect it did not
allow an error. `Window::startDataDrag` starts one from the window's
gesture. `Native\Drag` is darwin's surface_drag.go: one
`NSPasteboardItem` an item, a provider giving its formats when a
destination asks (`macType`: UTIs, the URI list in `dev.mygo.uri-list`
with `public.url` beside it, a MIME type's `UTType` or
`dev.mygo.mime.<hex>`) and the session's key; Escape posted to AppKit's
tracking loop cancels it. The view's `NSDraggingDestination` methods
offer the pasteboard's types unread; once the content takes formats
over the drop point (`Runtime::dataDragOver`: the innermost enabled node
taking a value of this process's by type, else `dropData`/`dataDragOver`
by format and negotiated effect, the modifier's, else copy before move),
the drop reads those, a Finder reference URL as its path's, and
`dataDrop` materializes them. Files no node takes as data go to a node
taking files (`droppedFiles`, `fileDragOver`), then to the window's
`fileDrop` listeners (mygo's SurfaceEvent). The formats the targets take
are told the view at the end of a frame when they changed
(`setDropFormats`).

## Router

`Router` is mygo's: a history of locations (an escaped path and a query,
resolved against the page shown as links in web pages are, as Go's
ResolveReference and path.Clean: `edit`, `../settings/`, `?tab=info`),
the entry shown, at most `MAX_HISTORY`. `view($c, $fn)` builds the page
shown in a column, keyed by the box and a number of the router (an entry
pushed with the same path, or keeping a layout's part of it, shares its
pages), so the nodes inside keep their ids; `Route::match` reads
`{name}` and `{name...}` patterns and `Route::view` builds the pages of
the rest of the path inside a layout, its view's state local to its box.
State goes with its node: `Runtime::local` drops what was not built, as
mygo's engine drops states, unless the router keeps the page it is in —
the `KEPT_PAGES` entries nearest the one shown (`Runtime::keep`, by the
page each node was last built in, `pageOf`), so going back finds a page
as it was. A change during a build asks for another pass, from outside
one a frame (`Runtime::changed`, the window's `requestFrame`). Going to a
page moves the focus into it (the node that had it there, else the page,
a focus target Tab goes past but into) when it was in the page going
away, in an overlay or nowhere; otherwise it stays, and the title is
announced. Pages slide (the upper over the lower, which moves 30% and
dims, both placed with `Node::at` by the box's last-frame width) or fade
over `SLIDE_TIME` and `FADE_TIME` or `$animation`; the page going away is
inert (`Node::$inert`: it takes no pointer, focus or shortcut, and
assistive technology does not see it). Cmd+[ and Cmd+] and a mouse's
buttons 3 and 4 (`Key::BACK`, `FORWARD`, from the view's
`otherMouseDown:`) go back and forward in the innermost router around the
focus, or the window's first. `Context::link` is focusable and goes to
paths through the router whose page it is in; `backButton` and
`forwardButton` are disabled (`Node::$disabled`) at the ends, and list
the pages that way in a context menu (`Router::pagesToward`). mygo's
browser history (its js build) has no place here.

## Locale and formats

mygo's locale model (locale.go) is `Locales`, behind `App::locale()`,
`setLocale()`, `setLanguages()`, `languages()`, `preferredLanguages()`,
`region()`, `onLocaleChanged()` and `onRegionChanged()`: the one
language the app shows, which MyPHP's controls and menus follow, and
the regional formats, a setting of their own. The language is the
user's preferred languages (`Native\SystemLocale`: AppleLanguages of
the global domain, else NSLocale's), or the language the app chose
(`setLocale`, kept as AppleLanguages of the app's own defaults domain
— the process name when php runs unbundled — which System Settings
shows; MYPHP_LOCALE for one run), matched against the languages the
app is written in (`setLanguages`, English alone by default) — `I18n::match`,
ICU's lookup, then the same language in its likely script (zh-TW finds
zh-Hant) — and the app's own where none matches. The formats are the
current locale (its `@rg=` keyword moved into the region: en_US@rg=gbzzzz
is en-GB), the calendar's first weekday and the hour cycle of the
skeleton "j" in the locale's date formats, filled in from CLDR for the
locale where the system says nothing; NSCurrentLocaleDidChangeNotification
reads them again, and a change redraws the windows, relabels the menus
and calls the listeners. Without a running app (the tests) the
language is English, the preferred languages none and the region the
zero one.

`I18n` is mygo's package i18n, over ext-intl for what ICU knows and over
mygo's CLDR tables for what ICU does not give PHP. Tags are normalized
(ICU canonicalizes language, script and region; extensions keep their
BCP 47 form, as ICU would make them `@hours=h23` keywords; old codes
iw, in, ji, jw, mo are the new ones; POSIX modifiers name scripts,
sr_RS@latin is sr-Latn-RS; only two- and three-letter languages are
languages, so that "messages.json" is no catalog) and matched by CLDR's
parents: the same tag, then an ancestor (es-419 for es-MX), then a
sibling under the same parent (en-GB for en-AU), then another region in
the same likely script (nb and no one language), never another script.
A language's likely script is the script ICU writes its name in, in
itself ("српски" is Cyrl), for the few without a name of their own a
table. `parent()` and `chain()` are mygo's parent.go over
`Native\CldrTables` (CLDR 48.2.0, converted from mygo's tables.go by
`bin/cldr-tables`), as are the plural categories: the rules as PHP
switches over `Operands` (mygo's, the last 18 digits plus 10^18 for
longer numbers), checked against CLDR's samples and against ICU's
categories of 250 languages (tests/i18n, mygo's testdata). PHP's intl has
no PluralRules, which is why they are tables.

Dates are `DateFormatter`: mygo's datePattern (a skeleton's hour letter
is an hour cycle, as V8 reads Intl's options; a style's pattern with
another cycle is the best pattern of its skeleton in it) over
IntlDatePatternGenerator and IntlDateFormatter, the narrow no-break
spaces of CLDR's times written as spaces as V8 writes them. Numbers are
`NumberFormatter`: mygo's writing of decimal strings (exact however many
digits, rounding half up or half even, percents shifted, Indian groups,
CLDR's minimumGroupingDigits read from ICU's bundles along the parents,
as ICU's own NumberFormatter ignores it: es writes 1234) with ICU's
patterns, symbols and digits. Formatters are kept by locale and options
and what dates they wrote cached (1024 entries, as mygo's fmtCache),
since the controls write the same dates frame after frame.
`Region::formatLocale($lang)` puts the language and the formats together
as Apple's Locale.current does ("en-GB-u-fw-mon-hc-h23", zh-HK with German
formats zh-Hant-DE; the pseudo locales' formats English);
`Context::formats()` is mygo's: the system's region when it names an hour
cycle, else CLDR's for the language.

`Message` is mygo's message.go and format.go: ICU MessageFormat parsed
(apostrophe quoting, Pattern_Syntax names, plural offsets, exact cases,
32 levels of nesting) into nodes and formatted with the plural rules of
the language the message is in and the numbers and dates of the locale;
mygo's fixtures (tests/i18n/messages.json), which its Go and TypeScript
formatters check, pass. The pseudo locales en-XA and ar-XB transform
the source language's messages (`Native\Pseudo`, Android's
pseudolocalizer), and `Locales` shows them whatever the app is written
in. `Catalog` is mygo's catalog.go: a directory of `<tag>.json` read a
language at a time, the message of a locale along its chain, the best
match of each catalog's languages, then the app's source, then the
library's; a library's catalog (`Catalog::library`) looks first in the
registered catalogs' keys "<namespace>.<key>"; texts formatted without
arguments are kept per registry version. `I18n::register` adds an app's
catalog, whose languages become `App::languages()` where the app sets
none (`Locales::catalogsChanged` shows them at once).

`L10n` is mygo's internal/l10n: MyPHP's own strings — the controls'
(Search, Find, "3 of 12", No matches, Done…), the code editor's and the
menu roles' — in `src/l10n/<tag>.json` (English the source with the
translators' descriptions, zh-Hans and zh-Hant), a library's catalog of
the namespace "myphp": `L10n::in($locale)->get($key, $args)` says one,
and an app changes them or translates them with keys "myphp.<key>" of
its own catalogs. `Menu::ROLES` holds the keys ("menuUndo"), the labels
taken at build in the language the app shows with the app's name ("Quit
Widgets"), and `App::relabelMenus` installs the bar again on a change.
`examples/widgets` switches among English, 简体中文 and 繁體中文.

An app's catalogs are myphp.json's `"i18n": {"dir": "locales", "source":
"en"}`. `Native\I18nGen` is mygo's internal/i18ngen: it reads them with
the lines of their keys (its own scanner of the top-level object, which
json_decode cannot give, nor duplicate keys), checks them (messages that
do not parse, keys, placeholders and their ARB types, the arguments of
translations against the source's, plural cases against the language's
categories, keys "myphp.<key>" against MyPHP's catalog, `myphp.appName`
and `myphp.appDescription` for packaging), and writes the accessors:
`<dir>/<Dir>.php`, a class named after the directory with a static method
per key in the language the app shows (`Locales::filesSelected(3)`) and
`in($locale)` for another, which registers the catalog as the app
requires the file; and, with "bindings", the TypeScript module next to
the client (src/locales.ts), which carries the page formatter
(`src/Native/i18n.ts`, mygo-runtime's i18n.ts reading
`window.myphp.locale`) and the messages of every language, so that the
first paint is in the right language. `myphp i18n check`, `myphp
generate`, `myphp dev` (which writes them before each run, and takes
`--lang` as MYPHP_LOCALE) and `myphp build` use it; build gives the
bundle a `<lang>.lproj` per language the app ships (Xcode's names:
zh-Hans, zh-HK, pt-BR), `InfoPlist.strings` naming the app where the
catalog does, and CFBundleDevelopmentRegion and CFBundleLocalizations,
so that AppKit's own texts and System Settings follow the app's
languages. tests/i18ngen_test.php checks mygo's golden catalog, its bad
catalogs and the command line; `bun test src/Native/i18n.test.ts` the
page formatter, the same fixtures, and the module type-checked by tsc.

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

## Code editor

mygo's `ui/editor` is in, as `MyPHP\UI\Editor`. The model below the
view: `Rope`, `RopeNode`, `RopeText` and `Summary`
are mygo's `internal/rope`, text as a persistent AVL tree of chunks of up
to 4 KB whose nodes sum bytes, runes, UTF-16 units and newlines, so that
an edit shares the tree with the text before it and a version is a
pointer; `Diff`, `DiffCompact` and `Hunk` are `internal/diff`'s line
diff: git's histogram algorithm, Myers's with xdiff's cost limit where
every line repeats, and the indent heuristic that slides changed blocks.
`Buffer` (with `Edit`, `Change`, `Pos`, `Snapshot`, `Transaction`) is
`buffer.go`: edits as transactions of undo, typing of one editor within
300 ms grouped, a log of the last 256 versions' changes that editors
follow (`changesTo`, `Change::mapOffset`/`mapLine`), and
`changesBetween` by lines for a reader too far behind the log.
`Selection`, `Sel` and `Chars` are `selection.go`: selections merged as
Zed's, words and subwords, graphemes (marks, joiners, flags, CRLF) and
indentation. `Command`, `Binding` and `Keymap` are `keymap.go`: the
commands of code editors and Zed's macOS bindings over `Key`'s virtual
key codes, which `Key` now names for the character keys (kVK_ANSI_*),
the app's bindings first and chords as Cmd+K Cmd+0. Threads being Go's,
the buffer has no mutex and the highlighter will work on the main thread.
Syntax is in too: `Token` (the kinds, and tokens as [end, kind] pairs
joined and relabeled), `Tokenizer` and `Lexer` with `LexString` and
`LexState` are `syntax.go` and `lexer.go`, the general lexer of
identifiers, numbers, strings with escapes and interpolations, comments
and operators whose state packs into 40 bits; `JsonLexer`, `TomlLexer`,
`CssLexer`, `MarkupLexer` (HTML's scripts and styles lexed by their own
tokenizers), `MarkdownLexer` (fenced blocks by their language) and
`DiffTokenizer` are `lexers.go`; `Language`, `BracketPair` and
`Languages` (28 languages, by file name or alias) are `languages.go`,
their indent patterns as PCRE; `Highlighter` with `SyntaxSelector`,
`Folder`, `BracketMatcher`, `Indenter` and `Range`, and
`TokenHighlighter`, are the rest of `syntax.go`: a line at a time from
the state the line before ends in, an edit tokenizing the lines after it
again until one starts as it did, a frame's budget (seconds) at a time;
`Buffer::highlighter()` keeps the buffer's and feeds it the log's
entries.
The view is in: `Theme` (`theme.go`, colors as [rgb, alpha]), `View`,
`LineLayout`, `Heights`, `WrapRows`, `LineGeometry` and `Block` are
`view.go` and `display.go`: lines laid out as they show, tabs expanded
and inlays inserted (segments map the displayed text to the line's),
soft wraps measured by summing cached advances as Zed's LineWrapper,
long rows shaped in pieces of 2 KB, and the heights of rows, folds and
blocks in Fenwick trees, so a million lines scroll as ten. `Editor` is
`editor.go`, its other files a trait each: `EditorEdit` (edit.go),
`EditorCommands`, `EditorLines`, `EditorMulticursor`, `EditorBrackets`,
`EditorFold`, `EditorLsp`, `EditorInput` (input.go: pointer gestures,
scroll bars, the gutter, and the `TextInputClient` methods), `EditorPaint`,
`EditorBuild` and `EditorGitDiff`; `Finder`, `GoToLine`, `Completions`,
`Hover` and `Gesture` are its parts' states, `DiffModel` and `GitDiff`
the changes against `State::$diffBase` (`diffmodel.go`, `gitdiff.go`).
Selections are values in mygo and objects here: what Go copied
(`slices.Clone`) is cloned (`Editor::cloneSels`), and `primary()` gives a
copy. The editor draws itself in a `Node::draw` closure and takes its
input as it comes (Input path above); its find bar and go to line bar are
nodes inside it, its completions and hover nodes over everything
(`Context::overlay`), each built by the app's render function
(`State::$renderFindBar`…) or its own (`FindBar::default`…). `State` is
the API (`state.go`): options as properties, `view($c)` builds the
editor. Before its first frame a state measures with `Editor::$text`, the
app's text engine (a headless test's own). Regular expressions are PCRE,
their replacements' `$1` and `${name}` expanded as Go's Expand does. Not
ported: text in hovers is not selectable. The editor is a text field of
many lines to assistive technology, named by `State::$label`, or as the
side of a diff it shows, with its text and its newest selection
(`accessibility`, mygo's).

The diff viewer is `diffview.go`, `difflayout.go`, `diffside.go`,
`diffparts.go` and `patch.go`: `DiffState` is the API (`view($c)` builds
it), two editors side by side sharing their scroll (`SharedScroll`) or
one with the old text's deleted lines in blocks above where they were.
`DiffModel` compares the texts again only around an edit of the new text,
pairs a hunk's lines by similarity and finds the words or characters that
changed in them (`Diff::words`, `Diff::chars`, `HunkWords`), and reverts
or keeps a hunk. `DiffSideView` is the `Decor` of a side: its colors
(`Theme::diff`, mixed in Oklab as mygo's), its hidden lines and the
blocks that align the sides (fillers, annotations, separators, a patch's
headers), which `Heights` places; the view lays the sides out in a
`draw` closure before they paint, and builds again when that moved what
its nodes stand on. Separators, the actions on a change and the gutter's
button are nodes over the lines, built by the app's render function or
their own (`DiffSeparator::default`, `DiffHunkActions::default`,
`DiffState::defaultGutterButton`). `FilePatch` and `PatchHunk` parse and
apply git's unified diffs; `setPatch` shows one without its files.
Regular expressions are PCRE, which matches nothing in a text with a byte
that is not UTF-8, as an edit splitting a character leaves: the finder
searches a copy with such bytes as \x1a, where Go reads U+FFFD.

## Markdown

`MyPHP\UI\Markdown\Parser` is mygo's ui/markdown/parser: CommonMark
0.31.2, with the extensions `Extensions` names (GitHub's tables, task
lists, strikethrough and autolinks; footnotes; math between `$$`, `\[ \]`
and `\( \)`, and single dollars with `DOLLAR_MATH`). All the spec's
examples and GitHub's extensions' render as cmark-gfm does
(`Document::html`, for the tests). The block parser follows cmark's
blocks.c line by line over flat blocks in document order (a block's
children right after it, up to its `$next`), byte offsets into the
source, a leaf's lines as segs; inlines are parsed when asked for
(`Document::inlines`), by CommonMark's delimiter algorithm with cmark's
guards against quadratic inputs, into a flat list the same way. A
`Document` takes text appended (`append`) or edited (`edit`, `set`)
and parses again only from the outermost block still open, or from the
top-level block before the change until a block starts where one did,
keeping the inlines of the blocks it parses again unchanged; a link
reference defined or undefined parses anew the blocks that looked it up
(`Block::revision`). `setStreaming` heals the end of a reply being
written: the last paragraph's emphasis, code spans, strikethrough and
math close, a link being written is a link (`Inline::$pending`), and a
line that could still turn into something else waits. PHP copies a
substring where Go slices one: a text node merged into keeps only its
offsets until its text is asked for, or long runs would be quadratic.

`Markdown::view` and `Markdown::stream` are mygo's markdown.View and
Stream (`MyPHP\UI\Markdown`): a column whose state (`ViewState`, kept by
the runtime as the column's local) holds the `Document`, set to the text
each frame, the resolved `Theme`, the paragraphs' spans by block
(`Para`, made by `SpanBuilder` and made anew only when a block's range,
revision or base style changed), the code blocks' tokens by chunks of 32
lines (`CodeState`), the tables' column widths and how much of a stream
shows (`RevealState`: a unit at a time at a pace catching up within
`Reveal::$catchUp`, the text revealed fading in by its spans). Blocks
are nodes (paragraphs rich text with links and footnote references as
links inside them, lists rows of a marker and a column, quotes a
column with a bar, code blocks a box with a copy button over a sideways
scrolling row, tables rows of cells as wide as their columns' text),
spaced by margins. The view is a Selectable container: Copy puts text
(paragraphs apart by blank lines, a tight list's items by newlines,
cells by tabs), HTML and Markdown on the clipboard, and Copy as
Markdown, its context menu's or Shift+Cmd+C, the source between the
selection's ends with the syntax cut there completed; each paragraph
notes where its runes come from in the source (`Para::$marks`).

The box model this needed is mygo's: `padding()` takes one to four
sides, `margin()` four with `Node::AUTO` for an automatic margin,
`borderWidth()` and `borderColor()` a border inside the edges, which the
content sits inside of, `background()` a fill with its own opacity,
`shrink()` how much a node gives up in an overflowing row, and a least
width set with `min()` replaces its content's. A node's measured size
includes its margins (`Layout::measure`), and arrange takes them off
again, an automatic margin sharing the room the node does not need
(`inMargins`); along a row or a column, automatic margins take the free
space before justify does, and across, a node with one takes all the
room and places itself in it.

## Rich text

`MyPHP\UI\RichText` is mygo's ui/richtext. A `Text` is a value: a rope
of its runes (the code editor's `Rope`) and a persistent sequence of its
paragraphs (`Seq`, an AVL tree of leaves of 32, as mygo's seq), aligned
with the rope's lines; each paragraph (`RichPara`) has an identity, a
`ParagraphStyle` and runs of `Attributes` as `[runes, attributes]`,
its last run its newline (or the text's end), whose attributes are
those typing gets in it. Every method returns a new text sharing what
did not change, so undo keeps versions. Attributes and styles in a text
never change: what a text hands out, and what a format's closure gets,
is a copy, and runs are joined when their attributes are `equals()`.
`Document` holds the text, the selection, the typing attributes and the
steps of undo (`UndoStep`: the texts before and after, the selections,
the region changed), with a log of changes (`Change`) for the editor to
lay out only what changed; `Edit` changes it as one step, its offsets
following the edit's own changes. The commands of a toolbar or a
Format menu are `DocumentCommands`. The codecs are mygo's: Markdown
written by `MarkdownWriter` (marks as HTML where their delimiters
cannot flank, checked by reading the result back) and read by
`MarkdownReader` over the Markdown parser; HTML written by `HtmlWriter`
and read by `HtmlBuilder` over a small tree builder of its own
(`HtmlNode`); RTF read by `RtfReader`; and JSON, the clipboard's own
format (`Clip`), which pastes the last copy of this process whole.

`RichText::editor` is mygo's richtext.Editor: a focusable column whose
local state, a `View`, follows its document each frame (`sync`, through
the document's log of changes, so that a keystroke lays out again only
its paragraph), lays out only the paragraphs in view (a `ParaView` each,
its `ParaLayout` the text system's layout of its spans, kept until the
paragraph, the composition in it or the look change; their heights in
Fenwick trees, `Heights`, estimated for those not laid out), and paints
them in its draw function: code blocks' and quotes' backgrounds, the
selection, the text (`Painter::paintSpans`, shared with rich text
nodes), attachments, list markers, the composition's underline, the caret
and a scroll bar. It takes keys as they come (`handleInput`) and text
from the input methods as their client (`TextInputClient`), every edit a
step of the document's undo, typing joined into one; the Markdown
shortcuts run after a character is typed. Copy writes the editor's JSON,
HTML and plain text; Paste reads the richest of those, RTF and Markdown
(`Runtime::$readClipboardData`, the pasteboard's `readData`).

## Terminal

`MyPHP\Plugins\Terminal` is mygo's plugins/terminal. The emulator is
libghostty-vt, the very build mygo's release carries
(`mygo-plugin.json`, copied: names, URLs and SHA-256s), found as mygo
finds it (`Plugins\NativeLibrary`: `$MYGO_GHOSTTY_VT`, the bundle's Resources, next to
`PHP_BINARY`, then `<cache>/mygo/natives/<sha256>/`, downloaded there
once and checked), and bound through one `FFI::cdef` (`Vt\Lib`) whose
layouts are checked against the library's own JSON description of its
types before anything is called; packed cells are read by the bit fields
that JSON gives. Callbacks are PHP closures assigned to the fields of a
struct of function pointers (`MyPHPTerminalCallbacks`), which keeps them
alive while the terminal lives. The pseudo-terminal is libc's
(`posix_openpt`, `grantpt`, `unlockpt`); the child cannot be a fork of a
PHP running AppKit, so `proc_open` starts `Pty/spawn.php` in a PHP of its
own, with the slave as its stdio, which calls `setsid`, makes the slave
its controlling terminal (`TIOCSCTTY`), closes the other descriptors and
`execve`s the program through FFI. PHP has one thread where mygo has a
reader and a writer goroutine: the terminal polls the master, non
blocking, on the run loop (`Terminal::poll`, through `App::after`, every
4 ms while output flows or input waits, backing off to 50 ms when quiet;
at most a megabyte a turn), writing what waits in its `InputQueue` as
far as the program takes it; `Terminal::$after` swaps the timer for the
tests. What the emulator's callbacks ask (a reply to the program, a
paste's reply in pieces with their progress) is queued and written by
the next poll, never inside the library's call. The view (`View`, with
`ViewInput` and `ViewPaint`) sizes the grid from the font's advance and
line height, rebuilds only the rows the render state marks dirty (a
`RowCache` each: backgrounds, runs of glyphs placed in their cells as
one-line layouts for `TextPainter::paintLayout`, lines, characters drawn
by `Box`), and is the client of the input methods: keys that type text
are left to them (`handleInput` returning false), their text comes as
`replaceText`, their composition as `setMarkedText`, and a key while they
compose is theirs. Keys are released as `InputEvent::KEY_UP` (`keyUp:`),
the modifiers held come with every pointer event and `flagsChanged:`
(`Context::modifiers()`), and `Runtime::$clipboardFormats` lists what a
paste could read.

## SQLite

`MyPHP\Plugins\Sqlite` is mygo's plugins/sqlite from PHP: the very
library mygo's release carries (SQLite's amalgamation and mygo's shim,
`native/sqlite.c`), found by `Plugins\NativeLibrary` as the terminal's is
(`$MYGO_SQLITE_LIBRARY`, the app's Resources, beside the executable, the
cache) and bound by one `FFI::cdef` (`Sqlite\Lib`), which checks the
shim's ABI version and that SQLite is threadsafe. The shim's functions
for doubles as bits are mygo's way around purego's registers; FFI passes
doubles, so `sqlite3_bind_double` and `sqlite3_column_double` are called
as they are. Each call is an operation of the shim (its busy handler,
timed by `OpenOptions::$busyTimeout`, and its authorizer, which denies
transaction control and ATTACH in the user's SQL while `mygo_sqlite_
prepare` compiles it); SQL is compiled whole, the tail after the first
statement too, before anything runs, so "one statement; another" never
runs half. The tail is found by its address: the out pointer is an
`intptr_t` passed as `const char**`, and the buffer's base comes through
a `void*` slot. PHP's strings are bytes, so TEXT is a string that must be
UTF-8 and a BLOB is a `Blob`, in and out. There are no contexts: an
operation runs to its end on the main thread, and nothing cancels it.

Plugins are PHP, as mygo's are Go: `App::use(Plugin)` (mygo's Use) checks
the name, binds the plugin's service as `plugin:<name>` (`Ipc::usePlugin`)
and runs its setup; the app's generated TypeScript leaves plugin services
out, as each plugin ships its page side as a TypeScript client of its own
over `window.myphp.call` (`client/index.ts`, with its `bun test`). A call
to a plugin not used says so (mygo's notBound). mygo hands bound methods a
`context.Context` that tells the calling page and is canceled as the page
navigates or its window closes; here a parameter typed `CallContext` gets
the window's context of its page in that place (`Ipc::arguments`, which
the page passes no argument for, as `Channel`s are made from theirs), one
per document: the bridge posts `{"t":"page"}` at document start, before
the page's scripts, which ends the last document's context, and closing
the window ends it too. `afterFunc` is Go's `context.AfterFunc`, which
the SQLite service closes a page's connections with. The service's
methods are lowercase, as PHP's (`plugin:sqlite.open`), and its shutdown,
which `App::onQuit` runs, is private, out of the pages' reach.

A bound method waiting for something, as a response from the network,
cannot block the main thread as mygo's goroutines do: a streaming
generator yields `Channel::wait($seconds)`, which sends nothing and has
`Ipc::pump` go on that much later (`App::after`), the run loop turning
meanwhile. The fetch plugin is such a generator around libcurl, the
system's (`/usr/lib/libcurl.4.dylib`), through FFI (`Native\Curl`): an easy
handle a request on a multi handle of its own, `curl_multi_perform` each
turn, its header and write callbacks (closures in a struct of function
pointers) gathering the head and the body, every 2 ms while data flows,
backing off to 50 ms. A PHP int goes to `curl_easy_setopt`, variadic, as
a 64-bit integer, libcurl's long and curl_off_t; a scalar CData does not
(FFI warns and passes garbage). It sends what Go's client sends: no
`Accept` or `Expect` of curl's own, gzip asked for and undone (its
`Content-Encoding` and `Content-Length` dropped) unless the page names an
encoding, redirects followed to 20 or returned (`manual`) or refused
(`error`), the head of the final response told by its blank line. The
plugins' clients are tested over the real `bridge.js`
(`src/Plugins/fake-myphp.ts`, mygo's fake-go.ts) with `bun test`.

The WebSocket plugin (`Plugins\WebSocket`) has libcurl only connect
(`CONNECT_ONLY`, with TLS for wss and through a proxy by a tunnel) and
keeps the handle in its multi handle, without which `curl_easy_send` and
`curl_easy_recv` fail (CURLE_UNSUPPORTED_PROTOCOL). The opening handshake
is written and read over them by the plugin, as Go's client writes it,
and the frames are mygo's conn.go over a non-blocking `Transport`:
`Conn::readMessage()` returns a message once its frames have all arrived,
and frames written wait in a buffer the transport takes as it can.
`connect` is a generator streaming the events, polling every 1 ms while
data flows, backing off to 25 ms; `send` is a generator without a
channel that waits for its turn (the page numbers its messages, as a Blob
is read asynchronously) and replies once the transport took the frame,
which is when the page's `bufferedAmount` goes down. Received messages
wait in PHP while the page is behind, as the channel's flow control holds
the generator. The tests' server (`tests/testdata/ws_server.php`) is the
same `Conn` as a server over a PHP stream (`StreamTransport`).

## File watches

`App::watchFiles` is mygo's WatchFiles on the main thread. The scan
(`Native\FileWatch\Scan`, mygo's watch_scan.go line for line) keeps what
it saw of the tree, by slash-separated path, and the targets it enrolled
in the backend; a notice only says where something changed, so an update
lists or stats those places again and the events are the differences
(`Scan::diff`, renames paired by identity). There are no goroutines:
`FileWatcher::next()` polls the backend, marks the notices, runs the
debounce against the clock and looks once it has passed, blocking in
the run loop or `poll` for at most the time left when the app isn't running, and
turning the run loop (`App::wait`, 50 ms at a time) while it is. A watch
looks only when `next()` is called, so what changed meanwhile waits in
the system's queue and comes in one batch. The page plugin calls
`next(0)` from its generator and yields `Channel::wait` between, 10 ms
backing off to 100 ms.

The backends (`Native\FileWatch\Backend`) are the system's through FFI,
and watch directories only: a file target is `Unsupported`, and the scan
enrolls no more files, which the watch of their directory names. On macOS
FSEvents (`FsEvents`, mygo's darwin fseWatch): one stream per directory
target, covering the tree beneath it, so a recursive watch is the root's
one subtree target and takes no descriptor per entry; scheduled on the
run loop in a mode of its own, which `poll()` runs, so its callback comes
on the main thread and only then. Its flags map to notices in the pure
`FsEvents::notice()`, which the tests check without a stream. On Linux,
where the tests run, inotify, an instance per watch, read without
blocking, each target checked against the scan's stat so that a
replacement racing the enrollment is not watched. Stats are PHP's `lstat` with its stat cache
cleared first (it keeps the last path's), and when one fails libc's
`lstat` again for its errno (`__error`, `__errno_location`), which PHP
doesn't give, so that not found and permission denied are told apart as
Go's errors are. PHP keeps array keys like "42" as integers, so the scan
reads keys back as strings. The tests send notices through a fake backend
(`Backends::$fake`, mygo's fake.FileWatch), then use the system's.

The watch plugin (`Plugins\Watch`) maps the names pages use to roots
checked at setup (symlinks resolved, every component an ordinary
directory) and again at each watch, walks a page's path component by
component without following symlinks, and counts each watch against its
page (its `CallContext`) until the page goes away.

## Updates

`Updater` is mygo's: `myphp build` with myphp.json's `updates` writes the
manifest's URL and the public key into Info.plist (`MyPHPUpdateFeed`,
`MyPHPUpdateKey`, where mygo's `-ldflags -X` put them), signs a gzip'd tar
of the bundle and writes `update-<target>.json`; the app checks the feed,
downloads the archive or the delta of its version, checks its size and
signature, and renames the new bundle into place (`Native\Update`, mygo's
internal/update line for line, and `Native\Update\Publish` the build's
side, which bin/myphp calls). Nothing needs an extension the static build
lacks: Ed25519 is TweetNaCl's arithmetic in PHP (16 limbs of 16 bits in
64-bit ints, SHA-512 from `hash`), a signature checked in about 20 ms with
the JIT, RFC 8032's vectors in the tests; gzip and raw DEFLATE are the
system's libz through FFI (`Native\Zlib`, a `z_stream` driven chunk by
chunk); tar is read and written in PHP, with PAX headers for long names as
Go writes them, and extracted as it is inflated. The requests are
libcurl's (`Native\Curl`, which the fetch and WebSocket plugins share),
HTTPS only but for the loopback interface. bsdiff's suffix array in PHP
takes about 3 s and 70 MB a MB of the old file, so deltas patch files of
up to 32 MiB (mygo's 256 MiB) and include larger ones whole; applying a
patch streams it.

Waiting is a fiber's, as mygo's is a goroutine's: `App::go($fn)` runs
`$fn` in a `Fiber`, and `Native\Fibers::sleep()` inside one schedules its
resumption with `App::after` and suspends it, so a download in the
background lets the app run; outside a fiber the wait turns the run loop
(`App::wait`), or sleeps when the app doesn't run, and without the run
loop fibers wait in a queue `Fibers::drain()` runs (the tests).
`App::relaunch()` quits and, after the quit's `onQuit`, starts the
bundle's executable again; under `myphp dev` the app exits with 75 and
dev starts it again, as mygo dev does.

The updater plugin (`Plugins\Updater`) is mygo's on top: `Checker` keeps
the user's choices in updater.json and arms the next automatic check with
`App::after` (a generation number cancels a timer, which `App::after`
cannot), again on `Power::onResume` as timers stop while the Mac sleeps; a
check is a `Session` in a fiber (`App::go`), whose waits for the user's
answer and for the network suspend it, the flow of mygo's `run` line for
line. The window is mygo's page.html over the myphp bridge: `watch`
streams the session's views (a generator that polls the session's change
count), `fit` and `respond` answer it, and only the session's window may
call them (its `CallContext`). Links of the release notes open in the
browser through its page's `willNavigate` event, a WKNavigationDelegate
on every page's web view that asks the window about navigations of the
main frame the app did not start (the loads of `WindowOptions`' html and
url pass). The tests drive the fibers with `Fibers::runUntil`; mind that
an arrow function captures by value, so a flag a waiting fiber polls is
read through a `function () use (&$flag)`. The texts are mygo's
translations, converted to sprintf's numbered arguments (`%1$s` for Go's
`%[1]s`).

The same window in native UI (`Plugins\Updater\Native`) builds the
session's view each frame, as mygo's native/window.go: `Session::set`
invalidates the session's window (nothing for a page, which watches the
change count), and the window asks for the size its content needs one
frame after a layout of the same view at the same size, measured with
`Context::bounds`; the rich text of a status wraps at the width its
column had in the frame before, so the size settles over a frame or two.
A window written from right to left reverses its rows (`Node::reverse`,
which mirrors a flex row or column's children inside its content box),
its check box and its progress bar; release notes reverse their lists by
the script of their first letter.

## Games

The game plugin (`Plugins\Game`) is mygo's: `Game::view()` is a focusable
node that takes its input as it comes (`handleInput`) into an `Input`, and
in its draw closure, which the painter runs every frame it shows, makes a
`Painter` over the display list, asks for the next frame
(`animationFrame`) and runs the steps the frame's time owes: whole
`1e9 / rate()` nanoseconds of `Runtime::$now`, at most a quarter of a
second after a pause, so painting twice at one time runs no step twice.
Keys are virtual key codes, which are places on the keyboard already, as
WASD wants. The window losing the keyboard (`Runtime::$windowFocus`) lets
go of what was held, whose releases never come. Pixel art is an `Image`
made `pixelated()`: its quads are kind 13, which the shader samples at
the nearest pixel; `Painter::imageRegion` draws a part of it, mirrored
by a negative slot size, unsnapped under a transform so that it glides.
Gamepads come from GameController.framework through `ObjC::msg` ('f:'
for an input's value), in an autorelease pool of their own; a test
replaces `Gamepad::$scan` with fake devices. Tests mind that a test file
shares its scope with tests/run.php: `$before`, `$file` and `$name` are
the runner's.

## App services

mygo's smaller services, ported one by one. Deep links and files reach
`App::onOpenURL`/`onOpenFile` from the app delegate's
`application:openURLs:` (file URLs are files), and from the command line
of the launch (after the whenReady callbacks, as mygo) and of second
instances; what the app handles is what the bundle's Info.plist declares
(`CFBundleURLTypes`, `CFBundleDocumentTypes`, which `myphp build` writes
from myphp.json's `urlSchemes` and `fileAssociations`), read with a small
property list parser (`Native\Plist`), as `Bundle::info` now is.
`registerURLScheme` calls Launch Services through CoreServices' C API,
which needs no Objective-C, so it works before `App::run`. The single
instance is a Unix socket of the user's (`myphp-<uid>-<hash>.sock`, under
100 bytes) that the first instance listens on, polled on the run loop
every 0.1 s as `Process` is; a later instance writes its arguments and
working directory as JSON, shuts its side, and waits for the first to
close. The Keychain (`Secrets`) goes through Security's SecItem functions
over CoreFoundation dictionaries, also without Objective-C; mind that a
`const char*` return is a PHP string cut at its first NUL, so byte
pointers are declared `const void*`. `Network` keeps one
Network.framework path monitor whose handler runs on the main queue
(`_dispatch_main_q`), which the run loop drains, as PHP runs on the main
thread only; `status()` turns the run loop (`App::wait`) for the first
path, a second at most. The clipboard's providers are the pasteboard's
(`Native\Pasteboard`); `Clipboard::flush()` gives it every
representation, and quitting flushes it. Cookies are the default data
store's `WKHTTPCookieStore`, whose handlers come on the main thread; each
call waits for its handler with `App::wait`. Find in page is
`window.__myphp.find` of bridge.js (mygo's find.ts: the CSS Custom
Highlight API), `printToPDF` an `NSPrintOperation` saving to a temporary
file, as a sheet of the window, its end heard by a delegate of its own;
downloads are `WKDownload`s whose delegate is the navigation delegate.

## Documents

mygo's documents (`Documents`, `Document`, `Native\Docs`), without its
goroutines: everything runs on the main thread, so the content needs no
lock and a write captures it and its change number at once; an edit made
while `write` runs (the app's closure) stays unsaved because the change
number moved. Timers are `App::after` with a number per document: a new
schedule bumps it, and a timer of an older number does nothing, as Go's
`timer.Reset`. A close or a quit that must ask cancels itself and asks on
the next turn of the run loop, then destroys the window or quits again
(`Registry`'s resolved flag lets the second `applicationShouldTerminate:`
through). What documents ask of the app goes through `Native\Docs\Host`
(dialogs, `later`, `after`, the clock, the data directory, quitting) and
windows through a `Frame` (`WindowFrame` of a `Window`), which tests
replace: tests/documents_test.php runs the flows on a test clock without
AppKit. Files are written next to themselves and moved over with
`NSFileManager`'s `replaceItemAtURL:` (`Files::$replace` in tests); a
permission error is `NotWritable`, which makes saving ask for another
file. Recovery sessions are locked with `flock`, which another process's
lock refuses and a crash releases. Watches are `App::watchFiles` looked at
with `next(0)` every 0.25 s; a file watch reports a rename as a remove of
".", whose identity (device, inode) is then searched for in its directory.
Menu roles whose selector is '' are documents': their click is
`Registry::role`, deferred to the next turn so the menu is done tracking;
`fileMenu` expands to DocumentGroup's menu and `openRecent` to the recent
documents each time the menu bar is built, which adding one rebuilds
(`App::relabelMenus`). Mind that the run.php scope is shared: the test
avoids `$file` and `$name`; and that a test running as root can write
read-only files, so the read-only flow runs only for other users.

## Protocol and the frontend

Custom schemes are WKURLSchemeHandlers (`MyPHPSchemeHandler`), set on each
web view's configuration for `Protocol::schemes()` (the handled ones and
`myphp`, the frontend's) as it is made, since WebKit takes them only then.
A request's method, URL, headers and body (HTTPBody, or HTTPBodyStream read
whole) become a `ProtocolRequest`; `Native\Scheme::serve` runs the handler
on the main thread and answers the `WebKitTask`, which retains the
WKURLSchemeTask until it finishes, fails or WebKit stops it, and is never
touched after: a string body goes in 256 KiB `didReceiveData:`s, an
iterable a chunk a turn (`App::later`), its first chunk read first to sniff
the content type. CORS headers go to the origins the window trusts, and a
preflight of theirs is answered without the handler. WebKit can't make a
download of what a scheme handler serves, so the navigation policies cancel
those (an attachment, by the disposition `Scheme` noted for its URL, or a
link with the download attribute) and serve the URL again into the file
`willDownload` chooses. Trust (`Native\Frontend::isTrusted`) is worked out
from the page's URL as each call comes: about:, file:, myphp: and handled
schemes, the dev server (`MYPHP_DEV_URL`, which `myphp dev` sets after
devUrl takes connections), loopback servers in development, and
`trustedOrigins`; WindowOptions' html is about:blank. A URL without a
scheme resolves against the dev server or myphp://localhost/, which serves
`App::setFrontend()`'s directory, `MYPHP_FRONTEND_DIST` under `myphp dev`,
or the bundle's Resources/frontend. tests/protocol_test.php drives
`Scheme::serve` with a task of its own.

## Canvas and charts

A `UI\Canvas` is drawn by a painter of its own (`Painter::forCanvas`) into
a display list it keeps, a pixel a point (`CanvasText` reports a scale of
1), and placed in shelves of two pages of the image atlas's texture array
after the images' (`CanvasAtlas`), which no CPU upload touches. Its bitmap
is an `Image` without pixels whose slot is the canvas's, so painters show
it as any image. Each frame the renderer takes the canvases drawn, or
placed anew after the pages filled up, oldest draw first (inner canvases
before those that show them), renders each list into a texture of the
canvas's size, cleared to transparent, through a pipeline of the quad
shader that writes RGBA with alpha kept premultiplied (source alpha ONE),
as the atlas holds images, then blits it to its place: a pass may not
sample the texture it renders into. Charts (`UI\Chart`) are mygo's: d3's
tick spacing exactly (`Ticks`), bands, time ticks on round times of the
times' zone, marks placed in percentages of the plot's area
(`Node::leftPercent`…, an absolute node against its parent's content box,
its margins moving it), and an axis of labels in slots of no size, spaced
by children that grow by fractions (`Node::grow`, a float now).

## Right to left

A node's `rtl` is set as it is added, from the node on the stack (the
root's from the window's locale, or `MYPHP_DIRECTION`), unless the node
was given a direction of its own (`dirSet`); `Node::setDir` then swaps
the logical edges it holds (bits in `$logical` say which sides were set
as start or end, so `padding()` and the like, physical, clear them) and
passes the direction on to children without one of their own, so a
direction given after the children were added still reaches them.
Layout stays left to right and mirrors positions as it places them: a
row's child at `2·(x + left padding) + view width − cx − child width`, a
column's cross position, a grid's columns and a wrapping row's lines
the same way. A row scrolled sideways keeps its offset counted from the
right: the content is placed from the right edge of the viewport and
moved right by the offset, so the start shows at 0 and the wheel and
the scroll bar's ratio work unchanged except for the thumb's drag.
Controls ask `Context::arrows()` for which of Left and Right go back and
forth along the line; the runtime's slider and group entries keep the
node's `rtl` for the keys and the pointer.

## Inspector

The inspector (`UI\Inspector`) is the runtime's, in a window whose
developer tools are on. It takes events before the view, in
`Runtime::frame`: its keys, and the pointer while it picks, which
`Runtime::contains` tests against the nodes of the last frame, the last
in the tree's order first. The window and the headless harness build
the view in a Context as wide as `contentWidth` leaves it, then the
panel (`InspectorPanel::build`) at the root with `Context::overlay`: an
absolute column at the content's right edge, keyed, left to right, its
own theme (`Node::$theme`, which the painter takes for the node and those
inside it); the root keeps clear of it with padding at its right. After
layout `snapshot` walks the root, the panel left out, into rows the next
frame's tree shows, and describes the node chosen; a hash of what the
panel would show, against the one it was built from (`stale`), asks for
another frame, so that frames settle once nothing changes.
`Context::add` notes the keys it gives, where the node chosen was built
(`Inspector::callSite`, the first frame of `debug_backtrace` outside
`src/UI`), and a key given twice in a pass, which `Runtime::duplicateKey`
throws in the tests and logs once in apps. `InspectorOverlay` paints the
highlight after the tree, through the node's world, clipped to the
content.

## Audio

`MyPHP\Audio` mixes in PHP, as mygo mixes in Go, but on the main thread:
the system's audio thread cannot call into PHP. The Audio Queue
(`Native\AudioQueue`) is made with the main run loop and its common
modes (`AudioQueueNewOutput`'s run loop arguments), so that its callback,
a PHP closure through FFI, runs there, menus and live resizing included;
each callback asks `Output::render` for 1024 frames, which decodes a
little of each stream first (`Stream::fillAll`, what mygo's decoding
goroutines do), mixes (`Mixer`, the voices of mygo's mixer.go: a
Catmull-Rom resampler, gains as a 2×2 matrix ramping over 5 ms) and
packs them as 32-bit floats into the buffer. Four buffers keep about 85
ms ahead, which is what a busy frame of the main thread may take before
the output underruns. Five seconds of silence close the queue, from
`App::later` (not from its own callback); a watchdog reopens one that
stopped calling back for a second. A device that does not open is
stood in for by `NullDevice`, which mixes on the app's timers so that
players still end on time. Sounds keep their samples as 32-bit floats in
a string, which `unpack` reads 256 frames at a time. The Vorbis decoder
is the spec's: codewords assigned as libvorbis's `_make_words`, looked up
10 bits at a time; floors of type 1; residues 0 to 2; the inverse MDCT
through a DCT-IV of N/2 points as an FFT of N/4 (unscaled, as libvorbis's
`mdct_backward`); seeking from the last page whose granule position is a
long block before the frame, the frames decoded held until a page's
granule position places them. It decodes 6 s of 44.1 kHz stereo in
about 360 ms here, and matches libvorbis to within 1e-4.

## Flex layout and element styles

`Layout::arrangeFlex` is CSS flexbox as mygo's `flexLayout`, inside
myphp's measures (`mw`, `mh` and `minMw` hold the margins; the sizes
`arrange` takes do too): each child's basis, least and largest size
along the axis, `flexible()` growing or shrinking them with freezing
(mygo's `resolveFlexible`), automatic margins before `justifyOffsets`'s
spaces, then each child across. A wrapping row lays lines out with
`arrangeRow` and shares the free height by `alignContent`. Absolute
children go last (`arrangeAbsolute`), in the padding box by their
insets, `attach` anchors or `attachTo` a node placed earlier in the
frame (`$laid`); `at()` places from the content box, as before. A
list's `rowWidth` widens the box its rows stretch in before the first
child is measured across, and its offset moves them as a scroll
container's does; `scrollWith` moves a node's children by another's.

What a node paints beyond a color and a radius goes through two quad
kinds that take the slot after them too, its kind -1 so that the vertex
shader draws nothing for it: a box (14: four radii, four border widths
and their color, solid or dashed, filled with a color, a gradient in
sRGB or Oklab along any line, or stripes) and a box shadow (15: outside,
the shape blurred, Evan Wallace's erf along x and four samples along y,
cut out under the box casting it; inside, the padding box less the hole,
blurred), mygo's shader ported to points with coverage in pixels.
`Painter::node` keeps the old one-slot quads where a node has neither,
so the lists the tests read stay as they were. Invisible nodes are
recorded as inert and not shown; a pass-through node is never under the
pointer; a disabled container passes `isDisabled` to what is built in it.

## Widgets and context (v1.08)

Actions (`Node::onClick` and the others) are noted while a pass builds
and run after it, changes and submissions first, as mygo's
`runNoticeActions` and `runActions`: one that runs makes the frame build
again, as a click consumed does. Handles and fields bound to the focus
(`focusBind`) apply after the build too, before the actions: a request
for a control not built waits, and the user's focus otherwise writes the
field, which the runtime holds by reference, told apart by
`ReflectionReference` ids. Toasts live in the runtime with the time each
showed while their time ran (stopped under the pointer, with the focus
in them, or the window in the background); the theme's look or the
app's (`toastViewportBase`) builds them once a pass. A tip of the app's
own (`tooltipBase`) is a tip of empty text: the runtime decides it after
the build as for others, and asks for the frame that builds it, which
the painter's own tips leave. Sliders map the pointer across their
content box, inside their padding, as mygo's: the theme's is padded by
half its knob. The gradient fill and stroke of a path paint its mask
from the glyph atlas as a box quad flagged 8, its gradient in the box's
second slot.

## App, page, menus and web views (v1.09)

The quit is mygo's sequence: `applicationShouldTerminate:` asks
`App::shouldQuit`, which runs the onBeforeQuit listeners, closes each
window as the user would (its 'close' listeners may keep it, and then the
app), then the onWillQuit listeners; while it closes them
`App::quitting()` is true, and documents leave the closing alone, as the
quit took care of them. The last window closing calls
`App::lastWindowClosed`: the onWindowAllClosed listeners, or without any,
a quit (documents' apps register one, as mygo's do).

The page runtime keeps what PHP tells it: the preferences (`Theme`, as
`ThemePreferences` encodes them) in `window.myphp.preferences` and the
`--myphp-accent` and `--myphp-text-scale` variables, the language in
`locale` and `dir`, told again on `dom-ready` (a page that started
loading as the language changed had the one before; the runtime tells
its listeners only of a language it did not have), the room of the window
controls in `--myphp-titlebar-*`, all in a constructed style sheet,
which a Content Security Policy allows. A press on an element whose
`--app-region` (or `-webkit-app-region`) is drag posts `drag`, and the
window moves with the web view's last mouseDown (`Native\WebView::lastDown`,
as the message comes after the event); a double click posts `dblclick`.
`Page::eval` is mygo's: `callAsyncJavaScript` (macOS 11) of the code as an
expression, awaited and JSON-encoded; a SyntaxError means it is no
expression, and it runs as an async function's body, nothing having run
twice. The JavaScript panels (alert, confirm, prompt, a file input) are
sheets of the page's window (`Dialog`), its completion handler called
before the delegate returns, the run loop turning meanwhile.
`window.open()` with a handler makes a window (`Window::opened`) with
WebKit's configuration and returns its WKWebView, which WebKit loads.

A web view in native UI (`Window::newWebView`, `Context::webView`) is a
WKWebView in a flipped clip view under the Metal view, in the window's
content view. Its node paints a hole: `DisplayList::hole` records the box
quad's slots, and Metal draws them with a pipeline whose blending is
(zero, one minus source alpha) for color and alpha, so what is there goes
by the quad's coverage — rounded corners and the opacity fade the page in
— and what paints after it shows over the page; a pass splits its draws
at the holes, changing the vertex buffer's offset. The runtime notes
where each shows (`Runtime::$webPlaces`: frame and visible clip, on whole
pixels), and the window places and stacks the clip views in paint order
after the frame, hides the others, and makes the layer non-opaque while
holes show. The Metal view's `hitTest:` returns nil where
`Runtime::webViewAt` says the pointer is a web view's — its node is what
takes the pointer there, nothing painted over it taking it — and AppKit
hits the web view under it. A web view's node the focus goes to (Tab)
gives its page the keyboard.

`Shell`, `Screen`, `Power` and `Theme` are AppKit's and CoreGraphics'
calls (NSWorkspace, NSScreen with CGDisplayRotation and CGDisplayIsBuiltin,
NSProcessInfo activities, IOPSCopyPowerSourcesInfo,
CGEventSourceSecondsSinceLastEventType, NSApp's appearance), the C ones
through `FFI::cdef` on their framework. A file's icon is drawn into an
sRGB bitmap of the size asked for and encoded as PNG.

## Accessibility

VoiceOver reads native content as mygo's does (`ui/access.go`,
`internal/darwin/access.go`). Once AppKit first asks the window's view for
its children, the view pushes `['accessOn']`, and from then on each frame
describes itself (`RuntimeAccess`, a trait of `Runtime`): an `AccessTree`
of `AccessNode`s, a parent before its children, which `Native\Access`
gives AppKit as `NSAccessibilityElement`s of its own class, kept by node
ID from frame to frame and set again only where they changed. A node's
`Role` is the one its widget sets (`Node::role`), or one found from what
it does: a field is a text field, a text a text, a button or a node that
is focusable and asked whether it was clicked (`Runtime::clicked` notes
it) a button, a scroll container a scroll area, a labeled or focusable
node a group. Controls are named by their text (`Runtime::innerText`),
or `Node::label`; the states and values of mygo's (`checked`, `expanded`,
`range`, `level`, `value`, `activeDescendant`, `nameFrom`, a tooltip as
the description) are `Node`'s fields and methods. What is behind a modal
is inert (not `reachable`). A list's rows are nodes of their own around
the list's children that each row built (`Node::$rowStarts`), as mygo
builds each row in a box: "row 5 of 10,000" from their index and the
count, built or not, chosen by focusing them; a table's rows are `ROW`s
of `CELL`s, a grid's items its list's items. Actions come back as
`['access', id, action, …]` events and act as the pointer and the keys
would: a press is a click (`activated`), an increment the arrow key with
its selector, setting a field's value selects all and inserts, expanding
a tree's item is `Runtime::expanded` for the tree to read. Toasts and
`Context::announce` are read out. Not ported, there being nothing of
them here: web pages inside native content, links inside rich text, the
formats of a rich text editor's runs, disabled controls and menus drawn
in the window.

## Animation

Values that move are mygo's motion.go, as `Context` methods taking the
node they belong to (`ContextMotion`): `animate`, `animateWith`,
`animateTo` (an `Animation`: a curve of `$duration` seconds along an
`Easing`, or a spring, `SpringOptions` by duration and bounce as SwiftUI's
or by mass, stiffness and damping as react spring's; with a `$delay`),
`spring`, `jump` (a drag setting the value, whose velocity a spring then
takes over), `velocity`, `settled`, `arrived` (the one frame it comes to
rest), `animateColor` (mixed with premultiplied alpha), `keyframes`
(`Keyframe`s with `KeyframeOptions`: run, repeat, alternate, delays),
`loop`, `inView` and `disableAnimations`; `Motion::rubberband` and
`Motion::project` compute targets, `Easing::cubicBezier` and
`Easing::bounce` make easings. Each value is a `MotionChannel` (or a
`KeyframeSequence`) in `Runtime`, by node id and key, kept while the node
is built: a curve samples its easing, a spring is solved analytically, so
frames however far apart follow the same trajectory, and a new target
takes over from where the value is, with its velocity. A value moving asks
for the next frame (`Runtime::animate`).

Time is `Runtime::$now`, in nanoseconds, read once a frame from
`Runtime::$clock` (hrtime's when null; tests set it, as mygo's clock);
`Runtime::$frameNumber` counts frames, for `arrived`. While the desktop
asks for less motion (Reduce Motion: `App::reduceMotion`, read from
`NSWorkspace` and again on its display options notification), values go to
their targets at once and sequences finish, but colors still change
smoothly; `disableAnimations` makes even colors jump, for one frame.

A `Timeline` (`Context::timeline($node, $key, TimelineOptions)`, mygo's
timeline.go) plays several `Tween`s along one playhead: each `tween()`
returns the value it shows in this frame, placed after the one before it,
with it, at a time or at a `label()` (`Placement`, as GSAP's position
parameter). Its options run it over, repeat and alternate it, delay it,
pause it, reverse it from where the playhead is, and seek it to a time or
a progress, which hold the playhead; `duration`, `time`, `progress`,
`settled` and `arrived` report it. A `TimelineState` keeps the playhead
from frame to frame (by node and key in `Runtime`, gone with the node):
only time it played moves it, so the frame that lets a pause or a seek go
plays none, and a definition that grows plays on from where it rested.
With less motion it is at once where it would come to rest, but seek,
progress and pause still put and hold it. The options apply in the pass
they are given in (`Runtime::$pass`), so a button that plays it over shows
that in the frame it is clicked in.

`Context::transition($node, ElementTransition)` is mygo's transition.go:
a node moves and resizes from where the last frame showed it to where the
layout puts it (its place in its parent, its size, its background and
border colors, as `position`, `size` and `colors` choose; 200 ms easing
out, or an `Animation`, a spring keeping its velocity, a delay
staggering), comes in from an `enter` and goes to an `exit`
(`TransitionMotion`, mygo's Motion: moved, faded, collapsed along its
parent's direction, scaled and turned), and a node of a `match` comes from
where the node sharing it showed the frame before, laid out at each size
on the way, in its colors. `RuntimeTransition` runs after the frame's
layout and before it is recorded, parents first, with boxes relative to
the parent's, as mygo's: a node moves with what is inside it
(`shiftTree`), resizes by laying its children out at the size it shows
(`Layout::arrange`), collapses by cutting them off (`Node::clip`), fades
by its opacity and turns by its transform. A node gone with an exit leaves
as its node of the frame before, in its parent's `ghosts`: painted under
its siblings, in no record, so it takes neither room, the pointer, the
focus nor assistive technology's notice, until its exit is over; built
again meanwhile, it comes back from where its copy was. Nodes appearing
with a parent that is new too, as a page, appear at once, and changes
that resizing the window makes do not animate; with less motion nothing
moves, colors still change smoothly, and what fades without collapsing
fades in place; `disableAnimations` shows the frame at once.
`Context::transitioning` reports a node on its way. A border of the
theme's own, no `borderColor`, changes at once, as the color it is known
here only when painted.

`Node::animatePose(Pose, Animation)` is mygo's pose.go: a node moved,
scaled, turned and faded after its own transform; `Runtime::record`
heads its five channels for the pose asked and sets where it shows (the
pose's map, `Node::$poseM`, before the transform in `transformAt`, and
its fade, which its opacity is times), so the pointer follows it as it
follows transforms. mygo moves poses in frames that only paint; frames
here always build, so a pose moving asks for the next one as any value
does. With less motion it moves at once and only fades smoothly.
`Node::contentTransition(ContentTransition)` is mygo's content.go: as a
text, an icon or a picture changes, the old fades out as the new fades
in, an icon shrinking away as the new grows in (its old draw closure, in
the node's box), and numeric text on one line rolls the characters that
change between those it shares at its start and end, which stay, the end
sliding to where it goes, each band its own clip (`ContentState`, kept
from paint to paint in `Runtime::contentStep`).

The widgets move as mygo's: a switch's knob over 140 ms, a slider's knob
growing into a lens over 150 ms as it is held (a range slider's and a
color picker's channels too), a collapsible's and an accordion's arrows
turning and their panels growing into view over 200 ms (`Node::clipY`
cuts off the panel), a sidebar section's arrow over 150 ms. `Node::clip`
and `clipY` cut what children draw off at the node's edges, and what is
cut off, or scrolled away, is not in view (`Runtime::record` keeps the
nodes that showed).

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
`src`) into a phar whose stub requires the entry, which `MachO::embed`
puts in phpmicro, a static PHP with ext-ffi, with `ffi.enable=1` and
`memory_limit` as its INI: one Mach-O, a proper main bundle, signable
and notarizable (the payload inside `__LINKEDIT`, v1.28 below). The micro build carries no opcache: in
the micro SAPI opcache's `zend_get_file_handle_timestamp` takes a
`phar://` path as a pointer (SIGBUS at an ASCII address) and its JIT
segfaults on arm64 even on a plain file, so the static launcher runs the
interpreter without JIT; `dev` and the exec launcher keep the JIT flags
on the Homebrew php. Inside the phar, `boot.php` finds
`./myphp` and the autoloader; `bridge.js` is read through the phar
stream; `dlopen` only touches system frameworks, so nothing needs to be
on disk. `DirectoryIterator` returned nothing on the container's mount
during testing while `scandir` worked, so the CLI walks with `scandir`.

Plugins with native libraries say so as mygo's do, in a
`mygo-plugin.json` next to their sources (`src/Plugins/<Name>/`), the
same file as mygo's: a build per platform, its URL and SHA-256. `myphp
build` puts those of the plugins the app uses at the top of
`Contents/Resources` (`natives()`), downloaded with curl into
`~/Library/Caches/mygo/natives/<sha256>/` once and checked there, the
cache mygo's CLI and the plugins share; a plugin is used when the app's
PHP files name its namespace, where mygo asks `go list -deps`. The
plugins find the library there first (`Plugins\NativeLibrary`: the bundle's
Resources from the framework's path, or from `Phar::running()` in a
static app) and never download it inside an app. A static app's
executable is phpmicro running its stub, whatever arguments it gets, so
the stub runs `Pty/spawn.php` when its first argument is
`--myphp-pty-spawn`, which is how the terminal starts its programs
there (`PHP_SAPI === 'micro'`).

Local state and ids: `Runtime::local` keys a control's state (a
combobox's popup, a picker's HSV, an alert's opening) by its node's id,
and an unkeyed node's id is its place in the build order, so content
that comes and goes before such a control must be keyed (`key()`), or
the control inherits the state another node left under the same id;
`modal`, `alertDialog` and `findBar` key themselves, and the widgets
example keys each tab's content by the tab. A key is the one node's:
`add` consumes it, so a keyed leaf (a range slider's knob) does not
pass its key to the next node built — which once gave the accordion
after a range slider the high knob's id, and Tab skipped the knob.

Focus and overlays: as an overlay opens (a node placed at the root with
`at()`, or one that `block`s) the runtime notes who had the focus, and
when the overlay is gone with the focus inside it, gives the focus back
to that node if it is still built — mygo's openers and restoreFocus: a
calendar closed by Escape leaves the date input focused, so Tab goes on
from there.

A node given a size of its own (`size()`, a `Node` built with a width or
height) keeps it across a container that stretches its children (a
column's width, a row's height), as mygo keeps an explicit Width or
Height; only nodes with no size of their own across are stretched.

Tests: `php tests/run.php` runs the `*_test.php` files in `tests/` with
`Headless` (fake metrics of 7 × 16 a character, a Runtime fed events as
the view would send them — a key event then its selector, the selector
taken by the focused field's TextState first, as the view does — and the
widgets example loaded without its window). `widgets_test` sweeps the
Tab order, what the keys do at each kind of control, the alert's focus
and the cursor; `layout_test` the grid, wrapping and a split;
`controls_test` each control on its own state — combobox, autocomplete,
token field, date, calendar and time inputs, color picker and well,
form and find bar, checkbox group, breadcrumbs, alert, grid view, the
selection of several, rows and items dragged, the title bar's handle,
SVG icons and avatars; `svg_test` the SVG renderer by the pixels it
leaves — shapes, path commands, transforms, the cascade, gradients,
strokes, opacity, clips, masks, use and symbol, path masks; `files_test` the files example on a folder it
makes — opening and going up, the find bar filtering, the table's sort
keeping the choice, Delete… asking. `Headless::example()` loads an
example's file up to its window in a namespace of its own, so each
can declare `view()`.

`Painter` takes a `TextPainter` (`TextMetrics` plus `paint`, `imageSlot`
and `scale`), which `Text` implements and the tests fake, so a frame can
be painted into an FFI buffer without CoreText or Metal. `php
tests/bench.php` times the PHP half of a frame — build, layout, record,
paint — for the examples' views; on PHP 8.3 with the JIT, two cores:

| view | build | layout | record | paint | ms/frame | quads |
|---|---|---|---|---|---|---|
| widgets General | 0.86 | 0.21 | 0.11 | 0.39 | 1.6 | 391 |
| widgets Advanced | 0.85 | 0.18 | 0.08 | 0.15 | 1.3 | 296 |
| files, 2000 entries, icons | 1.53 | 0.51 | 0.26 | 0.48 | 2.8 | 587 |
| files, 2000 entries, table | 1.21 | 0.41 | 0.21 | 0.50 | 2.3 | 644 |
| svg: folder icon, 32 / 96 px | | | | | 0.2 / 1.0 (to draw once) | |
| svg: gradient, clip, dashes, 256 px | | | | | 65 (to draw once) | |
| list, a million rows | 0.14 | 0.04 | 0.03 | 0.10 | 0.3 | 342 |
| list, a million rows, 1440p | 0.26 | 0.08 | 0.06 | 0.20 | 0.6 | 710 |
| table, a million rows × 5 | 0.60 | 0.21 | 0.14 | 0.21 | 1.2 | 626 |
| 2000 texts, not virtualized | 1.86 | 0.92 | 1.36 | 0.89 | 5.0 | 1970 |
| textarea, 2000 lines, typing | | | | | 1.4–1.7 | 3.6k |

A node costs about 4 µs to build (`new Node` 0.4 µs for its 78
properties, a keyed id's xxh64 0.2 µs), 0.5 µs to lay out and 0.7 µs to
record; a frame of the examples is 0.3–2.8 ms (the file manager's grid
of 63 cells, ten builder calls each, is the dearest), which leaves the 16.7 ms
budget to CoreText (shaping is cached by line) and Metal. The display
list is clipped to the window before the paint, so what lies outside
costs nothing. What the benchmark cannot time is the Mac's half:
`MYPHP_STATS=1 ./run.sh inbox 1000000` prints it a second at a time.

The Mac's half, measured by the developer (`MYPHP_STATS=1 ./run.sh
inbox 1000000`, scrolling): 55–59 frames a second at 1.4–2.5 ms a
frame, rising to 3–4 ms as faster scrolling shows more never-seen
strings a second (CoreText shapes each new line once; the line cache
sweeps by age and stays near 300–2000 lines). What that run also showed
was memory climbing 2 MB a second: `ListState` kept every measured row
height in a PHP array (some 32 bytes a row, a million rows scrolled
through would be 32 MB). It now keeps the first height measured as the
default, a bit per row measured (mygo's blocks keep a float32 a row;
a million rows are 125 KB here) and an entry only for rows of another
height, so a list of rows all one height stores nothing per row;
`forgetHeights()` drops them when the rows change under the list.
The second run, with live memory and the GC in the stats line, showed
the climb was no leak but the line cache itself: live memory followed
"lines cached" at some 9 KB a line (a PHP array of six floats a glyph,
and the carets), 1900 lines at the fastest scrolling. A cached line
now holds its quads as packed float32s (`pack('g6')`, 24 bytes a
glyph), unpacked once into `hot` while the line is painted and dropped
by the sweep once it leaves the view, and its carets only once a field
asked for them: under 2 KB a line. The stats line (`MYPHP_STATS=1`)
prints live and held memory, the glyphs in the atlas, the GC's runs
and the nodes with local state.

## The examples and what they needed (v1.11)

mygo's examples are ported one for one; what they used that myphp lacked
came with them.

- **A tint in the radius slot.** An image quad's 16 floats are all taken
  (place, size in the atlas, page, alpha, radius, kind, uv, clip), so a
  tinted image (`ImageOptions::$tint`) is not rounded and its radius is
  -1 - the packed rgb, exact in a float32 (0xffffff + 1 is 2^24); the
  shader multiplies the premultiplied color by it and the alpha slot
  carries the tint's alpha.
- **A list's pinned header.** With `ListState::$header`, the header of
  the section at the top is the header row built at the anchor, or, when
  it is above the rows built, that row built after them out of the flow
  (absolute). The list notes it (`Node::$pinned`) and the next header
  built (`$pinStop`); Layout places it after the rows, at the top or
  pushed up by the next, and `Node::paintOrder()` paints it last and
  records it last, so it is over the rows for the eye and the pointer.
  The kids stay in row order, which the rows' measures, the dividers and
  the reorder read.
- **Clicks seen without taking them.** A click is taken by the first
  node that asks; an editable text in a row must know of the row's
  click while the list, which asks after the row is built, still takes
  it: `Runtime::clickOver()` and `doubleClickOver()` look without taking.
- **Trimming a path** flattens its curves (a quarter point a line at
  most), keeps the length asked for and replaces the commands, as the
  masks and the atlas keys read the commands; the length kept fades a
  stroke shorter than its width.
- **Glyphs rasterized for the CPU.** `Context::renderGlyphs` asks Core
  Text for each glyph's mask at the scale asked (not the display's) and
  composites them as their quads blend (over), for an app's own effects
  (effort-slider blurs its letters).
- **Integers that overflow.** PHP's integers turn to floats past 2^63, so
  the examples' hashes multiply 32-bit halves (`mul32`) where Go's
  wrapped; `Random\Randomizer` seeds what Go's PCG did.
- **Examples in one process.** `Headless::load()` evaluates an example in
  a namespace of its own with its constants there (two name WINDOW_W);
  the examples' autoloaders require any class name, so nothing in the
  framework may autoload a name that is no class: `TypeScript` reads a
  PHPDoc type's class in the namespace of the code it documents, loaded
  classes first, and `Ipc::arguments` asks whether a parameter's type is
  a backed enum only when it is no builtin type.

## Development and the page packages (v1.13)

- **The development app.** `myphp dev` builds "<name> Dev" in
  `.myphp/dev/darwin-<arch>` as `myphp build` builds an app, but its
  launcher runs the project's entry in the project (an absolute directory
  compiled into it), so no file is copied but the resources. It is built
  again when a poll (every 250 ms) of what it is made of sees a change:
  the PHP files of the project and the framework, the configuration, the
  icon, the entitlements, the resources and the catalogs, not the files
  generated from them (the client, the catalogs' class and module), which
  would build it twice. A build whose fingerprint (the contents of those,
  the resources' names, modes, sizes and times, the icon, the Info.plist)
  is the running one's does not relaunch: "No change". A build that fails
  (`Failure`, which `fail()` throws and main prints) leaves the running app.
- **Stopping the app.** SIGTERM, then SIGKILL after 3 seconds, as mygo's
  dev; the app takes SIGINT and SIGTERM as Quit through `Native\Signals`:
  `sigaction` sets them to SIG_IGN (signals the parent ignored stay so)
  and a `DISPATCH_SOURCE_TYPE_SIGNAL` source on the main queue calls a
  block, PHP on the main thread, as the run loop drains the queue; a
  second signal exits with 128 + its number.
- **The launcher names the bundle.** The process of the exec launcher is
  php, whose path says nothing of the bundle, so the launcher sets
  `MYPHP_BUNDLE`, which `Native\Bundle::path()` reads once and takes out
  of the environment (the processes the app starts are not in the bundle),
  and passes its own arguments on after the entry.
- **myphp.config.ts.** Bun (`bun run -`) or Node.js 22.6+ (types
  stripped) runs a loader read from its standard input, which imports the
  file, calls its default export with the command when it is a function
  and writes JSON to a temporary file; project() then checks it as
  myphp.json's. What the configuration prints goes to stderr.
- **myphp-runtime from its sources.** packages/runtime exports `.ts`
  files: Vite transforms them, tsc with `moduleResolution: bundler`
  checks them, and Bun runs them. In the repository, tsconfig.json's
  `paths` resolve `myphp-runtime` and `myphp-runtime/*` for the plugins'
  clients, `bun test` and `tsc -p .`; `tsc` on one file refuses to run
  next to a tsconfig.json (TS5112), so the test of the generated locales
  module checks the whole project. The tests are excluded from `tsc -p .`:
  their Bun types are no dependency.
- **The web template.** mygo's: Vite, TypeScript, bun, a Greeter with a
  `tick` event, `myphp.config.ts` importing `defineConfig` from
  myphp-cli; `myphp init` runs `bun install` and generates the client.

## Packaging (v1.12)

`bin/myphp build` is mygo's `mygo build` for macOS; the work is in
`src/Native/Build/` (internal classes the CLI calls, tested on any system
by `tests/build_test.php`), the steps that need macOS's tools (cc,
codesign, ditto, hdiutil, xattr, xcrun) run them.

- **One directory a platform.** Each target builds in
  `<out>/.staging-darwin-<arch>-<pid>`, which then replaces
  `<out>/darwin-<arch>` (the old one renamed away first, so a running app
  keeps its files): a failed build leaves the last one, and nothing stale
  stays.
- **The order of signing.** The code among the resources is signed from
  the inside out (Mach-O files, then the bundles holding them), then the
  app with `--deep`; ad hoc signing keeps the signatures that copies have.
  The disk image is made of the signed app with `ditto --noclone` (HFS+
  cannot keep sparse files); notarizing staples the ticket to the image
  and the app, and the update archive is made after, of the stapled app.
- **The disk image's window without Finder.** `DsStore` writes the
  .DS_Store byte for byte as dmgbuild's ds_store package does
  (`tests/testdata/dmg.DS_Store` is its output), and `binaryPlist` the
  window's settings as Python's plistlib; no AppleScript, no automation
  permission, works headless.
- **hdiutil's "Resource busy".** Spotlight or an antivirus holding the
  image: retried after 2, 4, 8 and 16 seconds, as create-dmg does; the
  disk is detached by its device (/dev/diskN), which works after a
  failed eject too.
- **Universal resources.** darwin-arm64 and darwin-amd64 merge into one
  tree; their Mach-O files become universal binaries (`MachO::writeUniversal`,
  16 KiB aligned slices, like lipo); identical files and links ship once;
  anything else is an error naming both files.
- **The icon in PHP.** mygo's resize sums premultiplied colors over
  each output pixel's source area: for 1024 pixels and the eight entries
  (two pairs share their size) that is 1.6 s of PHP; the PNGs are 8-bit
  RGBA rows without filters, deflated by the system's zlib.
- **S3 without an SDK.** Signature Version 4 by hand (`S3::sign`, checked
  against the example of AWS's documentation), the PUT done by curl with
  `Expect:` off (a 100 Continue would come before S3's answer) and `-D -`
  for the status line and `X-Amz-Bucket-Region`.

## Windows out of sight (v1.12)

- **No frames nobody sees.** AppKit keeps calling a covered, minimized or
  other-space window's draws, so an animation went on spending a core.
  As mygo's runtime (`held`), a native window whose `occlusionState` has
  no `Visible` bit asks for no further frame and no wake; it marks itself
  held, and `windowDidChangeOcclusionState:` draws it again once some of
  it shows, its motions going on from where the clock puts them. Events
  still draw the frame they need. A popover's window is AppKit's, whose
  occlusion the delegate does not hear of, so popovers draw as before.

## Widgets, keys and lists (v1.14)

- **Passwords.** `TextState::$password` holds for the frame that asks
  for it (`Context::input` clears it each build): `shown()` is the text
  as bullets, `shownIndex`/`fromShown` map a caret between the two, which
  the painter, the caret's place and the input method's ranges use; Copy
  and Cut take `copyable()`, nothing of a password, and assistive
  technology hears only that it is one.
- **Shortcuts behind a dialog.** `Runtime::$modal` is the last blocking
  overlay recorded; `Context::insideModal()` tells a build outside it,
  whose `shortcut()` and window `onShortcut` take no keys. The dialogs'
  Escape is an `overlayShortcut`, as mygo's.
- **Rows with keys.** `ListState::sync` (before the rows build) finds the
  key of last frame's anchor row near where it was, and moves the place,
  the known heights and the choice with their items; `remember` (after)
  notes them. Rows are scoped by key (`rowScope`), so their state follows
  them.
- **Tables.** `TableLayout::arrange` gives the columns' order: fixed
  columns where they are, the others in the layout's order, then in
  theirs. Header cells are keyed by column id, so the header dragged keeps
  its press while the columns move; `moveColumn` swaps it with a neighbour
  whose middle the pointer passed (by last frame's rects) and takes that
  neighbour's width off the drag, so the header stays under the pointer.
  The end edge (`end(0)`, the left right to left, the drag mirrored) is
  asked for before the header, so a press there resizes rather than
  moves. A double click there sets `TableDrag::$fit`; the cells of that
  column built in the frame are measured by `Node::$colFit`, which
  `Layout::measure` runs before the table is measured (mygo's colFit). The
  list's rows are `rowWidth()` of the columns' least, and the header
  `scrollWith()` the list. The header menu's closure was typed
  `MyPHP\Menu` (Context imports it) where the runtime passes a
  `UI\Menu`: it never ran; it is typed by its full name now.
- **Keys that scroll.** After the build, a key nothing took
  (`Runtime::$keys[..][3]` unset) scrolls the innermost scroll container
  around the focus that moves, or under the pointer without one
  (`scrollKey`); a frame follows. A list's Page Up and Page Down left the
  choice for this, as AppKit's do.
- **Tooltips.** `Runtime::showTip` decides the tip once a frame, after
  `record()`: the innermost hovered node with one (rested TIP_DELAY, or at
  once within TIP_WARM of another), or the focused one while the focus
  ring shows, whichever came last; a press, a click by the keys
  (`$keyClicked`, kept even when the build asked no `clicked()`) or
  Escape (taken before the window's Escape handler) dismisses it until
  neither the pointer nor the focus is on it. A pointer moving within the
  node no longer hides it.
- **Rows never measured above.** `ListState::scrollBy` stops at the first
  row above whose height is not measured, the offset negative: the view's
  top above the anchor. `Context::list` builds the rows there as far as
  their estimates go, from `$first`; `Layout` measures them and counts the
  place from the first, adding their heights, so a step of the wheel or a
  key moves the rows by exactly the step (mygo's anchor), and the
  estimate never moves what shows.
- **The physical key.** `Runtime::shortcut` matches a character shortcut
  chorded with Command, Control or Option by the key's place
  (`Key::usChar` of the virtual key code, which is a place on macOS) when
  the layout typed no ASCII there; plain typing never matches by place.
- **A list's state.** `ListState::own` notes the list built with it in a
  pass of a context: `focused`, `focusWithin`, `focus` and `shortcut` ask
  that list and no other, none while it is hidden; a second list of the
  same state in a pass throws.

## Lists, the tests of list_test.go (v1.15)

- **Aligning by what is measured.** `ListState::$aligning` ([row, align])
  is a row to show centered, at the bottom (`scrollTo` with CENTER or
  END, `scrollToEnd`, `scrollIntoView` from above) or under its
  section's pinned header (`scrollTo` START with headers): the place
  goes where the estimates put it, the rows there are built, and Layout,
  having measured them, sets the offset from the first row built, no
  further than the last row's end nor above the first; a wheel or key
  step (`scrollBy` with a move) drops it.
- **The gap between rows** is `ListState::$rowGap`, which Layout sets;
  `step($row)` is a row and its gap, which the walks of the place add up.
- **Short of the view.** When the rows built end short of the view and
  more exist, Layout asks for another pass (`Runtime::changed`), so a
  first frame of rows far smaller than estimated has no gap.
- **Following the end** scrolls to the end in every frame the list is
  at it (`atEnd`, which a step of the user clears until Layout finds it
  there again). With a FollowAnimation, Layout compares where the first
  row showed (`$shownRow`, `$shownTop`) with where the end puts it now and
  adds the difference to the lag, which the next build glides from.
- **The focused row** (`$focusRow`) scrolled out of view is built still,
  `at(0, -1e6)`, so the focus stays and the field takes the keys.
- **Revealing in a list.** Layout's reveal moves a list's offset (counted
  from its first row built) as it moves a ScrollState's, so Tab shows the
  next row's control; a place moved past the rows built asks another pass.
- **The thumb** of a list drags absolutely: as far through the content
  as along the track from where it started (`scrollToOffset`), the end of
  the track the end, as the rows measured on the way change the estimate.
- **Sized by its rows.** In a scrolling container (`Node::$sizedByRows`,
  set as the list is built) or with a largest height, a list measures its
  rows built and the rest as estimated.

## Text fields, input clients and toasts (v1.16)

- **Escape on a control** lets the focus go after the frame is built
  (`Runtime::frame`), no longer as the key comes: what is around the
  focus sees it there (a toast's Escape closes it), the window still gets
  the key, a field keeps the focus.
- **Toasts' time** stops while the pointer rests on one of last frame's
  toasts (`Runtime::$toastRoots`, noted by `toastBase`) or the focus is in
  one, decided before the toasts whose time is over close;
  `setWindowFocus` stops and starts it as the window goes to the
  background and back.
- **Where a field's text starts** (`TextState::$offsetX`) is what the
  painter showed: scrolled to the caret while focused (its start
  unfocused), or put where `textAlign()` says while it fits; a click and
  the input method's caret rectangle go by it. A text area brings the
  caret into view when the caret moves, not when the text changes around
  it.
- **Undo steps** end as the caret moves (`setCursor`,
  `setTextSelection`), as mygo's editor ends them.
- **Text input clients**: the client the focus leaves while composing is
  unmarked (`unmarkText`), as mygo's.

## Primitives, styles and the window (v1.17)

- **Into what blocks.** When the focus is behind a node that now blocks
  (`block()`, a dialog), it goes to the first reachable focusable, inside
  it, rather than to none; Escape's overlay gives it back to the opener.
- **A disabled slider** is not registered for presses (`Runtime` record).
- **onTextKey** is offered by `Input::textKey` before the field sees a
  key, only keys that type no text; the view and `Headless::key` call it.
- **A press holds the hover**: while the mouse is down from a press on a
  node still built, only that node and those around it hover
  (`Runtime::hovered`); once it is gone, what is under the pointer does.
- **Grids** align their cells down by their own `align()` (mygo's
  AlignItems) when the cell sets none; fit tracks take at least their
  cells' least sizes.
- **Text areas** wrap their placeholder; a form's label levels with the
  first line of a text area; a fixed line height comes back to its points
  exactly (the text system rounds the multiple it is given).

## Right to left, indicators, dates and comboboxes (v1.18)

- **Texts stretch across a column** as other leaves do (`Layout::crossWidth`);
  a rich text wider than its lines is laid out across its box
  (`Node::$alignWidth`), so that `textAlign()` and the direction place the
  lines in it.
- **Mirrored right to left**: a range slider (knobs, fill and presses), a
  split (its first pane at the right, its handle and drag), a text input
  (its text, placeholder and caret at the right), a popover (attached at
  its anchor's start, `attachTo`), a menu button's menu (shown at its
  right and laid out right to left, `Menu::popupAt`).
- **Assistive technology's increment** presses Up, which raises a slider
  whichever way it runs.
- **A press on a stepper** steps it as it lands, and a click on a range
  slider moves its nearest knob, though released before the frame is
  built; a held stepper counts by the frame's clock.
- **Combobox popups** highlight under the pointer only when it moves, and
  scroll the highlight into view once; their options are read as chosen
  where the arrows are, named by their text; the input says whether the
  popup shows. A search field's clear button keeps the focus.

## Scopes, transforms, SVGs and web views (v1.19)

- **Select** is mygo's: `selectBase` with a button's look and a popup of
  the options in the window (`stylePanel`), no longer AppKit's menu; Escape
  closes the popup on top, then the dialog. `Node::SELECT` is gone.
- **Popovers in the Tab order** follow their anchors (`Runtime::placePopovers`),
  though built at the window's root.
- **Clips take the pointer too**: a node is under the pointer only inside
  every node clipping it, in that node's own layout, through its
  transforms (`Runtime::withinClips`).
- **Through transforms**: the caret the window gives the input methods
  (`Input::caretRect`), a text input client's rectangles and the index at
  a point (`Input::clientRect`, `clientIndex`, characterIndexForPoint:),
  and a popover's place below its anchor as it shows (`Layout::shownIn`),
  the popover itself not turned.
- **Nodes taking text of their own** (`Node::textCaret`): the input
  methods compose at its caret, which hears COMPOSE and TEXT events
  (`InputEvent`), the view keeping the composition (`Input::$composing`).
- **Icons** take the font size and the text color around them, or their
  own `textColor()`.

## Choosing, tables, widgets and inline text (v1.20)

- **A list's `changed()`** reports a new choice once, whether the keys
  made it before the build or a click in it (`ListState::changedSinceBuilt`).
- **Typing a row's letters** goes as mygo's `typed` (`Context::typedRow`):
  the same letter again to the next row starting with it, letters typed
  quickly adding up, by the frame's clock; lists, grids and sidebars.
- **Shift extends from its item**: the selection's pivot follows its key
  as rows come above (`Selection::pivotAt`).
- **A press shows in its frame**: `pressed()` has the frame it lands in
  build again (`Runtime::holds`).
- **`clickModifiers()`** gives the modifiers of a node's last click, none
  for Enter.
- **Tables**: a section's header row spans the columns, built as column
  0; rows chosen together round as one block (`roundRows`); several rows
  chosen is said to assistive technology.
- **Date inputs and color wells** open a popover of their own width
  (`popoverBase` with `stylePanel`); an editable text alone edits on Enter.

## Bases, forms, feedback, toggles and themes (v1.21)

- **Enter at a toggle** goes to the window's shortcuts first: a check
  box, radio button or switch takes Space, and Enter only when nothing
  took it (`Runtime::$enterToggle`).
- **Escape keeps the focus**, as mygo's: the rule dropping it is gone, so
  an alert that must be answered keeps its button focused.
- **An accordion's headers** are each a stop of Tab; Up, Down, Home and
  End move between them, without going round.
- **A toolbar's overflow** is a menu of the node (`Node::menu`), its
  toggles checked, choosing one pressing it.
- **A fieldset disabled after its build** disables what is in it
  (`record()` passes `disabledIn` down).
- **Themes**: `withAccent` follows mygo's (hover and pressed mixed, the
  selection's and the focus's alpha, dark text on a light accent); a
  spacing of 0 is 4; `parseHex` takes `#rgba`.
- **Segmented controls** name their radio group by their label; a check
  box without a label is a square.
- **The language of text**: each frame gives the text system the app's
  language, which text naming none is shaped in.
- **Headless tooltips** paint last, as the window's.

## Outlines, repaints, scrolling, selections and following (v1.22)

- **`after()` and `nextWake()` go by the frame's clock** (`Runtime::clockNow`,
  the tests' clock or hrtime), as mygo's `After`.
- **A spinner asks for its next spoke** as it paints (`after()`), not for
  every frame; it and a moving progress bar take their phase from the
  frame's time.
- **A container that clips nothing is painted though its box is out of
  the clip**: its children can show elsewhere (absolute, transformed,
  overflowing); one that clips or scrolls is skipped.
- **Fields**: an input's and a text area's text background, selection
  color and text color are their text style's; the composition's
  underline is a point thick over the glyphs; the caret is in the accent,
  as mygo's.
- **A text made rich** (a text background, a Selectable container's)
  keeps its content transition: `paintSpans` takes a `$glyphs` painter,
  and `contentStep` compares a rich text's spans.
- **ScrollState**: an offset is kept within the content at both ends, the
  axis a container does not scroll is at its start, and nothing to scroll
  puts it at the start; `scrollTo($y, Animation)` glides by the frame's
  clock (`Scrollable::glide($dt, $now)`), the default glide for `true`.
- **Following the end**: the list's own height changing (a composer below
  it growing, the window resized) is no glide; rows going while it glides
  show in place at once (`ListState::$went`).

## Pastes, text lines, inline objects and buffers (v1.23)

- **The Edit menu's commands go through the runtime**: the view's
  `copy:`, `cut:`, `paste:`, `undo:`, `redo:`, `delete:` and `selectAll:`
  push `['edit', name]` (`View::command`), which the node taking its input
  gets, else the focused field (`RuntimeMenu::editCommand`, Undo and Redo
  added), else the selectable text: one path, which `onPaste` sees, and
  the headless tests too.
- **Inline objects take their width when the text is shaped**: Core Text
  shapes an object's rune as a no-break space kerned to the object's
  width (`TextEngine::spaceAdvance`), so the text after it and the line
  breaks make room for it; the stub engine gives it the width.
- **TextLines before a layout** place lines `TextLines::LINE_HEIGHT` (18,
  mygo's) apart.
- **Malformed UTF-8 in a TextBuffer**: the summaries count a malformed
  byte as one UTF-16 unit (PCRE matched nothing in it, so a rune past the
  BMP was counted once), and native input reads it as U+FFFD.
- **A list's rows not chosen** (no `$selected`) still reorder by a drag;
  a click is then the row's own.

## Text wrapping at its width (v1.24)

- **mygo's text wraps at the width it is given**; myphp's layout measured
  every node before arranging, so a text knew no width. `Layout::layout`
  now arranges, has `wrapTexts` wrap each text or rich text arranged
  narrower than its lines at the width it got (a text becomes rich text,
  `Node::$autoWrap`; a size given to it stays), and measures and arranges
  once more, the lines' height in the columns and rows around them. It
  goes again only when a text wrapped, so a frame without wrapping lays
  out once.
- **What lets a text be narrower**: a text that may wrap (`Node::wrapsAtWidth`:
  not `singleLine()` or `noWrap()`, not given a width, not inside a
  paragraph, with a space or text past ASCII) shrinks in a row down to its
  widest piece no line breaks in (`Node::minContentWidth`, the text system
  laying it out at no width); in a column it is no wider than the column;
  a container not stretched is as wide as its content within its room,
  down to its content's least (CSS's fit-content).
- Text is laid out anew at each width, in the frame of a resize; a word no
  line breaks in keeps its width and overflows, as in CSS.

## Wide colors (v1.25)

- **mygo's Oklch colors outside sRGB keep their wide twin**, which a
  window on a Display P3 screen shows. myphp's colors are ints:
  `WideColor::of` returns the 0xRRGGBB of a color inside sRGB, and for
  one outside it the nearest sRGB color (CSS Color 4's mapping,
  `Oklch::nearestSRGB`) with, above the low 24 bits, the id of its
  extended sRGB components, kept in fixed point (1/8192, mygo's), so that
  a color mixed with itself is itself. `Theme::mix` mixes wide colors by
  their components (`WideColor::mix`), a mix inside sRGB losing its twin.
- **The display list writes the twin and notes the wide color**
  (`DisplayList::$wide`, the quads' fills, shadows, rings, lines and
  glyphs that show); `showWide($on)` writes either into the quads, so the
  same list draws on a target of either gamut.
- **Metal switches a view's drawables** to RGBA16Float in extended sRGB
  (`useWide`) once a frame has a wide color on a screen that shows
  Display P3 (`canRepresentDisplayGamut:`), for two seconds after the
  last (mygo's wideHold), with the pipelines and the glass's backdrop
  textures of that pixel format, built when first needed; the layer's
  own color space comes back with BGRA8.

## Running the examples beside mygo's (v1.26)

- **Each example ran beside its mygo twin on a Mac** and their windows
  were compared; what differed was fixed:
  - A tinted image drew black: the shader unpacked the tint, packed as
    -1 - rgb in the quad's radius, with `uint(v + 0.5)`, and a float32
    holds no halves past 2^23, so white (16777215) rounded up to 2^24, 0
    in its bytes. `unpackRGB` rounds with `rint` (farm's world, which
    draws its tiles through the day's tint, was black; a box's border and
    a gradient's second color unpack the same way).
  - Color glyphs (Apple Color Emoji) drew as masks in the text color:
    `CoreText::rasterize` draws a font with color glyphs
    (kCTFontTraitColorGlyphs) into premultiplied RGBA in sRGB, as mygo's,
    and `Text` keeps the bitmap as an `Image` in the image atlas, drawn
    as an image at the text's alpha; its quads mark it with a u of -1.
  - A text area wrapped its whole text as it painted, ten seconds and
    half a gigabyte for text-buffer's 100 000 lines: `FieldLayout` now
    wraps a paragraph when asked for its lines, as mygo's area, counting
    the others a line each in a Fenwick tree of the paragraphs' lines; an
    edit's layout takes the lines of the paragraphs it left, where they
    were or as many paragraphs on as it added. Revealing the caret below
    the view wraps the paragraphs above it first (mygo's reveal).
  - A field ignored its font: `font('monospace')` or `bold()` on an input
    or a text area now sets `TextState::$style`, the Font bits it is
    wrapped, hit and painted in.
  - Tiles zoomed by a camera showed seams: an image's edge was smoothed
    over a point of its own units, six pixels at farm's zoom. It is
    smoothed over a pixel of the screen (`fwidth`), so tiles side by side
    meet.
  - A leaf's least size (`minWidth`, `minHeight`) was kept only by
    containers; a slider of no width growing in a row of fit content had
    none, mygo's keeping `Space(20)`.
  - mayoi's street used a hash of its own: its tiles are where mygo's
    64-bit hash puts them (`mul64`, `shr64`, Go's wrapping arithmetic).
    The art's speckles differ, drawn from PHP's engines.
  - Text and rich leaves were placed without their padding, inputs grew
    along a column, a border without a background was not drawn, nor a
    dashed one, a button did not stretch across its column, a disabled
    leaf did not dim, rich text drew no box of its own, a slider's track
    was inside its padding and a slider of no width had none: each as
    mygo's now (`Node::textOrigin`, `Context::growsAlong`, `$sizedW`).
  - Dates showed UTC with no `date.timezone`: `App::run` sets the
    system's zone (`NSTimeZone localTimeZone`), as Go's local time; a
    php.ini that names a zone keeps it.
  - WebKit's WKDownloadDelegate protocol is not always registered at run
    time: the navigation delegate no longer declares it, as WebKit asks
    its methods by `respondsToSelector:`.
  - `MYPHP_FRAME_STATS` read the layouts made from `Text`, which has
    none: they are the text system's.

## Go's random numbers, and mygo's examples of the same name (v1.27)

- **Dates are in the system's time zone whatever php.ini names**, as
  the developer asked: a desktop app shows the time of the computer it
  runs on, as Go's local time (a php.ini of `date.timezone = UTC` made
  the gallery's clock four hours off).
- **Go's math/rand/v2 in PHP** (`Random\PCG`, `Random\Rand`) drew what
  mygo's seeded examples draw; v1.28 took it out again (below).
- **A border takes room**, as mygo's (its layout's sizes are border
  boxes): `border()` adds its width inside the padding (`Node::padT()`
  and the others), and a plain button, bordered, is a point wider and
  taller each side than a primary one. A button's own style (`bold()`,
  `italic()`, `underline()`, `strikethrough()`, as the notes' B, I, U and
  S) measures and paints its label.
- **Text is drawn with font smoothing**, as AppKit's and mygo's: Core
  Graphics emboldens glyphs the lighter their color, even in a mask, so
  a glyph is rasterized for each of four shades of the text's relative
  luminance (`Text::shadeOf`, mygo's ShadeOf), filled with the shade's
  gray (`shadeGray`), while the user leaves AppleFontSmoothing on; the
  atlas keys a mask by its shade, a shaped line keeps its glyphs and
  makes its quads for each shade it is painted in. A canvas's text
  (`glyphCoverage`) stays unsmoothed. White text on the dark theme was
  visibly thinner than mygo's.
- A text given a width alone (`size(96, 0)`) kept no height and was
  stretched across its row, its glyphs at the top: a text's measured
  height stays, as mygo's `Width()` leaves it.
- **A release whose press was elsewhere clicks nothing**: a mouse-up
  with no mouse-down in the window (the window opened under a pointer
  released then, a press in another window) made a click, so confetti
  fired at its stage's corner; mygo's Clicked asks for both.
- **mygo's native, notes and drag-drop**: ported under their names;
  myphp's own examples of those names are `inbox` and `markdown-notes`
  (its window's state under its own key now, as the notes' is "notes").
  An attachment's drawing takes a `Painter` (the window's theme, text),
  as mygo's `Draw`.

## Clicking through the examples on the Mac (v1.28)

- **A tray stays in the menu bar.** `statusItemWithLength:` hands back
  an autoreleased item the status bar keeps no reference to: the native
  example's icon left the menu bar as the pool drained, without an
  error. The tray retains it (mygo's `retain`) and releases it after
  `removeStatusItem:`.
- **A drag's image is the element's colors.** The preview went to an
  `NSBitmapImageRep` as BGRA with the alpha-first, 32-bit little-endian
  format, which the rep read as ARGB: a card's gray showed a saturated
  blue. `Drag::start` gives it RGBA (format 0, premultiplied), as mygo's
  PNG of the preview is.
- **A popover keeps the ids of what is built after it.** An unkeyed
  node's id is its place in the build order, and `popover()` built its
  panel unkeyed, so the notes' editor, built after the toolbar's link
  popover, became a new node as the popover opened: its `autoFocus()`
  took the focus back from the popover's field, and the URL typed went
  into the note. The panel is keyed by its anchor (`popover:<id>`), as
  `popoverBase`'s and the combobox's are.
- **A micro app is signed again.** `spc micro:combine` appends the phar
  after micro.sfx, and codesign refuses an executable with data after
  it ("main executable failed strict validation", macOS 27; a fat
  binary alike), so `myphp build -launcher micro` failed at signing.
  `MachO::embed` takes the signature off, grows `__LINKEDIT` over the
  INI and the phar, which codesign signs then, and writes where they
  start and end in a `__DATA,__micro_sfxsize` section, which phpmicro
  reads instead of the end of `__LINKEDIT`. phpmicro has the reading but
  not the section: `myphp micro` builds micro.sfx with `MICRO_PATCH`
  (`spc build -P`), which adds it, zeros taken as absent so that spc's
  checks of a plain concatenation pass; a micro.sfx without it is built
  again. A micro app signed so ran its phar with its INI on macOS 27.
- **A micro app has the extensions the framework calls.** micro.sfx had
  ffi, mbstring, ctype, phar and json, and an app stopped at its start
  (`Locale` not found, in `I18n`): `MICRO_EXTENSIONS` adds intl,
  xmlreader (SVG icons), curl (fetch, updates), sockets (one instance)
  and filter. `microLacks` runs micro.sfx with a script after it, which
  the patch leaves working, for what it has; one short of either is
  built again (`MYPHP_MICRO`'s own is refused).
- **A catalog in a phar.** `Catalog` listed its files with `glob`, which
  finds nothing in a phar, so a micro app stopped at its first menu (no
  en.json in `src/l10n`); it lists them with `scandir`, as the CLI walks
  directories. With these a micro app of the native example ran, its
  notification asking for permission as a signed bundle's does.
- **A pointer's address is `ObjC::addr`.** `AudioQueue` keyed its queues
  by `$c->cast('intptr_t', $q)->cdata`, but FFI's cast of a `void*` to an
  integer type reads what the pointer points to, not the pointer: the
  first 8 bytes of the queue went for its address, and an AudioQueueRef
  that points to nothing readable (macOS 27) crashed the process
  (SIGSEGV in `zend_ffi_cdata_get`) as farm took its first step and its
  first sound opened the queue, mayoi or the piano likewise on their
  first. lldb found the line (`zend_get_executed_filename()`/`_lineno()`
  at the stop). Addresses come from `ObjC::addr`, which reads the
  pointer's own bytes through a slot (AGENTS.md, FFI discipline);
  tests/ffi_addr_test.php shows the cast reading through and fails on any
  `cast('intptr_t'|'uintptr_t'` in src.
- **out inside frontendDist.** The todo example had out's default,
  dist, which is its frontendDist too: the app being made under dist
  copied the frontend, itself among it, into itself until the paths
  were too long (a 128 MB app, PHP warnings). Its myphp.json has
  `"out": "build"`, as mygo's mygo.config.ts; `myphp build` refuses an
  out inside frontendDist. Built so, beside mygo's, the two apps add,
  tick, filter, clear, export and keep their todos alike (both under
  Application Support/Todo, as both are named Todo).
- **No Go generators.** `src/Random` (v1.27) is gone, as the developer
  asked: the farm, mayoi, the piano and the gallery's charts draw from
  PHP's engines again (`Random\Randomizer` with Xoshiro256**, the
  piano's `mt_rand`), alike to mygo's but not draw for draw
  (mygo-deviations.md, the examples).
- **The exec launcher's app has no process in LaunchServices.** Once the
  launcher execs php, `NSRunningApplication` (and so
  `frontmostApplication`, accessibility clients, automation) reports the
  app with pid -1, whatever the php is, even a copy inside the bundle;
  with php itself as the bundle's executable (no exec) the pid is the
  process's. The micro launcher, one executable, has its pid, so it
  stays so: what it leaves out is tools driving a development build.

- **A static app runs from its phar.** In micro `PHP_BINARY` is empty:
  what runs PHP again (the terminal plugin's pty child, a native
  library beside the executable) asks `Bundle::executable()`, the phar's
  path there; `glob` and `realpath` see nothing in `phar://`, so the
  catalogs are listed with `scandir` and `Protocol::fileServer` serves a
  frontend in the phar by its path. `static_app_test` keeps
  `PHP_BINARY` and `glob(` to the files that may use them.
- **The 14 examples beside mygo's, pair by pair.** Each built as an app
  of both (`-launcher micro`, a bundle of mygo's binary), shown side by
  side and compared by eye; what differed was the framework's but for
  two examples:
  - a field's height left out its border (2 points short of mygo's); a
    button given `size(44, 0)` lost its measured height; a select had
    no least width (mygo's `MinWidth(Space(35))`); a web view in a
    bordered box covered the border (its hole now inside the padding
    and border);
  - a bordered face painted its border as a rect under the face, which
    a disabled button's half opacity showed through: the face is
    painted, then its ring, as mygo's;
  - `fontSize()` on a field did nothing (its line is measured again in
    layout), and `changed()` on a field never reported typing: a
    `TextState` counts the user's edits (typing, deleting, pasting,
    undo), which the field built next reports once, as mygo's Changed;
    what the program sets is no edit;
  - a list's rows short of the view went to its end with
    `justify(END)`, and what it shows without rows stays at the top,
    as mygo's;
  - the flyout example was another than mygo's (placements, a hover
    card, a panel, a tray flyout): it is mygo's now (a list of colors,
    a note's editor, an About popover); markdown-chat's list had
    neither mygo's gap nor its `Justify(End)`; documents' footer and
    monospaced editor were mygo's own.

## Releasing (v1.29)

- **myphp-cli from npm found no myphp.** Its `binaryPath` was the
  bin/myphp two directories up, a checkout's, as a `file:` dependency
  installs it; from npm that is the project's directory. It now runs
  `$MYPHP_CLI_BINARY`, the checkout's bin/myphp when it is in one (bin/myphp
  and src/App.php there), else `vendor/owner888/myphp/bin/myphp` in the
  current directory or above it, and says to `composer require
  owner888/myphp` when there is none (`packages/cli/index.test.ts`).
- **One version.** `php scripts/version.php <v>` writes it into bin/myphp's
  `VERSION` and the six npm packages (runtime, CLI, the four plugins' page
  sides and their peer range of the runtime); `--check v<v>` is the first
  step of the release workflow, so a tag the files do not have publishes
  nothing (`tests/version_test.php`).
- **Publishing.** `.github/workflows/release.yml` publishes what npm lacks
  of the six, with `--provenance` and npm's trusted publishing (no token):
  npm trusts the workflow per package, which needs the package to exist,
  so a package's first version is published by hand. Packagist reads the
  tags itself. The Packagist name is `owner888/myphp`: the vendor `myphp`
  was another's.
- **CI.** `.github/workflows/ci.yml` runs `tests/run.php` and the Bun
  tests on Linux: the tests are headless. The runner's PHP (8.5 on
  Ubuntu 26) showed three things a PHP 8.3 without Xdebug did not:
  - Xdebug, which it loads, stops a call 512 deep, as the Markdown
    parser's pathological inputs go: the workflows set `XDEBUG_MODE=off`;
  - PHP 8.5 warns of a NAN made a string: `Operands::of` spells it out;
  - PHP 8.5's CLI opens a library RTLD_GLOBAL without RTLD_DEEPBIND, so
    mygo's SQLite called the functions of PHP's own libsqlite3
    (pdo_sqlite's) for its own and crashed: `NativeLibrary::cdef` opens a
    plugin's library with RTLD_DEEPBIND first on Linux, which FFI's
    dlopen of it then finds (SQLite, libghostty-vt).
  - The runner is not root: the documents test's stand-in for
    NSFileManager gave a new file the mode of none (`fileperms` of a
    missing file), which root reads anyway; it renames a new file into
    place, as `Files::replaceItem` does.

## What wrapping text cost a frame (v1.30)

- **v1.24's text wrapping made layout several times dearer.** Run against
  the snapshots of each version on one machine, the layout of 2000 texts
  went from 2 ms (v1.23) to 14 ms, a million-row list's from 0.07 to
  0.35 ms: each text that may wrap had its min-content width (its widest
  word) laid out by the text system every frame, which made the parameters
  of a layout and their key to find it in the cache, about 6 µs a text.
  A plain text's min-content width now comes from the line that measured
  it (`TextMetrics::minContentWidth`): the Mac's line cache keeps it with
  the line, from its carets and `Text\LineBreaks` (where whole-word
  wrapping breaks), so it costs no layout at all; rich text still asks the
  text system. `wrapsAtWidth` asks its regex once a node. The layout of
  2000 texts is down to 4 ms, the list's to 0.12 ms (`tests/wrap_test.php`).
- **A text's min-content width was one glyph's.** Its layout at no
  width left `noBreakWords` off, which mygo sets: Core Text broke the text
  at every glyph, so a text in a row too narrow for it broke inside its
  words, and each new text on the screen cost a layout of a line a glyph
  (the slow frames of a scrolling inbox: 10–20 ms of layout, most of a
  megabyte held, for a dozen new rows). It breaks between words now
  (`tests/wrap_test.php`).
- **Every frame's tree was a cycle.** `MYPHP_STATS=1 ./run.sh inbox
  1000000`, scrolled on the developer's Mac, ran at 54–59 frames a second
  with frames of 4–9 ms, memory going up and down by tens of megabytes and
  PHP's collector running several times a second, freeing some 12 000
  cycles each second: each node's `context` holds the Context, which holds
  the root, so a tree a frame replaced waited for the collector, whose runs
  take long enough to drop a frame. `Runtime::frame` cuts the contexts of
  the tree it replaces (and of a pass's it builds again), and the tree goes
  as its last reference does (`tests/lists_test.php`).
- **Frames drawn as soon as asked.** The slow frames left, with 2–4 ms of
  work, spent 14–31 ms in `present`: `Metal::requestFrame` drew on the next
  turn of the run loop, faster than the display took frames, so the next
  drawable kept one waiting. It paces them as mygo's surface does: after a
  pause a frame draws at once; frames that follow each other draw at the
  ticks of the view's display link (macOS 14), which runs while they come
  and pauses after a tick with none asked for, or, before macOS 14, a
  refresh after the last frame began. `Metal::forget` ends a closed
  window's link. Two traps on the way: the first frame, which a window
  waits for before it shows, must draw at once (a link of a window not on
  screen never ticks); and a link added to the run loop for an NSString
  "kCFRunLoopCommonModes" never ticked, as that is a mode of that name:
  `Metal::commonMode` reads CoreFoundation's `kCFRunLoopCommonModes`
  itself.
- **Timing on a shared machine.** Medians of a frame moved by half between
  runs; the least of 80 frames, run in turn against the snapshot before,
  held within a few percent. Xdebug's profiler inflated function calls so
  much that a closure in `fixedMargins` looked like 12 % of a frame and
  removing it changed nothing measurable.

## Images decoded off the main thread (v1.31)

- **What a decode cost.** Measured on the developer's Mac (a 13.6 MB
  JPEG photo, and the HEIC `sips` made of it): `ImageIO::decode`, which drew the whole image
  into a smaller CGBitmapContext, took 200–250 ms for the HEIC at any
  size asked and 40 ms for the JPEG at 1024 pixels (230 ms at 2048), all
  of it on the main thread. ImageIO's thumbnails, decoded at the size
  asked, took 50–100 ms, and their lazy kind decodes as it is made: no
  ImageIO call leaves the work for later.
- **Off the thread without PHP there.** PHP must not run on another
  thread, and a C function cannot be sent to one with fewer arguments
  than it takes; an Objective-C message can, in an NSInvocation that an
  NSInvocationOperation runs on an NSOperationQueue. `Native\CoreImage`
  sends two: `+[CIImage imageWithData:options:]` (which reads the file:
  9 ms for the HEIC, 33 ms for the JPEG, measured on the main thread),
  upright as its orientation says, then, once the main thread has
  scaled the image with `CILanczosScaleTransform` to whole pixels (a
  tenth of a millisecond), `-[CIContext
  render:toBitmap:rowBytes:bounds:format:colorSpace:]` into the
  `Mem` buffer the image keeps, which decodes it. The main thread spends
  about 0.2 ms an image; the pixels are there 25–30 ms (HEIC) and 50 ms
  (JPEG) later, sooner than the decode took on the main thread. Its
  colors are those CoreGraphics drew within one level of 255 (five, for
  a thumbnail of 128 pixels, Lanczos against CoreGraphics' filter), its
  first row the top.
- **Waiting.** In a fiber (`App::go`) the decoding waits with
  `Fibers::until`, looking every 4 to 16 ms while the app draws; outside
  one it blocks (`waitUntilFinished`), as decoding did, rather than turn
  the run loop under a view being built. Autorelease pools are drained
  before each wait, as a fiber's wait is no scope of the main thread's
  pools; what outlives one is retained (the invocations retain their
  arguments and results). The photos and files examples decode their
  thumbnails and full-size images in fibers, the full-size photo shown
  as its thumbnail until it is there; the Markdown view loads each image
  in a fiber of its own, its description showing until then, as mygo's
  goroutine (`tests/markdown_view_test.php`, `tests/image_test.php`).
- **`ObjC::boot` adds frameworks.** An image decoded before `App::run`
  boots the bridge with CoreImage; `App::run`'s boot then dlopens the
  frameworks the first lacked instead of making a second FFI instance,
  which would have orphaned the classes and selectors found by the first.
- **Trap: HEIC photos showed sideways.** `ImageIO::exifOrientation` read
  the orientation of JPEGs only; a HEIC's (its `irot`) was ignored, so a
  portrait HEIC decoded landscape. CoreImage's
  `kCIImageApplyOrientationProperty` applies either, so mygo's EXIF
  parser and the transform made from it are gone; `tests/image_test.php`
  decodes a JPEG of orientation 6 on macOS. `ImageIO::decode` remains
  for `Build\Icns`: the largest of a PNG's or an ICNS's images, on the
  calling thread.

## Underlines skipping ink (v1.32)

- **As AppKit and mygo draw them.** `System::decorate` cuts an
  underline around the ink of each glyph crossing it, a thickness of the
  line further on either side, and leaves out pieces no longer than three
  quarters of one (mygo's `cut`); Han, Kana and Hangul, and spaces, do
  not cut it (`skipsInk`), nor do inline objects. Where a glyph crosses
  the band of the line comes from its outline (`bandX`), kept by font,
  glyph and band, 4096 at most (`inkInBand`); a strikethrough stays whole,
  and a thickness of the app's (`$thick`) puts every piece on the same
  pixels.
- **Outlines from the engine.** `Engine::outline` gives a glyph's outline
  as line segments, y up from its origin: `Native\CoreText::outline`
  reads `CTFontCreatePathForGlyph` through `CGPathApply`, its curves in 16
  lines each (mygo's `glyphOutline`), with one callback made once and
  held in a struct field; the oblique fonts of synthetic italics have
  their slant in the path. mygo keeps the band cache in its CoreText
  engine; here it is the system's, so the stub engine's outlines (a bar
  down through the descender of g, j, p, q and y, a glyph's id now its
  rune) test the cutting on Linux (`tests/text_test.php`), and on macOS
  "gypsy" at 24 points comes in at least three pieces, as mygo's test asks.

## Video, decoded and shown by the system (v1.33)

- **The pipeline.** `Media\Player` reads a file's packets in PHP and
  gives them, encoded, to the system, which decodes and plays them on
  threads of its own: an `AVSampleBufferDisplayLayer` the video (shown by
  the window server, no frame of it through PHP or myphp's renderer), an
  `AVSampleBufferAudioRenderer` the audio, an
  `AVSampleBufferRenderSynchronizer` both on one clock (`Native\Renderers`).
  Nothing calls back: `Native\MediaTicker`, an `NSTimer` in the main run
  loop's common modes (so a live resize or an open menu does not starve
  it), tops the renderers up 20 times a second to two seconds past the
  clock, each renderer from its own queue, so that one full never starves
  the other. PHP never runs off the main thread: VideoToolbox decoding to
  pixels would have called back on its own thread, which ruled it out.
- **Where the packets come from.** `Media\Matroska` reads Matroska and
  WebM in PHP (EBML; Xiph, EBML and fixed lacing; block groups; header
  stripping; segments and the cues after the clusters, found by the seek
  head): AVFoundation reads no Matroska. `Native\AssetReader` reads MP4,
  MOV and M4V with an `AVAssetReader` whose track outputs pass the
  samples through as stored, their sample buffers going to the
  renderers as they are. Both are a `Media\Source`; their tracks name
  their codecs as Matroska does. `Native\CoreMedia` makes a Matroska
  track's format (`CMVideoFormatDescriptionCreate` with its CodecPrivate as
  the `avcC` or `hvcC` atom; an AudioStreamBasicDescription, AAC's
  AudioSpecificConfig its magic cookie) and a packet's sample buffer.
  Codecs: H.264, HEVC; AAC, AC-3, E-AC-3, MP3.
- **What it costs.** Measured on the developer's Mac with a 1080p H.264
  film of two hours (AC-3 and AAC, 5.7 GB): opening it, its header,
  tracks and 7599 cues, 15–20 ms; reading its packets 0.46 ms of PHP a
  second of film; topping the renderers up 2.4 ms a second; the process
  2–3.5 % of a core while it plays.
- **Seeking.** A seek goes to the cue before the time (Matroska; the
  reader starts at the keyframe before it), flushes the renderers, marks
  the video's frames before the time `kCMSampleAttachmentKey_DoNotDisplay`
  (decoded for those after it, not shown), drops the audio before it, and
  starts the clock at the time itself (`setRate:time:`): it lands on the
  time, not the keyframe. Another audio track is a seek to where the
  player is. Matroska leaves out the frames of the cue's cluster before
  the cue, where a cluster holds several.
- **The picture in the UI.** `Context::video` paints a hole as a web
  view's node does (`Painter::nativeHole`, shared): the window puts the
  player's layer, in a view of its own that clips it, under the native
  view where the frame showed the node (`Window::placeVideos`, without
  Core Animation's implicit animation of the frame), so what is built
  over the node shows over the picture. The picture takes no pointer;
  one player shows in one window at a time, its layer moving to the
  window that showed it last.
- **`ObjC` takes CMTimes.** The letters `T` (CMTime) and `X`
  (CMTimeRange) pass and return them by value, as the synchronizer's
  `currentTime` and `setRate:time:` and the reader's `setTimeRange:` do;
  larger than 16 bytes, libffi passes them by reference as arm64 does.
- **Trap: a float rate passed as a double.** The synchronizer's `rate` is
  a float: sent as `d`, the value lands in the register's other half and
  the clock never ran (rate 0, the window black). `setRate:`,
  `setVolume:` and `rate` go as `f`.
- **Trap: audio stuttered at Matroska's millisecond times.** An AC-3
  packet is 1536 / 44100 s, 34.83 ms; its time, in whole milliseconds,
  left gaps and overlaps the renderer played as breaks. A track's packets
  now follow one another sample for sample from the first one's time
  (`Renderers::$nextAudio`), the file's time taken again where it jumps
  by more than 0.1 s.
- **Trap: a seek stalled for 5 s.** From macOS 11.3 the synchronizer holds
  a rate change until both renderers report enough data for a reliable
  start (`delaysRateChangeUntilHasSufficientMediaData`), which a player
  keeping two seconds ahead of a clock that has not started never gives;
  it is off. (Splitting the renderers' queues, tried first, was not it.)
- **Trap: the controls over the picture lost their clicks and keys.** A
  click goes to the first node built that asks for it where it is: the
  example's picture, which pauses when clicked, asked before the controls
  over it, so a press on the seek bar or the audio tracks' select paused
  the film. The picture asks after them, and the bar after its children
  (the end of a drag of its slider is a click none of them takes). A
  press on a slider or a button focused it, which then took the arrows
  and Space from the window's shortcuts: the bar `keepFocus()`
  (`tests/examples_test.php`).
- **Outside an app.** The gamepad's GameController boots the bridge
  itself (`ObjC::boot` adds frameworks since v1.31), which the farm's
  test, run alone on macOS, had not.

## Subtitles (v1.34)

- **Text subtitles, drawn by the UI.** `Media\Subtitles` reads SubRip,
  ASS and SSA, and WebVTT files (UTF-8, UTF-16 by its mark, else
  GB18030, as Chinese subtitles often are) and the text of Matroska's
  subtitle packets (`S_TEXT/UTF8`, `S_TEXT/ASS` and SSA, whose text is the
  ninth of the fields, `S_TEXT/WEBVTT`) and of MP4's tx3g samples (its
  16-bit length and text, the style boxes after it left out; the asset
  reader has an output of its text tracks too) as plain lines: ASS's
  override tags and SubRip's and WebVTT's markup left out, `\N` and
  `<br>` breaking lines. `Media\Cues` keeps a track's cues by start, the
  same cue read again kept once, one without an end lasting until the
  next, and gives those showing at a time by a binary search.
- **In the player.** Every text track's packets are noted as the film is
  read (two seconds ahead of the clock), so a track chosen later has what
  was read; the files beside the film named after it (`movie.srt`,
  `movie.zh.ass`) load as tracks and the first shows, as IINA does, else
  the first track the file marks default. `subtitles()` is the lines
  showing at the clock less the delay (`setSubtitleDelay`). A seek into a
  part not read yet misses a cue begun before its keyframe.
- **In the example.** The lines show over the picture, white and bold,
  over eight copies in black around them for an outline (myphp draws no
  text outline), above the controls while they show; a subtitle file
  dropped on the window loads over the film.

## A player laid out as IINA's (v1.35)

- **System materials under a hole.** `Node::systemMaterial($name, $dark)`
  puts an `NSVisualEffectView` of one of `Window::MATERIALS`, or an
  `NSGlassEffectView` (`glass`, `clear-glass`; macOS 26) under the node,
  as web views and videos are: the node paints a hole inside its border
  (padding and all: the material is its background), rounded as its
  corners inside the border, and the window places the view there, in
  the appearance asked (`DarkAqua`, `Aqua`, or the window's), at the
  node's opacity, so a material fades with its node. The views stack
  right under the Metal view in the order the frame painted them, over
  the videos' layers they blur.
- **SF Symbols.** `Image::symbol($name, $size, $rgb, $weight)` draws the
  system's symbol at a point size and weight into a bitmap at the
  window's scale, in one color (a template image draws black in its
  alpha: the color, premultiplied by it); a name of no symbol throws.
- **The traffic lights** hide and show with
  `Window::setWindowButtonVisibility`, Electron's name.
- **The window keeps the picture's shape** (`Window::setAspectRatio`,
  NSWindow's `contentAspectRatio`; 0 sets the resize increments back to
  1 by 1, as AppKit undoes it): resized by any side, as IINA's.
- **The example.** `examples/video` is laid out as IINA's window,
  measured on it (960 by 540): the title bar (32 high, the file's name
  after the traffic lights), the control bar (460 by 80 in glass, 10% of
  the height from the foot; the volume, back, play, on and four buttons
  above, the times and the bar of the time below), the message at the
  top left (OSD: IINA's words, a second), the sidebar of quick settings
  (360 wide, glass, its tabs in a pill; video, audio, subtitle cards
  with IINA's lists, segmented controls, sliders and switches); the keys
  are IINA's defaults (`iina-default-input.conf`) the player has the
  means for, a right click plays and pauses. What the player has no
  means for (crop, rotation, aspect, the equalizer, secondary and online
  subtitles, plugins, picture in picture, the playlist, audio delay) is
  shown and held. IINA's icons are its own (GPL): SF Symbols stand in.
  `Player::videoTrack` gives the video track's codec, size and rate for
  the video tab.
- **Trap: forcing the glass's appearance.** The example first set the
  glass to the dark appearance (too dark next to IINA's), then to the
  light one (a flat gray over the picture). IINA uses the system's glass
  as it comes; so does the example now (`systemMaterial('glass')`, the
  window's appearance), and a glass view gets an empty `contentView` as
  AppKit asks. Don't tune a system view's defaults to match a look
  (AGENTS.md rule 11).
- **The picture goes with the window's animations.** A video's view
  across the window (or down it) is width- (height-) sizable, and its
  layer, when it fills the view, sizable with it: a resize stretches the
  picture before the next frame places it exactly. AppKit's full-screen
  animation crossfades a picture of the window from before with one from
  after, and the video showed its old size in the grown window; a window
  showing a video goes in and out of full screen by its own animation
  (`customWindowsToEnterFullScreenForWindow:`,
  `window:startCustomAnimationToEnterFullScreenWithDuration:` and the
  exit's), its frame animated to the screen's and back, as IINA's: each
  step is a resize, drawn at once, the picture growing with the window.
- **Trap: closing the sidebar on a click of the picture.** A slider's
  release is a click where the pointer is let go: a drag of the speed
  begun in the sidebar and let go over the picture clicked the picture,
  and the sidebar closed with nothing clicked. It closes on a press
  outside it (`pressedOutside`), the control bar and the title bar
  excepted while they show, as a press is where the hand went down.

## Playing for less CPU (v1.36)

- **A frame only when what shows changed.** The player told the time 20
  times a second and the example drew a frame at each, the controls hidden
  or not. It draws now when what the frame shows of the time changes
  (`look()`: the second and the bar's point while the controls or a
  message with the time show; the subtitles' lines), so with the controls
  hidden and the subtitles still, it draws none.
- **Four ticks a second.** `MediaTicker` tops the renderers up every
  quarter of a second (they hold two seconds ahead; at a rate over 1 the
  player reads that many times two seconds of the film). A change of the
  subtitles between two ticks wakes the player at it (`Cues::next`,
  `App::after`) and it tells the time then, so a cue shows when it
  starts, not up to a tick late; a wake come before the clock (which
  starts a moment after `play()`) waits again.

## VP9 and AV1 in hardware, Opus (v1.37)

- **The system's decoders, registered.** VP9 and AV1 are supplemental
  decoders of VideoToolbox: a process registers them
  (`VTRegisterSupplementalVideoDecoderIfAvailable`, as `CoreMedia::c()`
  boots) before a display layer or AVFoundation's reader meets them. A
  VP9 or AV1 track plays where the Mac decodes it in hardware
  (`VTIsHardwareDecodeSupported`: VP9 on Apple silicon, AV1 from M3);
  elsewhere the film plays its sound alone, as one of a video codec the
  system has no decoder for.
- **Their formats.** AV1's CodecPrivate in Matroska is its av1C record,
  given as is. VP9 has none in Matroska: its vpcC (version 1 of the VP9
  ISO-BMFF binding) is made from the uncompressed header of the track's
  first packet, a keyframe as playing starts at one: the profile, bit
  depth, chroma subsampling, range and color space (its matrix; BT.709
  and BT.2020 their primaries and transfer too), the level the least
  whose picture size holds the frame. MP4's vp09 and av01 tracks are
  AVFoundation's, their samples passed through.
- **Opus**, as WebM files have it: the system's decoder (`'opus'`), its
  cookie the OpusHead of the CodecPrivate, its packets of as many frames
  as their TOC byte says (RFC 6716 3.1: 2.5 to 60 ms at 48 kHz, one, two
  or as many frames as the count byte holds), each sample buffer's packet
  description telling them, the next packet's time counted on by them.
  The pre-skip (312 frames, 6.5 ms) is decoded and played, not trimmed:
  the sound runs that much ahead of the picture.

## A video player for apps; the picture's aspect, crop and rotation; the sound's delay (v1.38)

- **`Context::videoPlayer(Player, VideoPlayerState, ?VideoPlayerOptions)`**
  builds what the `video` example built, for any app (`UI\VideoPlayer`):
  the picture, the title bar, the control bar, the messages, the sidebar
  of quick settings, IINA's keys; the options leave the title bar, the
  messages, the sidebar or the keys out. `VideoPlayerState` keeps what
  lasts across builds (the title, the volume, which a player built next
  takes, whether the subtitles show, the sidebar's tab, what failed) and
  does what the controls do, telling it as IINA's messages do, for an
  app's own menus and keys. Its strings are MyPHP's (`src/l10n`, keys
  `video…`, in English, Simplified and Traditional Chinese), the
  languages of tracks the locale's names of them (ICU, ISO 639-2's
  bibliographic codes as their terminology ones).
- **Trap: a variable before a non-ASCII character in a string.** PHP's
  names take the bytes 0x80 to 0xff: `"$d°"` reads the variable `$d°`
  (undefined, the rotation's labels empty). Brace it: `"{$d}°"`.
- **What it asks of the window.** A view has no window: it asks its
  runtime (`Runtime::askWindow`: whether the traffic lights show, the
  content's aspect to keep, full screen), and the window does it once the
  frame is built, once for each change; a resize or full screen waits for
  the frame to end (`App::later`), as a resize draws the window at once.
  The window tells the runtime whether it is full screen before each
  frame. The state asks for a frame itself as the player plays
  (`Runtime::changed`), when what shows of its time changes.

- **Laid out by the window.** `Player::setAspectRatio`, `setCrop` and
  `setRotation` change where the window puts the picture
  (`Media\Picture::fit`): the picture at its aspect, cut to the crop's
  around its middle, turned, fitted in the node's box and centered. A
  video's host keeps a crop view between its clip view and the picture's
  view: the crop view is the part that shows (masking), the picture's
  view the whole picture in it, its display layer stretched to its bounds
  (`AVLayerVideoGravityResize`), centered and turned by
  `setFrameCenterRotation:` (clockwise, AppKit's counterclockwise). The picture as the file
  has it stays as before: the display layer fits it itself
  (`ResizeAspect`) and stretches with the window as it resizes.
- **The sound's delay** (`Player::setAudioDelay`) shifts the audio
  packets' times as the renderers are given them, and goes again from
  where the player is (a seek, as another audio track does); an earlier
  sound reads the film that much further ahead, and after a seek the
  audio comes from where it plays delayed.

## The wheel, going on from where a film was left (v1.39)

- **The wheel over the picture** (`UI\VideoPlayer`), as IINA's: taken as
  it comes (`Node::handleInput`, `InputEvent::SCROLL`), up and down the
  volume (200 points all of it), across a seek (a tenth of a second a
  point), the seeks at most one a tenth of a second, what the wheel moved
  between them added up (one reads from a keyframe). Over the open
  sidebar it goes on to the sidebar's scroll.
- **Where films were left** (`Media\PlaybackPositions`, `Player::path`):
  by the file's path in a JSON file (playback-positions.json in the
  app's user data), written through a temporary one, the most recent 500
  kept. The player (`VideoPlayerOptions::$resume`, on by default) goes
  on from where a film was left when it is more than 5 s in and before
  its last 5, telling it; it notes where it is every 10 s of playing, at
  a pause and when another player comes, and forgets a film played to
  its end. A quit loses at most the last 10 s.

## The playlist, picture in picture (v1.40)

- **The state opens films.** `VideoPlayerState::open($path)` makes the
  player of a film (`setPlayer` takes one the app made), and
  `Context::videoPlayer($state, $options)` builds over the one it plays:
  going to another film is the player's own (the playlist, the next at
  an end), the app's code a call. A player taken is gone back to where it
  was left at the first build with it, which knows the options.
- **The playlist** (`Media\Playlist`): the films of the opened film's
  folder (`Playlist::folder`, their names in Finder's order:
  `strnatcasecmp`, episode 2 before episode 10), the one playing, next
  and previous, adding and taking out; durations read from the files'
  headers as the sidebar asks (`Player::durationOf`), once. Its sidebar
  slides in from the left as IINA's (the tabs, the rows of the films,
  the one playing marked, a double click playing one, the time of them
  all, add, remove, clear); the next film plays at the end of one;
  Cmd+Right and Cmd+Left go to the next and the one before.
- **Picture in picture** (`Native\PictureInPicture`, macOS 12): AVKit's
  controller of the player's display layer
  (`AVPictureInPictureControllerContentSource` of a sample buffer display
  layer), its delegate telling the system's window the film's time range
  (the clock's timeline, from 0) and whether it is paused, and playing,
  pausing and skipping from its buttons (the skip's completion block
  called); the state tells it when it plays, pauses or seeks
  (`invalidatePlaybackState`). It starts once the system says it can (a
  moment after the picture shows). The system's window takes the display
  layer, and shows where it was what says so (the player draws nothing of
  its own there, AGENTS.md rule 11).
- **Trap: a display layer as a sublayer.** Picture in picture maps a
  sample buffer display layer through the view that hosts it: as a
  sublayer of a view's layer it showed a corner of it, at the size it had
  in the window, in a gray box. The video's host is views all the way
  down: a clip view, a crop view (masking), the picture's view, layer
  hosting with the display layer as its layer (`setLayer:` before
  `setWantsLayer:`), laid out by frames and turned by
  `setFrameCenterRotation:`.
- **macOS mirrors the layer unscaled** (FB22411168; Apple's forums,
  thread 821582): the system's window shows a sample buffer display layer
  through a CALayerHost, pixel for pixel, a corner of a large layer and
  black around a small one. As AetherEngine does, with public AppKit: the
  window that comes as it starts (a visible panel, not among the app's
  windows before, of the picture's shape, the only one so, looked for 5 s)
  is found, and the picture's view laid out at its content view's size,
  followed through its frame's notifications and the render size the
  controller tells; the player's window, hidden, shows none of it.
- **Trap: the view by the layer's delegate.** The picture's view was
  first reached as its display layer's `delegate`; for a layer hosting
  view (the layer set before `setWantsLayer:`) that is not the view, and
  laying it out changed nothing, the picture still a corner in black. The
  window keeps the view it made (`Renderers::$view`, set as it places the
  video, cleared as it closes) and the controller lays that out.
- **Trap: taking the layer back.** `placeVideos` put a video's layer back
  in its host whenever it was elsewhere: in picture in picture, every
  frame took it out of the system's window, which then showed a corner of
  it in a gray box. The host leaves it while it is elsewhere
  (`Renderers::$elsewhere`, from the start's beginning, as the system's
  animation sizes it, to the stop's end), as the file has it (fitted,
  upright, sizable with the system's window).
- **The window goes with it.** As IINA's, the player's window hides while
  the film plays in picture in picture and shows again as it stops (and
  when the system asks to restore it, before the picture goes back):
  from the controller's delegate, outside any frame, so through
  `Runtime::$showWindow`, which the window sets (a hidden window draws
  no frame that could show it).
- **Trap: going back faded out.** As it stops, the system's window
  animates into where the layer is in the app's window, if that window
  shows with the picture in its place when the restore's completion is
  called; the picture's view was still at the system's window's size and
  left (`elsewhere`), so the small window faded and the large one came
  after. The restore gives the layer back first (`elsewhere` off, the
  panel let go) and `Runtime::$showWindow(true)` places the videos at
  once, from the last frame's places, before calling the completion: the
  small window grows into the large one, as IINA's.
- **Trap: the close button as IINA's.** IINA's close button (a private
  PIPViewController's `replacementWindow`) grows the small window into
  the large one and pauses. With AVKit, giving the layer back at
  `WillStop` and pausing at a stop with no restore went worse (the
  developer's call): its close button is left as AVKit has it, the
  window and the picture back at `DidStop`, playing on.
- **The playlist's tabs slide.** The playlist and the chapters are two
  pages side by side in a clipped box, both moved by the tab's animated
  place (0.25 s), each built while any of it shows, as IINA's tabs go
  from one to the other.

## Chapters; the playlist looped, shuffled and sorted (v1.41)

- **Chapters** (`Media\Chapter`, `Source::chapters`, `Player::chapters`,
  `Player::chapter`): Matroska's of the default edition (else the
  first), its top-level atoms but the hidden and disabled, as mpv lists
  them, titled by their first display, their start in nanoseconds
  whatever the timestamp scale; read with the other top-level elements,
  through the seek head when they come after the clusters. An MP4's, a
  QuickTime chapter track, as AVFoundation reads it
  (`chapterMetadataGroupsBestMatchingPreferredLanguages:`, the common
  title of each group), once asked for.
- **Trap: an undetermined chapter track.** ffmpeg and HandBrake write
  the chapter track's language undetermined ("und", or 0 read as it),
  which matches no preferred language: the best match gave no chapters
  for a user of English. With none matching, the chapters are read in
  the file's first language (`availableChapterLocales`,
  `chapterMetadataGroupsWithTitleLocale:containingItemsWithCommonKeys:`).
- **Trap: a CMTimeRange returned by value.** The group's `timeRange`
  sent through `ObjC::msg('X:', …)` came back as numbers of nothing (the
  titles right, the starts in millions of seconds, out of order), where
  a CMTime returned (`'T:'`) and a CMTimeRange passed (`setTimeRange:`)
  are right. The chapter's start is read from its items' `time`, a
  CMTime; why the 48-byte return goes wrong is not found yet. The one playing is the last
  started (`chapter()`, -1 before the first).
- **The chapters' tab**, as IINA's `SidebarChaptersPane`: a row of 40 a
  chapter, the one playing marked, the title (or "Chapter n", n from 0
  as IINA counts) over "start → next start", the last's "End"; lines
  between and below; a double click goes to it and plays
  (`VideoPlayerState::playChapter`, IINA's `playChapter`). IINA writes
  "Chapter" and "End" in English whatever the language; the strings here
  are translated. The progress bar has no chapter marks: IINA's
  `showChapterPos` is off by default. With the sidebar open, which
  chapter plays is part of the look that asks for a frame.
- **The loop** (`VideoPlayerState::$loop`, `nextLoop`): IINA's button
  goes off, the file (`repeat.1`), the playlist (`repeat`), off, in the
  accent while on. The file's plays the film again at its end; the
  playlist's goes from the last to the first at an end and for Cmd+Right
  (and from the first to the last for Cmd+Left), as mpv's loop-playlist.
- **Shuffle and sort** (`Playlist::shuffle`, `unshuffle`, `shuffled`,
  `sortByName`, `sortByPath`): as mpv's playlist-shuffle and
  playlist-unshuffle, the one playing staying the one playing, the order
  before coming back with what was added since after it; the button in
  the accent while shuffled. The sort button's menu, as IINA's: a header
  and four items, the names or the paths compared by their bytes, up or
  down. IINA's two name items sort by path (both call `sortPath`); here
  they sort by name, which a playlist of one folder cannot tell apart.
- **The display kept awake** (`Native\Awake`, `Player::awake`): while a
  player plays, a ProcessInfo activity of idle display sleep disabled, as
  IINA's SleepPreventer (its default: the screen saver off, sound alone
  too), ended as it pauses, ends or fails, and with the player;
  `pmset -g assertions` lists it as "Playing <file>". `Player::setKeepAwake`
  turns it off, as `VideoPlayerOptions::$keepAwake` (on) asks.
- **Trap: the pointer shown in full screen.** A cursor of `Cursor::None`
  was a transparent NSCursor set once, as the shape changed: in a full
  screen window AppKit set its own back, and the pointer showed over the
  film. It is hidden as IINA hides it besides,
  `setHiddenUntilMouseMoves:` each frame it is none (from macOS 11 a click
  shows it again without a move: IINA's workaround of FB11963121); the
  transparent cursor keeps it unseen between a move and the frame after.
- **Menu headers** (`UI\Menu::header`, `MenuItem::HEADER`,
  `MenuItem::header`): NSMenuItem's `sectionHeaderWithTitle:` (macOS 14),
  chosen by none; before macOS 14 menus have none and the item is left
  out, as IINA leaves it.

## FLAC (v1.42)

- **FLAC in Matroska** (`A_FLAC`, CoreAudio's `flac`): its magic cookie
  is the one AVFoundation makes of an MP4's, read off its format
  description: a whole dfLa box (size 50, "dfLa", version and flags 0)
  holding the STREAMINFO block alone, marked the last, which Matroska's
  CodecPrivate carries after "fLaC". The format's frames a packet are
  STREAMINFO's largest block (4608 as ffmpeg writes), as AVFoundation's;
  each packet's frames, which the sound's timeline adds up, come from its
  frame header (the block size's code, or the byte or two after the
  UTF-8 coded frame number), the last block fewer.
- **FLAC in MP4**: AVFoundation's sample buffers go to the renderer as
  they are; the reader names `flac` A_FLAC, which the system decodes.
- **Vorbis** is not taken: the system decodes it (`vorb` is among
  kAudioFormatProperty_DecodeFormatIDs), but a packet's frames are of its
  mode's block, which only the setup header's modes say (read from its
  end, past the codebooks), and the decoder's magic cookie is not known.

## Image subtitles: PGS and VobSub (v1.43)

- **PGS** (`Media\Pgs`, Matroska's S_HDMV/PGS): a packet is a display
  set, its segments without the "PG" and times of a .sup. The
  composition places objects (bitmaps of indexes in runs, in fragments
  over several segments) on the plane in a palette's colors (Y, Cr, Cb,
  alpha: BT.709 for a plane of 720 lines or more, else BT.601, limited
  range, as ffmpeg's decoder takes them), each maybe cut (its crop).
  Objects and palettes last through an epoch, which later sets reuse, so
  one decoder reads a track's sets in order; a set of no objects clears,
  a cue of no pictures that ends the one before (a set's cue lasts until
  the next). An object of before a seek, in the epoch it began in, is
  left out until a set that has it.
- **VobSub** (`Media\VobSub`, S_VOBSUB): the .idx header is the track's
  CodecPrivate (the plane's size, 16 RGB colors); a packet is a
  subpicture unit: its bitmap of two-bit pixels in runs of nibbles, its
  even and odd lines apart, then control sequences of when it shows and
  stops (in ticks of 1024 of 90 kHz after the packet's time; Matroska's
  block durations from ffmpeg are of no use), the four colors and alphas
  its pixels take, its box, where each field's runs start.
- **Pictures, not pixels, kept** (`Media\SubtitlePicture`): where it is on
  the plane and the plane's size; its premultiplied RGBA decoded from
  the runs as asked for (a film has hundreds, a megabyte or more each
  decoded). The player's window makes the image of the few shown last.
- **Cues of pictures** (`Cues::add` of a text or pictures): a set read
  again after a seek is the same cue; one of none shows nothing.
  `Player::subtitles` gives the texts showing, `subtitlePictures` the
  pictures, `subtitlePlane` where the plane lies in a box: over the whole
  picture as `picture()` lays it out (a crop cuts it with the picture),
  fitted in what shows of a turned picture.
- **The fixtures**: no ffmpeg encodes PGS, nor draws text into image
  subtitles. A .sup of two display sets and their clears was drawn (text
  in Noto Sans CJK, white outlined in black) and encoded for the test,
  checked against ffmpeg's decoder (its overlay), and muxed into
  clip-pgs.mkv (`-copyts`, else ffmpeg starts its times at the first;
  its track marked default, which alone a player shows unasked, as mpv);
  clip-vobsub.mkv is ffmpeg's dvdsub of it (`-fix_sub_duration`, else
  its subpictures stop at the largest delay).

## ASS in its styles (v1.44)

- **The script** (`Media\Ass`): [Script Info]'s PlayResX and PlayResY
  as libass takes them (384 by 288 when neither is given; 1280 wide for
  1024 high alone, else 4:3), WrapStyle (2 breaks only at \N),
  ScaledBorderAndShadow; the styles of [V4+ Styles] or SSA's [V4 Styles]
  (its TertiaryColour the outline, its old alignment on the numpad) by
  their Format line. A Matroska track's header is its CodecPrivate, a
  packet a Dialogue without its times (`Subtitles::assPacket`); a file's
  are read whole (`Subtitles::assFile`). `subtitles()` gives their text
  as before; `Player::subtitleLines()` the lines (`AssLine`) in runs of
  their styles (`AssRun`).
- **Override tags**, scanned as libass scans them (a tag to the next
  backslash, or its closing parenthesis, \t's holding tags of their
  own): \b \i \u \s, \fn \fs \fsp, \c \1c to \4c, \alpha \1a to \4a,
  \bord \shad, \fscx \fscy \frz, \an \a, \pos, \r, \p (a drawing, left
  out). \be, \blur, \fax \fay, \frx \fry, borders and shadows apart in
  x and y, and a drawing's \clip and \iclip are left out.
- **Lines that change as they show** (`AssLine::at`, `changes`): a line
  knows its start and duration; \move goes between its points over its
  times (its whole time without), \fad and \fade set its opacity, \t
  takes what follows it to its tags between its times by its
  acceleration (sizes, spacing, borders, shadows, scales, rotation,
  colors by channel, alphas), \k \K \kf \ko sing its syllables (the
  secondary colour until then; \kf at its start, not swept; \ko its
  outline). `Player::subtitleLines` gives them as they are now; while one
  changes the view asks for a frame at each refresh of the display.
- **Lines laid out alone**: one placed (\pos, \move), turned, scaled or
  clipped is not stacked with others; it is drawn in boxes of the plane
  one in the other: cut to \clip's rectangle, turned by \frz around
  \org (else the point of its alignment, as libass), scaled by \fscx and
  \fscy around that point. A line turns and scales by its first run's.
- **Trap: a wrapped text aligned twice.** A rich text given a width to
  wrap at lays its lines out aligned within it, but is itself as wide as
  its widest line: put in the middle by its column too, a centered line
  went right by half the room left (the test's text engine aligns no
  lines, so only the Mac showed it). A row that wraps is as wide as it
  wraps at, its text stretched in it, the alignment the layout's alone.
- **Trap: a font fallen back on.** Chinese in a style of Arial is drawn
  in a font the system falls back on, whose ascent and descent are not
  Arial's: sized as Arial's, it was a fifth larger than libass draws it.
  A run is laid out at 100 to find the font of each rune, and cut into
  pieces by font, each its em from its own font's ascent and descent,
  as libass sizes every face.
- **Laid out as libass lays it**: over the plane of PlayRes on the
  picture (`subtitlePlane`, x and y scaled apart), a line at \pos the
  point of its alignment there, else within the margins at its
  alignment, those of one alignment and margins stacked (at the bottom
  the later above), wrapped at the margins; lower layers first. Each row
  is a rich text of its runs over its outline (copies around it, 8, or 16
  past a point and a half) and its shadow (those moved), or for
  BorderStyle 3 over a box of the outline's color.
- **Trap: Fontsize is no em.** libass sizes a font so that its ascent and
  descent are Fontsize high (FreeType's real dimensions), not its em:
  Arial's em is 0.9 of it. The em is Fontsize times 100 over the font's
  ascent and descent at 100 (`Context::fontMetrics`), by family, weight
  and italic.
- **The fixtures**: clip-ass-motion.mkv muxes motion.ass (\move,
  \fad, \t, \k, \frz with \org, \fscx, \clip), held to libass at 0.5,
  1 and 1.5 s. clip-ass.mkv muxes tests/testdata/styled.ass (styles
  of an outline and shadow, of a bold top line, a box; \c, \i, \b, \u,
  \N, \pos at 3/4 across); libass's rendering of it (ffmpeg's
  `subtitles` filter) is what the player is held to. An .ass beside a
  film of its name loads as its subtitles: the file is named apart.

## Subtitles under the glass (v1.45)

- **A layer under the materials** (`Node::belowMaterials`): mpv draws
  subtitles into the picture, under IINA's control bar, whose glass blurs
  them; drawn with the content they lay over the glass. A window of
  native content paints such a node, and what is in it, in a view of its
  own: an MTKView of the window's size, clear and not opaque, right over
  the top video and under the materials (`stackUnder`, put back as videos
  come; the materials go right under the window's view). Its place and
  its pointer are the node's as ever; the frame notes it with the
  transform, opacity and clip its painting had there (`Runtime::
  $underPaints`) and paints it in that view instead. A window of no such
  layer (a test's) paints it where it is.
- **In the same turn**: the frame's list and buffers are of a ring the
  frames go round; the view under the materials draws once the window's
  frame is done and the ring has moved on (`Metal::$afterFrame`), in the
  same turn of the run loop, so the two present together, and only when
  a frame has some or had (to clear it). An atlas that starts over as it
  paints, paints it again.
- The player's subtitles (text, ASS, PGS and VobSub) are one node
  belowMaterials(), built before the bars.
- **Outlines of text** (`Node::textOutline`, `textShadow`): a glyph's
  outline is its own mask, the glyph's path filled and stroked twice its
  width (round joins, no font smoothing) by CoreText into the atlas, of
  widths a quarter point apart; a rich text paints its shadow (the
  outlined glyphs moved), its outline, then its glyphs. A color glyph has
  none. Outlined or shadowed text is laid out as rich text.
- **Trap: outlines of copies.** Subtitles were outlined by copies of
  their text around them (8 for a text track's, 16 and 16 more for an ASS
  line's outline and shadow): a screen of moving ASS was over two hundred
  rich texts laid out and painted 30 times a second, 17 % of the CPU on
  solid video against 1.4 % without, the layer under the materials as
  dear as the window's frame. Each row is one text now, painted three
  times.
- **Trap: a moving line juddered.** A line of \move (a danmaku's) was
  asked frames 30 a second by a timer, out of step with the display, and
  laid out again at each place: its glyphs land on whole pixels, so it
  went by uneven steps of them. It is asked a frame at each refresh of the
  display (`animationFrame`), laid out where it starts and moved by a
  transform (`AssLine::$moved`), in fractions of a pixel the atlas's
  linear sampling draws, as a browser moves one.
- **Trap: animationFrame() asked as a frame is built was lost.** It set
  the runtime's needsFrame, which the frame's end clears before it adds
  what this frame asks for: asked from a build (a moving subtitle, a
  Markdown view's reveal, a router's page going, a drag coming to rest),
  no frame came, and what moved went by the player's four ticks a second,
  or stopped. Asked while building it is kept as the frame's own
  (animating, as an opened sheet's), else needsFrame as before.

## The player's events, keyframe seeks, Now Playing, frame steps, screenshots (v1.46)

- **Events as it changes** (`Player::EVENTS`): play and pause (its end
  and an error pause it too), rate, seek (with where it went) and track
  ('audio', 'subtitle'), each once, when it changes: play() playing and
  setRate() at the rate tell nothing. A seek inside the player (a track
  of sound changed, a delay set, play() after the end) goes by go(),
  which tells nothing.
- **Keyframe seeks** (`seek($s, exact: false)`), as mpv's (IINA's arrow
  keys, buttons and wheel, `VideoPlayerState::seekBy`): the source goes
  to the keyframe at or before the time as ever; behind, that one; ahead,
  the packets are read on to the first keyframe at or after it, kept
  (`$ahead`, which the next packets come from) but for the video before
  it, so it shows at once and nothing is decoded unseen. None ahead (past
  the last): to the time itself, as mpv goes to the end. Exact stays the
  default, the bar's (IINA's slider seeks exactly).
- **Now Playing** (`Native\NowPlaying`, `VideoPlayerOptions::$nowPlaying`):
  IINA's NowPlayingInfoManager and RemoteCommandController. One for the
  process: the state that showed it last takes the commands (play,
  pause, the two, stop, next, previous, 15 s either way exactly, the
  loop, the rates 0.5 to 2, a place), its handlers static closures given
  it, held weakly, so that a state let go ends it (its destructor). It is
  shown again at each play, pause, rate, seek and chapter, the rate 0
  while paused (else Now Playing's bar runs on); a film opened paused is
  set playing then paused, as IINA does, else Now Playing drops it for
  Music's. Nothing playing after the last film, it ends: the system's own
  takes it back (IINA's issue 4331).
- **Frame steps** (`Player::step`, IINA's `.` `,` and Option+arrows):
  by the frames' grid, the track's frame duration from the time of the
  first frame read; to half a frame past the grid's place, exactly, so
  that the nearest frame shows, and the clock set back to that frame's
  own time (the last read at or before where it went, `$landed`).
  **Trap: rounded times.** Matroska's frames are at whole milliseconds
  (0.042, 0.083 of 1/24 s) and its block durations rounded too (0.041,
  which drifts a frame in a second): the grid is the track's duration,
  the packets' only when it has none, and the frame showing is found a
  quarter of a frame early let pass, else 0.083 reads as the frame
  before 0.0833 and a step stands still.
- **Snapshots** (`Player::snapshot`, `VideoPlayerState::screenshot`, ⌘S):
  the display layer's video renderer gives the pixel buffer it shows
  (`copyDisplayedPixelBuffer`, macOS 14.4), made a PNG by CoreImage and
  NSBitmapImageRep; no decoder of our own. Only of a layer on screen:
  one in no window (a test's player alone) gives none, null. The film's pixels: no
  subtitles on it, unlike mpv's screenshots of IINA's default. Saved as
  IINA's are, ~/Pictures/Screenshots/movie-0001.png, the first number
  not taken.
- **Trap: a message given the wrong arguments.** `ObjC::msg('v:p', $w,
  'orderFrontRegardless')` passes `php -l` and every test off macOS; the
  FFI of macOS throws only when the line runs. `tests/objc_sig_test.php`
  reads each message of a literal signature and selector, everywhere, and
  fails where the signature's arguments, the selector's colons and the
  arguments given differ, or a letter is none ObjC maps.

## The seek bar's thumbnails (v1.47)

- **A decoder of our own** (`Native\VideoDecoder`, `Player::thumbnail`):
  the display layer gives only the frame it shows on screen (v1.46), so a
  thumbnail is decoded apart from what plays: a second reader of the file
  reads the keyframe at or before the time alone (`Source::keyframe`:
  Matroska's cluster walked by its elements' headers, the other tracks'
  blocks skipped), and a VideoToolbox decompression session decodes it
  synchronously into BGRA of the thumbnail's size, VideoToolbox scaling
  as it decodes; vImage makes it RGBA.
- **Measured** (101 thumbnails of a 1080p H.264 Matroska film, per
  thumbnail): reading its whole cluster 1.5 ms, the keyframe alone a
  fraction of that; decoding 3.7 ms then CoreImage's conversion and
  Lanczos 2.8 ms, against VideoToolbox's own scaled output 4.7 ms and 0.02
  ms after it. About 10 ms a thumbnail first, about 5 now; the first
  of a film 20 ms more, its session made.
- **Trap: VideoToolbox's callback is on a thread of its own**, even for a
  synchronous decode (`pthread_main_np()` 0 in every one of some 1500
  decodes of H.264 and HEVC, 160 by 90 to 1080p, by a probe). PHP
  runs there only because the main thread waits in the decode until the
  callback returns: one thread runs PHP at a time. The callback keeps the
  frame (CVPixelBufferRetain) and nothing more, in an object of its own
  (no cycle to the decoder); it stood 200 calls deep (PHP's stack limit,
  8.5) on that thread. Never decode asynchronously: the callback would
  run PHP beside the main thread.
- **As IINA's** (FFmpegController, TimePreviewView, ThumbnailPeekView):
  101 thumbnails, the keyframe at each hundredth, one kept of each
  keyframe, 240 pixels wide shown at 120 points; made one at a time on
  the main thread after a film opens (some 5 ms each: IINA's thread),
  each right after the window presents a frame (`Runtime::afterFrame`),
  where the next frame has the most time, or 20 ms on when it draws none;
  the state's once all are (`VideoPlayerState::$thumbnails`,
  `thumbnailAt`: the one before the first at or after the time). Over the
  bar of the time: the time there (its chapter's title above it) in glass
  5 points above the bar, the thumbnail 5 above that, round by 4, ringed
  and shadowed, turned as the picture is.
- **Kept on disk** (`Media\ThumbnailCache`, IINA's ThumbnailCache and
  CacheManager): a file a film, by the md5 of its path, in thumb_cache of
  the app's caches, valid while the film's size and modification time
  are those it was made of; JPEGs of quality 0.75, each made as its
  thumbnail is (a fraction of a millisecond), written at once through a
  temporary file; read back at once when the film opens again, each
  decoded when first shown. Past 500 MiB, the least recently used go
  until half of that is free; a file cut short goes.
- **Trap: a `const char*` return is a PHP string.** FFI turns a function's
  `const char*` result into a string cut at its first NUL (as
  `sel_getName`'s is meant to be): `CFDataGetBytePtr` of a JPEG came back
  four bytes, `\xFF\xD8\xFF\xE0`, no pointer. Bytes are `void*`, read
  with FFI::string and their length.
- **Trap: no cue at 0.** A Matroska film whose first frame (and cue) is
  a moment in (0.023 s, of muxers that start at the audio's) threw "no
  cue at or before 0s" on a seek to the start: the first thumbnail, and
  play() after the end of such a film. A time before the first cue goes
  to it, where decoding starts.

## Boundaries and the A-B loop (v1.48)

- **Boundaries** (`Player::at`, AVPlayer's boundary time observers): told
  when it plays from before a time to it or after, not when a seek lands
  past it (a seek, play() and a step note where it is, `$passedTo`). The
  ticks, four a second, look; a boundary before the next tick gets a
  timer of its own, as a subtitle's change does, so it is told within a
  few milliseconds. One that seeks (a loop back) ends the round: the
  boundaries after it were not played past.
- **The A-B loop** (`VideoPlayerState::abLoop`, ⌘L, mpv's ab-loop of
  IINA's): A where it plays, then B there (the earlier of the two A, it
  loops at once from A when B is where it plays), then none, each told as
  IINA's OSD tells it. B is a boundary that seeks to A. The points show
  on the bar of the time as IINA's loop knobs (gray 0.65, three by eleven
  points, round by one), and dragged they move (`moveLoopPoint`), told
  with the point's time. Another film clears it.

## Films over HTTP (v1.49)

- **The run loop drives libcurl** (`Native\CurlLoop`): one multi handle,
  its sockets watched by CFFileDescriptors of the main run loop
  (CURLMOPT_SOCKETFUNCTION; their callbacks fire once, armed again after
  each) and its timeouts by timers (CURLMOPT_TIMERFUNCTION), so that a
  transfer runs as its socket is ready: nothing waits on the main thread,
  nothing is polled. Off macOS, with no run loop, `pump()` drives it
  (curl_multi_poll and perform), which the tests of Linux use. A fiber
  waits for what a transfer brings (`waitUntil`), resumed once it came,
  looked at as each socket's data is read; outside one the run loop
  turns meanwhile (App::wait).
- **Bytes** (`Media\Bytes`, `FileBytes`, `HttpBytes`): Matroska reads its
  bytes through them. A film's over HTTP, as mpv's stream and demuxer
  cache: one transfer at a time from where the reader is (a Range
  request, its first from 0 saying the size), into a cache of 256 KiB
  blocks, what came of the block it fills read as it comes; a read of
  what is not near the transfer starts another there; it stops once
  the cache holds 150 MiB ahead (IINA's defaultCacheSize) and goes on as
  it is read, the blocks least recently read going past that. A server
  of no ranges is read from its start, and a read behind what it kept
  fails. `has()` says, without waiting, whether bytes are here, and asks
  for them; `read()` waits.
- **Never waiting in a tick**: `Source::ready()` (Matroska's: the next
  element's head here, and the bytes of the block it reads) is asked before next(),
  which returns null for what is not here yet as for the end; the
  player's feed stops there and goes on as it comes. Opening a film over
  HTTP reads its header and cues, waiting: `VideoPlayerState::open` does
  it in a fiber of its own (App::go), the window going on, IINA's
  "Opening stream…" in the middle meanwhile.
- **Paused for the cache** (mpv's paused-for-cache, the buffering event):
  once less than 0.1 s is given to the renderers ahead of the clock and
  the next is not here, the clock stops, and goes on once 1 s is (IINA's
  cachePauseWait), or the end; looked at every 50 ms meanwhile, how much
  of the second came the percent (`bufferingPercent`). IINA's
  BufferIndicatorView shows it, with what is cached and how fast it
  comes, as IINA's FloatingPointByteCountFormatter writes them.
- **As IINA's**: no thumbnails of a film over the network, nor subtitle
  files looked for beside it; the playlist of a URL is itself.
- **Trap: a cache past PHP's memory_limit.** The blocks were PHP strings:
  read at a local server's 114 MB/s, the cache passed 128 MiB, PHP's
  default memory_limit, and the process died in the transfer's callback
  (the player stood "Buffering… 0%", nothing coming). They are native
  memory now (Mem::alloc), as mpv's cache is, which memory_limit counts
  none of: a film of 225 MB read through within 128M, the cache held at
  its 150 MiB. Whether the cache is full ahead is looked at as a block is
  kept, not at each piece the callback is given (thousands a second).
- **Trap: a socket's callback tells a change, not a state.** At a local
  server's 44 MB/s the transfer stood still, 128 KiB into a block, at
  0 B/s: curl reads a buffer at a time, and what it left in the socket
  (the server waiting for room to send more) woke the descriptor no more.
  After each act on a socket, poll() says whether it is ready still, and
  it is acted on again, 64 times a turn at most, the next turn going on
  with it (`CurlLoop::drain`).
- **Trap: a CFFileDescriptor invalidated takes the next socket's kevent.**
  curl lets a socket go (CURL_POLL_REMOVE), closes it, and opens the
  next transfer's at once, of the same number: the descriptor of the one
  gone, invalidated, took the kevent of the new one a moment later, and
  that transfer stood still, 64 KiB at a time in, the server waiting to
  send more (a trace: armed, then nothing for 5 s). A descriptor of each
  socket number is kept for the process, its callbacks disabled when
  curl lets the socket go and enabled for the next.
- **Trap: a sample a piece.** The speed was of a list of every piece the
  callback was given in the last second, pruned only when the speed was
  asked: at a local server's 3.3 GB/s, 200,000 a second and none asked
  while it played (the indicator asks only while it waits), so the list
  grew to 512 MiB, the app's memory_limit, and the indicator's prune,
  each frame, starved the main thread: "Buffering… 0%" with the cache
  full. The bytes are summed by tenths of a second, ten kept.
- **Trap: a whole cluster before a frame.** Matroska read a cluster at
  once, so ready() waited for all of it: some megabytes, seconds at a
  slow speed, the percent of the wait standing still until it came and
  went on at once. A cluster is read element by element now, each block
  as its bytes come, as a stream demuxer reads; a cluster of unknown
  size (a live one's) ends at the next top-level element.
- **The reader is where it was asked to be**: a read asked of a block not
  here (has()) puts the reader there, so that the cache reads ahead of,
  and keeps, what it reads next: opening read the cues at the film's end,
  and the transfer from its start counted as behind them.
- **A connection that breaks** (curl's PARTIAL_FILE, RECV_ERROR, a timeout,
  nothing got) is gone on from the block it filled, as ffmpeg's reconnect
  does, 3 times in a row at most (0.5, 1 and 2 s apart, counted again once
  bytes come), before what failed is told.
- **Trap: a film whose cues follow its clusters** (most muxers write them
  last) reads its end before it plays: the cache's transfer goes there
  and back. A film smaller than a block waits for all of it.

## MP4 over HTTP (v1.50)
- **An MP4 or MOV over HTTP is read in PHP** (`Media\Mp4`), as FFmpeg's
  mov demuxer and so mpv and IINA read it: AVFoundation's reader takes
  only a file, and AVPlayer's own loading has neither mpv's cache nor its
  pause for it. `Player` tells the two kinds of film apart by their first
  bytes (EBML's magic, or the type of an ISO box: ftyp, moov, mdat, free,
  skip, wide, pnot); a local MP4 is AVFoundation's still.
- **The movie box is where it is**: at the start (a "fast start" film) or
  after the samples (most cameras and muxers); the top-level boxes are
  walked by their sizes, so a film whose moov is last reads its end
  before it plays, as a Matroska film whose cues are last does.
- **The sample table is packed**: the samples' offsets, sizes, times and
  durations are each one packed string (`P`, `V`, `q`, `V`), built in one
  pass over stsz, stco/co64, stsc, stts and ctts, and the order the
  samples are stored in (all tracks, by offset) another: an hour of film,
  241,441 samples, opens in 0.36 s at an 18.5 MB peak, where a PHP array
  of a sample each took ten times that.
- **Codecs as Matroska names them**: the sample entry's four characters
  map to Matroska's codec ids and its configuration box to what
  CodecPrivate holds (avcC, hvcC, av1C, vpcC whole; esds's
  AudioSpecificConfig, its object types 0x69 and 0x6B MP3; dOps rebuilt
  as an OpusHead; dfLa as `fLaC` and its blocks; tx3g as `S_TEXT/TX3G`),
  so the decoders and the subtitle reader take them as they take
  Matroska's.
- **The edit list shifts the times** (its first entry's media time, as
  FFmpeg applies it): AAC's priming, a B-frame film's composition delay;
  an empty first entry delays the track.
- **Chapters**: a QuickTime chapter track (`tref/chap`, its text samples
  the titles) or Nero's `chpl` in udta, as FFmpeg reads them.
- **Trap: App::later before the runtime.** `App::later` and `after`
  defined their target class through ObjC as if `App::run` had booted
  it; a transfer started before it (`tests/http_bytes_test.php` run
  alone, a tool with no window) called them from curl's timer callback:
  "must not be accessed before initialization", fatal from an FFI
  callback. `App::later` boots Foundation itself, as the natives do.
- Checked packet for packet against ffprobe (time, size, keyframe) on the
  test films and an hour-long one; `clip-subs.mp4` has an empty tx3g
  sample ffprobe drops, which shows nothing.

## MPEG-TS and HLS (v1.51)
- **HLS is read in PHP** (`Media\Hls`, `HlsStream`), as FFmpeg's hls
  demuxer and so mpv and IINA read it; AVPlayer plays HLS but with no
  cache of mpv's, no pause for it and none of the player's own pipeline
  (subtitles, steps, the A-B loop), so the segments go through the
  demuxers the rest does: MPEG-TS (`Media\Ts`), fragmented MP4
  (`Media\Fragment`, of the init segment's `Mp4`) or packed audio
  (`Media\PackedAudio`: ADTS, AC-3, E-AC-3 or MP3 frames after an ID3
  tag whose PRIV frame com.apple.streaming.transportStreamTimestamp is
  the first's PTS; Apple's examples' AC-3 renditions), told apart by a
  segment's first bytes as FFmpeg probes them. `Player` takes a URL
  whose path ends .m3u8 or .m3u (or whose first bytes are #EXTM3U) as HLS.
- **The variant of the highest bandwidth** (mpv's hls-bitrate=max); its
  audio group's renditions with playlists of their own are audio tracks
  (EXT-X-MEDIA's LANGUAGE, NAME, DEFAULT), each probed at the start as
  FFmpeg probes them, only the one played downloaded: `Source::choose`,
  which `Player` calls as it opens and as the audio track changes (a
  file's sources read all their tracks anyway).
- **Segments download one after the other, ahead**: each stream's next
  starts once the one before is done, while what the streams hold ahead
  of where they read is under 90% of IINA's 150 MiB (mpv's demuxer cache);
  `Media\Download` keeps a segment in native memory as `HttpBytes` keeps a
  film, read as it comes (a segment plays as its first bytes come),
  AES-128 decrypted as it comes (CommonCrypto, libcrypto off macOS, by
  FFI: `Native\Aes`), its last block kept back for PKCS#7's padding.
  The IV is the tag's, else the segment's sequence number (RFC 8216).
- **The times are lined up on the playlist's**: each stream's own (a
  TS's PTS, an fMP4's tfdt) less what the earliest frame of the first
  segment read was ahead of the segment's start, for each discontinuity
  sequence (the streams of one share their clock, RFC 8216 3), so that a
  film starts at 0 as FFmpeg's start_time has it. A discontinuity met as
  it plays goes on from where the frames given end: the segments before
  are often longer than their EXTINF (a test's, 31 ms), and from the
  playlist's start the next frames would come before the last.
- **Live**: a playlist of no EXT-X-ENDLIST is read again every target
  duration, half of it when nothing was added (FFmpeg's); it starts 3
  segments from its end (live_start_index -3) at 0, has no duration, and
  a seek goes no further back than where it started.
- **Trap: a segment's frames are not in the order of their times.** A
  video frame of a TS is held until the next one's decode time says its
  duration, and B-frames come in decode order: within a stream the times
  go back and forth, and `Hls::next` orders only between the streams
  (the earliest next frame of each), which is all the renderers need.
- **MPEG-TS in PHP** (`Media\Ts`), a file's too (.ts, .m2ts, .mts,
  which AVFoundation does not read): PAT and PMT (one packet each, as
  muxers write them), PES packets gathered by PID, H.264's and HEVC's
  Annex B made lengths, their SPS, PPS (and VPS) an avcC or hvcC record,
  the size from the SPS (`Media\Elementary`); AAC's ADTS frames a packet
  each, their AudioSpecificConfig of the header; AC-3, E-AC-3 and MP3 a
  frame a packet. A file seeks by a binary search of its PES times, back
  to a random access point (or an IDR), as FFmpeg's generic seek does;
  its duration is its last PTS less its first. Checked packet for packet
  against ffprobe (time, duration, keyframe) on H.264, HEVC, AAC, AC-3,
  E-AC-3 and MP3 streams and the HLS fixtures.
- **Trap: a rendition's URI is the master playlist's.** Resolved from the
  variant's playlist (it was the Download at hand), Apple's examples'
  a1/prog_index.m3u8 became v9/a1/prog_index.m3u8, beside the variant in
  its folder: HTTP 401. The master's renditions are made absolute as it
  is read; the test server serves folders now, as one did not see it.
- **Trap: a PES is no frame.** A video PES was taken as an access unit,
  as FFmpeg's muxer writes them; Apple's segmenter splits a frame of
  1080p over several, the rest of no PTS, and those were dropped (a
  frame cut short) or, of no start code, made empty frames, which
  CoreMedia's CMBlockBufferCreateWithMemoryBlock refuses. The stream's
  bytes are made NAL units and access units by where a picture starts
  (AUD, SPS, PPS, SEI, a picture's first slice) as FFmpeg's h264 and hevc
  parsers do, each timed by the first PES that started in it (a PES's
  4-byte start code's zero counted as its start); one of none goes on
  from the frame before by a frame (wrong for B-frames, as two frames in
  a PES are rare). `tests/ts_test.php` splits and joins clip.ts's PES.
- **The first segments are probed only as far as a frame of each track**
  (a 1080p segment is megabytes); all the streams' first segments start
  downloading before any is probed, and the renditions' playlists with
  the variant's.
- **Trap: an arrow function holds what it captured as it was.** The
  probe's "a frame of each track yet?" was an fn() over $first, which it
  had captured empty: it never held, and the probe read the whole first
  segment (Apple's example: 5.35 s of its 9 s to open, 6 MB). The test
  sends a segment at 8 kB/s and wants it open in under 2 s.
- **Trap: nothing thrown from a transfer's end.** A segment's Download is
  driven by the run loop as HttpBytes is; its end (its finished callback)
  starts the next from the loop's callback, where nothing may throw: what
  fails there is kept and thrown by the next read (`HlsStream::peek`).

## yt-dlp, two sources, AV1 in software (v1.52)
- **A URL that opens as no film is read by yt-dlp** (`Media\YtDlp`), as
  mpv's ytdl_hook does on load failure for IINA: `yt-dlp --no-warnings -J
  --flat-playlist --sub-format ass/srt/vtt/best -- URL`, its default
  format (bestvideo+bestaudio/best), its JSON read: the formats' URLs and
  their http_headers (sent with every request: `HttpBytes`, `Download`,
  `Hls` take headers), the title (`Player::title`, the title bar's), the
  chapters, the subtitles (not the automatic captions; each a track,
  downloaded once it is chosen), a playlist's entries (`Player::playlist`,
  the sidebar's, titled: `Playlist::title`; each entry read by yt-dlp as
  it plays, from the one a watch?v=…&list=… URL names). The program is
  `YtDlp::$path`, else the first yt-dlp (or youtube-dl) in PATH,
  /opt/homebrew/bin or /usr/local/bin: an app the Finder opens has only
  the system's PATH. What the direct open said and what yt-dlp said are
  told together when both fail.
- **Trap: YouTube sends a whole film's request at the speed it plays.**
  A request from a place to the end (Range: N-) came at the film's rate,
  a 4K AV1's 8 MB in 3 s and then a stall; a seek waited on it for ever.
  yt-dlp reads YouTube in requests of 10 MB (its formats'
  downloader_options.http_chunk_size), which come as fast as the network
  goes: `HttpBytes` takes that size (whole blocks), one request after the
  other as the cache reads ahead. A transfer under a byte a second for
  20 s now ends (curl's low speed limit) and goes on from where it was.
- **Two sources as one** (`Media\Merged`): yt-dlp's video and audio
  formats are two films (DASH's MP4 and M4A, WebM, or HLS), each read and
  cached apart, their tracks together (the second's numbered from 1000),
  their frames given in the order of their times, a seek of both, as mpv
  plays a video with an audio-file.
- **Fragmented MP4 files** (DASH's, YouTube's): `Mp4` reads the movie
  fragments after the movie box (`Fragment` from a place of the file),
  seeks by the segment index (sidx), or, of none, by the fragments walked
  (their video's tfdt), and takes the duration from mehd, the index, or
  the last fragment's end.
- **Trap: a sidx's SAP is no keyframe.** FFmpeg marks every subsegment
  as starting with a SAP, those its -frag_duration cut between keyframes
  too: a seek to 1.9 s of clip-dash.mp4 landed on 1.583's P frame. The
  candidate's own first video sample flags (trun's, tfhd's, trex's) are
  looked at, and the one before taken where it is no sync sample.
- **AV1 in software** (`Native\Dav1d`, `Native\SoftVideo`), as mpv
  decodes what the system does not: libdav1d by FFI where VideoToolbox
  has no AV1 (before M3), or where `Player::$hardwareDecoding` is off
  (IINA's "Enable hardware decoder", read as a film opens). Each picture
  is made an NV12 CVPixelBuffer of IOSurfaces from a pool (luma copied a
  plane or a row at a time, chroma interleaved by vImage), its colors the
  sequence header's (H.273's matrix, primaries, transfer; 709 where it
  says none), its range '420v' or '420f', and a sample buffer at the
  picture's time the display layer shows; snapshots are
  the layer's as ever. Thumbnails decode by a dav1d of their own, scaled
  by vImage. `bin/myphp build` copies Homebrew's libdav1d.7.dylib into
  Contents/Resources of an app that plays video (as the plugins'
  libraries); the app finds it there, else in Homebrew's lib. Only 8-bit
  4:2:0 decodes yet (10-bit throws, said).
- **Trap: dav1d drains when asked twice.** `dav1d_get_picture` with
  nothing sent since the last call drains (waits on its frame threads, as
  at the end); mid-stream, a packet still queued in it (EAGAIN), that wait
  never ends. As FFmpeg's libdav1d.c: a picture is taken once a packet
  sent, and a refused packet is sent again before each take (a send, even
  refused, ends the drain); the loop that drains is only the end's
  (`Renderers::pump`).
- **Trap: a paused player has no tick.** The decoder full, its pictures
  were taken on the player's ticks; paused after a seek, there were none,
  and the layer showed the frame of before for every seek (the same
  snapshot at 0.5, 1 and 1.5 s, a mean error of 28 against the
  hardware's). `Renderers::ready` takes them then and there
  (`SoftVideo::unclog`), the main thread waiting on dav1d as on
  VideoToolbox. `tests/dav1d_test.php` checks every frame bit for bit
  against FFmpeg's libdav1d (framemd5).
- **The hardware's AV1, paused, sometimes shows a frame on the way.** A
  seek to 1 s of clip-av1.mkv on M3's decoder showed 0.375 s's frame now
  and then (one run in three; the frames before the target are enqueued
  marked DoNotDisplay), where the software's showed 1 s's every time. The
  test compares the software's frames with themselves (the same at 1 s
  from anywhere, another at 1.5 s) and the thumbnails with the
  hardware's; why the display layer shows a hidden frame of VideoToolbox's
  AV1 is open (H.264 and HEVC show none).
- **Fibers' waits are looked at every 50 ms** (`CurlLoop::waitUntil`):
  they were only as transfers moved, and a wait for a child process's end
  (yt-dlp's) would never have been. A condition that throws resumes the
  fiber, which throws it itself, not the loop's callback.
- **A fragmented MP4's seek never waits** (`Mp4::seek` keeps the time,
  `sought()` finds its fragment as `ready()`/`next()` go on, each read
  `has()`-gated, `Fragment::first` false while its moof is not here). It
  read the moofs it looked at, waiting: on the main thread outside a fiber
  that wait ran the run loop, which drew the window again, whose seek bar,
  dragged, sought again inside it, nested a level a frame: the UI froze
  dragging YouTube's seek bar. Only the opening waits (the duration, the
  fragments walked).
- **A web page's formats open together** (`Player::together`): its
  video's and audio's formats open together, each in a fiber
  of its own whose waits are the run loop's (a fiber started in the app's
  fiber suspends to it; the loop resumes it): one after the other, each
  first response and its index read took 18 s. The wait for them all is
  a closure taking `$done` by reference: an arrow function's copy of it,
  empty, never filled, and the film never opened (the CLI, its jobs run
  to their end in turn, never saw it). yt-dlp's own run (39 s
  of a slow network, its requests to the site) and the 4K the default
  format picks are as IINA's.
- **A film of two readers has one cache** (`Media\CacheBudget`, which
  Merged makes its readers share): yt-dlp's video and audio, each an
  HttpBytes (or an Hls), had 150 MiB each, 300 MiB in all, where IINA's
  is the whole film's (mpv's demuxer cache, of all its streams). What
  they hold ahead is counted together (each reads ahead while all of it
  is under half, stops at 90%), past it the least recently read of all
  go (one clock of reads), and a read of one resumes the others, whose
  transfer may have stopped for want of room.
- **The buffering indicator counts from the keyframe a seek decodes
  from** (`Player::bufferingPercent`): it counted the seconds given past
  the clock, which waits at the target, and a seek reads from the keyframe
  before it (seconds of 4K AV1 over the network): 0% all the while, then
  done. It counts what was read since the seek's first packet (or since
  the clock began waiting, a stall as it plays) of what reaches
  CACHE_WAIT past the clock, as mpv's cache-buffering-state counts what is
  cached past what its decoders read.
- `./run.sh video` logs the player to the terminal (`MYPHP_VIDEO_LOG=1`
  in examples/video): what opened, its events, a frame drawn inside
  another, a frame over 0.1 s; with pcntl, every 3 s, where PHP is in a
  frame held up 3 s, and while a film opens, where each fiber waiting
  waits. No sampling by a flood of signals (SIGUSR1 every 10 ms): the
  app hung in its first seek with it (likely: a signal lands on any thread,
  dav1d's too, whose PHP handler there is not safe).
- **A seek while it plays holds the main thread some 45 ms** (measured,
  4K AV1 over the network): `AVSampleBufferAudioRenderer`'s flush waits
  for the audio it is rendering (the display layer's and dav1d's take no
  time). Stopping the synchronizer first only moves the wait there (30 ms
  of `setRate:0`, then 12 of the flush). Paused, it is nothing. Left as
  it is: once a seek, as the picture goes elsewhere anyway. (Off the main
  thread as of v1.63, its section.)
- **An arrow function copies what it reads as it is made** (AGENTS.md
  rule 12): `Player::together`'s wait read its `$done` empty for ever.
  Looked for across the code (each arrow function reading a variable
  its scope writes later): the widgets example's list key kept the steps
  as they were before a drag reordered them, the glass example's toolbar
  the checkboxes' state before their click; both take `$s` by reference
  now. The others read `$this`, objects, or values meant as they were.


## yt-dlp's order, YouTube's ads, secondary subtitles (v1.53)
- **YouTube's and Twitch's pages go to yt-dlp first** (`YtDlp::first`,
  mpv's ytdl_hook `include`, its on_load hook), others to it once they
  fail to open as a film (on_load_fail): opened as a film first, the
  page's response alone took 8 s over a slow network. v1.52 started
  yt-dlp beside every direct open instead (a process for every film over
  HTTP); mpv's order is back.
- **A video's formats are read once its ad is over** (`YtDlp::wait`,
  yt-dlp's `available_at`): YouTube's TV clients' URLs answer HTTP 403
  until the seconds of the ad before the video went by (its URL marked
  `epbp=1`); 206 twenty seconds later, for curl too. yt-dlp's downloader
  sleeps till then, mpv's ytdl_hook defers the load (`get_stream_delay`);
  the player waits so before it opens them (the indicator shows opening).
- **Secondary subtitles are mpv's** (`Player::setSecondarySubtitleTrack`,
  secondary-sid): a text track's lines as plain text (secondary-sub-ass-
  override strip), at the top as far from it as the first are from the
  foot (mpv's toptitle), their own delay and visibility (the sidebar's
  switch, quietly, and the Primary/Secondary segments over the delay).
  A track shows once: the first's refused as the secondary and the other
  way round, as mpv's "already selected"; image tracks are no secondary
  ones here (mpv draws them where they are, over the first). The wake for
  a cue's change takes the sooner of the two tracks', each by its delay.
- **A node's id is mygo's: its parent's mixed with its place there**
  (`Context::add`, `mix`, `place`): with its kind for an unkeyed node, its
  key's hash for a keyed one (a key is its parent's, as mygo's); the
  root's view and the overlays numbered apart (mygo's overlay layer), a
  list's row under its item's key. An unkeyed node's id was its place in
  the frame's build order (in a keyed container, its place in that): what
  came and went renumbered every node built after it, wherever it was.
  The subtitles' lines coming with each cue moved the sidebar's scroll
  (it jumped as the picture played), an open collapsible, a field's
  error or a search field's clear button renumbered what followed
  (`tests/ids_test.php`), and widgets keyed their own container to keep
  their keys apart (gone: the keys are their parent's). mygo mixes by
  multiplying (splitmix64); PHP's integers turn to floats past 64 bits
  and a hash call a node cost some 4 ms a frame of 9 000, so `mix` takes
  shifts and exclusive ors (xorshift64 twice), the benchmark's build
  times as they were. `nextId` says the kind of the node it foresees
  (a buffer's input or text area). The token field keys its input
  `input`, as mygo's: its chips before it, keyed, no longer took no
  place. The subtitles' overlays and the sidebars stay keyed, and
  `Context::videoPlayer` keys its player by its state.


## The ad's countdown (v1.54)
- **The indicator counts the ad down** (`Player::__construct`'s
  `$waiting`, `VideoPlayerState::$adOver`): opening a web page's video
  after an ad (`YtDlp::wait`), "Skipping ad… 13s", a frame each second,
  then IINA's "Opening stream…". IINA shows only that (mpv logs "Waiting
  N seconds for the stream to become available"): a wait of seconds
  with no word of why looked like a hang, the developer's ask.


## Vorbis, external audio and the equalizer in the video player (v1.55)
- **Vorbis is decoded by the app's own codec** (`Native\SoftAudio`,
  `Audio\Vorbis\Codec`): CoreAudio has none; mpv decodes it with
  FFmpeg's. The Ogg decoder of the sound API (mygo's, jfreymuth's
  oggvorbis) was split: `Codec`, the three headers and each packet's
  frames (its blocks overlapped), and `Decoder`, Ogg's pages around it.
  A Matroska track's headers come Xiph-laced in CodecPrivate
  (`Codec::fromXiph`). Its frames go to the audio renderer as 32-bit float
  PCM, interleaved, in WAVE's order with a channel bitmap layout
  (`CoreMedia::pcmFormat`, `pcmSample`; Vorbis's order, section 4.3.9,
  put so: a 5.1's center is its second, WAVE's third), each packet at its
  time (a Matroska Vorbis block's time is that of the first frame it
  completes), the first after a start or a seek completing none. In PHP
  it decodes 12 times as fast as it plays (28 with the JIT) of 48 kHz
  stereo here: libvorbis (and libogg, bundled with their load paths
  rewritten) was weighed and left, the developer's choice. The frames
  match the Ogg decoder's of the same stream (`tests/soft_audio_test.php`).
- **An external audio file plays beside the film** (`Player::loadAudio`,
  IINA's Load External Audio, mpv's audio-add): the film's source is
  wrapped in a `Merged` (alone, its tracks the very objects the player
  chose, the cues' keys theirs), the file's audio tracks added to it
  (`Merged::add`, numbered k thousand on, named after the file where
  they have no name), the first chosen and played from where the film
  is (`setAudioTrack`'s seek, which seeks every source). A URL's reader
  joins the film's cache; a local film then waits for the network too.
- **The equalizer is on only while a band has a gain**
  (`Player::setEqualizer`, `Native\Equalizer`, `Native\AudioDecoder`):
  Flat, the audio goes to the renderer as it always did, the system
  decoding it; otherwise each packet is decoded here, the system's
  codecs by AudioConverter (a Matroska track's format description, or
  the sample buffers' of AVFoundation's reader, their packet
  descriptions with them; Vorbis by SoftAudio), filtered and given as
  float PCM in the order the converter's output layout says. The filter
  is IINA's for mpv, FFmpeg's `equalizer=f=…:t=h:width=f/1.224744871:g=…`
  a band (af_biquads.c's peaking biquad: A = 10^(g/40), alpha = sin(w0) /
  2Q), the bands of a gain in a cascade by vDSP_biquad over each channel
  strided through the interleaved frames, its delay lines kept from one
  buffer to the next and cleared at a seek; a band past half the rate is
  none (FFmpeg bypasses it). Turning it on, off or changing a gain feeds
  the sound again from where it is (`go`), so a slider sets its gain as
  it is let go (the arrows at once). The converter's input callback is
  held in a struct (an FFI callback passed per call would be made anew
  each). IINA's presets and its Manual are in the pop-up; its saved
  profiles (save, rename, remove) are not yet. A file's AAC priming
  (AVFoundation's trim attachments) is not cut while it is on.
- **AudioConverter takes an AAC's cookie as an ES descriptor**: a
  Matroska track's CodecPrivate is the bare AudioSpecificConfig, which
  the format description (and the audio renderer) take, but the
  converter refused ('!dat'); `AudioDecoder::esds` wraps it as FFmpeg's
  audiotoolboxdec does (AVFoundation's readers' cookies are one already).
  vDSP_biquad's state is in floats: its cascade is 2e-4 from the same in
  doubles over ten bands of a few dB (as FFmpeg's float filter is).
- **Left for later (IINA's)**: the equalizer's saved profiles; online
  subtitles, which need a provider and its API key (IINA's OpenSubtitles
  key is IINA's).

## The equalizer's profiles, the tracing JIT, the video log's watchdog (v1.56)
- **The equalizer's profiles are IINA's** (`Media\EqualizerProfiles`,
  SidebarAudioPane's pop-up): saving is Manual's alone (disabled
  otherwise), renaming and removing show only while a profile of the
  user's is chosen, the profiles after the presets past a separator; a
  slider moved makes it Manual. A name is asked by `Dialog::prompt` with
  IINA's check as it is typed: none, or a preset's or a profile's name,
  disables OK, the reason under the field (an NSControlTextDidChange
  observer, its block one for the process). Removing asks nothing and
  leaves the gains, Manual. A file that is not ten gains a name shows as
  the error, the pop-up of no profile.
- **The tracing JIT and `?->`**: under `run.sh`'s `opcache.jit=1255`,
  `Layout`'s `$n->followX?->scroll` once warned "Attempt to read property
  \"scroll\" on null" (a video opening, 12 s in, the frames hot), which
  `?->` cannot. It is a plain null check there now; the suite under the
  same JIT never showed it. A `?->` in a hot path that warns so is the
  JIT's, not the code's.
- **The video log's watchdog walks no stack of its own** (examples/video,
  `MYPHP_VIDEO_LOG`): it took `(new Exception)->getTraceAsString()` in
  its SIGALRM handler for a frame held up 3 s, and the app hung there
  (`sample`: MTKView draw, JIT code, pcntl_signal_dispatch,
  zend_fetch_debug_backtrace spinning), the equalizer's name prompt open
  over 3 s inside its frame, OK'd. It logs only that a frame is held up
  now; a hang's place is `sample`'s. The waiting fibers' traces stay
  (ReflectionFiber, of suspended fibers, never hung). Only `./run.sh
  video` had it; a dialog shown from a frame holds the frame, as
  `Dialog::open` from the audio tab always did.

## 10-bit AV1 in software, HDR colors, a menu button's focus (v1.57)
- **10-bit AV1 decodes in software** (`Native\SoftVideo::x420`): dav1d
  gives 16-bit samples, the 10 in the low bits; the display layer takes
  x420 ('x420', 'xf20' of the full range: VideoToolbox's own of HEVC
  Main 10), the 10 in the high bits, the chroma interleaved. As WebKit's
  libvpx frames go (libyuv's CopyPlane_16 and MergeUVPlane_16 by 64),
  each plane is made floats 64 times as large (vImageConvert_16UToF),
  the chroma's interleaved (PlanarToChunkyF), and 16 bits again
  (FTo16U, of no scale): six vImage calls a picture, the floats kept for
  the next of its size. The colors are the sequence header's as of 8
  bits (BT.2020, PQ and HLG attached as they are said). Thumbnails take a
  quarter of each sample in 8 bits (`eightBits`) and the 8-bit way.
  Tests: each frame of clip-av1-10.mkv bit for bit as FFmpeg's libdav1d,
  and on macOS the pixel buffers bit for bit (NV12's too); its frame on
  screen and thumbnail as its 8-bit twin's (clip-av1-10-twin8.mkv, the
  same testsrc2 encoded at 8 bits). HDR on an HDR screen is the
  developer's to look at; an HDR film's thumbnails are not tone-mapped.
  `MYPHP_VIDEO_SOFTWARE=1 ./run.sh video …` turns hardware decoding off
  in examples/video (`Player::$hardwareDecoding`), for a Mac of AV1's
  own decoder (M3 on) to decode it by libdav1d.
- **The format of a hardware-decoded film says the container's colors**
  (`Track::$colors`, internal: Matroska's Colour, an MP4's colr nclx;
  `CoreMedia::format`'s ColorPrimaries, TransferFunction, YCbCrMatrix,
  FullRangeVideo extensions, as AVFoundation's format of a colr says
  them). Without them an HDR Matroska AV1 (YouTube's, merged by yt-dlp)
  showed washed out on the M5's hardware decoder, PQ and BT.2020 taken
  for BT.709, while libdav1d's pixel buffers, the sequence header's
  colors attached, were right. A code of 2 (none said) is left to the
  decoder; a VP9's vpcC, made of its keyframe (which says no transfer),
  takes the container's codes too (YouTube's VP9.2 HDR). One mapping of
  H.273's codes to the constants' names serves both
  (`CoreMedia::colorNames`). The mastering display and content light
  levels (Matroska's MasteringMetadata, MaxCLL) are not passed yet.
- **Trap: the layer takes a fifth of a second of decoded pictures.**
  libdav1d's 1080p60 HDR (YouTube's) dropped frames all along in
  software, while dav1d decodes it at 994 frames a second and the x420
  pixel buffers take 1.2 ms a frame (a bench of the developer's M5): the
  display layer stops taking pixel buffers after a dozen or so (it takes
  seconds of compressed frames), and the player's tick, which tops it
  up, comes every quarter of a second. A tick of 0.02 s played it
  smoothly (an experiment, reverted). Now, while the layer takes no
  more of a software decoder's pictures, it is asked to say when it will
  (`requestMediaDataWhenReadyOnQueue:usingBlock:` on the main queue,
  AVFoundation's way to feed a renderer), and is given the video queued
  then (`Renderers::$videoWanted`, the player's `give('video')`); taking
  more with nothing queued, it stops asking until a tick reads more.
  One block for the process serves every player's renderers (a block
  made each time would live for the process); a flush or the
  renderers' end stops it.
- **A software decoder is not waited for.** A seek (each step of a
  slider dragged) held the main thread 0.12 to 0.5 s in software, against
  nothing with VideoToolbox: `SoftVideo::unclog` spun on
  `dav1d_get_picture` until dav1d's threads had decoded the frames from
  the keyframe to the target (hundreds of a 60-frame film, YouTube's
  keyframes seconds apart). It takes what is decoded now and says
  whether dav1d takes another packet; while it does not, the renderers
  look again every 4 ms (an NSTimer in the common modes, one for the
  process, stopping once no decoder is full), as mpv's decoder thread
  goes on while its window does. The end's drain still waits. The
  layer's readiness is asked once a look: asked twice (before taking
  dav1d's pictures and after), it turned ready between, on the layer's
  thread, and a packet went to a full dav1d (which throws).
- **dav1d's send waits too, so the software decoder gets slices.** Not
  waiting in `unclog` left 258 ms a seek of the main thread (a bench of
  the developer's: VideoToolbox's 73, reading the packets 1 to 10):
  `dav1d_send_data` submits the frame, and with its frame threads all at
  work, waits for the oldest. PHP has no thread to send from, so the
  packets go to a software decoder for 10 ms at a time
  (`Renderers::SLICE`, from `Player::give`'s `slice()`), then on at the
  next POLL, the window drawn between; the frames from the keyframe take
  as long as ever on the whole.
- **A menu button's press moves the focus as any press does** (mygo's
  pointerDown): it took the focus itself, past `keepFocus`, so the
  player's equalizer pop-up, clicked, kept Space (its menu again) from
  the window's play and pause. The press goes through `pressAt` now,
  which leaves the focus inside what keeps it, then opens the menu.

## A slider dragged: the video in slices, seeks kept while one lands (v1.58)
- **All the video is given in slices** (`Renderers::SLICE`, v1.57's of
  the software decoder's): after a seek a hardware-decoded film took
  74 ms of the main thread as well (a bench of the developer's), most of
  it the sample buffers made here of the frames from the keyframe on and
  the audio renderer's flush (some 45 ms while it plays, as of v1.53).
  The video goes 10 ms at a time, the rest at the next poll; while the
  layer takes no more video, of either decoder, it is asked to say when
  it will (a paused player has no tick to give it the rest).
- **An exact seek while the last one lands is kept, the last of them
  made once it landed** (`Player::$pending`), as mpv's seeks while one
  decodes: a slider dragged asked one a frame of the window, each a
  flush and the frames from a keyframe again, none of which showed. A
  seek lands once the frames before its time were given to the video's
  renderer (`catchingUp`); the seek event and `time()` say the time
  kept at once. A keyframe seek (the arrow keys') and any other going
  elsewhere (a step, a track) go at once, dropping the one kept.
- **A slider's change is one of its value, as mygo's**: the log showed
  each step of a drag seeking two or three times at once, the same time:
  `Context::slide` reported a change at each move of the pointer pressed
  on it, and at an arrow at an end, its value as it was. mygo's
  sliderInput marks it changed only when its set() gives another value;
  `slide` now compares the value before and after its input (a test in
  primitives_test). Every other control already reported only a value
  of its own, or a click.

## An HDR film's mastering display and light levels (v1.59)
- **The system's tone mapping is told the film's HDR metadata**, as
  AVFoundation's formats of an MP4's mdcv and clli tell it, and mpv's
  frames carry it: the mastering display (SMPTE ST 2086's primaries,
  white point, largest and least luminance) and the light levels
  (MaxCLL, MaxFALL). `Track::$colors` (internal) holds a container's:
  Matroska's MasteringMetadata (floats, cd/m²) and MaxCLL, MaxFALL; an
  MP4's mdcv (green, blue, red in 0.00002, luminance in 0.0001 cd/m²)
  and clli. `CoreMedia::format` puts them in MasteringDisplayColorVolume
  and ContentLightLevelInfo (HEVC's SEI bytes, big-endian:
  `CoreMedia::masteringDisplay`, `contentLight`). In software, each
  pixel buffer is given its picture's own (AV1's metadata OBUs, dav1d's
  `mastering_display` and `content_light`, 0.16, 24.8 and 18.14 fixed
  point: `Dav1d::hdr`), else the container's, as CoreVideo's attachments
  of those keys, their CFData made once a film. Tests: SVT-AV1's
  metadata OBUs (clip-av1-hdr.mp4), a Matroska Colour and an MP4's
  boxes made in the test, the format's extensions on macOS.

## The quick settings' hardware decoding, online subtitles (v1.60)
- **Hardware decoding switches as it plays**, as IINA's quick setting
  (mpv's hwdec set while playing): `Player::setHardwareDecoding`
  (internal, the video tab's) tells the renderers (`setHardware`: the
  software decoders dropped, the next packet decoded the other way), the
  thumbnails' decoder goes, and the film seeks exactly to where it is,
  its frames from the keyframe decoded again. The choice is the player
  state's, of this film and the next (`VideoPlayerState::$hardwareDecoding`,
  applied in `setPlayer` as the volume and the equalizer are). The switch
  says how the film decodes (`hardwareDecoding()`: H.264 and HEVC only
  on the system's here, AV1 without its decoder only in software) and
  moves only where both ways do (`decodingSwitchable`: AV1, libdav1d
  and the system's decoder, an M3 on); elsewhere it is fixed, "not
  supported yet" (IINA decodes all in software too, by FFmpeg). The
  sidebar's switches are switches to assistive technology now, labeled.

- **Online subtitles are IINA's of OpenSubtitles** (`Media\OpenSubtitles`,
  its REST API as IINA's OpenSubClient and OpenSub.Fetcher use it): the
  subtitles tab's Search Online (`VideoPlayerState::searchOnlineSubtitles`,
  in a fiber, the requests on CurlLoop) says "Finding online subtitles…"
  until it ends, logs in with the settings' account first (once a
  process, its password the Keychain's; none stored, none), asks for the
  languages it has once a process (infos/languages), searches in the
  settings' preferred languages, else the system's, those it has
  (`languagesOf`: zh-cn of the simplified script, zh-tw of the
  traditional, pt-br, pt-pt; English where none, as IINA's), by the
  file's hash (`hash`: its size and the 64-bit words of its first and
  last 64 KB; none of a stream or of a file of 128 KB or less) and its
  name (a stream's title), the parameters in the order of their names.
  None found, "No subtitles found"; one, it downloads at once; more, IINA's
  list over where the message is (a row each, a click choosing, Command
  more, a double click downloading it; CANCEL, DOWNLOAD). Downloading is
  the download method's link, then its bytes, saved as "[index]name" in
  the temporary folder and loaded (`loadSubtitles`), "Subtitle
  downloaded" and their names told; the downloads spent (406, remaining
  -1) say IINA's limit and when to try again.
- **The film is looked up on TMDB first, as agc_player does it** (the
  developer's player): the file's name is read as agc_player's MetaVideo
  reads it (`Media\MetaVideo`, its Flutter client's parser, MoviePilot's
  before it: the name up to the year, resolution, source or codec, the
  Chinese one before the English, a show's season and episode, a
  `{tmdb-123}` in the name or its folder's; a name of only a number, or
  of 第3集, is the folder's, its 第二季 the season). TMDB's first result
  of the name (the Chinese, then the English) and year (`Media\Tmdb`,
  search/movie or search/tv; a show of none that year asked of any) is
  the id OpenSubtitles is asked by (`searchTmdb`: tmdb_id of a film,
  parent_tmdb_id, season_number and episode_number of a show's episode).
  No id, or no subtitles of it, and the search is IINA's, by hash and
  name. What fails on TMDB is told as OpenSubtitles' failures are. The
  parser's slips are fixed where they gave a wrong answer: the episode of
  `01.mkv` and of `Name - 12` was always 1 (the whole match of a file's
  name read as its number), the "1" of DDP5.1 before the extension made
  a film a show's episode 1, and the words it strips were of one case
  only where MoviePilot's are of none (the "Season" of
  Game.of.Thrones.Season.8 stayed in the name). The requests of both go
  through `Media\Https` (the User-Agent "MyPHP", HTTPS only).
- **The key is myphp's, in its source, reversed, as IINA's**
  (`OpenSubtitles::KEY`, the developer's): an app built has it, where
  anyone can read it out of the bundle, as of any client (IINA keeps its
  own reversed in OpenSubClient); reversed, a search of the source for
  keys does not find it. `VideoPlayerOptions::$openSubtitlesKey` gives an
  app's own (null: myphp's; '': none, the button disabled). The
  User-Agent is "MyPHP" (IINA's "IINA v<version>"). TMDB's key is the
  developer's too, kept so (`Tmdb::KEY`).
- **The settings are IINA's Subtitles pane's the player has**
  (`Context::videoPlayerSettings`, the app's settings window's;
  `Media\SubtitleSettings`, subtitle-settings.json in the user data):
  Online Subtitles' OpenSubtitles account ("Not logged in" or "Logged in
  as", Login asking a name and password, `Dialog::login`, IINA's
  quickUsernamePasswordPanel, the account tried then kept, its password
  in the Keychain by Secrets; a failure IINA's alert; Logout forgets it,
  the password too, and ends the session), and Other's preferred
  languages, a token each (IINA's ISO 639-2 token field; here the codes
  `languagesOf` takes, as zh-Hans or en), the system's where none.
  examples/video opens them from Settings… (Cmd+,).
- **The wheel over the list of subtitles found scrolls it.** The
  picture takes the wheel for the volume (its handleInput, given the
  events before the frame's hit test), so a list over it lets the wheel
  through as a sidebar does: the list's box of the last frame
  (`VideoPlayer::over`) is where the picture leaves the wheel alone.
- **Trap: the size of a file PHP looked at before is its stat cache's.**
  `OpenSubtitles::hash` read a file rewritten in the test at its old
  size; it clears the cache of the path first (a film still being
  written, too).

## The player's settings window, its General page, the welcome and history windows (v1.61)
- **The settings window is IINA's new one** (SettingsWindow and its
  SettingsPage*, `UI\VideoPlayerSettings` drawing what
  `UI\VideoPlayerSettingsPages` describes): a sidebar of the pages under a
  capsule search field, the page chosen in the accent while the window
  has the focus, its sections under it with IINA's line and dot; the
  page's sections of cards, a row a setting (its symbol, label,
  description, its control centered on its first line), the rows under a
  switch enabled while it is on, those under a disclosure shown at a
  click. The search finds a row whose words hold every word typed, a row
  under another with it, by page; a click goes to the row and rings it.
  The strings are IINA's (settings.* to settings…, its other keys to
  iina…, for en, zh-Hans and zh-Hant), added by a script from its
  .strings, its VerbatimStrings and two of its xibs' (the welcome's
  buttons, the screenshot preview's).
- **The settings are IINA's preferences** (`Media\PlayerSettings`, by
  IINA's keys and raw values, of their types; settings.json in the user
  data, written through a temporary file, `onChange` told). General's
  apply where the player is: pause or full screen as a film opens, the
  pauses when minimized, inactive, entering or leaving full screen and
  as the Mac sleeps (`Runtime::$windowListeners`, `Power::onSuspend`),
  keep the window open at the end (else it closes, IINA's), legacy full
  screen (a borderless frame over the screen, the Dock and menu bar
  hidden) and black out the other screens, the screen saver kept off as
  it plays (the display awake, `Awake::setDisplay`; for audio, where
  asked, only the system's sleep held off, IINA's SleepPreventer), resume
  where a film was left, the history and Open Recent, the folder's
  films added to the playlist, the next played, the repeat of a film
  opened by hand, the screenshots' folder, format (those of IINA's
  ImageIO writes here, `Media\Screenshot`), clipboard
  and preview (IINA's, with DELETE, EDIT and REVEAL). The app-wide ones
  are the app's: examples/video opens a window a film or the one in
  front, as IINA's PlayerCore.openURLs, gathers the Finder's files for
  0.2 s, shows the welcome window or the open panel at launch, quits
  after the last window only where asked, and has File > Open Recent
  and Playback History.
- **The welcome and playback history windows are IINA's**
  (`Context::videoPlayerWelcome`, `Context::videoPlayerHistory`,
  `Media\PlaybackHistory`: history.json in the user data, a film once,
  the latest first): the welcome's film to resume over the recent
  documents (Up, Down, Return); the history's groups by day or folder
  (a URL's by its host), Expand All and Collapse All, Clear, a search in
  the names or paths, multiple choice, Return and Command-Return, Delete,
  IINA's menu and alerts.
- **A settings page's title bar frosts the page under it, as macOS 26's
  hard scroll edge** (Plugins\Glass\ScrollEdge): a system material
  (`systemMaterial`) shows what the window draws under it but punches a
  hole through myphp's own content, so the page's rows under the bar did
  not show at all.
- **Trap: the section a click in the index scrolls to was not the one
  marked.** IINA's rule (the first section crossing a band a third of the
  way down, 40 high) marked the one before: its end was still in the
  band. The section marked is the last begun by the band's bottom, and
  the one clicked stays marked until the page is scrolled again (a page
  that ends before the section reaches the band marked its last).
- **Trap: window asks made between frames were lost.** The runtime's
  asks start empty with each frame; `VideoPlayerState` keeps those of an
  event (a film opened by the Finder, its end) and asks them as it
  attaches in the next frame.
- **Trap: a test's arrow function holds a copy.** `fn(Context $c) =>
  …(function () use (&$opened) …)` binds the inner reference to the
  arrow function's copy of `$opened`: the test saw nothing opened. The
  view is a `function () use (&$opened)`, as for any closure run later.
- **Trap: a node of a border and a color painted it opaque**; a row of a
  fill's alpha borders itself with `borderWidth` and `borderColor`.
- The tests' user data is a temporary folder (tests/run.php), not the
  user's: the player's settings, history and positions start from none.
- Fixed: the playlist's Add Files and the settings' folder button named
  options OpenDialogOptions has not (multiSelections, properties).

## The player's settings pages, music mode, the log, key bindings, Live Text (v1.62)
- **The settings window's pages are IINA's whole**: UI (the theme, the
  OSC's layouts with their own figures, the toolbar's sheet of dragged
  buttons, the window's initial size and place as mpv's geometry, its
  resizing, the OSD, thumbnails, picture in picture, animations), Video
  (decoding threads, hardware decoding, Live Text, HDR), Audio (the
  output device, initial and maximum volume, ReplayGain, gapless audio,
  the preferred languages), Subtitles (auto load, ASS's override level,
  text subtitles' style and place, online subtitles, languages,
  encoding), Network (the cache, its throbbers, User-Agent, proxy,
  yt-dlp), Control (pinch, Force Touch, the clicks, the wheel, the seek
  type and sensitivities), Key Bindings, Advanced (the log) and
  Utilities. Each applies where the player is: most as it plays, those
  of opening a film (Player's statics, CacheBudget's, HttpBytes', YtDlp's,
  Subtitles' encoding) as the next opens.
- **Music mode** (`UI\VideoPlayerMusic`, IINA's mini player in the same
  window, its compact size asked of the window): the album art, the
  tags (Matroska's Tags, AVAsset's common metadata), the controls, the
  playlist under it; automatic for audio as the settings say.
- **The volume past 100% and ReplayGain are a gain in PHP**
  (`Renderers::setGain`, vDSP's vsmul on the float PCM): the sound given
  again from where it plays when the gain changes, as the equalizer.
  ReplayGain's tags are Matroska's (track's, then segment's) and
  AVAsset's (ID3's TXXX, iTunes's), mpv's sums (preamp, peak clipping,
  fallback).
- **Text subtitles are ASS lines of the settings' style**
  (`UI\VideoPlayerSubtitleStyle`: a Default style on a plane 720 high,
  mpv's scaled pixels), so SRT and VTT are drawn by v1.44's ASS renderer;
  ASS tracks forced to it (force), stripped to text (strip) or scaled by
  sub-scale (scale). The margins go into the black bars where asked
  (sub-use-margins): the box from the window's edges, the scale the
  picture's. sub-pos moves the bottom lines by its part of the height.
- **Subtitle files are found as IINA's AutoFileMatcher finds them**
  (`Media\SubtitleMatcher`: FileGroup's series by common beginnings, a
  series' subtitle of the same number, those containing the film's name,
  priority words, search paths, the unmatched by edit distance); a file
  that does not read is passed over (IINA's suppressError).
- **The app's log is `MyPHP\Logger`** (the developer's choice over a log
  of the player's own): verbose to error, subsystems ("app", the
  player's "player0", "fmatcher", "ytdl", "keybindings", "livetext"),
  kept for `Context::logViewer` (IINA's Log Viewer: follow, level,
  subsystems, save, filter, copy), to a file of the session where
  `logToFile`. Its level and printing by default as the app's kind
  (`App::isDev`): a development app (`php app.php`, `myphp dev`, `myphp
  build -debug`) from debug on, printed to the standard error; a
  `myphp build` one warnings and errors only, printed nowhere (kept for
  the viewer, and the file where it is on). The player's Advanced
  settings set its level only while they are on, as theirs hold it, and
  its file. The framework's own reports went over to it from
  `error_log` (documents, windows' states, the clipboard, the scheme's
  failures, audio, updates, duplicate keys, MYPHP_FRAME_STATS); the
  test runner turns printing off. Not a log, and so not the Logger's:
  what the pseudo-terminal's child (Plugins/Terminal/Pty/spawn.php) says
  on the terminal before it becomes the program, the user's to read
  there.
- **Key bindings are IINA's input.conf sets** (`UI\VideoPlayerKeys`:
  IINA's five in src/UI/keybindings, the user's in input_conf of the user
  data; a key pressed named as mpv names it from charactersIgnoringModifiers,
  Runtime's takeKeys after the shortcuts) carried out by
  `UI\VideoPlayerCommands` (mpv's seek, set, add, cycle, cycle-values,
  multiply of the properties the player has, IINA's commands). The page
  edits the user's sets; its key mapping alert records a key and builds
  an action as IINA's NSRuleEditor does (`UI\VideoPlayerKeyRecord`).
- **The mouse and trackpad as IINA's**: the middle button, a pinch
  (magnifyWithEvent:) and a Force Touch (pressureChangeWithEvent:'s
  stage 2) reach `Context::middleClicked`, `magnified` and `forceClicked`
  (internal); a single click waits the system's double-click interval
  where a double click does something; acceptsFirstMouse: is the view's
  as the settings say (Window's firstMouse ask).
- **Live Text reads the frame off the main thread** (`Native\TextRecognizer`:
  VNRecognizeTextRequest performed by an NSInvocationOperation on its
  own queue, no PHP on that thread; polled each tenth of a second); its
  words, their boxes of the picture, chosen by a drag
  (`UI\VideoPlayerLiveText`, through the picture's input), copied.
- **Trap: `setAudioOutputDeviceUniqueID:` takes no nil**: it throws
  NSInvalidArgumentException and PHP aborts. A renderer never set is left
  to follow the default; set back to the default, it is given the
  default output's UID (`AudioDevices::defaultOutput`).
- **Trap: CALayer's dynamic ranges have no k**: `CADynamicRangeAutomatic`
  and `CADynamicRangeStandard` (macOS 15); FFI's cdef of a symbol the
  library lacks throws at the cdef.
- **Trap: ICU reads "'{''{'" as {'{**: '' inside a quote is an
  apostrophe, so the strings generator quotes a run of braces whole
  ("'{{'"), as IINA's Mustache templates of key bindings have.
- **Trap: "glob(" in any source, a comment's too, fails static_app's
  test**; a static app's phar has no glob, so folders are listed.
- **Trap: in testdata, IINA's matching gives clip.mkv every "clip…"
  subtitle** (the first film, by name, takes those containing its name):
  the player's tests turn Player::$autoLoad off, and test it in a folder
  of their own.
- Fixed: a player whose window closed itself in its own frame drew on
  (Window's frame stops once destroyed; Metal forgets the view's draws).

## A seek as it plays, off the main thread (v1.63)

- **The audio renderer's flush goes on a serial queue while the clock
  runs** (`Native\Renderers`): `AVSampleBufferAudioRenderer`'s flush
  waits some 45 ms for what it is rendering (v1.52), stopping the
  synchronizer first only moving the wait there. As mpv's play thread
  flushes its audio output, the flush and the synchronizer's
  `setRate:time:` after it go to an `NSOperationQueue` of one at a time,
  as NSInvocationOperations (no PHP on that thread; their arguments
  copied in by `setArgument:atIndex:`). Until the last is done no packet
  goes to either renderer (`ready()` false, the poll timer giving them
  after, the player's `videoWanted` giving the audio too), and the
  clock reads as the time it goes to. A change of the clock made
  meanwhile (a pause, buffering) goes on the queue too, in order. Paused,
  the flush takes no time and is made at once, as before. The renderers
  gone, what waits on the queue is done first. Measured (the developer's
  4K HDR AV1, .compare/seekbench.php, as it plays): a seek's main thread
  12 ms on the mean with the hardware decoder (74 before), 6 with
  libdav1d; a slider's 30 seeks 16 ms apart 8 ms in all, landing where
  the last went.

## ASS's drawings, 3D turns, shears, blurs and \kf (v1.64)

- **A drawing (\p) is a glyph of its box** (libass's OUTLINE_DRAWING):
  as wide and high as its points span (control points too), its
  contours at their own coordinates from the box's top left, not moved
  to it (a mask of the screen is `\an7\pos(0,0)` and its corners). Its
  masks are Core Graphics' (`Native\VectorMask`: filled non-zero, or
  filled and stroked twice the border wide for its outline and shadow),
  at the display's pixels, made white and tinted as drawn, so a color
  animated draws no mask again; an image, not the glyph atlas, as a
  4K screen's mask is larger than the atlas and an image's quad is
  scaled (a mask past 2040 pixels is made smaller and drawn larger).
  The B-spline (s, p, c) is uniform cubic, its segments Beziers.
- **\frx is the other way round from CSS's rotateX**: libass turns the
  top toward the viewer for a positive angle, CSS away; \fry is CSS's
  rotateY. libass applies \frz, \frx, \fry, then a perspective of
  20000 of the plane's units (`20000 * blur_scale_y`), around \org.
- **\fax is applied under \fscx and \fscy** (the row sheared, the
  line scaled around it): libass shears the scaled glyph by
  fax * scale_x / scale_y, which comes to the same; a row is sheared
  from its top left, as libass shears each line from its top.
- **\blur blurs the outline, not the glyphs, where there is one**
  (libass's ass_synth_blur over bm_o; the shadow a copy of it): the fill
  stays sharp. Its sigma is the tag's value of the plane's units times
  2 / sqrt(ln 256); \be's passes of [1 2 1] across and down are half a
  pixel's variance each, of the display's pixels, added to it. Glyph
  masks of a blur are the atlas's by an eighth of a point
  (`Native\Gaussian`, Accelerate's vImageConvolve_Planar8, three
  sigmas each way; the bitmap grown to hold it).
- **\t of no end time runs over the whole line** (libass: t2 of 0 is
  the event's duration): read as instant before.
- **\kf and \K sweep**: the syllable is drawn in the secondary color
  and the same text over it, of that syllable alone in the primary,
  cut at how far the time has swept across its glyphs (their leftmost
  to their rightmost, line after line, from the text laid out as the
  node lays it, `shapeRichText`).
- **A mask is part of a transform entry** (`DisplayList::setMask`,
  `Node::clipMask`): an entry is 16 floats now, its map and then where
  the mask lies in the window (its width below 0 inverted) and its
  image's slot (u plus 4096 times the page; no slot: the rect itself,
  its edges a pixel soft); the fragment shader multiplies what a quad
  paints by the mask's alpha where the fragment is (quad_paint under
  quad_fragment). Entries are keyed by the map and the mask, so a
  transform inside a mask gets an entry of both. One mask at a time (a
  mask inside another throws); the window's under-layer replays keep
  the mask they were noted under. Tests reading the transforms must use
  FLOATS_PER_TRANSFORM, not 8.
- **Runs apart are drawn as copies of the whole text, the others
  ghosts** (`Span::$ghost`: laid out, nothing of it painted): runs
  turned, scaled or sheared apart (assAlone, a pass each, as libass
  turns each glyph around \org), of outlines, shadows or blurs apart
  (assText, a node each over the first), \kf's primary, and with \fay
  each glyph (libass shears by the glyph's own x: glyphs slant, the
  line does not), each over a text laid out once. Ghosts paint no quad;
  a glyph of no alpha draws none either.
- **libass cuts the fill out of the outline** (ass_fix_outline) unless
  the fill and the secondary are opaque and the line not fading
  (FILL_IN_BORDER), and out of the shadow unless the fill shows
  (FILL_IN_SHADOW); a shadow of no outline shows only of a fill that
  shows (`VideoPlayerAssDrawing::rules`). A glyph's cut outline is a
  mask of its own (`Node::textCutOut`, CoreText drawing the glyph over
  the grown and blurred one in kCGBlendModeDestinationOut), a
  drawing's the same by VectorMask.
- **BorderStyle 3's box is per line of the runs' glyphs**, not the
  row's box (which was the wrap's width for a line that wraps), and
  blurs as a soft rect (`DisplayList::shadow`, its feather 1.64 sigmas).
- **\t of a \clip rectangle moves it** from the line's (the plane's
  without one), as libass; a drawing's clip does not move (libass's
  neither).
- **A run restyled keeps what it has** (`VideoPlayerSubtitleStyle::with`,
  by its fields' names): forcing the settings' style (sub-ass-override)
  and sub-scale had built runs anew of their first twenty fields, which
  would have dropped a drawing, its turns and its blur. The settings'
  Blur (sub-blur) is the style's Blur of text subtitles now.

## The atlases evict bitmap by bitmap (v1.64)

- **`UI\Atlas` keeps its free room** (shelves of heights in steps of
  four, each with the gaps its bitmaps left, gaps side by side joined; a
  bitmap goes into the lowest shelf of a gap wide enough no more than a
  quarter higher than it, else a new shelf below, else an empty shelf
  split to it; a shelf emptied joins the empty shelves beside it, the
  last gives its rows back). `add` returns null without room now, not an
  exception.
- **What a frame draws from is never evicted** (`Atlas::$frame`, set by
  its owner each frame; `get` and `mark` note a slot's frame): its quads
  may be in the frame's display list already. `evict` frees the bitmaps
  of the oldest frames, one at a time from the least recent (`$used` is
  kept in that order: a slot asked for again goes last), looking at the
  shelf each left, at most 64; else it empties the run of shelves high
  enough together whose newest bitmap is the oldest (a tall bitmap needs
  a shelf, which scattered gaps never make). Under pressure a bitmap
  takes a gap of any higher shelf before the atlas starts over. A
  bitmap freed has its pixels cleared, as its neighbors' edges sample
  them through their pixel apart. Measured (.compare/atlasbench.php,
  Linux, JIT): glyphs all held 0.04 ms a frame; text scrolling by, 30
  glyphs new a frame, 0.6 ms; 200 new a frame, 4.4 ms (less than
  CoreText takes to rasterize them); a working set of 8 to 160 pixels
  two thirds of the atlas still starts over now and then (no shelves
  pack it).
- **The text painter's lines keep their glyphs' places** (their packed
  quads' u and v): a line marks the slots it draws from once a frame
  (`Text::quads`), and an eviction drops the packed quads (not the
  shaping) of the lines that drew from a slot gone (`Text::$users`, by
  slot; swept with the lines), made again from the slots left. The atlas starts over only when one frame draws more than it
  holds (`Text::place`; the frame paints again, as before).
- **The image atlas evicts images, not pages**: past its MAX pages the
  pages are tried from the one drawn from least recently, each evicting
  its least recently drawn images; a page is emptied whole only when
  the frame draws from all of it.

## Memos: subtrees kept from frame to frame, the frame's hot spots (v1.65)

- **Where a frame's time goes** (`.compare/uibench.php`, mygo's
  BenchmarkStressFrame scenes and three of a settings page of 40 cards
  still but for a clock, a field typed in or a hover; the headless
  window's fixed metrics, Linux, JIT; excimer's profile): building,
  laying out, committing (`Runtime::record`) and painting each take a
  quarter, all by the node, 2 to 6 µs each. mygo's incremental layout
  compares a node's properties with last frame's to keep its measure: in
  PHP that is 2.2 µs a node (69 properties by hand; `get_object_vars`
  9 to 17 µs) against 2.5 µs of laying it out, and it keeps only the
  layout. So the work is skipped where the app says what a part is built
  from (`Context::memo`, below; `mygo-deviations.md`).
- **The hot spots made cheaper**: `Affine::bounds` of an affine map
  without its projective path (a translated column's every node);
  `Node::transformAt` returns at once without a pose or transform;
  `Runtime::record` asks `hovered` once a node, and only of what shows
  a cursor; `Layout::fixedMargins` made a closure each call. A
  translated column of 1000 rows, 44 → 37 ms.
- **`+=` on a typed property's array copies the array** (PHP's
  `zend_binary_assign_op_typed_prop` works the result apart, then
  assigns it): `$this->rects += $more` is O(all the rects), not O(more).
  Add entries one by one (`$this->$prop[$k] = $v`), or `array_push` to a
  list. Found as a memo's record given back cost a millisecond.
- **`Context::memo($key, $deps, $build)`**: $build builds one node,
  keyed by $key; a memo of the same $deps (`===`) built in the same
  context (the direction, disabled, the text style around it, the
  theme, scale, window size, a toolbar, form, list row…) is placed
  again, its nodes as they were (`Node::$memo` 2), unless something
  reaches it this frame (`RuntimeMemo::memoHit`):
  - the pointer: where it rests, where it was last frame, and every
    press, release, drag, wheel, pinch, within the room its nodes showed
    in last frame (2 points around);
  - keys, a command, text or an access action, when it holds the focus
    or its build read keys (`Runtime::shortcut`, `takeKeys`,
    `submitMods` note it);
  - the state its build reads outside its nodes changing anywhere (the
    focus, the ring, a press, a slider, a drag, a tip, a menu, a modal,
    the window's focus, full screen, the text selection…): every memo is
    built again that frame;
  - a scrolling or list state, or a field's or area's text, inside it
    moved (noted as it is recorded);
  - a pass after the first (an action changed state): built again, and
    not kept until a first pass builds it.
  A build that asked for frames (`animationFrame`, `after`) is built
  every frame. What a build changed in the runtime and the context is
  told from their properties before and after it (`memoAdds`): entries
  added are given back each time it is placed again (the clicks its
  toggle takes, steppers, local state); a build that changed a value or
  an entry it had is not kept.
- **Laid out as it was**: `Layout::measure` keeps a placed memo's
  measures; `arrange` at its size moves it (`Layout::move`, frags too);
  at another size it is forgotten and built again in another pass (its
  texts wrap at the new width).
- **Recorded as it was** (`memoRecordEnd`, `memoReplay`): what recording
  it added to the runtime's arrays is kept with its arguments (its place
  and size, the clip, the parent's world, page, inert, the focus); placed
  and recorded with the same, those entries are given back, the frame's
  order and paragraphs' indexes counted on from where it comes. A pose,
  a drag, a dialog, ghosts or a focus group inside (`$recordTaint`), or
  recording that changed a value, is recorded every frame.
- **Painted as it was** (`Painter::paint`, `memoPaintEnd`, `memoRepaint`):
  its quads are copied out of the display list (`DisplayList::copyOut`,
  `copyIn`) with what they drew from the atlases (`TextPainter::drawnSince`:
  a line's quads by their make, glyph and image slots by their place),
  kept when it is drawn upright, unmasked, without glass, holes, wide
  colors, native places, frames asked or a repaint; painted again at the
  same place, clip, opacity and theme while each of those is where it
  was (`drawnAgain`, which marks them drawn this frame).
- **Its nodes' context**: a memo's nodes are moved into a context of no
  tree (`RuntimeMemo::memoKeeper`), which holds the runtime: kept in the
  context of the frame they were built in, they held that frame's whole
  tree. `Runtime::detach` passes over a memo placed in the tree that
  replaces the one it lets go (`Node::$placed`).
- Measured (uibench, medians, a noisy machine): the settings page, about
  6 ms a frame built anew, as memos 1.3 ms (a hover), 1.6 ms (a clock
  ticking), 2.2 ms (a field typed in); build, layout, commit and paint
  each 3 to 10 times less.

## Metal draws only what changed (v1.66)

- **A frame the same as the last draws nothing** (`Native\FrameDamage`,
  mygo's scene.Differ): `Metal::present` copies the frame's quads and
  transforms out of the display list as bytes and compares them with the
  last frame's from both ends (by halves, as bytes: 0.05 ms for 500
  quads); the same, with the same size, scale, clear color, glass and
  holes, it takes no drawable and presents nothing: the drawable shows
  the frame already.
- **Otherwise what changed is drawn into what the drawable holds**: the
  boxes of the quads that differ between the shared start and end (in
  pixels, within their clips, two around for their smoothed edges;
  where they drew and where they draw now; a box's second slot stands
  for its box), in at most eight rectangles apart from each other
  (`FrameDamage::add`, mygo's addRect). A CAMetalLayer hands its
  drawables out in turn, so each is tracked by its texture's address
  (held while tracked, as mygo's), with what changed since it was drawn
  (`take`, mygo's gpu.Ages); a drawable new to the window is drawn whole.
  The pass loads it, each rectangle is cleared to the frame's color by
  an unblended quad of it (`$clearPipeline`, a point past it each way),
  and every draw is made once in each rectangle, scissored to it
  (`setScissorRects:count:`, by pointer). Past half the frame together
  it is drawn whole.
- **Drawn whole**: a frame with glass (it reads what is below it), with
  a canvas rendered or an image page uploaded (pixels the quads do not
  tell of), with a quad drawn through a transform or a mask changed
  (the entry it names), with other transforms, a new size (the
  drawables let go, `Metal::letGo`), or more than 768 quads changed
  between the shared ends; `MYPHP_PARTIAL=0` draws every frame whole.
  Glyph slots need nothing: a quad unchanged names a slot this frame
  draws from, which the atlas never evicts (v1.64).
- `MYPHP_FRAME_STATS` tells how each frame was drawn: `Metal, drew whole`,
  `drew 2 rectangles`, `drew nothing`.
- The test draws a frame whole into one texture and, into another
  holding the frame before, in part, and compares them pixel for pixel
  (`tests/damage_test.php`, on macOS).
- **An input method asks for half a character**: the system counts in
  UTF-16, and a range may end inside an emoji's surrogate pair. The
  text-input example's client cut it into bytes that are not UTF-8,
  NSString made nil of them and NSAttributedString threw from inside
  the callback, which aborts the app. The example cuts at whole
  characters now; `View` logs text that is not UTF-8 as an error and
  answers nil (no text), which the text system takes. The example's
  `boundsForRange` gave the shape before `TextInputClient`'s, a
  rectangle without its range.

## mygo's fixes, a first batch (v1.67)

- **Clicks counted per node** (`Runtime::countPress`, mygo's #347): the
  system's count of quick presses starts over on a press of another node
  than the last's, the innermost of the press's target and the node
  taking its input, so a click and then one on its neighbor, or on what
  the first click put under the pointer, is no double click. `deliver`,
  which `Input::push` calls first for every event, takes the event by
  reference and rewrites its count, so the frame's record sees the same;
  a release carries its press's count, by button. A press of another
  button that no node takes leaves the press a node holds. Tests that
  double-click press once first, as a user does.
- **A document follows its file renamed** however quickly: the watch
  tells what a watched file it found gone was as it last saw it
  (`FileWatcher::gone`, from `Scan::removedFile`), not a stat after the
  batch, which may come after the next rename; and a document's stamp
  holds the identity of the file it last read (`Files::stamp`'s third
  item, which `Files::sameStamp` and recovery ignore), for a file another
  app replaced and renamed before the watch looked again. Identities
  hold the file's type: ext4 gives a freed inode to the next file made
  (`Fs::same`).
- **Renames paired by identity**: `Scan::diff` counts the entries before
  and after by device, inode and type, so pairing is a lookup; comparing
  every removed entry with every other made a directory of n files
  renamed n² comparisons (20,000 files: 0.2 s now).
- **A row's percentage maximum binds**: the content's least width is
  measured where a percentage of the row does not resolve; the row clamps
  it by the maximum, unless the node set its own least width.
- **A diff fitting its content** folds its unchanged lines as it is
  built (`DiffState::fit`), not only as it is drawn, so one below a
  scroll container's view is as high as it shows; and it keeps no room
  for a scroll bar it does not show (`Editor::trackWidth`).
- **The updater** checks soon after every launch, and each window keeps
  the language the app showed as its check began (`Session::$t`);
  `Checker::text()` is made once per language.
- **A drag's end** says whether it was given up (`Result::$escaped`:
  Escape, `cancelDataDrag`, the window closing) and where it ended
  (`$point`). AppKit's tracking loop takes Escape itself: the event that
  ended the session tells it (key down, key code 53).
- **Pitfalls**: `ObjC::msg('p:q', …, 'numberWithLong:', 42)` passes an
  NSPoint (`q`), not a long (`l`), and FFI throws; an arrow function
  captures by value, so a closure inside it that takes a variable by
  reference takes the arrow's copy (`function () use (&$x)` instead); a
  first-class callable keeps a parameter by reference
  (`$input->direct = $this->deliver(...)`).

## Layout and text as CSS and mygo have them (v1.68)

- **Flexbox as Chrome** (mygo's 269e2f9): a box is never smaller than
  its padding and border (`Node::padSums`, kept as `padW`/`padH` by
  `measure`, which also keeps the fixed margins as `marW`/`marH`: the
  calls a child were hot spots); a flex base size is the measured size
  before the least and largest (`natW`/`natH`), which only the
  hypothetical size is clamped by; items shrink by their bases less
  their padding, border and margins; items clamped the way the total
  moved are frozen, so a least height over a largest one wins without
  moving its siblings; a row sized by its content counts an item that
  cannot grow at most its basis and one that cannot shrink at least
  (§9.9.1). myphp's columns still keep their children's heights, except
  those that set a least height, which shrink to it (a split's pane).
  Layout of 2800 nodes is as fast as before (within noise).
- **Baselines** (`Node::BASELINE`, mygo's 4dc371f): the children of a
  line lined up by their baselines are laid out at the origin first
  (`lineUp`), their baselines found from what they laid out
  (`boxBaseline`: a text's first line, a button's label, a field's line,
  a container's first child's), then moved into place, not laid out
  again. A row measures as high as the highest ascent and the deepest
  descent take, from baselines found as measured (`measuredBaseline`); a
  grid's rows ask for the room above their items too. Containers that
  ask for none skip it all (`asksBaseline`).
- **Hidden** (`Node::hidden`, mygo's 9b0de23): out of the flow, the
  layout gives it and what is inside it no room (`Layout::gone`, which
  marks them), they record as invisible nodes do, what is attached to
  them hides, and their transitions stop.
- **Splits give way**: `split` keeps both panes in a smaller window, the
  first down to Space(10), and drags and keys move the divider from
  where it shows; `splitPercent` takes a share of the room. The handle
  is placed on the divider where this frame lays it out
  (`Node::$placeOn`).
- **Words in Chinese and Japanese** (`Text\Boundaries::standsAlone`,
  `wordBoundary`, mygo's 5a134b4): each ideograph and kana is a word,
  in text fields (whose words were runs between spaces until now, and
  are letters, digits and underscores as mygo's), the code editor, rich
  text and Markdown's reveal by words.
- **A grid child's percentages** are of its area, with their least and
  largest sizes and insets.
- **Paths past the atlas** draw in tiles of their mask, each the whole
  path's cut to the tile, those that do not show not made, those the
  path covers whole sharing one (`Text::PATH_TILE`, mygo's b0d5f43);
  `DisplayList::$pathQuad` gives a list of quads now. A gradient spans
  the path's bounds across its tiles.
- **Pitfall**: a closure made in `arrangeFlex` a call, and called a
  child, cost a layout a few percent: the sizes stay inline, and the
  closure is made only for lines that line up baselines.

## The feel of the wheel, gestures and drag regions (v1.69)

- **A mouse wheel glides** (`Runtime::wheelScroll`, `glideWheels`,
  mygo's 2549f24): a notch (`['scroll', …, precise false]`, which
  `View` tells by `hasPreciseScrollingDeltas`) goes over 0.15 s along an
  ease out, by two `MotionChannel`s from 0 to as far as all the notches
  scroll, the container moved each frame by what they went since the
  last, so that a list measuring its rows meanwhile does not throw it
  off. Notches add up; past the end they stop; the app scrolling the
  container (a ScrollState's offset set, a `scrollTo`, a `ListState`'s
  `scrolled`, mygo's 9fd99c0), the thumb pressed or a touchpad's scroll
  take over; with less motion a notch scrolls at once. A touchpad
  follows the fingers at once, as before.
- **The wheel chains**: the innermost scroll container under the pointer
  that it moves takes it, those around it what it cannot (at its end), as
  browsers do.
- **Gestures**: a pinch reaches the node under the pointer taking input
  as `InputEvent::ZOOM` (`$scale`, mygo's 2be9252), what none takes going
  on to `Context::magnified` as before; a swipe (`swipeWithEvent:`, a
  touchpad set to swipe between pages, the side buttons of mice whose
  software sends swipes) is Back or Forward, as the side buttons are
  (mygo's 4b839bc); a scroll's momentum is told (`$momentum`), which the
  terminal's Command zoom ignores, a touchpad gesture keeping what it
  began as (mygo's 9c69cf8). Pages swipe through their history and zoom
  with a pinch only when asked (`PageOptions::$swipeNavigation`,
  `$pinchZoom`, mygo's 32188db).
- **Drag regions move the window after a quick drag**
  (`Native\WindowDrag`, mygo's b0a8b3f and da3657e): a local event
  monitor follows the left button's press; the window server is asked
  when the pointer has not moved yet, myphp moves the window itself
  (keeping the point pressed under the pointer, below the menu bar) when
  it has or when the window server did not begin, and leaves it to the
  window server once that says it begins (`windowWillMove:`) or the
  window is not where myphp put it, so that both never move it. The
  press's drags are the window's then, not the content's.
- **A native drag held at a scroll container's edge scrolls it**, its
  target following the rows that come under the still pointer (mygo's
  8049993).
- **Pitfall**: an event pushed by tests without its precise flag scrolls
  as a touchpad (`$e[5] ?? true`), as all of them did before the glide;
  `Headless::wheel` sends a notch.

## Drawing vocabulary: strokes, radial gradients, zoom, paragraphs (v1.70)

- **Paths stroke with caps, joins and dashes** (`Path::cap`, `join`,
  `miterLimit`, `dash`, mygo's d9d45f4): `Svg\Mask::of` hands the path's
  style to the SVG renderer's `Stroker`, whose butt and square caps,
  miter and bevel joins and dashes SVG already drew; dashes are cut from
  the flattened center line (`Stroker::dash`), each subpath from where
  `trim()` cut it on the subpath it came from (`Path::$phases`), so that
  a path drawing itself in keeps its dashes in place, and a dash through
  the start of a closed subpath is one, turning there (as Skia's); past
  65536 dashes the rest is stroked whole. `Text::pathQuad` keys masks by
  the style, the dash phases snapped to a quarter pixel within one
  pattern, and reaches past half the width as far as square caps and
  miters do (`Path::reach`). Butt caps keep a stroke trimmed short from
  fading. Unstyled paths stroke as before, to the pixel.
- **Radial gradients** (`RadialGradient`, `Node::radialGradient`,
  `Painter::fillRadialGradient`, mygo's 977740f): from a center in
  fractions of the box to a radius in points, or the farthest corner, in
  sRGB or Oklab, in the Metal shader (`paintAt`). Two colors, as myphp's
  linear gradients; since v1.72 they paint through stops (paints 8 and
  9) and the flags moved again (see v1.72).
- **Zoom** (`Context::setZoom`, `zoom()`, mygo's 91d105a): the runtime
  keeps the zoom asked (`$zoom`) and the one the frame was laid out at
  (`$frameZoom`, set before each pass, so that a pass that zooms builds
  again at the new size, or the next frame when it was the last). The
  window lays the content out at its size over the zoom, gives the
  title bar's room over it, and draws with `DisplayList::$zoom`: Metal's
  viewport is the content's, its scale the backing scale times the zoom
  (`Metal::viewUniforms`, readbacks too), and `App::rescale` rasterizes
  glyphs at the largest backing scale times zoom of the windows.
  `Input::$zoom` divides what comes in (pointer, a touchpad's scroll,
  pinches, Force Touch) and multiplies the input methods' rectangles;
  context menus, drags and their previews, accessibility's boxes and hit
  tests, drops, web views, videos and materials are mapped at their
  boundaries (`Runtime::windowRect`). Tests keep the window's points
  (`Headless::rect`).
- **Text**: inline objects reach below the baseline (`Span::$objectDescent`)
  and draw after the glyphs (`Node::drawObjects`, mygo's 610df69), which
  Markdown's `Options::$inlineMath` uses for math within text, copied as
  its source; streaming, math a single dollar opens heals as math, its
  `_` and `*` TeX. The shared layout (`Text\System`, mygo's a8f25ed)
  indents a paragraph's first line (`Params::$indent`), justifies lines
  (`$justify`, `System::justifyLine`), shows soft hyphens as hyphens where
  lines break at them and as nothing elsewhere (`Text\Hyphens`), breaking
  a line again where its hyphen does not fit, and shifts spans off the
  baseline (`Span::$baselineShift`), decorations and backgrounds with them;
  engines break at one width, so the system breaks the indented line and
  lines that end at a soft hyphen apart. `TextLayout::lines()` gives a
  layout's lines.
- **Rich text paragraphs** (mygo's 7e510df): `Theme::$firstLineIndent`
  (a layout's indent, so no character the caret steps over) and
  `$paragraphSpacing` set body paragraphs apart; `ParagraphStyle::$id`
  follows a paragraph's text through `Text::replace`, undo and JSON,
  never through the clipboard, and `Document::identifyParagraphs` names
  those edits make; `Document::visibleParagraphs` gives the paragraphs in
  view where the editor paints them, and a paint that moves them from
  where the app read them asks for another frame (`View::moved`).

## Menus drawn in the window, menu icons, browsing a calendar (v1.71)

- **Drawn menus** (`Node::popoverMenu`, `popoverMenuButton`,
  `Context::popoverMenuButton`, mygo's 34c735c, d728256 and 85fd4bb):
  the same machinery as context menus and menu buttons opens them
  (`RuntimeMenu::buildMenu` with `$drawn`, the press, the menu key,
  Shift+F10, Enter, Space, Down, assistive technology), but rather than
  handing the window a native menu it keeps a `DrawnMenu`: where it
  opened, from the node's whole box, its levels (the menu and the
  submenus open, each with the item highlighted), the frame it opened in
  and the one that last built it. While it shows, each pass runs the
  menu's function to collect its items and builds a panel for each level
  in an overlay, attached to a point of the node (`attachTo` flips it at
  the window's edges) or below the button, a submenu after its item; the
  panel takes the focus (the overlay gives it back as it goes) and
  highlights its items as its active descendant. The keys move among
  the items that can be chosen, type-ahead goes to an item's first
  letters, Right and Left open and close submenus (mirrored right to
  left), Enter and Space choose (Space comes as the panel's activation),
  Escape closes the submenu on top; Tab while a panel has the focus
  closes the menu (`Runtime::tabOutOfMenu`, before Tab moves the focus);
  a press outside closes it, not the one opening it. A choice reaches
  the function as a native menu's does (`menuChoice`), an editing item
  does its command (`menuCommand`). The menu goes once the frame's last
  pass did not build its node (`resolveMenu`). `UI\MenuItem::radio`
  marks radio items, in native menus too. The menu key opens a context
  menu at the node's right right to left.
- **Menu icons** (`MenuIcon::of`, `MenuItem::$icon`, `setIcon`, mygo's
  6a95d8f): a PNG made once into an NSImage fit to 16 points, a template
  one tinted as the menu's text, shared by items showing the same image
  (a weak map by its hash) and released with the icon.
- **Calendars**: `Node::browse()` (mygo's b7e6bdd) gives a calendar a
  cursor of its own, which its buttons and keys move without choosing,
  following the build before, as input reaches the calendar as it is
  built; the month buttons draw their chevrons in their middle (mygo's
  e554723).

## Gradient stops, conic gradients, element blur (v1.72)

- **Stops** (`GradientStop`, `LinearGradient::$stops`,
  `RadialGradient::$stops`, mygo's 3cb5ea6): a gradient of stops paints
  a box through `DisplayList::box` with a paint of stops (linear 4 and
  5, conic 6 and 7, radial 8 and 9, each in sRGB or Oklab, mygo's
  numbering); the box's quad holds how many stops in `u`, and each stop
  takes a slot of kind -1 after the box's second: its color, straight,
  at the list's opacity, its place (at the one before's or after, as
  CSS orders them) and its color in Oklab, worked out once in PHP
  (`DisplayList::oklab`). The vertex shader passes the instance's index
  on, the fragment shader reads the stops from the quads' buffer, which
  the pass binds to the fragment stage at the offset it draws from
  (`setFragmentBufferOffset`), and `stopsAt` mixes the two around `t`
  as mygo's does. Stops travelling with their box, frame damage sees
  them change as it sees the box's colors, and a canvas's list carries
  its own. A radial gradient always paints through stops, its two
  colors as two; a linear one of two colors keeps paints 1 and 2. At
  most 32 stops (`DisplayList::MAX_STOPS`).
- **Flags**: a box's slot holds its paint plus 16 when dashed, 32 for a
  path's mask, 64 when the gradient paints the border; Metal's `box()`
  takes them off from the largest.
- **Conic gradients** (`ConicGradient`, `Node::conicGradient`,
  `Painter::fillConicGradient`, `fillPathConicGradient`,
  `strokePathConicGradient`): `g` is the center, from the box's top left,
  and the start angle in radians; `t` is the angle of the point from
  upwards, clockwise, less the start, in turns. A path's tiles move the
  center alone (`DisplayList::pathGradient` takes the paint).
- **Border gradients** (`Node::borderLinearGradient`,
  `borderConicGradient`): the background paints as ever, then a box of
  the border's widths, its inside transparent, flagged 64: the shader
  paints the border with the gradient times the border's color, opaque
  white at the node's alpha, whose stops then take no opacity of their
  own. A linear one of two colors becomes two stops at its start and end
  (`Painter::gradientPaint` with `$asStops`).
- **Element blur** (`Node::blur`, `Painter::paintBlurred`): a blurred
  node paints its subtree into a `Canvas` kept for it by its id
  (`Runtime::$blurLayers`, dropped with the node) covering its box and 3
  sigma around, in the window's pixels, or in a half, a quarter or an
  eighth of them for a wide blur, as mygo computes a large blur at a
  fraction of the size, the blur left at 2 pixels at least; what the
  averaging takes of the variance comes off the sigma. The canvas's list
  draws through a base map (`DisplayList::setBase`): the node's map
  inverted, then the canvas's pixels, which every map `setWorld` is
  given goes through, so the subtree paints as it is laid out, with its
  transforms and clips; `world()` returns the map given. `Canvas::$blur`
  has `renderCanvas` blur the rendered canvas along rows into a texture
  of its size and back along columns (`blur_clear_fragment`, sampling
  `clamp_to_zero`: transparent beyond it, as CSS's), before it is copied
  to its page. The window then shows the bitmap over the area it was
  drawn from, through the node's map, at the opacity around it. A
  canvas larger than `Canvas::MAX` paints the node as it is; one out of
  the clip paints nothing. Hit testing, focus and accessibility take
  the node as laid out.
- **Inspector**: conic backgrounds and gradients of stops show as
  `conic-gradient(from …, n stops)`, a border's gradient as
  `border-image: gradient`, a blur as `filter: blur(…)`.

## Choosing by dragging over rows, blocks of cells (v1.73)

- **Drag-select** (`ListState::$dragSelect`, mygo's 87d400a): in a list
  with a selection, `Context::list` hands each row to `pressRow`. A
  left press this frame on the row's first node itself
  (`Runtime::pressOn`: the press's target, no control inside the row
  taking it) chooses the row then, as `clickAt` with the press's
  modifiers (`Runtime::$pressMods`), unless it is chosen; either way
  the row's press is noted in `ListState::$rowPresses` (where, and
  whether it chose), which its click then reads: a press that chose
  makes the click do nothing more, one that did not has the click
  choose where it pressed. Notes are by row, so a release of one row and
  a press of another in one frame do not cross. Past 4 points of
  `dragged()` the drag starts (`$dragSel`): the row alone, or with Cmd
  from it besides those chosen (the pivot moved there), or with Shift
  as the Shift-press extended. While the button is held,
  `dragSelectRows` extends the choice to the row under the pointer, as
  the rows showed last frame through any transform (`toLocal`), the
  first or last built past them, and scrolls the list near its edges;
  clicks on the rows while a drag is on are its release, no click. The
  press's notes go once the button is up. A list cannot both drag-select
  and reorder (`LogicException`).
- **Cells** (`ListState::$cellSelect`, `$cells`, a `ColumnRange`,
  `TableColumn::$rowHeader`; the `ListStateCells` trait): a table
  choosing cells fills `ListState::$tableCols` with its columns in the
  order shown and their headers' ids before it builds its rows; the
  column at a point is the nearest header as it showed last frame. A
  click on a cell sets the block's columns as mygo's `clickCell` does,
  a row header's chooses every column; drags extend the columns too
  and scroll sideways near the left and right edges. `Runtime::command`
  gives Left and Right (mirrored right to left) to `cellKeys`, and the
  keys moving the rows leave one column unless with Shift; Cmd+A
  chooses every column. The block's cells are `selected` (the rows are
  not), the painter's selection over them as over a chosen row, and the
  cursor's cell in the row the keys move from has a 1-point accent ring
  while the table has the focus; a cell holding the focus shows as it
  is. A click on a row header column's header chooses everything and
  focuses the table; a row header column does not sort
  (`TableColumn::sorts`). `cellSelected`, `selectedColumns` and
  `selectColumns` read and set it.
- **Right-click** (`Runtime::chooseRowAt`, from `menuPress` once a menu
  is to open): the first nodes of the rows of lists with a selection are
  noted as the frame records them (`$listRows`); the innermost under the
  pointer is chosen as a click with no modifiers would, unless chosen
  (in a table choosing cells, unless the cell is in the block) or the
  list's `$menuKeepsSelection` is set. The next build reports the change.
- **Cells' menus and double clicks**: `ListState::$cellMenu` gives each
  cell's box a context menu told its row and column;
  `Context::rowDoubleClicked` tells a node built in a list's row that a
  double click this frame landed on it (`Runtime::doubleClickOver`),
  without taking it from the row.

## Occlusion culling (v1.74)

- **What goes** (`DisplayList::cull`, mygo's 9e5bdc3 `planCull`): as
  `rect()` and `box()` write an opaque fill (alpha 1 at the list's
  opacity, plain color, through no map or mask) of at least 40,000
  square points, a panel's, a sheet's or a popover's, the list notes the
  part it certainly covers whole: its box less half a point of smoothed
  edge, 0.3 of its largest corner radius (a corner's curve stays outside
  the box in by 0.29 of its radius), and a box's border, within its clip.
  `Metal::present`, once the frame's damage is taken from the list and
  before it draws it, has `cull()` go back from the last fill noted,
  keeping the eight largest seen, as mygo does: a quad drawn through no
  map whose box, a point more each way, within its clip, lies inside one
  gets the kind -1, which the vertex stage draws nothing for, as a box's
  slots after it. Glass, a backdrop blur's level and the hard scroll
  edge read what is under them: they stop the look. The pixels are those
  of drawing everything; the frame's statistics count the quads left out.
- **Cost**: the look reads the quads from the buffer, about 0.2 ms a
  frame for the gallery's 495 quads in the Linux container the tests
  run in, against its 0.9 ms of painting; smaller fills would be looked
  under for less than they save the GPU, so they are not noted. Lists
  without a fill that large cost nothing.

## The terminal and the clipboard (v1.75)

- **Reading the clipboard** (`Options::$clipboardRead`, a
  `ClipboardAccess`, and `$onClipboardRequest`, mygo's 123d8a9): what a
  paste offered a program is answered as before; a read carrying a paste
  event's password this terminal cannot grant stays silent, for the
  terminal that pasted; with `ClipboardDeny`, the default, the effect
  returns an empty reply, which the library encodes as its refusal (an
  empty OSC 52 reply, EPERM for OSC 5522). Otherwise the effect returns a
  reply carrying a `Vt\DeferredClipboardRead` and queues a
  `ClipboardRequest` on the terminal; the binding takes the refusal the
  library writes as the callback returns into it (`setRefusal`: OSC 52's
  prefix and terminator, the Kitty read's id, location and terminator)
  rather than sending it. As the view builds, it takes the queued reads
  and answers them in order (`View::answerReads`): allowed ones from the
  clipboard read then, the text always among them, as OSC 52 asks for it
  alone; asked ones through the hook, once, whose `allow()` or `deny()`
  asks for a frame, or through the terminal's own `alertDialog`, one a
  pass and one at a time, with the text as one line of 200 characters at
  most, Allow its default. `Vt\Terminal::answerClipboard` writes the
  answer encoded as the library encodes one (`DeferredClipboardRead::answer`),
  or the refusal held back. A read of the types available alone is
  served without asking.
- **Pastes**: with no text but other formats, as only an image, a program
  that asks for bracketed pastes gets an empty one, as VS Code's terminal
  sends (mygo's 8dc7b74); Select All copies with `$copyOnSelect`, which
  `Terminal::setCopyOnSelect` changes in a running terminal (4bda750).

## Distribution: provisioning profiles, localized Info.plist strings, senders (v1.76)

- **Provisioning profiles** (`macos.provisioningProfile`, mygo's f691dd8):
  `embedProfile` in `bin/myphp` copies it to the bundle's
  `Contents/embedded.provisionprofile` before `Codesign::sign`, in `build`
  and `dev`, so the signed app, its disk image and its update archive hold
  it. Ad-hoc signing cannot use one: `build` stops before it builds
  anything, `dev` without `-sign` says to leave the profile out of
  development builds (with a computed configuration, myphp.config.ts),
  whose identifier, `<identifier>.dev`, the app's profile does not
  authorize. The profile is among what `dev` watches.
- **The app's InfoPlist.strings** (mygo's 2e50d4d): `writeLocalizations`
  reads the one the resources copied into a language's `.lproj`
  (`Native\Build\Strings`: Xcode's text format, in UTF-8 or UTF-16 with a
  byte order mark, its comments and escapes, a surrogate pair's two `\U`s
  as one character, or a property list in XML), keeps its strings, adds
  the name the catalog gives (myphp.appName) where it gives none, and
  writes them as XML, in place of the link the copy of the resources may
  have left; one it cannot read stops the build, naming it.
- **Senders** (`NotificationOptions::$sender`, a `NotificationSender`,
  mygo's dc7d966): an incoming direct message's sender, copied with the
  options, named by the title without a name. `show()` asks once whether
  Intents has `INSendMessageIntent` and the content
  `contentByUpdatingWithProvider:error:`, and whether the app is signed with
  `com.apple.developer.usernotifications.communication`
  (`SecTaskCopyValueForEntitlement`); then it makes an `INPerson` of the
  sender's id (a handle of unknown type, no contact detail), name and
  avatar, an incoming `INSendMessageIntent` with the subtitle above the
  body and the group (else the id) as its conversation, donates its
  interaction, and shows the content updated from the intent. Without the
  capability, with no id or an avatar that is no image, or when the update
  fails, the ordinary notification shows.

## Hints-only watches, FSEvents (v1.77)

- **FSEvents** replaces kqueue on macOS (mygo's fseWatch): see "File
  watches". `maxEntries` defaults to 65536 there, as a watch's cost is no
  longer a descriptor per entry, and 8192 elsewhere.
- **Directories only** (mygo's 8cda101): backends refuse file targets,
  the scan then enrolls none (`$noFiles`), and the first scan keeps
  nothing as it was before (`$before` null), its contents being no
  events.
- **`FileWatchOptions::$hintsOnly`** (`Native\FileWatch\Hints`, mygo's
  fileWatchHints): no snapshot, no file stat'ed. It enrolls the root (one
  subtree target where the backend takes one) or, on Linux, every
  directory, found by `Fs::subdirs` from readdir's `d_type`, lstat'ing only
  entries of unknown type. A notice hints at the directory it names; when
  recursive without a subtree, the child it names is enrolled if it is a
  new directory and its targets dropped if it moved; after an overflow
  the tree is enrolled again and the hint is ".". Hints under another one
  are left out (`Hints::hintEvents`). The watch drives either through
  `Looker`, which `Scan` implements too. On a 20,000-file tree here the
  first look takes 17 ms and 0.2 MB, against 160 ms and 9 MB.

## Live chart lines, a selection's caret (v1.78)

- **`Painter::strokePolyline`** (mygo's adaee8c): an opaque line is a
  capsule a segment (`DisplayList::line`, the quad that draws a line with
  round ends), drawn as other quads are, with no mask of the whole line to
  rasterize on the CPU; where the line or the painter is translucent it
  goes through `DisplayList::path` as `strokePath` does, so that overlaps
  do not show darker. A NaN or infinite point breaks it.
- **`Chart::minMax`**: the lowest and highest point of each pixel column
  of a run, in their order, with the ends of the run and at most one point
  past either edge, found by halving; its loop does without `runs()`'s
  closures, 2.2 ms for 15,000 points here. **`Chart::lineInto`** and
  `Path::reset()` refill a kept path; a linear line goes to the path
  straight, without the points a curve needs. `examples/chart-streaming`
  scrolls three waveforms of 15,000 samples, 1,000 a second each, drawn
  three ways (`polyline`, `path`, `raw`).
- **A selection's caret** (`TextState::textAnchorCaret`, mygo's 0eae690):
  the selection in runes from its anchor to its caret, beside
  `textSelection`, in order, so that an app extends one made backwards
  from its anchor; the bytes stay `$anchor` and `$cursor`.
- **FSEvents' start**: `kFSEventStreamEventIdSinceNow` is UINT64_MAX,
  which PHP writes as -1: as `0xFFFFFFFFFFFFFFFF` it is a float, which
  the `uint64_t` parameter took as nothing usable, and v1.77's streams
  reported no change.

## Markdown in linear time (v1.79)

- **Source maps** (`Parser\SourceMap`, mygo's bb363d6): a block's content
  joined from lines keeps where each line starts in it, or in a table cell
  where each `\|` was, and a source offset is looked up by halving; one
  map serves every inline of the block, and the definitions at its start.
  `srcOffset` walked the lines for each offset, quadratic in a paragraph
  of many lines: 5,000 lines of `*a*` took more than 100 s, now 0.16 s.
- **Brackets a link closes**: links do not nest, so a link closing makes
  the brackets open before it, but images, open none. That is now one
  count (`InlineParser::$linked`), below which a bracket is spent, rather
  than a walk marking each one: 5,000 brackets then 5,000 links took 1.8
  s, now 0.07 s. The linear-time checks of the parser's test include
  both, with lines indented, quoted, and escaped pipes in a cell.

## Shaders in Go's syntax (v1.80)

mygo's ui/shader, with the compiler it forks from Ebitengine's Kage
(Apache 2.0: src/UI/Shader/LICENSE, the notice kept in each file ported
from it), in PHP:

- **Syntax** (`UI\Shader\Syntax`): Go has go/parser; PHP has none, so a
  scanner and a parser of Go's syntax make a tree of go/ast's nodes and
  fields (lowerCamel), all of Go but generics; what a shader cannot have
  (pointers, maps, closures, switch, go...) parses into `Unsupported`
  nodes the compiler reports. It stops at the first error, which Go's
  parser words the same in most cases.
- **The compiler** (`UI\Shader\Compiler`, mygo's internal/shader) checks
  the tree and makes the program of `UI\Shader\Ir` (internal/shaderir);
  `Ir\Constant` is go/constant's part it uses, floats kept as exact
  fractions while they fit 64 bits, as go/constant keeps them (0.13*4.5 is
  0.585, which wind.kage folds). `compileFragment` adds the bridge (the
  uniforms renderers set, image0At... layerAt, backdropAt) as bridge.go.
- **MSL** (`UI\Shader\Msl`, shaderir/msl): the program as Metal's.
- **Tests against mygo's compiler**: Go 1.24 builds internal/shader and
  shaderir as they are, so their output for every source mygo's own
  tests compile (3640, and the 22 shaders of its examples and ui/shader
  tests) is in tests/testdata/shader: whether go/parser takes each, the
  compiler's error, its program and its MSL. The port's errors match
  word for word (but for five that print Go pointers), its programs and
  its MSL byte for byte.
- **Drawing** (`UI\Shader\Shader`): a draw is a quad of kind 16
  (`DisplayList::shader`), a fill turns the glyphs' and paths' masks fn
  painted into kind 17 (`shaderGlyphs`), each with its draw among
  `$list->shaders` (the code, the uniforms' bytes in their lanes, those
  of a wide gamut, the images) and in a run of `$list->shaderRuns`;
  `KIND_STRIDE` is 32 to make room. Metal draws a run with the shader's
  pipeline (`Shader\Code`'s library: the shader's MSL and a tail of its
  own vertex and fragment stages, which place quads as quad_vertex does
  and scale the shader's color by the quad's coverage, clip, mask and
  opacity), built when first drawn; the uniforms are buffer 3, the images
  textures 5 to 8, each an image's pixels or a canvas's copied from its
  page. A shader reading its backdrop is a glass entry: the pass splits,
  the frame so far is copied (and blurred at half size for a blur), and
  Metal sets where its texels lie. An effect (`Node::effect`) renders the
  node into a canvas, as a blur does, which the shader draws over as its
  image3. A frame with a shader is drawn whole: its uniforms change what
  the quads do not tell.
- **mygo's examples drawing with shaders** (examples/wind, blackhole,
  chat-glow, run headless by examples_test.php) load their .kage files
  next to main.php. A frame costs ~1 ms of PHP in the headless runs
  (wind ~1.0–1.4, blackhole ~0.4, chat-glow 0.7 at rest to 2–2.7 while it
  writes, with JIT and without), a shader draw's uniforms ~0.15 ms of it.
  Chat-glow found two things in lists following their end, both fixed as
  mygo has them: a frame without a FollowAnimation, as when much came at
  once, kept the lag of a glide on its way, which the next frame went on
  gliding from (mygo's glideToEnd drops it: `Context::list` now jumps it
  to 0); and the row a list aligns (`ListState::$aligning`, its end as a
  reply grows) was aligned in the first arrange only, so when texts then
  wrapped and the reply grew past the list's bottom, the list was no
  longer at its end and stopped following (`Layout::layout` now aligns
  it again, from where it showed, in the arrange after the wrap).
## Large canvases, inputs as the app styles them, cheaper shaders (v1.81)

- **Canvases larger than a page** (`Canvas::loose`): a canvas is up to
  8192 pixels a side (mygo's maxEffectLayer, what every GPU's textures
  allow), so that blackhole's scene is the window's pixels. One larger
  than a page of their atlas (2048) has no place there: Metal renders it
  into a texture of its own, kept with it (`Native\Owned`, released as it
  goes, as the textures shaders read images and canvases from now are),
  which shaders read as it is; drawn as an image (`picture`,
  `imageRegion`, a blur's layer), a shader of the painter's samples it
  (`Painter::LOOSE`, a quad of kind 16), as the quads' images do, gray,
  pixelated or tinted. Its MSL is mygo's compiler's, byte for byte.
- **What a draw and a compile cost**: `Code::bytes` lays the uniforms'
  words out in runs worked out once (the words whose lanes follow each
  other packed together, `pack('V*')`), where it wrote word by word
  (wind's 451 words: 106 → 7 µs); a draw's uniforms set from a list pack
  their floats at once; Metal copies a draw's bytes from one buffer it
  keeps (into a buffer of their own past setFragmentBytes's 4096). The
  entry point (Fragment or an effect's) is found in the source, its
  comments and literals left out, where a parse of its own found it: a
  quarter of a compile (wind.kage ~43 → ~32 ms here, the checking the rest).
- **Inputs styled by the app**: `input()` takes the padding, corners,
  background, border and shadows its setters give it, as mygo's
  TextInput, its height its text's line and its padding, so wind's
  composer and chat-glow's field are inputs as mygo's are.


## A field's text clipped at its border (v1.82)

`Painter::paintField` clipped a field's text 2 points inside its box on
either side, whatever its padding: a field with none (chat-glow's, padded
only above and below) lost the first pixels of its first letter. It clips
inside its border now, as mygo's paintInput does: at the box's edges for a
field without a border, a point in for the theme's, where the padding
keeps the text clear of it as before (tests/textarea_test.php).

## A terminal's pointer as Ghostty's, avatars as PNGs (v1.83)

- **The terminal's pointer** (mygo 0104a5f): `View::pointerShape` picks
  it each frame as Ghostty does: the I-beam over a shell, the arrow while
  the program takes the mouse, the I-beam again while Shift is held (Shift
  selects then), and the shape the program asks for with OSC 22
  (`Vt\Terminal::mouseShape`, a GhosttyMouseShape, mapped to the nearest
  `UI\Cursor` by `cursorOf`) until it takes or leaves the mouse again;
  the hand over a link with Command stays first.
- **A sender's avatar** (mygo 768644e): `Notification::communicationAvatar`
  hands Intents the avatar redrawn as a PNG (NSImage, then
  NSBitmapImageRep), as NSImage reads SVG, which the Intents image
  service refuses; what NSImage can't read keeps the plain notification.

## A terminal's clears, copies, links, drops and colors for apps (v1.84)

mygo 633b369, ea23ad2, e15e462, ff9e8ff, 2dd788b, 0faf347, c238c0c,
135d776, 82a9154, dc20c14, in plugins/Terminal:

- **Clears**: `Terminal::clearScreen` (what ActionClearScreen's keys do,
  left out on a full-screen program's screen) and `clearScrollback` are
  public; `Options::$onClear` sees the user's clear first (`Clear`), and
  one it takes clears nothing here, so that a terminal showing a server's
  has the server's terminal clear. The context menu's last item is Clear,
  which clears as Command-K does (disabled on the alternate screen), where
  it was Clear Scrollback, which changed nothing on the screen.
- **Copies**: `Options::$onCopy` hears each text the terminal writes to
  the clipboard and what copied it (`CopySource`: the user, a selection
  copied, a program's OSC 52), all through `View::writeClipboard`.
- **Links**: `Links::urlAt` and `pathAt` give the columns a link spans;
  the link Command hovers is a span of cells (`View::$hover`, a
  hyperlink's the cells around with its URL), underlined in its text's
  color, and `Terminal::hoveredLink()` tells an app what it opens, whether
  it is a path, and where its cells are, for a preview over the view.
- **Dropped files** paste their paths (`dropFiles`), each quoted for the
  program's shell (`ShellQuote`: backslashes, `$'…'` for control
  characters, PowerShell's single quotes for pwsh), bracketed for a
  program that asks, and offered as a file list to one that reads the
  clipboard itself; the shells in the tests read each quoted path back.
- **A snapshot** keeps the blank rows at the end of the screen, which
  Ghostty's formatter leaves out (`Vt\Terminal::padRows`): replayed, the
  scrollback is as long, and the line above the cursor is not written
  over.
- **Colors without a view**: `Terminal::useTheme` gives a terminal no
  view shows its colors, `Options::$theme` from the start, which programs'
  color questions get; a program that asked (mode 2031) is told when the
  colors turn light or dark, by `useTheme` or a view's theme.

## A terminal's padding and minimum contrast (v1.85)

mygo 12b177c, d5bcfdb, 344c353, and of 3473193 what is not the mirror's:

- **Padding**: `Options::$padding` (`Padding`, points across and down; 6
  and 4 when zero) and `$paddingColor` (`PaddingColor`, Ghostty's
  window-padding-color): the background, or the backgrounds of the cells
  nearest to it extended into it (`ViewPaint::extendPadding`), beside each
  row and above and below the top and bottom rows, out to the corners.
  PaddingExtend takes only rows whose every cell has a background of its
  own (`RowCache::$filled`), as Ghostty's extend, so that a shell's
  highlights, a program's tabs and its boxes keep to their cells;
  PaddingExtendAlways every row. A lone character in inverse video, as a
  program draws its own cursor, keeps its background in its cell (the
  fourth field of a row's backgrounds).
- **Minimum contrast**: `Options::$minimumContrast` (and
  `Terminal::setMinimumContrast`), a WCAG ratio, as Ghostty's
  minimum-contrast: a cell's text with less contrast with its background
  is mixed toward black or white, whichever contrasts more, as little as
  reaches it (`Contrast`, eight halvings); shapes (box drawing, blocks,
  the legacy computing symbols, Powerline's: `Box::noMinContrast`) keep
  their colors. A change draws every row again.

## Painting skips what shows nowhere (v1.86)

mygo d41f1ee. `Runtime::record`, which finds every node where it shows
in the window each frame, sets its ink (`Node::$ink`, `setInk`): its box
grown by its shadows' reach and 4 points, through its transform, and the
union of its children's. The window's painting (`DisplayList::$cull`, set
around `Painter::paint` of the root) skips a node whose ink lies outside
the clip, its subtree with it (`Painter::paintNode`); before, only a node
whose box was clipped and that held nothing, or clipped what it held, was
skipped, so that a long column moved out of view by a translation or a
scroll walked every row down to its leaves. Nodes that paint beyond their
ink, or do more than paint, are `$noCull`, and those around them: words
in a paragraph and rich text, effects and blurs, dividers, the debug
overlay, exit transitions' copies, and drawings of the app's own (draw,
drawOver, drawObjects). Canvases, blurs' and effects' layers, the layer
under materials, a dragged copy and tooltips paint everything, as they
paint nodes elsewhere than record found them. 1000 rows translated out of
view: a paint 13.5 → 0.7 ms (headless); `setInk` costs nothing measurable
of a frame. tests/paintcull_test.php counts the rows a scrolled column
skips, and checks that trees styled to paint past their boxes, moved in
and out of view, paint the same quads that show with the skipping and
without (`Headless::$noCull`).

mygo 4139cef (text measured once per element; a grid measuring only the
items its tracks read) is not ported: myphp's text measurements are kept
in the text engine's table by their text, size and style, found again by
a hash lookup each frame, which is what mygo's commit saves; its measuring
pass sizes every node before arranging, which the grid's part would split.

## A terminal tells of commands that finished (v1.87)

Ghostty 1.3's notify-on-command-finish, which mygo has not (the developer
asked for it): the library reports the steps of a command the shell marks
with OSC 133 (`GHOSTTY_TERMINAL_OPT_SEMANTIC_PROMPT`, in the libghostty-vt
mygo pins), which `Vt\Terminal` hands to `Effects::$semanticPrompt`. The
terminal notes when output starts (C) and, at the end (D), how long the
command ran and its exit code; after the output it was written in,
`Terminal::commandFinished` tells the user as `Options` say:
`$notifyOnCommandFinish` (`NotifyOnCommandFinish`: Never, the default,
Unfocused, while the view has not the focus, or Always),
`$notifyOnCommandFinishAction` (`CommandFinishAction`: the bell, as BEL
through `$onBell`, by default, and a desktop notification through
`$onNotify`) and `$notifyOnCommandFinishAfter` (5 seconds). The
notification is Ghostty's: "Command Succeeded", "Command Failed" or
"Command Finished" (no exit code), "Command took 1m 5s 230ms and exited
with code 1." (l10n, in Chinese too). Shells mark their commands
themselves (fish, Nushell) or through an integration (zsh and bash,
which Ghostty injects; the terminal injects none).

## A shell integration for zsh and bash (v1.88)

zsh and bash mark no commands of themselves, so v1.87's notifications
told of none in them; Ghostty injects its own shell integration, and so
does the terminal now (`ShellIntegration`, the developer's choice: written
for myphp, not Ghostty's scripts, whose bash one is GPLv3, Kitty's). Its
two scripts are written once into the system's temporary directory, by
their contents' hash. A zsh or bash started alone, as the user's shell
is (`Options::$shellIntegration`, on by default), starts with them:

- **zsh**: `ZDOTDIR` points at the integration's directory, the user's
  kept in `MYPHP_ZDOTDIR`; its `.zshenv` puts `ZDOTDIR` back (or unsets
  it), reads the user's `.zshenv`, and in an interactive shell adds a
  precmd hook (OSC 133 D and the exit code after a command, A before each
  prompt) and a preexec one (C as a command runs); zsh then reads the
  user's other startup files from their own place.
- **bash** starts with `--posix` and `ENV` the integration's script (the
  user's `ENV` kept in `MYPHP_BASH_ENV`), which an interactive shell in
  POSIX mode reads and nothing else: it leaves POSIX mode, reads the
  startup files bash would (a login shell's /etc/profile and the first of
  ~/.bash_profile, ~/.bash_login, ~/.profile; else ~/.bashrc), puts its
  prompt hook first in `PROMPT_COMMAND` (D, A) and marks a command's start
  with a DEBUG trap (C), unless the user set a trap of their own.
- **macOS's own `/bin/bash`** (3.2) takes none, as in Ghostty: Apple's
  build reads the usual startup files in POSIX mode too and never `ENV`
  (v1.91.1: before, it ran with no marks, and the test waited for
  them); a bash installed apart, as Homebrew's, takes it.

tests/terminal_test.php runs bash and zsh in a terminal with a startup
file of their own: it is read, a command that fails and one that
succeeds are told of, an empty line is none.

## Bare file names, rounded caps, short lists, Japanese (v1.89)

- **Bare file names** (mygo 156c6af): `Links::PATH` takes a file name
  with an extension, a `:line` or not (`notes.md`, `write(notes.md)`), and
  `pathAt` leaves dotted numbers (version 1.2.3) no link; Command-hover
  underlines the cells Command-click opens.
- **Powerline's rounded caps** (150f5bb): U+E0B4 to U+E0B7 are drawn by
  the terminal (`Box::draw`), half an ellipse spanning the cell, filled or
  stroked, as its triangles are: the font's fell short of tall cells.
- **Drag choosing rows in a short list** (db2e1b8): the edges near which
  the list scrolls (`Context::dragSpeed`) take 32 points each, or half of
  a list shorter than both; a list 50 tall scrolled up whatever the drag.
- **Japanese** (165bc98): src/l10n/ja.json, mygo's translations of its
  own strings, and of the terminal's command notifications.

## The code editor: imports folded, text tinted, scrolled in points (v1.90)

- **A run of imports folds as one** (mygo d4067ac): `Language::$imports`,
  a pattern of a line that imports, for 17 languages as mygo's; a run of
  more than one (`EditorFold::importsEnd`), with the lines in brackets of
  each and the blank lines between, folds as one, as VS Code folds them;
  one alone, as Go's `import (`, by its brackets; lines in strings and
  comments are none. `State::foldImports()` folds the first, as an
  editor can on opening a file; the gutter marks the run's first line.
- **Text backgrounds** (38c9fa7): `State::$textBackgrounds`
  (`TextBackground`: bytes, [rgb, alpha]) fill ranges of the text under
  it and over line backgrounds, as the words a merge changed, with
  `fillRange` as a diff's words: across wrapped rows, tabs and wide
  characters, cut to the text and to characters' edges.
- **The scroll in points**: `State::scrollY()`, `setScrollY()` and
  `lineTop()`, so that editors side by side scroll together and an app
  draws the bands joining their lines.
- **Focus shows a client's caret**: a node with an app's text input client
  (a code editor) is revealed by its caret's box, not its whole box
  (`Layout::clientCaret`): an editor as high as its lines in a scroll
  view jumped to its first line. Such an editor takes its height as its
  view's before it paints (`EditorBuild`), so that one out of view, which
  painting skips, still gives its caret's bounds.

## The terminal's scroll bar drags, and hides as macOS's (v1.91)

- mygo 7a8d3a3: the scroll bar shows for a second after the viewport
  last moves (a wheel, a key, output at the bottom does not move it) and
  then fades out over 300 ms (`ViewPaint::BAR_LINGER`, `BAR_FADE`),
  waking the loop with `after()` and then `animationFrame()`; at the
  bottom it no longer stays hidden while hovered.
- The pointer over the 12-point strip at the right edge
  (`View::SCROLLBAR_STRIP`), while there is a scrollback, shows the bar
  wider (8 points) and is the arrow; there Command+hover finds no link.
- A press on the thumb holds it where it is pressed, one on the rest of
  the track by its middle, which comes to the pointer; the drag scrolls
  the scrollback (`Vt\Terminal::scrollBy`) until the release, off the
  strip too, and selects nothing. A full-screen program's screen has no
  scrollback, and no bar.

## Audio: a player's position is where it is heard (v1.92)

- mygo 3d452cb: `Player::position()` is where a voice is heard, not
  mixed. The mixer counts the frames it mixed (`Mixer::$mixed`) and, of
  the latest, those the device holds (`$unheard`): as many as it was
  written, up to what it holds (`Device::held()`, the Audio Queue's
  buffers, none for the null device), from when it opened
  (`Mixer::setHeld`). Each voice marks the output's frame and its own
  source frame where each mix starts and ends, and forgets the marks the
  output played; `position()` interpolates them at the frame heard
  (`Voice::heardAt`), which carries speed changes, a stream waiting for
  its decoder, and a pause playing out. A seek or a stop drops them, so
  the position is the target at once and stands there until the output
  plays what it mixed since.

## Now Playing and the media keys (v1.93)

- mygo 138c63b: `MyPHP\NowPlaying` shows what the app plays in Control
  Center and the menu bar, and reports the media keys and those controls
  as commands (`onCommand`) while it shows something: `set()`,
  `setPlayback()`, `clear()`, `info()`; `NowPlayingInfo`, `PlaybackState`,
  `MediaCommand`'s bits. The play/pause key is PAUSE while playing, else
  PLAY; only the commands offered reach `onCommand` (0 offers play, pause
  and stop); a seek is clamped to the track, the other commands get where
  playback is, which advances at the rate while playing.
- `control(MediaPlayer)` lets the commands play, pause, stop and seek a
  player, `Audio\Player` implementing `MediaPlayer`, offering them on top
  of the info's (seek when it knows its duration), and shows its state;
  `sync()` after the app moves it, and its end (`Player::onEnd`, new,
  called after the mix the player ended in) syncs it.
- What it shows is applied once the app is ready, each time from the
  latest (`NowPlaying::$show`, a test's backend in tests/nowplaying_test.php).
  `Native\NowPlaying::media` sets MPNowPlayingInfoCenter's info and
  state, and enables MPRemoteCommandCenter's commands as offered (the
  toggle with play or pause, seeking with a duration); the video
  player's Now Playing (`show`) and it share the system's one, the last to
  show it having the commands. The artwork is not shown on macOS
  (docs/mygo-deviations.md).

## An image lets its pixels go once shown (v1.94)

- mygo c1b67c8: `Image::releasePixels()` lets go of the pixels of an image
  `fromBytes()` made, and of its smaller levels; it keeps the bytes, which
  cost a fraction of them, and decodes them again (`Image::decoded`'s
  closure) when something needs them: the image atlas adding it again
  after it evicted it or started over, a level for drawing it smaller, a
  shader's texture, `pixels()`. What takes them says so (`address()`,
  then `uploaded()`), and a released image lets them go again. An image
  `fromPixels()` made, a canvas's, a symbol, or one `update()` changed has
  nothing else to make its pixels from, and keeps them; `update()` on a
  released one keeps the new pixels.
- What it saves: the image's own copy. The atlas's pages keep theirs on
  the CPU, as they always did, for the pages to be uploaded from; mygo's
  GPU renderers hold the pixels in textures alone. Decoding again happens
  as the frame paints, on the main thread, where `CoreImage::decode`
  blocks until it is done.

## Open items

Where myphp is not mygo's yet is listed in `mygo-deviations.md`.

- The player's settings (v1.62) are IINA's whole; what of them has no
  equivalent without mpv is in `mygo-deviations.md`. Key bindings carry
  out the mpv commands the player has the means for, the others logged.

- A screen of never-seen strings costs a shaping burst (one dropped-frame
  second at 1000 rows); the shaped-line cache is swept by age but the
  burst itself is CoreText's.
- An icon turned every frame rasterizes a mask a frame (mygo does too);
  the masks of the turns before go as the atlas needs their room (v1.64).
- Text glyphs still come from CoreText: the SVG renderer could draw
  fonts' outlines on Linux and Windows once a font parser exists.
- `grid` has no automatic margins (nodes have no margins here); a child
  fills its area across unless `justifyItems`/`justifySelf` place it.
  mygo's `Margin` comes with the Markdown view, which uses it.
- Video: ASS's outlines are cut out per glyph, not per line (v1.64, its
  section and mygo-deviations.md; its styles, places and motion, v1.44;
  image subtitles, PGS and VobSub, v1.43).
  Vorbis is decoded in PHP (v1.55; FLAC by the system, v1.42; VP9, AV1 and Opus, v1.37),
  nor DTS or TrueHD, which the system has no decoder of. A size
  animated by \t rasterizes its glyphs anew each frame.
- Video: the hardware AV1 decoder's display layer sometimes shows a
  hidden frame (v1.52, its section).
- `text()` measures with the old metrics unless its style needs the
  text system (`TextStyle::needsLayout`).
- mygo's CodeEditor and diff viewer (ui/editor) and the Markdown parser
  are in, with mygo's text system and paragraphs of inline text; the
  RichText editor, Markdown view and Terminal are in (their sections
  above); text is selectable (mygo's Selectable).
- VoiceOver is in (Accessibility above); Linux's and Windows's assistive
  technology come with those platforms.
