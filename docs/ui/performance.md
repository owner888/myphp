# Native UI performance and reactivity

`php tests/bench.php [frames]` times the PHP half of a frame — build, layout, record (the runtime's hit-test rectangles) and paint (quads into a buffer) — for the examples' views and dense benchmarks, headless. Measured on PHP 8.5 with the JIT on an M-series Mac (see [`docs/architecture.md`](../architecture.md)):

| View | ms per frame | Quads |
|---|---:|---:|
| widgets, General page | 1.4 | 448 |
| files, 2000 entries, icons | 0.7 | 598 |
| list, a million rows | 0.5 | 343 |
| table, a million rows × 5 | 2.0 | 627 |
| 2000 texts, not virtualized | 6.3 | 1971 |
| declarative 10×25, one value changing: one closure, stacks kept by value | 1.6 | 806 |
| declarative 10×25, one value changing: closure children (`Computed`) | 0.28 | 807 |

The two declarative rows are the same 500-node page with one value changing each frame. Kept by value, the stacks are neither laid out nor painted again (layout 0.02 ms), but the view closure still makes their views and `ViewKey` compares them, leaving out what is at its class's default (build 1.3 ms); in closure children whose state did not change, nothing of them is made (build 0.09 ms). Closure children stay the way to keep a page cheap; stacks kept by value catch what was not split.

A node costs about 4 µs to build, 0.5 µs to lay out and 0.7 µs to record; 9,000 nodes are rebuilt, laid out and painted in about 4–6 ms a frame at 60 fps, and a million-row `ListView` or `Table` costs about 1.3 ms and 8 MB flat, as only the rows in view are built.

## Fine-grained PHP 8.4+ reactivity (`State`, Property Hooks & `Computed`)

MyPHP combines immediate-mode GPU rendering with fine-grained reactive subtree retention (`MyPHP\UI\Reactive\Observer`):

1. **Zero-boilerplate plain properties**: Any state object bound with `Content::file(__DIR__ . '/view.php', $app)` is automatically tracked via cached closure token analysis. Mutating `$this->count++` in a button action, an `App::go()` fiber, or a timer wakes the window and invalidates only the scopes that read `$this->count`.
2. **PHP 8.4+ Property Hooks & `State<T>`**: Wrap a value with `$count = State(0)` (or use `Observer::get` / `Observer::set` in PHP 8.4 property hooks) for zero-polling push invalidation.
3. **Retained subtrees (`Computed` & closure children)**: Passing a closure child `fn() => ...` or `Computed(fn() => ...)` inside `VStack`, `HStack`, `ZStack`, or `ListView` creates an isolated reactive `Scope`. When its tracked dependencies have not changed, MyPHP reuses the exact retained `Node` subtree from the previous frame with **0 rebuilds**.

```php
$store = new class {
    public int $a = 1;
    public int $b = 2;
};
$view = fn() => VStack(spacing: 8)(
    // Each closure child is an independent reactive scope: mutating $store->a skips rebuilding B.
    fn() => Text("A: {$store->a}"),
    fn() => Text("B: {$store->b}"),
    Button('Increment A', action: fn() => $store->a++),
)->padding();
```

## What a frame costs

Judge myphp's speed from these mechanisms and from measurements (`php tests/bench.php`, `MYPHP_FRAME_STATS=1`), not from "PHP is slower than native": most frames do far less than build, lay out and paint the whole view. Which of four kinds a frame is decides what it costs:

1. **Nothing changed: no frame.** A frame is drawn only when an input event arrives, a `State` or watched property changes, or an animation or `TimelineView` asks for the next tick. An idle window costs 0% CPU and GPU.
2. **Only drawings move: paint only.** A drawing that moves as it is painted (one calling `Painter::animationFrame()` while it paints, as an indeterminate progress bar does) asks for frames that only paint again: when nothing else happened — no input, no state change, the window the same size and zoom — the last frame's nodes are painted again at the new time, and the view is neither built nor laid out (`Runtime::mayRepaint`, `Runtime::beginRepaint`). An animation of the view's values (`withAnimation`, a transition) asks while the view is built, so its frames are of the next kind. A drawing that finds the view was built for another box than layout gave it (a width read from the frame before) calls `Painter::invalidate()` instead: the next frame is built anew.
3. **Some state changed: the root and what changed are built.** The root view closure runs again, making its views anew. Each closure child (`fn() => …`) or `Computed(fn() => …)` whose `Scope` is clean, and each stack of 8 views or more whose value is the frame before's (SwiftUI's diff of view values, below), is not built at all: its node subtree from the frame before is placed again as it was laid out — measured as it was and only moved where it now goes (`Layout::arrange`) — and what its build registered (the clicks it takes, the states inside it) is given back. While it sits where it did, under the same clip, opacity and theme, its hit-test recording is replayed (`memoReplay`) and its quads are copied (`memoRepaint`); moved, under a transform or a mask, or holding the focus, it is recorded and painted again, still not built. Only the subtrees whose dependencies changed are built and laid out anew.
4. **Everything is built.** A view written as one closure with no closure children, whose stacks are not told by value (they hold buttons or other controls), is built, laid out, recorded and painted in full on every frame it draws. This is where the per-node costs above add up to the whole page: 2,000 non-virtualized texts take about 6 ms of PHP, close to the 8.3 ms a frame has at 120 Hz. `tests/bench.php` measures this kind for each view in the table above, and `declarative 10×25 Computed` against `declarative 10×25 full rebuild`, the same page with one value changing each frame.

