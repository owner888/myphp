# Spinner

`$c->spinner()` creates an indicator of work of unknown length, as AppKit's
spinning progress indicator: spokes turning, in the theme's muted text
color, a little higher than the theme's font size (1.25 times it).

```php
$c->row(gap: 8);
  $c->spinner()->label('Loading');
  $c->text('Loading messages…');
$c->end();
```

`size()` makes it larger, as `size(32, 32)` in an empty view; `textColor()`
gives its spokes another color, as on a chosen row. It goes on turning when
the desktop asks for less motion, as the system's spinners do: it shows
that something is going on.

It turns only while it is in view, and costs only its painting: its spokes
move twelve times a turn, and each time the window paints the last frame
again without running the view.

## Accessibility

Assistive technology sees a progress indicator of unknown length, named by
its `label()`.
