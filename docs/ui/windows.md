# Windows with native UI

A native UI window mounts a declarative view file with `Content::file(__DIR__ . '/view.php', $state)` (which supports instant stateful hot reload in `myphp dev` and `myphp run`), or an inline closure with `Content::view(fn() => …)`.

<!-- no-test -->
```php
new Window(new WindowOptions(
    title: 'Notes',
    width: 900,
    height: 600,
    stateKey: 'main',
    titleBarStyle: 'hiddenInset',
    vibrancy: 'sidebar',
    content: Content::file(__DIR__ . '/view.php', $app),
));
```

## Hidden title bars and `WindowDragGesture`

With `titleBarStyle: 'hidden'` or `'hiddenInset'`, the window's content extends into the title bar area. A `NavigationSplitView` automatically insets its sidebar and toolbar clear of the macOS traffic-light window buttons.

For custom title bars or frameless windows (`frameless: true`), attach `->gesture(WindowDragGesture())` to any view to let the user drag the window by that view (and double-click it to zoom/maximize on desktop):

```php
$app = new class { public int $count = 0; };
$view = fn() => VStack(spacing: 0)(
    HStack()(
        Text('Custom Title Bar')->font(Font::headline),
        Spacer(),
        Button('Count', action: fn() => $app->count++),
    )
        ->padding(12)
        ->background(Color::secondarySystemBackground)
        ->gesture(WindowDragGesture()),
    Divider(),
    Text("Count: {$app->count}")->padding(24),
);
```

See [`examples/titlebar`](../../examples/titlebar) and [`examples/vibrancy`](../../examples/vibrancy).

## Vibrancy and materials

Setting `vibrancy: 'sidebar'` (or `'under-window'`, `'hud'`, `'popover'`, `'mica'` on Windows 11) lets the desktop wallpaper shine through translucent regions of the window, such as the sidebar of a `NavigationSplitView`.

## Interactive zoom (`ZoomableView`)

Wrap content in `ZoomableView(minScale: 1.0, maxScale: 4.0)(…)` to support pinch-to-zoom and double-tap zoom on touchscreens and trackpads.
