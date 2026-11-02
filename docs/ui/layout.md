# Layout

Nodes lay out their children with flexbox, as in CSS, or in a
[grid](grid.md), in points:

```php
$folder = SVG::fromFile('icons/home.svg');
$view = function (Context $c) use ($folder) {
    $c->row(12, 8);
      $c->icon($folder);
      $c->text('Documents')->grow(1);
      $c->text('12 items')->textColor($c->theme->textMuted);
    $c->end();
};
```

`$c->row()` and `$c->column()` take their padding, gap, grow and background
color as arguments, `row($pad, $gap, $grow, $color)`, and every one of them
as a call on the node after; `$c->end()` closes the container.

## Containers

- `$c->column()` stacks its children from top to bottom and stretches them
  to its width. `$c->row()` places them from left to right and centers
  them vertically. `reverse()` lays them out the other way, from the right
  or the bottom, as CSS's `row-reverse` and `column-reverse`.
- `$c->spacer()` takes the free space of its row or column, pushing its
  siblings apart, and `$c->divider()` draws a thin line across its row or
  column (`divider(vertical: true)` in a row). `dividers($width, $rgb)`
  draws a line between each two children of a row, a column or a
  [list](list.md), in the middle of the gap between them, which it does
  not widen: give the node a `gap()` at least as wide.
- `$c->box($w, $h, $rgb)` is a plain rectangle, as a swatch or a
  placeholder.
- `$c->grid()` lays its children out in columns and rows: see
  [Grid](grid.md). Containers that scroll are [scroll views](scroll.md),
  and [lists](list.md) build only the rows in view.

## Sizes

- `width()`, `height()` and `size()` set sizes; `widthPercent()` and
  `heightPercent()` take a share of the parent; `minWidth()`,
  `maxWidth()`, `minHeight()` and `maxHeight()` bound them, and their
  `Percent` forms by a share of the parent; `fill()`, `fillWidth()` and
  `fillHeight()` take the parent's whole content box. In a
  [grid](grid.md#sizing-children), a child's percentages are of its cell.
- `grow(1)` gives a node the free space along its parent's direction, like
  `flex: 1`: a list that fills the rest of a window, or a spacer that
  pushes the nodes after it to the end. `shrink()`, `basis()` and
  `basisPercent()` work as in CSS; `basis(0)` with `grow(1)` shares the
  room equally and keeps content from widening the parent.
- `aspectRatio()` keeps a node's proportions.

## Spacing

`gap()` spaces the children, `gapX()` and `gapY()` horizontally and
vertically apart; `padding()` and `margin()` take one, two or four values,
as in CSS, and `paddingX()`, `paddingY()`, `marginX()` and `marginY()` two
sides. A margin of `Node::AUTO` takes the free space on its side, as in
CSS: `margin(0, Node::AUTO)` centers a node in a column, and
`margin(0, 0, 0, Node::AUTO)` sends a row's child, and those after it, to
the right.

`paddingStart()` and `paddingEnd()`, `marginStart()` and `marginEnd()`,
and `borderStartWidth()` and `borderEndWidth()` set the side lines start on
and the other one: the left and the right, or the right and the left of a
node laid out [right to left](right-to-left.md), as CSS's logical
properties do. `marginStart(Node::AUTO)` sends a row's child to its end in
either direction.

## Alignment

`justify()` places the children along the direction (`Node::START`,
`CENTER`, `END`, `SPACE_BETWEEN`, `SPACE_AROUND`, `SPACE_EVENLY`),
`alignItems()` across it (`START`, `CENTER`, `END`, `STRETCH`,
`BASELINE`), `alignSelf()` one child; `center()` centers them both ways.
`START` is the left of a row and the top of a column, and the right of a
node laid out [right to left](right-to-left.md), which lays a row out from
there. `wrap($width)` wraps a row onto more lines, which `wrapReverse()`
stacks upward, and `alignContent()` places those lines (as `justify()`
places children, or `STRETCH`).

`BASELINE` lines up the first lines of text of a row's children, as a large
price beside its small unit, and the row is as high as that takes. The
baseline of a box is that of its first child (in a row, of its first child
aligned by its baseline), and a child without text lines up its bottom, as
in CSS:

```php
$c->row(gap: 4)->alignItems(Node::BASELINE);
  $c->text('$42')->fontSize(40);
  $c->text('/month')->textColor($c->theme->textMuted);
$c->end();
```

## Positioning

`absolute()` takes a node out of the flow, placed with `top()`, `right()`,
`bottom()` and `left()` in its parent, in points or with `topPercent()`
and the like; automatic margins center it between the sides it is placed
from. As in CSS, they are measured from just inside the parent's border,
whatever its padding: in a box with a border 1 point wide, `top(0)` is 1
point below the box's top edge. `start()` and `end()` (and
`startPercent()`, `endPercent()`) place it from the side lines start on and
from the other, which are the right and the left
[right to left](right-to-left.md). On a node in the flow, `top()` and the
others move it from where the layout put it, without moving its siblings,
as CSS's relative positioning does: a badge raised a little above its
text. `at($x, $y)` places a node at a point of its parent.

```php
$bell = SVG::fromFile('icons/clock.svg');
$view = function (Context $c) use ($bell) {
    $c->column()->size(40, 40);
      $c->icon($bell, 24);
      $c->badge('3')->absolute()->top(-4)->right(-6);
    $c->end();
};
```

`attach($at, $self)` takes a node out of the flow too, and puts a point of
it (`$self`) on a point of its parent's box (`$at`): a corner, the middle
of a side or the center, as `Anchor::TopRight` or `Anchor::Center`, or one
on the side lines start or end on, as `Anchor::TopEnd`, which is on the
left right to left. The node keeps its own size, and `top()`, `right()`,
`bottom()` and `left()` move it from there. `attachTo($node, $at, $self)`
attaches it to another node built this frame:

```php
$c->column();
  $c->avatar('Sample User');
  // Centered on the avatar's top right corner, whatever its size.
  $c->badge('3')->attach(Anchor::TopRight, Anchor::Center);
$c->end();
$c->column()->fill();
  $c->primaryButton('New')->attach(Anchor::BottomRight, Anchor::BottomRight)->right(16)->bottom(16);
$c->end();
```

## Clipping and visibility

- `clip()` cuts its children to its rounded box, inside its border, which
  it draws over them; `clipX()` and `clipY()` clip only on the sides or
  above and below.
- `invisible()` hides a node and its children, which keep their room but
  draw nothing and take neither the pointer nor the focus, as CSS's
  `visibility: hidden`.
- `hidden(true)` hides them and takes their room away too, as CSS's
  `display: none`: the siblings close up, with no gap left for it, and
  assistive technology does not read it. Unlike not building the node, it
  keeps the state of what is inside it, a scroll offset, text being
  edited, an animation, for when `hidden(false)` shows it again. The focus
  leaves it as it hides, and what is attached to it (`attachTo()`) hides
  with it.

  ```php
  $app = new class { public bool $showDetails = false; public TextState $notes; public function __construct() { $this->notes = new TextState(); } };
  $view = function (Context $c) use ($app) {
      $c->column()->hidden(!$app->showDetails);
        $c->textarea($app->notes);
      $c->end();
  };
  ```
- `debug()` outlines a node and everything inside it, with their padding
  and margins, to see why the layout is what it is. The
  [inspector](../inspector.md) shows the boxes of every node, and why.
- Nodes move, resize and recolor smoothly with a
  [transition](transitions.md).
