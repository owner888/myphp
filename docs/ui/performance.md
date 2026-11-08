# Native UI performance

> This page is about the engine under the views: its builder, `$c` a
> `Context`, which an app reaches inside a `Representable(fn(Context $c) =>
> …)`, as SwiftUI's `NSViewRepresentable` ([custom widgets](custom-widgets.md)).

`php tests/bench.php [frames]` times the PHP half of a frame — build,
layout, record (the runtime's hit-test rectangles) and paint (quads into a
buffer) — for the examples' views and a few dense ones, headless. What it
leaves out is the platform's half: shaping text, the atlas, Metal. Measured
on PHP 8.3 with the JIT (see [docs/architecture.md](../architecture.md)
for the full table and the machine):

| View | ms per frame | Quads |
|---|---:|---:|
| widgets, General page | 1.6 | 391 |
| files, 2000 entries, icons | 2.8 | 587 |
| list, a million rows | 0.3 | 342 |
| table, a million rows × 5 | 1.2 | 626 |
| 2000 texts, not virtualized | 5.0 | 1970 |

A node costs about 4 µs to build, 0.5 µs to lay out and 0.7 µs to record;
9 000 nodes are rebuilt, laid out and painted in about 4–6 ms a frame at
60 fps on an M-series Mac, and a million-row list costs about 1.3 ms and 8
MB, as only the rows in view are built.

## Memory

A development run (`myphp dev`, `myphp run`) logs what the process holds
every 10 seconds, once PHP's cycles are collected (`MemoryWatch`, the
`memory` subsystem, at debug), and warns when it reaches 75% of
`memory_limit`, when it grew in each of the last six samples by a
megabyte or more (a leak's signature), and when one sample collects a
thousand cycles or more (something builds a cycle over and over).
`myphp dev` warns the same way about its own memory. `tests/memory_test.php`
checks that frames of the examples leave no cycles behind and that every
`draw`/`drawOver` callback is `static`, taking its node as the third
argument: a callback that holds the node, or the Context, makes the
frame's whole tree garbage only the collector frees, which took a
megabyte a frame of examples/mobile.

## Keeping frames cheap

- Build only what shows: a [list](list.md), a [table](table.md), an
  [outline](outline.md) or a [grid view](grid-view.md) builds only the rows
  in view.
- Parts of a view that change seldom can be kept with
  `$c->memo($key, $deps, $build)`: the node `$build` built is kept and
  used again while `$deps` (an array compared by value) and what is around
  it stay the same, so the build costs nothing in frames that do not
  change it. Style the node inside `$build`.

  ```php
  $app = new class { public array $rows = ['a', 'b', 'c']; };
  $view = function (Context $c) use ($app) {
      $c->memo('rows', $app->rows, function () use ($c, $app) {
          $c->column(gap: 4);
            foreach ($app->rows as $r) {
                $c->text($r);
            }
          $c->end();
      });
  };
  ```
- Motion that only paints, [poses](motion.md#poses-that-only-paint),
  content transitions and drawings that animate from their `draw()`, does
  not run the view.
- `cache()` keeps a still subtree that is dear to draw as one image (see
  [Styling](styling.md)).

Measure the Mac's half with `MYPHP_STATS=1 ./run.sh inbox 1000000`, and a
frame's parts with `MYPHP_FRAME_STATS` (see [Rendering](rendering.md)).
Compare with the numbers in `docs/architecture.md` after a change to
`src/UI`.
