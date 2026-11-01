# loading

The 29 loading indicators of [loading.dev](https://loading.dev) for MyPHP
apps of native UI (mygo's plugins/loading): arcs, rings, dots, bars and
blocks that MyPHP draws itself, as loading.dev's look and move.

```php
use MyPHP\Plugins\Loading\{Easing, Loading, Options};

Loading::arc($c)->label('Saving');
Loading::ring($c, new Options(size: 32, color: $t->accent, easing: Easing::EaseInOut));
```

`Loading::all()` lists them by name; see `examples/loading` (`./run.sh
loading`), and `Loading`'s and `Options`' comments.

The indicators follow loading.dev's SVG and CSS (`loading-dev` 0.3.5,
MIT, © Jakub Krehel; see [NOTICE.md](NOTICE.md)) as mygo's do: their
shapes in the same viewBoxes and fractions of their size, their
keyframes, staggers and timing functions, and their poses for reduced
motion.
