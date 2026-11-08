# Rating

A `Rating` is a row of stars, as AppKit's rating level indicator, of which
an `int` (bound by reference) are filled: a click on a star sets the
rating, a click on the star set clears it, and the arrows, Home and End
change it while it has the keyboard focus. SwiftUI has none: it is myphp's
own, SwiftUI-shaped.

```php
$app = new class { public int $stars = 3; };
$view = fn() => Form()(
    Rating('Rating', value: $app->stars),
    Rating('Difficulty', value: $app->stars, maximum: 10),
);
```

The stars take their size from the text, and show the rating a click would
set as the pointer rests on them.

## Accessibility

Assistive technology sees a slider from 0 to the number of stars, named by
its title.
