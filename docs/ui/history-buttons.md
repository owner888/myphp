# Back and forward buttons

`$c->backButton()` and `$c->forwardButton()` create buttons going back and
forward in a [router](navigation.md)'s history, as in the toolbar of Finder
or a browser: disabled at the ends of the history, and showing a chevron.
Give them the router, a `Router` in your state:

```php
$router = new Router('/');
$view = function (Context $c) use ($router) {
    $c->toolbar(function () use ($c, $router) {
        $c->backButton($router);
        $c->forwardButton($router);
        $c->text($router->title())->bold();
    });
};
```

A right click lists the pages to go back or forward to, nearest first, by
their titles (a route's `title`), or their paths without one; choosing one
goes there at once.

The buttons are a shortcut for what the keys already do: Cmd+[ and Cmd+]
on macOS, Alt+Left and Alt+Right elsewhere, and the side buttons of a
mouse.

## Accessibility

Assistive technology sees buttons named "Back" and "Forward", unavailable at
the ends of the history.
