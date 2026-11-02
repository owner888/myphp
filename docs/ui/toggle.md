# Toggle

`$c->toggle()` creates a button that stays pressed while a `bool` (by
reference) is true, as Bold in an editor's toolbar: a click presses it or
lets it go, as does Space while it has the keyboard focus, and
`$c->changed()` reports a change.

```php
$app = new class { public bool $inspector = false; };
$view = function (Context $c) use ($app) {
    $c->toggle($app->inspector, 'Inspector');
};
```

## Toggle groups

`$c->toggleGroup()` draws the toggles and buttons inside it joined, as the
segments of one control, as Bold, Italic and Underline. They are one stop
of Tab, among which the arrows move the focus, as in a
[toolbar](toolbar.md):

```php
$app = new class { public bool $bold = false; public bool $italic = false; public bool $underline = false; };
$view = function (Context $c) use ($app) {
    $c->toggleGroup(function () use ($c, $app) {
        $c->toggle($app->bold, 'B');
        $c->toggle($app->italic, 'I');
        $c->toggle($app->underline, 'U');
    })->label('Style');
};
```

For a choice of one among several, as a view mode, use a
[segmented control](segmented.md).

## Without a look

`$c->toggleBase()` is a toggle without a look: a row, built in until
`end()`, that turns its `bool` over when clicked, which takes other
content, as an icon: see [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a toggle button, pressed while on, and a toggle
group as a group named by its `label()`.
