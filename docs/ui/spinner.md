# Spinner

`ProgressView()` without a value is an indicator of work of unknown length,
as AppKit's spinning progress indicator: spokes turning in the muted text
color; with a title, the title beside it.

```php
$app = new class { public bool $loading = true; };
$view = fn() => VStack(spacing: 12)(
    $app->loading ? ProgressView() : Text('Loaded'),
    ProgressView('Searching…'),
)->padding();
```

It goes on turning when the desktop asks for less motion, as the system's
spinners do: it shows that something is going on. It turns only while it
is in view, and costs only its painting.

## Accessibility

Assistive technology sees a progress indicator of unknown length, named by
its title.
