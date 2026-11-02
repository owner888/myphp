# Meter

`$c->meter()` creates a bar showing a value between two bounds, as AppKit's
level indicator and the web's `meter`: in the theme's `success` color, or
`warning` and `danger` from the levels given, null for none.

```php
$disk = new class { public float $used = 410; public float $size = 500; };
$view = function (Context $c) use ($disk) {
    $size = $disk->size;
    $c->meter($disk->used, 0, $size, new MeterLevels(warning: 0.8 * $size, critical: 0.95 * $size))->label('Disk');
};
```

`MeterLevels` are the levels from which the value shows it is worse: the
`warning` color from `warning`, the `danger` color from `critical`. A
`critical` below `warning` makes low values the bad ones, as a battery's:

```php
$battery = 15.0;
$c->meter($battery, 0, 100, new MeterLevels(warning: 20, critical: 10))->label('Battery');
```

The bar is 160 points wide unless the last argument says otherwise.

For work going on, use a [progress bar](progress.md).

## Accessibility

Assistive technology sees a level indicator of the value, named by its
`label()`.
