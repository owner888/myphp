# Transitions

`$c->transition($node, $t)` makes a node move smoothly where the layout
changes it: from where the last frame showed it to where it is now, in its
parent, at its size, and in its background and border colors. The view
says only where things are; the transition animates the way there.

```php
$app = new class {
    public array $items = [['id' => 1, 'title' => 'Milk'], ['id' => 2, 'title' => 'Bread'], ['id' => 3, 'title' => 'Eggs']];
    public function remove(int $id): void { $this->items = array_values(array_filter($this->items, fn($i) => $i['id'] !== $id)); }
};
$rowMotion = new ElementTransition(
    enter: new TransitionMotion(collapse: true),
    exit: new TransitionMotion(collapse: true),
);
$view = function (Context $c) use ($app, $rowMotion) {
    $c->column();
      $removed = -1;
      foreach ($app->items as $it) {
          $row = $c->key($it['id'])->row(8);
          $c->transition($row, $rowMotion);
            $c->text($it['title'])->grow(1);
            if ($c->clicked($c->button('Remove'))) {
                $removed = $it['id']; // once the loop is done (see Views)
            }
          $c->end();
      }
      if ($removed >= 0) {
          $app->remove($removed);
      }
    $c->end();
};
```

Rows added grow in, rows removed collapse, and the others slide to their
places, whether the items were added, removed, sorted or shuffled.

## What moves

An `ElementTransition` animates over its `duration` (0.2 seconds when
zero), along its `ease` (`Easing::easeOut` when null, or any
[easing](drawing.md#animation)), or as its `animation` says, when set: with
a spring, such as `Animation::snappy()`, a node sent elsewhere on its way
turns smoothly, keeping its velocity (see [Motion](motion.md)):

- **Position**: where the node is in its parent. Moving with its parent,
  as when a scroll view scrolls, is not a change.
- **Size**: its width and height. The content lays out at each size on
  the way, as a panel opening wraps its text anew.
- **Colors**: its background and border colors, as a hover fades in and
  out.

All three animate unless `position`, `size` or `colors` choose some:

```php
$view = function (Context $c) {
    $t = $c->theme;
    $tile = $c->column()->size(64, 48)->radius(8)->background($t->surface);
    $c->end();
    if ($c->hovered($tile)) {
        $tile->background($t->surfaceHover);
    }
    $c->transition($tile, new ElementTransition(colors: true, duration: 0.12));
};
```

The node needs the same ID from frame to frame: a `$c->key()`, or the same
place among its siblings. A transition animates the node alone: the nodes
that a change moves too need a transition of their own to move smoothly,
the same one to move in step, as the rows above, and the column holding
them if its size changes:

```php
$app = new class { public bool $sidebar = true; };
$view = function (Context $c) use ($app) {
    $c->row()->fill();
      $c->transition($c->column()->width($app->sidebar ? 240 : 56)->clipX(), new ElementTransition(size: true));
      $c->end();
      $c->transition($c->column(grow: 1), new ElementTransition());
      $c->end();
    $c->end();
};
```

A `delay` in the `animation` holds changes for a while: delays growing with
the index stagger the rows of a list:

```php
$items = [['id' => 'a'], ['id' => 'b']];
$view = function (Context $c) use ($items) {
    foreach ($items as $i => $it) {
        $a = new Animation(spring: Animation::snappy()->spring, delay: $i * 0.025);
        $c->transition($c->key($it['id'])->row(), new ElementTransition(animation: $a, enter: new TransitionMotion(y: 8)));
          $c->text($it['id']);
        $c->end();
    }
};
```

`$c->transitioning($node)` reports whether the node's transition was
moving it in the frame before.

## Coming and going

`enter` is where a node comes from as it appears, and `exit` where it goes
as it leaves, from its place in the layout: a `TransitionMotion` moved by
`x` and `y`, at an `opacity` (0, transparent, when not set), scaled by
`scale` (0 keeps it at its size) and turned by `rotate` degrees around its
`transformOrigin()`, and with `collapse`, without height in a column or
width in a row, its content clipped.

```php
$toast = new ElementTransition(
    enter: new TransitionMotion(y: 12),
    exit: new TransitionMotion(opacity: 1, x: 300),
);
$popup = new ElementTransition(
    animation: Animation::snappy(),
    enter: new TransitionMotion(scale: 0.95),
    exit: new TransitionMotion(scale: 0.95),
);
```

A node appears with its `enter` only in a parent that was there in the
frame before, so that a page or a dialog showing all at once does not
animate every node in it. While it comes in, the layout moving it, as rows
landing above it, moves it at once where its transition does not animate
(`position`, `size`), and resizing it resizes its `collapse` in proportion:
only its `enter` plays. When a node is no longer built while its parent
still is, a copy of it, as the last frame showed it, goes to its `exit`
under its siblings: it takes no room, so the siblings move into its place
at once, smoothly if they have transitions, and it takes neither the
pointer nor the focus. Built again before its exit ends, the node comes
back from where its copy was.

## Shared elements

`match` gives a node an identity it shares with another, as a photo's
thumbnail in a grid and the photo in its detail view. A node with a
`match` appearing where one with the same `match` showed in the frame
before comes from there, at that one's size and in its colors, even from
another parent, as SwiftUI's `matchedGeometryEffect` and Motion's
`layoutId` do:

```php
$photos = [['id' => 1], ['id' => 2]];
$app = new class { public ?array $open = null; };
$view = function (Context $c) use ($app, $photos) {
    $t = $c->theme;
    if ($app->open === null) {
        $c->row(gap: 8);
          foreach ($photos as $p) {
              $thumb = $c->key($p['id'])->box(64, 64, $t->accent);
              $c->transition($thumb, new ElementTransition(animation: Animation::snappy(), match: 'photo:' . $p['id']));
              if ($c->clicked($thumb)) {
                  $app->open = $p;
              }
          }
        $c->end();
    } else {
        $hero = $c->key('hero')->box(320, 240, $t->accent);
        $c->transition($hero, new ElementTransition(animation: Animation::snappy(), match: 'photo:' . $app->open['id']));
    }
};
```

Give the identity a prefix of your own, as `'photo:'`, so that it is no
other's. The node appearing goes from the other's box to its own, its
content laid out at each size on the way, as `size` lays it out, so
nothing in it stretches, its rounded corners included. Give one node a
`match` at a time; the one gone goes as its own `exit` says, if it has
one.

## When nothing moves

Changes that resizing the window makes do not animate. While the desktop
asks for less motion (`$c->preferences()->reduceMotion`), nothing moves:
nodes show where the layout puts them at once, and shared elements do not
fly. Colors still change smoothly, and an `enter` or `exit` that fades
without collapsing fades in place, as Apple's guidelines suggest; one that
collapses, which moves its siblings, is at once. Frames are drawn only
while something moves.

Rows of a [list](list.md) are placed by the list, which builds only those
in view: their content can have transitions, but the rows do not slide,
come or go with one.
