# Loading

The loading plugin has the 29 loading indicators of
[loading.dev](https://loading.dev), `loading-dev` for
React, for native UI: arcs, rings, dots, bars and blocks that MyPHP draws
itself, each a node that moves while it shows. They look and move as
loading.dev's do: the same shapes, keyframes and timing functions, the
same pace. It is all PHP: no JavaScript package, and nothing to
`App::use()`. Pages, which have CSS, can use `loading-dev` itself.

[examples/loading](../../examples/loading) shows them all, with their
options (`./run.sh loading`):

```php
use MyPHP\Plugins\Loading\Loading;

$c->row(0, 8)->alignItems(Node::CENTER);
  Loading::arc($c)->label('Saving');
  $c->text('Saving…');
$c->end();
```

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

Each is a static method of `Loading`, as `Loading::ring($c)`.
`Loading::all()` lists them as `Spinner`s with their names, for a gallery
or a setting that lets the user choose one:

```php
use MyPHP\Plugins\Loading\Loading;

$c->row(0, 16);
  foreach (Loading::all() as $s) {
      $c->key($s->name)->column(0, 8)->alignItems(Node::CENTER);
        ($s->new)($c)->label($s->name);
        $c->text($s->name, 11);
      $c->end();
  }
$c->end();
```

## Options

Each method takes an `Options`, whose defaults are loading.dev's:

```php
use MyPHP\Plugins\Loading\{Cap, Easing, Loading, Options};

Loading::ring($c, new Options(
    size: 32,                    // points high: 20 by default
    color: $c->theme->accent,    // the theme's text color by default
    duration: 0.6,               // seconds of one cycle: the indicator's own by default
    easing: Easing::EaseInOut,
    cap: Cap::FlatCap,
));
```

- **`size`** is the indicator's height, and its width, except for
  `bouncingDots` and `linearDots`, rows of dots 1.15 and 0.9375 times as
  wide as high. The node's own size, as `size()`, `fill()` or a parent's
  layout give it, scales the drawing to fit, centered.
- **`color`** is what it draws in, `0xRRGGBB`, with the faint parts (a
  ring's track, a fading dot) in it at their opacity. Null is the theme's
  `text`.
- **`duration`** is one cycle of its motion, in seconds, which differs
  from one to another: 0.8 for `arc`, 2.4 for `slide`.
- **`paused`** stops it where it is; set back to false, it goes on from
  there.
- **`easing`** is how the indicators that turn (`arc`, `atom`, `clock`,
  `comet`, `dual`, `orbit`, `radar`, `ring`, `snake`, `trace`) go around:
  `Easing::Linear` at one speed, `EaseInOut` slowing at the end of each
  turn, `Stacked` both added, two turns a cycle, fast in the middle.
- **`cap`** ends the strokes of `arc`, `cascade`, `dual`, `ring`, `snake`
  and `trace`: `Cap::RoundCap` or `FlatCap`.
- **`sweep`** is the order of `blocks`'s blocks: `Sweep::Diagonal`, `Rows`
  or `Columns`.
- **`direction`** sends `ripple`'s rings out from the center
  (`RippleDirection::RippleOut`) or in to it (`RippleIn`).
- **`origin`** grows `wave`'s bars from their middle
  (`WaveOrigin::WaveCenter`) or their bottom (`WaveBottom`).

## Motion and accessibility

An indicator asks for a frame each time it is painted, as a spinner does
(`Painter::animationFrame()`), and only while it shows: one out of view is
not painted and asks for none, and it does not build the view again. Each
starts its cycle as it first shows, as a CSS animation does.

Where the desktop asks for less motion (`$c->preferences()->reduceMotion`:
macOS's Reduce Motion or GNOME's animations turned off), an indicator
stands still in loading.dev's pose for reduced motion, as dimmed dots or a
ring of rings, and asks for no frames.

Assistive technology sees a progress indicator of unknown length. Name it
with `label()`, as what is loading: `Loading::ring($c)->label('Loading
messages')`.
