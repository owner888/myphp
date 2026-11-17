# Motion and animation

Views animate their changes with SwiftUI's `->animation($anim, value: $watched)`, `withAnimation($anim, fn() => …)`, `KeyframeAnimator`, and `TimelineView` ([Coming from SwiftUI](../swiftui.md#animation)).

```php
$app = new class { public bool $open = false; };
$view = fn() => VStack(spacing: 12)(
    Button('Move', action: fn() => withAnimation(Animation::snappy(), fn() => $app->open = !$app->open)),
    RoundedRectangle(cornerRadius: 12)
        ->fill(Color::accentColor)
        ->frame(width: 120, height: 80)
        ->offset(x: $app->open ? 120 : 0)
        ->scaleEffect($app->open ? 1.05 : 1.0),
)->padding();
```

## Springs and timing curves

An `Animation` describes how properties (`offset`, `scaleEffect`, `rotationEffect`, `rotation3DEffect`, `opacity`, and layout transitions) move to a new target:

- **Springs**: `Animation::smooth()` (no bounce), `Animation::snappy()` (a small bounce), `Animation::bouncy()`, or `Animation::spring(duration: 0.5, bounce: 0.2)`. Springs are solved analytically and preserve velocity when retargeted mid-flight.
- **Timing curves**: `Animation::linear($duration)`, `Animation::easeIn($duration)`, `Animation::easeOut($duration)`, `Animation::easeInOut($duration)`.
- **Modifiers**: `->delay($seconds)` waits before starting; `null` disables animation for a transaction.

## KeyframeAnimator

`KeyframeAnimator` steps through a sequence of timed tracks (`LinearKeyframe`, `SpringKeyframe`, `CubicKeyframe`, `MoveKeyframe`) whenever `trigger:` changes, or continuously when `repeating: true`:

```php
$app = new class { public int $clicks = 0; };
$view = fn() => VStack(spacing: 12)(
    Button('Bounce', action: fn() => $app->clicks++),
    KeyframeAnimator(
        initialValue: 0.0,
        trigger: $app->clicks,
        content: fn(float $y) => Circle()->fill(Color::accentColor)->frame(width: 40, height: 40)->offset(y: $y),
        keyframes: [
            SpringKeyframe(-30.0, duration: 0.2),
            SpringKeyframe(0.0, duration: 0.35),
        ],
    ),
)->padding();
```

## TimelineView

`TimelineView()` redraws its content on the display's frame clock (`TimelineViewContext::$nanoseconds` and `$date`), pausing automatically when `paused: true` or when the window is hidden (see [`examples/timeline`](../../examples/timeline) and [`examples/confetti`](../../examples/confetti)).
