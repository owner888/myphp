# Transitions and matched geometry

Views animate as SwiftUI's:
- **`->animation(Animation::smooth(), value: $watched)`** animates changes whenever `$watched` changes.
- **`withAnimation(Animation::snappy(), fn() => …)`** animates all state changes made inside the closure.
- **`->transition(AnyTransition::…)`** specifies how a conditional or `Each` view enters and leaves when added or removed.
- **`->matchedGeometryEffect(id: $id, in: $namespace)`** smoothly interpolates a view's position and size from another view with the same `id` and `in` namespace as one replaces the other.

```php
$app = new class {
    public bool $shown = true;
    public bool $expanded = false;
};
$view = fn() => VStack(spacing: 12)(
    Button('Toggle', action: fn() => withAnimation(Animation::snappy(), fn() => $app->shown = !$app->shown)),
    $app->shown
        ? Text('Hello')->transition(AnyTransition::scale()->combined(AnyTransition::opacity()))
        : null,
    $app->expanded
        ? RoundedRectangle(cornerRadius: 16)->fill(Color::accentColor)->frame(width: 200, height: 100)
            ->matchedGeometryEffect(id: 'card', in: 'hero')
            ->onTapGesture(fn() => withAnimation(Animation::snappy(), fn() => $app->expanded = false))
        : RoundedRectangle(cornerRadius: 10)->fill(Color::accentColor)->frame(width: 64, height: 64)
            ->matchedGeometryEffect(id: 'card', in: 'hero')
            ->onTapGesture(fn() => withAnimation(Animation::snappy(), fn() => $app->expanded = true)),
)->padding();
```

## Built-in transitions (`AnyTransition`)

- **`AnyTransition::opacity()`** — fades in and out.
- **`AnyTransition::scale(float $scale = 0.001)`** — grows from `$scale` and shrinks back, fading.
- **`AnyTransition::slide(float $distance = 60)`** — enters from the leading edge and exits toward the trailing edge.
- **`AnyTransition::move(Edge $edge, float $distance = 60)`** — slides in and out from `$edge` (`Edge::top`, `bottom`, `leading`, `trailing`).
- **`AnyTransition::offset(float $x, float $y)`** — slides from/to an offset.
- **`$a->combined($b)`** — combines two transitions (e.g. `scale()` + `opacity()`).
- **`AnyTransition::asymmetric(insertion: $in, removal: $out)`** — uses different transitions for appearing and disappearing.
