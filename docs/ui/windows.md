# Windows with native UI

A window's view is `Content::file(__DIR__ . '/view.php', $app)`, or
`Content::view(fn() => …)` for a small one; the window takes the [window
options](../windows.md#options) of any window. With `titleBarStyle:
'hidden'` or `'hiddenInset'` the content goes under the title bar, a
`NavigationSplitView`'s sidebar and toolbar placing themselves around the
window controls, as SwiftUI's on a Mac; `->gesture(WindowDragGesture())`
makes a view drag the window, as SwiftUI's. `vibrancy:` shows the
desktop's material through the window, `'sidebar'` under a sidebar.

<!-- no-test -->
```php
new Window(new WindowOptions(title: 'Notes', width: 900, height: 600, stateKey: 'main',
    titleBarStyle: 'hiddenInset', vibrancy: 'sidebar',
    content: Content::file(__DIR__ . '/view.php', $app)));
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](custom-widgets.md)).

A window with `content` takes the [window options](../windows.md#options)
of any window, such as its size, `stateKey`, `frameless` and `parent`, as
well as menus, dialogs and the other [desktop APIs](../native.md). It has
no page: `page()` is null, and it ignores `url` and `html`. Its PHP code
needs no bindings: the view calls it directly. `capturePage()` returns a
PNG of what it shows.

<!-- no-test -->
```php
new Window(new WindowOptions(
    title: 'Notes',
    width: 900,
    height: 600,
    stateKey: 'main',
    content: Content::view(fn(Context $c) => $app->view($c)),
));
```

## Title bars

With `titleBarStyle: 'hidden'`, the view draws the title bar under the
window controls, as a page does with the `--myphp-titlebar-*` CSS
variables: `$c->titleBar()` returns the room the controls take (`height`,
`left`, `right`), zero in full screen, and `dragWindow()` makes nodes drag
the window, which double-clicking them zooms or minimizes as a title bar
would:

```php
$bar = $c->titleBar();
$c->row()->height(max($bar['height'], 32))->padding(0, $bar['right'] + 12, 0, $bar['left'] + 12)->dragWindow();
  $c->text('Inbox')->bold();
$c->end();
```

In a `frameless` window, `dragWindow()` makes a node move the window, and a
double click on it maximizes the window.

To draw the window controls too, where the view wants them, add
`hideWindowButtons: true` to the hidden title bar: the system's controls
go, `$c->titleBar()` is zero, and the window keeps its title bar's edges,
which resize it, the top one included. The view's buttons call
`minimize()`, `maximize()`, `unmaximize()` and `close()` on the window.

## Zoom

`$c->setZoom($z)` zooms the window's content, as a browser zooms a page:
the view is laid out in a window `1/z` its size and shown `z` times
larger. It is not a picture of the window scaled up: frames draw at the
screen's resolution times `z`, so text, icons, vectors, images and their
edges stay as sharp as at that size natively, and the window's frames keep
their size in pixels. Unzoomed, nothing changes. The zoom stays until set
again, between 0.25 and 5; `$c->zoom()` returns it, 1 for none.

```php
$app = new class { public float $zoom = 1.25; };
$view = function (Context $c) use ($app) {
    $c->setZoom($app->zoom); // 1.25 for 125%, every frame or once
    [$width, $height] = $c->size(); // the content's: the window's divided by the zoom
    $c->text(sprintf('%.0f × %.0f', $width, $height));
};
```

What the view sees is zoomed: `$c->size()`, `$c->titleBar()`,
`$c->bounds()` and every length are in the content's points, `z` of them to
the window's. A frame that sets another zoom builds again at the new size
before it shows. What leaves the content goes to the window in its points:
the pointer hits what the zoom shows under it, input methods compose at
the caret, assistive technology outlines nodes where they show, context
menus and drags start under the pointer, and web views take the box the
content gives them. The window controls keep their size: `$c->titleBar()`
is the room they take, zoomed, so that a title bar laid out clear of them
stays clear.

A transform's `scale()`, by contrast, scales what is drawn at the
content's own size: its text is drawn as bitmaps of their untransformed
size, sampled bilinearly ([Transforms](transforms.md#rendering)), right
for motion, blurry for zooming a window.

## Vibrancy

A window's `vibrancy` shows wherever its native UI draws no background, on
macOS. `$c->vibrancy()` tells whether it shows: elsewhere, what the view
leaves transparent shows no material, so draw a background there instead.
The root draws the theme's by default: make it transparent and give
backgrounds to the parts that need one, as a sidebar beside opaque content
does:

```php
$view = function (Context $c) {
    $t = $c->theme;
    if ($c->vibrancy()) {
        $c->root->color = null;
    }
    $c->row()->fill()->alignItems(Node::STRETCH);
      $sidebar = $c->column()->width(240);
      if (!$c->vibrancy()) {
          $sidebar->background($t->surface);
      }
        $c->text('Sidebar'); // over the material
      $c->end();
      $c->column(grow: 1)->background($t->background);
        $c->text('Content');
      $c->end();
    $c->end();
};
```

Text over an opaque background keeps the system's antialiasing under a
transparent root; over the material, it is antialiased in grayscale.
`examples/vibrancy` picks the window's material in such a sidebar.

The [glass plugin](../plugins/glass.md) draws Liquid Glass inside the
window, with what the window itself draws: a toolbar over content
scrolling under it.

## Closing

A view can close its own window, from a button's click or its callback.
The window goes at once, by `destroy()` or by a `close()` no `close`
listener prevents, and the rest of the view builds as it would, with a
`Context` that still has the theme and the window's size. The frame ends
there: nothing more is laid out or painted, and no other callback runs.
Open the window that takes over first, since an app [quits](../app.md)
when its last window closes:

<!-- no-test -->
```php
if ($c->clicked($c->button('Done'))) {
    $app->showMain();
    $win->close();
}
```

## No webview

An app whose windows all show native UI needs no webview: on Linux it
needs GTK 3 alone, not WebKitGTK.
