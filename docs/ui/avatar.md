# Avatar

`$c->avatar()` creates a picture of a person or a thing: its image, cropped
to a circle, or, without one, the initials of its name on a color picked
from the name, so that each person keeps theirs. It is 2.25 times the
theme's font size high.

```php
$user = new class { public string $name = 'Sample User'; public ?Image $photo = null; };
$view = function (Context $c) use ($user) {
    $c->row(gap: 8);
      $c->avatar($user->name, $user->photo); // an Image, or null
      $c->text($user->name);
    $c->end();
};
```

## Accessibility

Assistive technology sees an image named by the name.
