# Combobox

A `ComboBox` edits a string (bound by reference) that is one of its
`items:` or anything typed, as AppKit's NSComboBox: a field whose text
filters the items below it as the user types, those starting with it first,
while a click or Down shows them all. Up and Down move among them and Enter
chooses, as does a click; Escape closes them. SwiftUI has none: it is
myphp's own.

```php
$app = new class { public string $fruit = 'Apple'; };
$view = fn() => Form()(
    ComboBox('Fruit', text: $app->fruit, items: ['Apple', 'Apricot', 'Banana', 'Cherry'], prompt: 'Choose a fruit'),
);
```

The field keeps the keyboard focus while the user types and picks. For a
text that may be anything, with suggestions, a field with
[suggestions](autocomplete.md); for a short list without typing, a
[picker](select.md).

## Accessibility

Assistive technology sees a combo box named by its title, editable as a
text field.
