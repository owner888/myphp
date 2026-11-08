# Suggestions

`->textInputSuggestions()` on a `TextField` suggests, below it, those of a
list of strings containing what was typed, those starting with it first,
as SwiftUI's: Up and Down move among them and Enter takes one, as does a
click, while Enter on none is the field's `onSubmit`.

```php
$app = new class { public string $city = ''; public int $searches = 0; };
$view = fn() => Form()(
    TextField('City', text: $app->city)
        ->textInputSuggestions(['Berlin', 'Bern', 'Beijing', 'Bergen'])
        ->onSubmit(fn() => $app->searches++),
);
```

Unlike a [combobox](combobox.md), whose items show for a click, the field
keeps what the user typed and suggests only as they type.

## Accessibility

Assistive technology sees a combo box named by its title, editable as a
text field; the suggestion the arrows are on has the focus for it.
