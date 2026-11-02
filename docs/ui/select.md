# Select

`$c->select()` creates a drop-down choosing one of a list of labels into an
`int` (by reference), the index of the one chosen: a click on it, or Enter,
Space, Up or Down while it has the keyboard focus, opens its options below
it, where the arrows, Home and End move among them and Enter, Space or a
click chooses one; Escape closes them. `$c->changed()` reports a new
choice.

```php
$app = new class { public int $plan = 0; };
$view = function (Context $c) use ($app) {
    $c->select(['Free', 'Pro', 'Team'], $app->plan)->label('Plan');
};
```

The third argument sets its width, at least `$t->space(35)` by default.
The popup shows above the select where there is no room below it, and
gives the focus back to the select as it closes. With more options than the
window has room for, it is as tall as the room and its options scroll.

For a long list that typing filters, use a [combobox](combobox.md); for a
few options shown at once, [radio buttons](radio.md).

## Without a look

`$c->selectBase()` is a select without a look, choosing a value of any
type into a variable (by reference): a `trigger`, built in until `end()`,
opening a `popup()` of `item()`s, which the arrows highlight
(`highlighted()`) and Enter chooses. `highlight($value)` moves the
highlight to an option, as a select of yours may as the user types its
first letters:

```php
$chevron = SVG::fromFile('icons/chevron.svg');
$sizes = ['Small', 'Medium', 'Large'];
$app = new class { public string $size = 'Medium'; };
$view = function (Context $c) use ($app, $sizes, $chevron) {
    $t = $c->theme;
    $sel = $c->selectBase($app->size);
    $sel->trigger->gap(6)->padding(6, 10)->radius(8)->border(1, $t->border);
      $c->text($app->size);
      $c->icon($chevron);
    $c->end();
    $sel->popup(function (Node $panel) use ($c, $t, $sel, $sizes) {
        $panel->margin(4, 0, 0, 0)->padding(4)->radius(10)->background($t->background)->border(1, $t->border);
        foreach ($sizes as $size) {
            $item = $sel->item($size)->padding(6, 10)->radius(6);
            if ($item->highlighted()) {
                $item->background($t->accent)->textColor($t->accentText);
            }
              $c->text($size);
            $c->end();
        }
    });
};
```

See [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a pop-up button whose value is the choice, named
by its `label()`, and, while it is open, its options, following the one the
arrows are on.
