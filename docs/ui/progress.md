# Progress bar

`$c->progress()` creates a progress bar filled to a value between 0 and 1;
a negative value shows activity of unknown length, a segment sliding
across.

```php
$app = new class { public int $done = 3; public int $total = 10; };
$view = function (Context $c) use ($app) {
    $c->progress($app->done / $app->total)->label('Uploading');
    $c->progress(-1)->label('Connecting');
};
```

`reverse()` fills it from the right, for interfaces laid out from right to
left. It is 160 points wide; the second argument gives it another width.

A bar of unknown length moves only while it is in view, and costs only its
painting: the window paints the last frame again at the display's rate,
without running the view.

For a spinning indicator that takes less room, use a
[spinner](spinner.md); for a level that is no progress, as a disk's use, a
[meter](meter.md).

## Accessibility

Assistive technology sees a progress indicator of the value, or of unknown
length, named by its `label()`.
