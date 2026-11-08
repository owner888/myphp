# Avatar

`Avatar($name, $image)` shows a person or a thing: its image cropped to a
circle, or, without one, the initials of its name on a color picked from
the name, so that each person keeps theirs. SwiftUI has none: it is myphp's
own.

```php
$view = fn() => HStack(spacing: 12)(
    Avatar('Ada Lovelace'),
    Avatar('Grace Hopper'),
    Text('Ada and Grace'),
)->padding();
```

## Accessibility

Assistive technology sees an image named by the name.
