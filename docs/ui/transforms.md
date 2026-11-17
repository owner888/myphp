# Visual transforms

`->offset(x:, y:)`, `->scaleEffect($scale)`, `->rotationEffect($degrees)`, and `->rotation3DEffect($degrees, axis: [$x, $y, $z])` transform how a view and its children are drawn while layout reserves its untransformed space, as SwiftUI's modifiers do.

Pointer hit-testing, hover states, text selection, input method candidate windows, popovers, and accessibility bounds automatically follow the inverse transform matrix — including nested 2D and 3D perspective transforms!

```php
$app = new class {
    public string $name = '';
    public bool $flipped = false;
};
$view = fn() => VStack(spacing: 20)(
    VStack(spacing: 8)(
        Text('Transformed controls')->bold(),
        TextField('Name', text: $app->name)->frame(width: 150),
    )
        ->padding(12)
        ->background(Color::secondarySystemBackground)
        ->cornerRadius(8)
        ->scaleEffect(1.05)
        ->rotationEffect(6)
        ->offset(x: 12, y: 0),
    Button($app->flipped ? 'Back' : 'Front', action: fn() => $app->flipped = !$app->flipped)
        ->rotation3DEffect($app->flipped ? 180 : 0, axis: [0, 1, 0])
        ->animation(Animation::snappy(), value: $app->flipped),
)->padding();
```
