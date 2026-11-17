# Accessibility

Views describe themselves to assistive technology, as SwiftUI's: a `Button` is a button named by its title, a `Toggle` a switch or a check box with its state, a `Slider` a slider with its value, a `Text` its text. `->accessibilityLabel('Close')` names a view without text, as a button of an icon alone, and finds it in [tests](testing.md); `->help()` and `->accessibilityHint()` describe it; `->accessibilityValue()` reports a custom formatted value; and `->accessibilityHidden(true)` hides decorative views from assistive technology.

VoiceOver on macOS and iOS, TalkBack on Android, Orca on Linux, and Narrator / UIAutomation on Windows read them and act on them as the keyboard and the pointer would.

```php
$app = new class { public int $n = 0; };
$view = fn() => HStack(spacing: 8)(
    Button(action: fn() => $app->n++, label: Image(systemName: 'plus'))
        ->accessibilityLabel('Add')
        ->accessibilityHint('Adds one item to the list')
        ->help('Add an item'),
    Text('{n} items', n: $app->n)
        ->accessibilityValue("{$app->n} total"),
)->padding();
```

On a phone, VoiceOver and TalkBack move through the views one swipe at a time, as through UIKit's and Android's own: a row of a list is one element named by what it shows, its controls after it; a row's `swipeActions` are its actions (VoiceOver's Actions rotor, TalkBack's actions menu), and a `contextMenu` adds Show Menu, which opens it as a long press does; the menu, a sheet or an alert on top is all they read until it closes, which their dismiss gesture does (a two-finger scrub, Back). Rows a list builds beyond its edges scroll into view as they reach them.
