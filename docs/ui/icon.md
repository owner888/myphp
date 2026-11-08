# Icon

`Image(systemName:)` shows one of the system's symbols as an icon, in the
color and size of the text around it: SF Symbols on Apple platforms, the
GTK icon theme's on Linux, Material Symbols on Android. `Label` puts one
before a title, as SwiftUI's:

```php
$view = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
    Image(systemName: 'star.fill')->foregroundStyle(Color::yellow),
    Label('Favorites', systemImage: 'star'),
    Label('Settings', systemImage: 'gearshape')->font(Font::title),
)->padding();
```

An SVG of an icon set (Lucide, Heroicons, Tabler…) is `Image($svg)`,
parsed once with `SVG::fromFile()`.

## In buttons

`Button('Save', action: …, systemImage: 'square.and.arrow.down')` is a
button of an icon and a title; `Button(action: …, label:
Image(systemName: 'plus'))` one of the icon alone, which needs an
`accessibilityLabel` ([button](button.md)). `->rotationEffect()` turns an
icon, as an arrow that opens.

## Accessibility

Icons are decorations that assistive technology does not see, unless
`accessibilityLabel()` names them: name the button showing only an icon
instead.
