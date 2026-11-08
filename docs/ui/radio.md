# Radio

A `Picker` with `->pickerStyle(PickerStyle::radioGroup)` shows its choices
as radio buttons, each a `Text` tagged with the value it chooses into the
selection (bound by reference): a click selects one, as does Space while it
has the keyboard focus. The value can be of any type that compares with
`===`. The group is one stop of Tab: Tab goes to the one chosen, and the
arrows choose among them, as do Home and End.

```php
$app = new class { public string $size = 'Medium'; };
$view = fn() => Picker('Size', selection: $app->size)(
    Each(['Small', 'Medium', 'Large'])(fn(string $s) => Text($s)->tag($s)),
)->pickerStyle(PickerStyle::radioGroup)->padding();
```

The buttons are a column; `->horizontalRadioGroupLayout()` makes them a
row, as SwiftUI's:

```php
$app = new class { public string $align = 'left'; };
$view = fn() => Picker('Alignment', selection: $app->align)(
    Text('Left')->tag('left'), Text('Center')->tag('center'), Text('Right')->tag('right'),
)->pickerStyle(PickerStyle::radioGroup)->horizontalRadioGroupLayout()->padding();
```

For a choice among many, a pop-up [select](select.md) or a
[combobox](combobox.md); between views, a [segmented control](segmented.md).

## Accessibility

Assistive technology sees a radio group named by its title, of radio
buttons named by their labels, on for the one chosen.
