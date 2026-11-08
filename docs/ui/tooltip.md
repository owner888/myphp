# Tooltip

`->help()` shows a tip once the pointer rests on a view, or the keyboard
focus comes to it, and describes the view to assistive technology, as
SwiftUI's:

```php
$app = new class { public int $n = 0; };
$view = fn() => Button(action: fn() => $app->n++, label: Image(systemName: 'arrow.clockwise'))
    ->buttonStyle(ButtonStyle::borderless)->accessibilityLabel('Reload')->help('Reload the list')->padding();
```

The tip shows once the pointer has rested on the view for 0.6 seconds, and
at once as the keyboard focus comes to it from the keyboard. It goes above
the view, centered, or below it where there is no room above. Within 0.4
seconds of a tip going, the next one shows at once, as the pointer moves
along a toolbar. It goes as the pointer leaves, and a click or Escape hides
it until the pointer leaves and comes back. Over views inside one another,
the innermost one's tip shows. Give one to buttons showing only an icon,
and to what a title alone does not explain. A phone shows none.

The tip takes the theme's inverse colors, its text and background turned
over (see [themes](styling.md#themes)).
