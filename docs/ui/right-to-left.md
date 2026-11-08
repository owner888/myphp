# Right to left

Views lay out from the right when the app's language reads right to left,
as SwiftUI's: `leading` and `trailing` (`HorizontalAlignment::leading`,
`Edge::leading`) are the right and the left then, an `HStack` starts at the
right, a back chevron points right. `->environment(layoutDirection:
LayoutDirection::rightToLeft)` gives a part its own direction, as a phone
number or a media player's controls that stay left to right:

```php
$view = fn() => VStack(alignment: HorizontalAlignment::leading)(
    Text('مرحبا'),
    HStack()(Text('+1 555 0100'))->environment(layoutDirection: LayoutDirection::leftToRight),
)->environment(layoutDirection: LayoutDirection::rightToLeft)->padding();
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](custom-widgets.md)).

Arabic, Hebrew, Persian and Urdu read from right to left, and so do the
interfaces of apps in those languages: a row starts at the right, a
sidebar is on the right, a back arrow points right. Native UI lays a
window out so when the app's language reads right to left, as Flutter's
`Directionality`, SwiftUI's `layoutDirection`, CSS's `direction` and GTK's
text direction do. The coordinate system is not mirrored: X still grows to
the right in rectangles, in what `draw()` paints, and in the pointer's
position. Only the layout is.

## The direction

`Direction::LeftToRight` and `Direction::RightToLeft` are the two
directions. A window takes the direction of the app's language, and every
node that of the node it is built in. `direction()` on a node gives it and
what is built inside it a direction of its own, as a phone number, a code
sample or a media player's controls that stay left to right in a
right-to-left window:

```php
$c->row(gap: 8)->direction(Direction::LeftToRight);
  $c->button('⏮');
  $c->button('⏯');
  $c->button('⏭');
$c->end();
```

Give it right after creating the node, before its children, as you would a
key: widgets read the direction as they are built. The popups attached to a
node (popovers, comboboxes' options, tooltips) and what an `overlay()`
builds take the direction of where they were asked for.

`$c->direction()` returns the direction of what is built now, for widgets
of your own that draw what points along the line:

```php
$forward = 'M5 2 11 8 5 14';
if ($c->direction() === Direction::RightToLeft) {
    $forward = 'M11 2 5 8 11 14';
}
```

## What mirrors

Right to left:

- A row lays its children out from the right, and a column aligns them
  across from the right: `justify()`, `alignItems()` and `alignSelf()`
  take `START` and `END` from the direction. `reverse()` lays a row out
  from the left. A grid places its columns from the right, and
  `justifyItems()` and `justifySelf()` start at the right of their cells.
- `textAlign()`'s `START` is the right of the node, whatever its text
  reads, as in CSS, AppKit and GTK, and `END` the left. A paragraph reads in
  the direction of its first strongly directional character, as Unicode's
  bidirectional algorithm finds it, and one without any, as a number or an
  empty line, right to left. Left to right, nothing changes: a paragraph
  in Arabic still starts at the right, as Apple's guidelines align a
  paragraph by its language.
- The logical edges are on the right where they start: `paddingStart()`,
  `paddingEnd()`, `marginStart()`, `marginEnd()`, `borderStartWidth()`,
  `borderEndWidth()`, the `start()` and `end()` of positioned nodes (and
  `startPercent()`, `endPercent()`), and the anchors `Anchor::TopStart`,
  `Start`, `BottomStart`, `TopEnd`, `End` and `BottomEnd`, as CSS's logical
  properties and Flutter's `EdgeInsetsDirectional` and
  `AlignmentDirectional`. An absolute node placed from neither side goes to
  the right, and where both `left()` and `right()` are given and its width
  leaves no room for both, `right()` wins.
- Scrolling sideways starts at the right, and `ScrollState::$x` counts
  from there (0 shows the start, `maxX` the end), as Flutter's offsets do;
  the wheel and the trackpad move the content as they do left to right,
  and Shift with the wheel goes toward the end. The vertical scroll bar is
  on the left.
- The controls mirror what points along the line: a switch turns on to the
  left; sliders, range sliders, progress bars and meters fill from the
  right, and their arrows move the knob the way they point; tabs, segmented
  controls, toolbars, ratings, grid views and calendars go to their next
  item with Left; disclosure arrows, breadcrumbs' chevrons, a toolbar's
  overflow and the back button point left, or right for back; trees,
  outlines and disclosures indent from the right and open with Left; a
  split's first pane is on the right; a table's columns go from the right
  and resize from their left edges; pages pushed on a router slide in from
  the left, and Alt+Right goes back.
- Popovers, a select's and a combobox's options, and the menus of menu
  buttons open at the start of what opens them, and context menus are laid
  out right to left, opening to the left of the pointer.
- In a single-line text input, the text starts at the right, its
  placeholder too, and the caret of an empty one is there. The arrows move
  the caret the way they point, through text of either direction, and
  Option and Command with the arrows go by words and to line ends the way
  they point in a right-to-left paragraph, as AppKit and GTK do.

## What does not

- What is physical stays physical, as in CSS: `left()`, `right()`,
  `padding($top, $right, $bottom, $left)`, `borderWidth()`,
  `Anchor::TopLeft`, `radius()`'s corners, gradients' angles, transforms.
- Text, images, icons and what `draw()` paints are not mirrored: a check
  mark, a magnifying glass, a spinner and a chart read as they do left to
  right. Draw what must point the other way after reading
  `$c->direction()`.
- Code reads left to right: the code editor and the diff viewer
  (`UI\Editor`), Markdown's code blocks and the terminal keep their own
  direction, as do time inputs, as times are written so.
- The rich text editor (`UI\RichText`) aligns its paragraphs as their text
  reads, as a document does.
- The inspector reads left to right.

## Trying it

`MYPHP_DIRECTION=rtl` lays every window of native UI out right to left
(and `ltr` left to right), whatever the app's language, to try an
interface before it is translated, as GTK's `GTK_DEBUG=invert-text-dir`
and Qt's `-reverse` do:

```sh
MYPHP_DIRECTION=rtl myphp dev
```

The [inspector](../inspector.md)'s RTL toggle does the same for its window
while it runs, and a headless test lays its window out right to left after
setting its runtime's `rightToLeft`, as a test of the layout would:

```php
$router = new Router('/');
$h = new Headless(function (Context $c) use ($router) {
    $c->row()->fill();
      $c->backButton($router);
    $c->end();
}, 400, 300);
$h->rt->rightToLeft = true;
$h->frame();
[$x] = $h->rect($h->byLabel('Back'));
check($x >= 200, 'the back button is at the right');
```
