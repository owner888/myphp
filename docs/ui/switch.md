# Switch

`$c->switch()` creates a switch turning a `bool` (by reference) on and off,
for a setting that applies at once: a click turns it over, as does Space
while it has the keyboard focus, and its knob slides across.
`$c->changed()` reports a click.

```php
$app = new class { public bool $wifi = true; };
$view = function (Context $c) use ($app) {
    $c->row(gap: 12);
      $c->text('Wi-Fi')->grow(1);
      $c->switch('', $app->wifi)->label('Wi-Fi');
    $c->end();
};
```

Its first argument is a label shown beside it; with an empty one it shows
no text of its own: give it a `label()` for assistive technology, or put it
in a [field](form.md), whose label names it and toggles it when clicked:

```php
$app = new class { public bool $sound = false; };
$view = function (Context $c) use ($app) {
    $c->form(function () use ($c, $app) {
        $c->field('Sound', fn() => $c->switch('', $app->sound));
    });
};
```

For a choice that applies when a form is saved, use a
[check box](checkbox.md).

## Without a look

`$c->switchBase()` is a switch without a look, which you draw from the
value: see [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a switch, on or off, named by its label or its
field.