A kept subtree is built again, not reused, when:

- a property it read changed (its `Scope` is dirty), or, for a stack kept by value, its value changed;
- it is offered another size than it was laid out at: forgotten, and built again in another pass of the frame;
- what it is built in changed: the theme, the scale, the window's size, its environment (font, tint, styles, disabled, locale…), its inherited text style;
- it holds controls (clicks, a field, a list, a scroll view) and a pointer event fell inside it, the focus or the press moved into or out of it (or of a node around it), or state every control may read changed (a menu or modal opened, the window's focus, a drag, a tooltip, the text selection, full screen, Reduce Motion, right to left), or the frame is in its second pass after its first handled an input;
- it reads keys and keys came, or the focus is inside it when keys come;
- a scroll position, list or text field inside it moved;
- its build asked for a frame of its own (an animation, a timer, a measurement) or handled an input: such a build is not kept.

**Stacks told by value.** A `VStack`, `HStack` or `ZStack` of 8 views or more is compared with the frame before's as a value (`ViewKey`), as SwiftUI diffs its views: the same value, environment and states keep its subtree with no closure child needed. It is told by value only when everything in it is `Text`, `Label`, `Image`, `Spacer`, `Divider`, a color, a shape or such a stack, with modifiers that only box, place or paint (`padding`, `frame`, `background`, `overlay`, `opacity`…) and no closure, binding, state object, event, transition or animation anywhere in it. Any other stack is built as before: put its changing parts in closure children.

To keep frames cheap:

- Split a page into closure children (`VStack()(fn() => …, fn() => …)`) along the state each part reads, so a change rebuilds only its part. The root closure should read little and build little.
- Use `ListView` or `Table` for long collections: only the rows in view are built.
- Use `->drawingGroup()` for a static subtree that is expensive to paint.
- In development, a declarative view whose frames build 1,000 nodes or more anew, 30 frames in a row, is told once in the log and the inspector to split into closure children.
- Measure before optimizing: `MYPHP_FRAME_STATS=1` logs frames over 8 ms with their build, layout, quad recording and presentation times.

## Memory

A development run (`myphp dev`, `myphp run`) logs memory every 10 seconds after collecting cycles (`MemoryWatch`), warning if memory grows steadily or if closures form reference cycles. All reactive `Observer` and `Scope` subscriptions use `WeakMap` and `WeakReference`, leaving `0` reference cycles (`gc_collect_cycles() === 0`).

## Offscreen caching (`drawingGroup`)

Use `->drawingGroup()` on a static subtree that is expensive to paint to rasterize and cache it as a single GPU texture across frames.
