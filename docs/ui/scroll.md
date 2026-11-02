# Scroll view

`$c->scroll()` scrolls its children up and down with the wheel, the
touchpad, its scroll bar and the keyboard: the arrow keys, Page Up and
Page Down, Space, Home and End scroll the container around the focus, or
under the pointer, unless the focused node takes those keys, as a text
input does. `$c->scrollBoth()` scrolls both ways, as a canvas or a wide
table does. A notch of a mouse wheel glides over a short ease out, as
browsers scroll it, and notches in a row add up, until the app scrolls the
container (a `ScrollState`'s `scrollTo()`, a `ListState`'s `scrollTo()`,
`scrollIntoView()` and `scrollToEnd()`, or `scrollIntoView()` of a node in
it), which takes over; a touchpad follows the fingers, and while the
desktop asks for less motion, a notch scrolls at once.

```php
$posts = array_map(fn($i) => "Post $i", range(1, 30));
$view = function (Context $c) use ($posts) {
    $c->scroll(16, 12, 1);
      foreach ($posts as $p) {
          $c->text($p);
      }
    $c->end();
};
```

`scroll($pad, $gap, $grow, $color)` takes a column's arguments; `end()`
closes it. Give it a size, or grow it in its parent. For the rows of a
collection, use a [list](list.md), which builds only those in view.

Laid out [right to left](right-to-left.md), a container scrolling sideways
shows the start of its content, at the right, first, and `ScrollState::$x`
counts from there: 0 is the start and `maxX` the end, as Flutter's offsets
are.

## Keeping the offset

A scroll container keeps its offset as it keeps its other state, by its
place in the view or its key. To read the offset, set it or keep it in the
app's state, make any container scroll with a `ScrollState` of yours:
`->scroll($state, horizontal: false, vertical: true)`. The container shows
its content from the state's `x` and `y`, writes them as the user scrolls,
and sets `maxX` and `maxY` to how far they go. `y = 0` scrolls to the top
and `INF` to the end, and a `ScrollState` for each page a container shows
keeps each page's place. A log that follows its end, unless the user
scrolled up from it:

```php
$app = new class {
    public ScrollState $log;
    public array $lines = ['started', 'listening on :8080'];
    public function __construct() { $this->log = new ScrollState(); }
};
$view = function (Context $c) use ($app) {
    if ($app->log->y >= $app->log->maxY) {
        $app->log->y = INF;
    }
    $c->column()->scroll($app->log)->grow(1);
      foreach ($app->lines as $line) {
          $c->text($line)->font('monospace');
      }
    $c->end();
};
```

`scrollTo($y, $animation)` scrolls there smoothly, as a spring or a curve
(see [Motion](motion.md)), turning toward a new place if called again on
the way; the user scrolling stops it, and `moving()` reports whether it is
on its way. Where the desktop asks for less motion, it scrolls at once:

```php
$feed = new ScrollState();
$view = function (Context $c) use ($feed) {
    if ($c->clicked($c->button('Back to top'))) {
        $feed->scrollTo(0, Animation::smooth());
    }
    $c->column()->scroll($feed)->grow(1);
      $c->text('…');
    $c->end();
};
```

## Scrolling into view

`scrollIntoView()` scrolls the containers around a node as little as shows
it, once the frame is laid out, so that it works in the frame that adds
the node: a new message, or the item the keys chose. Call it in that frame
only, or the user could not scroll the node away. Rows a list has not
built scroll into view with its `ListState`.

```php
$app = new class { public bool $added = true; };
$view = function (Context $c) use ($app) {
    if ($app->added) {
        $c->text('New message')->scrollIntoView();
        $app->added = false;
    }
};
```

The keyboard focus scrolls into view as it moves. A node with a
`TextInputClient` of the app's ([text input
clients](text-input-client.md)) scrolls its caret into view rather than
its whole box: a code editor as high as its lines shows the line being
edited as it takes the focus, not its first.

## Scroll bars

A scroll container whose content overflows it shows the thumbs of its
scroll bars over its content while the pointer is over it, which the user
drags; they take no room. Right to left, the vertical bar is on the left.
The theme's `scrollbarWidth` sets their width and its `scrollbar` their
color; `scrollbars(false)` hides them.

`scrollbarInsets()` moves the bars in from the container's edges, CSS
style as `padding()`: the vertical bar runs from the top inset to the
bottom one, the right inset in from the right, and the horizontal bar from
the left inset to the right one, the bottom inset up. So the bars keep
clear of what floats over the content, as a toolbar it scrolls under, as
AppKit's and UIKit's do:

```php
$c->column()->fill();
  $c->scroll()->fill()->padding(64, 16, 16)->scrollbarInsets(64, 0, 0);
    $c->text('Content');
  $c->end();
  // The toolbar floats over the top 64 points.
  $c->row()->absolute()->top(0)->left(0)->right(0)->height(64);
  $c->end();
$c->end();
```

A node that scrolls itself rather than through a container, as the code
editor and the terminal do, draws its bars with `Scrollbar`, so that they
look as the containers' do and follow the theme: `Painter::scrollbar()`
paints the thumb, and the bar maps a drag of it to an offset. Show it as a
container does, while the pointer is over the node or drags the thumb:

```php
$content = 2000.0; $scrollY = 120.0;
$view = function (Context $c) use ($content, $scrollY) {
    $box = $c->column()->fill()->draw(function (Painter $p, array $r) use ($content, $scrollY) {
        $bar = new Scrollbar(box: $r, view: $r[3], content: $content, offset: $scrollY);
        $p->scrollbar($bar, new ScrollbarState(dragging: false));
    });
    $c->end();
};
```

A node inside that zooms or pans with the wheel, the touchpad and pinches,
as a chart, takes them from the container: see
[Scrolling and pinching](input.md#scrolling-and-pinching).

## Accessibility

Assistive technology sees a scroll area, which it scrolls to show what it
reads.
