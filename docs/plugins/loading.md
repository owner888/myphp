# Loading

In a view, an indicator is wrapped with `Representable`, as [`examples/loading`](../../examples/loading) shows them:

```php
use MyPHP\Plugins\Loading\{Loading, Options};

$view = fn() => Representable(fn(Context $c) => (Loading::all()[0]->new)($c, new Options(size: 24)))->padding();
```

The loading plugin includes all 29 vector loading indicators of [loading.dev](https://loading.dev) for native GPU UI: arcs, rings, dots, bars, and blocks drawn in pure PHP at 60 fps.

## The indicators

| Function | What it draws |
| --- | --- |
| `arc` | A single open stroke turning in a circle. |
| `atom` | Three rings tumbling inside a circle. |
| `blocks` | Nine blocks shrinking and growing in a sweep across a grid. |
| `bouncingDots` | Three staggered dots bouncing up and down. |
| `cascade` | Three nested arcs fanning into a spiral, then snapping back in line. |
| `circularDots` | Eight dots in a ring, the brightest going around. |
| `classic` | Twelve fading bars in a radial pattern. |
| `classicV2` | Eight ticks in a ring, lit in turn. |
| `clock` | A clock hand sweeping around a faint face. |
| `comet` | A full ring fading into its tail. |
| `compass` | Four ticks turning a quarter turn at a time. |
| `dual` | Two arcs turning in opposite directions. |
| `eclipse` | Two dots trading places, one passing behind the other. |
| `flip` | A square flipping over one axis, then the other. |
| `gather` | Four blocks pulling together, turning, and pushing apart. |
| `leap` | Three dots in a row, the last one leaping to the front. |
| `linearDots` | Three dots lighting up in turn from left to right. |
| `loading` | The loading.dev mark, its brightest segment circling the ring. |
| `morph` | A square rounding into a circle and back as it turns. |
| `orbit` | A fading half ring turning around a dot. |
| `pulse` | A ring rippling outward from a dot. |
| `radar` | A fading beam sweeping around a faint dish. |
| `ring` | An arc turning in a faint circle. |
| `ripple` | Three rings spreading out from the center. |
| `slide` | Three dots sliding into the empty corner of a square. |
| `snake` | An arc stretching and shrinking as it circles. |
| `swirl` | A bright cell chasing its trail around a square. |
| `trace` | A dash tracing the outline of a rounded square. |
| `wave` | Five bars rising and falling in a wave. |

Each is a static method on `Loading` (such as `Loading::ring($c, $opts)`), and `Loading::all()` lists all 29 as `Spinner` descriptors with their names:

```php
use MyPHP\Plugins\Loading\{Cap, Easing, Loading, Options};

$view = fn() => HStack(spacing: 16)(
    Representable(fn(Context $c) => Loading::ring($c, new Options(
        size: 32,
        color: 0x38bdf8,
        duration: 0.6,
        easing: Easing::EaseInOut,
        cap: Cap::FlatCap,
    ))),
    Text('Saving…'),
)->padding();
```

## Options

- **`size`** — height and width in points (`20` by default).
- **`color`** — `0xRRGGBB` color (defaults to the theme's text color).
- **`duration`** — seconds per cycle.
- **`paused`** — holds the animation at its current frame when `true`.
- **`easing`** — `Easing::Linear`, `EaseInOut`, or `Stacked`.
- **`cap`** — `Cap::RoundCap` or `FlatCap`.
- **`sweep`** — `Sweep::Diagonal`, `Rows`, or `Columns` (for `blocks`).
- **`direction`** — `RippleDirection::RippleOut` or `RippleIn` (for `ripple`).
- **`origin`** — `WaveOrigin::WaveCenter` or `WaveBottom` (for `wave`).
