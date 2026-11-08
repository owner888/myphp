# Toggle

A `Toggle` with `->toggleStyle(ToggleStyle::button)` is a button that stays
pressed while a `bool` (bound by reference) is true, as Bold in an editor's
toolbar: a click presses it or lets it go, as does Space while it has the
keyboard focus.

```php
$app = new class { public bool $bold = false; public bool $italic = false; };
$view = fn() => HStack(spacing: 4)(
    Toggle('Bold', isOn: $app->bold)->toggleStyle(ToggleStyle::button),
    Toggle('Italic', isOn: $app->italic)->toggleStyle(ToggleStyle::button),
)->padding();
```

In a page's `toolbar()` the toggles are one stop of Tab, among which the
arrows move the focus ([toolbar](toolbar.md)). For a choice of one among
several, as a view mode, use a [segmented control](segmented.md).

## Accessibility

Assistive technology sees a toggle button, pressed while on, named by its
title.
