# Combobox

`$c->combobox()` creates a combobox choosing a string (by reference) among
options: a field showing the choice, whose text filters the options below
it as the user types, those starting with it first, while a click or Down
shows them all. Up and Down move among them and Enter chooses, as does a
click; Escape closes them, and the text goes back to the choice when the
field loses the focus. `$c->changed()` reports a new choice.

```php
$app = new class { public string $font = 'Helvetica'; };
$fonts = ['Avenir', 'Helvetica', 'Menlo', 'Times'];
$view = function (Context $c) use ($app, $fonts) {
    $c->combobox($app->font, $fonts, 200, 'Font')->label('Font');
};
```

The field keeps the keyboard focus while the user types and picks. The
third argument is its width (0 for its natural one), the fourth a text
shown in it while its text is empty. `autoFocus()` on the combobox gives
the focus to its input.

For a text that may be anything, with suggestions, use
[autocomplete](autocomplete.md); for a short list without typing, a
[select](select.md).

## Without a look

`$c->comboboxBase($text)` is a combobox without a look over a `TextState`:
its parts' `input` is the text input whose text filters the options,
`popup($fn)` shows them below it while it is open, `item($value)` makes an
option the arrows highlight and Enter or a click chooses, and `chosen()`
tells which: see [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a combo box named by its `label()`, editable as a
text field, which expands; the option the arrows are on has the focus for
it, and reads as which of how many it is.
