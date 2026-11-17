# Right-to-left layout (RTL)

Views lay out from right to left automatically when the app's locale (`App::locale()`) is an RTL language such as Arabic (`ar`), Hebrew (`he`), Persian (`fa`), or Urdu (`ur`), as SwiftUI's do:

- `HorizontalAlignment::leading` and `Edge::leading` refer to the **right** edge, and `trailing` to the **left**.
- `HStack`, `LazyHStack`, `Grid`, `Form`, `ListView`, `Table`, and `NavigationSplitView` reverse their horizontal flow so the first item starts at the right.
- Navigation bars, back chevrons, disclosure arrows, switches, sliders, and progress bars mirror automatically.

Use `->environment(layoutDirection: LayoutDirection::rightToLeft)` (or `leftToRight`) to override the layout direction for a specific subtree, such as keeping a phone number or code snippet left-to-right inside an Arabic interface:

```php
$view = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
    Text('مرحبا'),
    HStack()(Text(verbatim: '+1 555 0100'))
        ->environment(layoutDirection: LayoutDirection::leftToRight),
)->environment(layoutDirection: LayoutDirection::rightToLeft)->padding();
```

## Bidirectional text and pseudo-locales

CoreText, DirectWrite, and Pango shape mixed bidirectional paragraphs (such as English words or numbers inside Arabic sentences) using the Unicode Bidirectional Algorithm, with split carets and visual arrow-key navigation at direction boundaries.

To test your app's RTL layout without switching system languages, pass `I18n::PSEUDO_BIDI` (`ar-XB`) to `App::setLocale()`, or run `myphp dev --lang=ar-XB`.
