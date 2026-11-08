# Progress bar

`ProgressView(value:, total:)` shows a bar filled to the value; with a
title, the title over it:

```php
$app = new class { public float $done = 0.4; };
$view = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    ProgressView(value: $app->done),
    ProgressView('Downloading', value: 120, total: 300),
)->padding()->frame(width: 260);
```

Without a value it shows activity of unknown length ([spinner](spinner.md)).
An indicator moves only while it is in view, and costs only its painting:
the window paints the last frame again at the display's rate, without
running the view. For a level that is no progress, as a disk's use, a
[gauge](meter.md).

## Accessibility

Assistive technology sees a progress indicator of the value, or of unknown
length, named by its title.
