# Radio

`$c->radio()` creates a radio button with a label that selects its value
into a variable (by reference): a click selects it, as does Space while it
has the keyboard focus, and `$c->changed()` reports it. The value can be of
any type that compares with `===`.

`$c->radioGroup()` holds radio buttons as one stop of Tab: Tab goes to the
one chosen, and the arrows choose among them, as do Home and End.

```php
$app = new class { public string $size = 'Medium'; };
$view = function (Context $c) use ($app) {
    $c->radioGroup(function () use ($c, $app) {
        foreach (['Small', 'Medium', 'Large'] as $size) {
            $c->radio($size, $app->size, $size);
        }
    })->label('Size');
};
```

A radio group is a column, its buttons `$t->space(2)` apart; make it a row
with `row: true`:

```php
$app = new class { public int $align = Node::START; };
$view = function (Context $c) use ($app) {
    $c->radioGroup(function () use ($c, $app) {
        $c->radio('Left', $app->align, Node::START);
        $c->radio('Center', $app->align, Node::CENTER);
        $c->radio('Right', $app->align, Node::END);
    }, row: true)->gap(16)->label('Alignment');
};
```

For a choice among many, use a [select](select.md) or a
[combobox](combobox.md); between views, a [segmented control](segmented.md).

## Without a look

`$c->radioBase($selected, $value)` is a radio button without a look: a row
that selects its value when clicked: see [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a radio group named by its `label()`, of radio
buttons named by their labels, on for the one chosen.
