# Glass

In a view, `->glassEffect(new Glass())` puts a view on MyPHP's Liquid Glass material, as SwiftUI's `glassEffect()`; `Glass(Style::Clear)` is the clear variant:

```php
use MyPHP\Plugins\Glass\{Glass, Style};

$view = fn() => Text('On glass')
    ->padding()
    ->glassEffect(new Glass(style: Style::Regular, interactive: true))
    ->padding();
```

## Styles and options

- **`Style::Regular`** (the default) frosts what shows through and adapts its tone to light or dark mode so text and icons remain legible over any background, with optical refraction and a hairline rim light along the edges.
- **`Style::Clear`** has high translucency, suited for overlays above photos or video where the foreground brings its own contrast.
- **`tint:`** colors the glass toward a tint color, as a prominent glass button: `->glassEffect(new Glass(interactive: true), tint: Color::accentColor)`.
- **`interactive: true`** gives the glass subtle elastic expansion and rim brightening while pressed or hovered.
- **`clarity`** (`0.0` to `1.0`) makes large panes such as sidebars and inspectors more transparent while keeping their edge refraction.

## Scroll edges and progressive blur

`ScrollEdge` and `Blur` are materials for bars floating over scrolling content (`ZStack`), fading or progressively blurring content as it scrolls underneath:

```php
use MyPHP\Plugins\Glass\{Glass, ScrollEdge};

$view = fn() => ZStack(alignment: Alignment::top)(
    ScrollView()(
        VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
            Each(range(1, 20))(fn(int $i) => Text("Row $i")),
        )->padding(Edge::top, 64)->padding(Edge::horizontal, 16),
    ),
    HStack()(Text('Toolbar')->bold(), Spacer())
        ->padding(12)
        ->frame(maxWidth: INF, height: 56)
        ->glassEffect(new ScrollEdge()),
)->frame(width: 360, height: 240);
```

See [`examples/glass`](../../examples/glass) for a complete interactive demonstration.
