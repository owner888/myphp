# Segmented control

A `Picker` with `->pickerStyle(PickerStyle::segmented)` is a segmented
control: its choices, each a `Text` (or `Label`) tagged with the value it
chooses, and the value bound by reference, as a switch between views. A
click chooses a segment, as do the arrows while it has the keyboard focus.

```php
$app = new class { public string $view = 'list'; };
$view = fn() => VStack(spacing: 12)(
    Picker('View', selection: $app->view)(
        Text('List')->tag('list'),
        Text('Grid')->tag('grid'),
    )->pickerStyle(PickerStyle::segmented)->labelsHidden(),
    $app->view === 'list' ? Text('The list') : Text('The grid'),
)->padding();
```

It is one stop of Tab, which goes to the segment chosen. A `Label` with a
`systemImage:` makes a segment of an icon. Under a search field the scopes
of `searchScopes()` are one ([search field](search-field.md)).

## Accessibility

Assistive technology sees a radio group, named by its title, of radio
buttons named by their labels.
