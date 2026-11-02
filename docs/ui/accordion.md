# Accordion

`$c->accordion()` creates sections, one above the other in a bordered box,
that `$c->accordionItem()` builds: a header showing a title, which a click
opens and closes, as do Enter and Space, and below it what its function
builds while its `bool` (by reference) is true. Each opens and closes on
its own; Up and Down move the focus between their headers, as do Home and
End. `$c->changed()` on an item reports a click.

```php
$app = new class { public bool $general = true; public bool $privacy = false; public bool $advanced = false; };
$view = function (Context $c) use ($app) {
    $c->accordion(function () use ($c, $app) {
        $c->accordionItem('General', $app->general, fn() => $c->text('General settings'));
        $c->accordionItem('Privacy', $app->privacy, fn() => $c->text('Privacy settings'));
        $c->accordionItem('Advanced', $app->advanced, fn() => $c->text('Advanced settings'));
    });
};
```

For one section open at a time, close the others as one opens:

```php
$app = new class { public int $section = 0; };
$sections = ['General', 'Privacy', 'Advanced'];
$view = function (Context $c) use ($app, $sections) {
    $c->accordion(function () use ($c, $app, $sections) {
        foreach ($sections as $i => $title) {
            $open = $app->section === $i;
            $item = $c->accordionItem($title, $open, fn() => $c->text("$title settings"));
            if ($c->changed($item)) {
                $app->section = $open ? $i : -1;
            }
        }
    });
};
```

Tab goes from a header into the content of its section while it is open.

## Accessibility

Assistive technology sees each header as a disclosure, expanded or
collapsed, before the content of its section.
