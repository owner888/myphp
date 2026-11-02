# Autocomplete

`$c->autocomplete()` creates a text input editing a `TextState` that
suggests, below it, those of a list of strings containing what was typed,
those starting with it first: Up and Down move among them and Enter takes
one, as does a click, while Enter on none is `$c->submitted()`.
`$c->changed()` reports a change, typed or taken.

```php
$city = new TextState();
$cities = ['Amsterdam', 'Berlin', 'Lisbon', 'London', 'Paris'];
$view = function (Context $c) use ($city, $cities) {
    $c->autocomplete($city, $cities, 'City')->label('City');
};
```

The third argument is the placeholder shown while the field is empty, the
fourth its width (200 points by default).

Unlike a [combobox](combobox.md), whose value is one of its options, an
autocomplete keeps what the user typed.

## Accessibility

Assistive technology sees a combo box named by its `label()`, editable as a
text field; the suggestion the arrows are on has the focus for it.
