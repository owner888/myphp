# Split view

`$c->split()` lays out two panes side by side, with a divider between them
that the user drags to resize them, or moves with the arrows once it has
the keyboard focus: a `float` (by reference) is the width of the first,
which the divider keeps 40 points (`$t->space(10)`) from either edge. When
the window leaves less room than that, the first pane gives way so the
second keeps its 40 points, and the first takes its size back when there
is room again; the `float` keeps the size the user chose.
`$c->changed()` reports a move.

```php
$app = new class { public float $sidebarWidth = 200; };
$view = function (Context $c) use ($app) {
    $c->split($app->sidebarWidth, fn() => $c->text('Files'), fn() => $c->text('Editor'))->fill();
};
```

`vertical: true` puts the first above the second, the `float` its height:

```php
$app = new class { public float $consoleHeight = 240; };
$view = function (Context $c) use ($app) {
    $c->split($app->consoleHeight, fn() => $c->text('Editor'), fn() => $c->text('Console'), vertical: true)->grow(1);
};
```

`$c->splitPercent()` takes the percentage of the room the first pane takes
instead, from 0 to 100: both panes then grow and shrink with the window, in
proportion, as a results panel under an editor does.

```php
$app = new class { public float $editorPercent = 70; };
$view = function (Context $c) use ($app) {
    $c->splitPercent($app->editorPercent, fn() => $c->text('Editor'), fn() => $c->text('Results'), vertical: true)->grow(1);
};
```

Keep the size in the app's state, and save it to restore the layout when
the app starts again. The pointer shows a resize cursor over the divider.

## Accessibility

Assistive technology sees the divider as a splitter named "Divider",
which takes the focus and moves with the arrows.
