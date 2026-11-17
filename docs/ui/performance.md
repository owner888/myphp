# Native UI performance and reactivity

`php tests/bench.php [frames]` times the PHP half of a frame — build, layout, record (the runtime's hit-test rectangles) and paint (quads into a buffer) — for the examples' views and dense benchmarks, headless. Measured on PHP 8.5 with the JIT on an M-series Mac (see [`docs/architecture.md`](../architecture.md)):

| View | ms per frame | Quads |
|---|---:|---:|
| widgets, General page | 1.6 | 391 |
| files, 2000 entries, icons | 2.8 | 587 |
| list, a million rows | 0.3 | 342 |
| table, a million rows × 5 | 1.2 | 626 |
| 2000 texts, not virtualized | 5.0 | 1970 |

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

## Memory

A development run (`myphp dev`, `myphp run`) logs memory every 10 seconds after collecting cycles (`MemoryWatch`), warning if memory grows steadily or if closures form reference cycles. All reactive `Observer` and `Scope` subscriptions use `WeakMap` and `WeakReference`, leaving `0` reference cycles (`gc_collect_cycles() === 0`).

## Offscreen caching (`drawingGroup`)

Use `->drawingGroup()` on a static subtree that is expensive to paint to rasterize and cache it as a single GPU texture across frames.
