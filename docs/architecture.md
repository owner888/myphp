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
on. `Metal::requestFrame` instead performs `draw` on the next turn of the
run loop in its common modes (coalesced), and `windowDidResize:` calls
`draw` directly; a frame asked for while one is drawing (waiting) is drawn
after it.

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
place them (`System::decorate`; underlines do not skip descenders yet).
`Node::children` on a rich text is mygo's Children on one: the text
built inside it (`Context::within` pushes the node while the closure
runs) continues its paragraph, each node's style a span over the paragraph's
(`Node::inlineSpans`); after arranging, `Layout::placeInline` gives each
the boxes of its words (`frags`), which are its area for the pointer
(`Runtime::under`), its focus ring and its bounds to assistive
technology (`accessInline`). A paragraph wraps at `$width`, or at its
container's last-frame room with `wrap: true`; otherwise at newlines.
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
focused slider as steps (`stepped`); the select pops an `NSMenu` under
the node's last-frame rect (`Menu::popupAt`); the popup runs its own loop
but AppKit sends the chosen item's action on a later run-loop turn, after
the popup returned, so the action records the choice in `Runtime`
(`chose`) and asks for a frame, and the next build's `select()` takes it
(`chosen`).

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
as one glyph quad from a mask `Text::pathQuad` keeps in the glyph atlas,
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
itself inset by 3. `examples/native` chooses several rows.

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
`kevent`/`poll` for at most the time left when the app isn't running, and
turning the run loop (`App::wait`, 50 ms at a time) while it is. A watch
looks only when `next()` is called, so what changed meanwhile waits in
the system's queue and comes in one batch. The page plugin calls
`next(0)` from its generator and yields `Channel::wait` between, 10 ms
backing off to 100 ms.

The backends (`Native\FileWatch\Backend`) are libc through FFI: kqueue on
macOS, an `O_EVTONLY` descriptor per file and directory (mygo's darwin
watch), checked by `fstat` against the scan's stat so that a replacement
racing the enrollment is not watched; inotify on Linux, where the tests
run, an instance per watch, read without blocking. As Go does at start,
the first kqueue watch raises the soft limit of open files to the hard
one, at most OPEN_MAX (10240): macOS gives a process 256, and a watch
takes a descriptor per entry. Stats are PHP's `lstat` with its stat cache
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
`MYPHP_STATS=1 ./run.sh native 1000000` prints it a second at a time.

The Mac's half, measured by the developer (`MYPHP_STATS=1 ./run.sh
native 1000000`, scrolling): 55–59 frames a second at 1.4–2.5 ms a
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
- **Photos upright.** `ImageIO::exifOrientation` reads the Orientation
  tag of a JPEG's EXIF (mygo's parser); `orientTransform` is the
  CoreGraphics transform, in its y-up space, that draws each orientation
  pixel for pixel where mygo's `orient` puts it (a test maps every pixel
  of a 4 by 3 image), concatenated before CGContextDrawImage, with the
  bitmap's sides swapped for 5 to 8.
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

## Open items

Where myphp is not mygo's yet is listed in `mygo-deviations.md`.


- The glyph atlas starts over when full and is re-rasterized whole; an
  image page is emptied whole. Eviction per glyph or image would avoid
  the restart, at the cost of free-space bookkeeping the shelves lack.
- A screen of never-seen strings costs a shaping burst (one dropped-frame
  second at 1000 rows); the shaped-line cache is swept by age but the
  burst itself is CoreText's.
- Image decoding (`ImageIO`) runs on the main thread: a 12 MP HEIC takes
  a frame or two; `Process` is the only way off the thread, and it costs
  a child process. Apps decode between frames (`App::later`) meanwhile.
- An icon turned every frame rasterizes a mask a frame (mygo does too);
  the atlas starts over when it fills.
- Text glyphs still come from CoreText: the SVG renderer could draw
  fonts' outlines on Linux and Windows once a font parser exists.
- `grid` has no automatic margins (nodes have no margins here); a child
  fills its area across unless `justifyItems`/`justifySelf` place it.
  mygo's `Margin` comes with the Markdown view, which uses it.
- Underlines do not skip descenders (mygo's ink skipping reads glyph
  outlines), and `text()` measures with the old metrics unless its style
  needs the text system (`TextStyle::needsLayout`).
- mygo's CodeEditor and diff viewer (ui/editor) and the Markdown parser
  are in, with mygo's text system and paragraphs of inline text; the
  RichText editor, Markdown view and Terminal are in (their sections
  above); text is selectable (mygo's Selectable).
- VoiceOver is in (Accessibility above); Linux's and Windows's assistive
  technology come with those platforms.
- NSTimer is not locked to the display (the CoreGraphics path ran 50–58
  fps with identical PHP time per frame); MTKView pacing is what gives 60.
