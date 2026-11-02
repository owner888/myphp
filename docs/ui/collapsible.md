# Collapsible

`$c->collapsible()` creates a disclosure, as SwiftUI's `DisclosureGroup`: a
label beside an arrow, which a click opens and closes, as do Enter and
Space, showing below it what its function builds while a `bool` (by
reference) is true. The arrow turns and the content grows into view as it
opens. `$c->changed()` reports a click.

```php
$app = new class { public bool $advanced = true; public bool $verbose = false; public bool $experimental = false; };
$view = function (Context $c) use ($app) {
    $c->collapsible('Advanced', $app->advanced, function () use ($c, $app) {
        $c->checkbox('Verbose logging', $app->verbose);
        $c->checkbox('Experimental features', $app->experimental);
    });
};
```

With less motion asked of the desktop, the content shows at once.

For several sections in a box, use an [accordion](accordion.md).

## Without a look

`$c->collapsibleBase()` is a collapsible without a look: a `trigger`, built
in until `end()`, that shows and hides what `panel()` builds, growing and
shrinking it with `progress()`, which goes from 0 closed to 1 open, for a
look and a motion of your own:

```php
$chevron = SVG::fromFile('icons/chevron.svg'); // parse once, not in the view
$app = new class { public bool $open = false; public string $details = 'Made with MyPHP.'; };
$view = function (Context $c) use ($app, $chevron) {
    $p = $c->collapsibleBase($app->open);
    $p->trigger->gap(6);
      $c->icon($chevron)->rotate(90 * $p->progress());
      $c->text('Details');
    $c->end();
    $p->panel(function () use ($c, $app) {
        $c->text($app->details);
    });
};
```

See [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a disclosure named by its label, expanded or
collapsed, which it opens and closes as a click does.
