# Gauge

A `Gauge` shows a value between two bounds as a level, as AppKit's level
indicator and SwiftUI's gauge; its title leads it in a `Form`:

```php
$app = new class { public float $used = 182; };
$view = fn() => Form()(
    Gauge(value: $app->used, in: [0, 256], title: 'Disk'),
    Gauge(value: 0.9, title: 'Memory')->tint(Color::orange),
);
```

`->tint()` colors it, as a warning. For work going on, a
[progress bar](progress.md).

## Accessibility

Assistive technology sees a level indicator of the value, named by its
title.
