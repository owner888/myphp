# Views

A view is a value built from the state, as SwiftUI's: a function returns
it for every frame, and myphp lays it out, draws it and routes the user's
input to it. Containers take their content as a list
(`VStack(spacing: 8)( … )`), modifiers chain after a view
(`->padding()->background(…)`), controls bind to the state's properties by
reference, and actions are closures. [Coming from SwiftUI](../swiftui.md)
lists every view and modifier.

```php
$app = new class {
    public array $todos = [['id' => 1, 'title' => 'Milk', 'done' => false], ['id' => 2, 'title' => 'Bread', 'done' => true]];
    public function left(): int { return count(array_filter($this->todos, fn(array $t) => !$t['done'])); }
};
$view = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
    Each($app->todos, id: 'id')(fn(array $t, int $i) => Toggle($t['title'], isOn: $app->todos[$i]['done'])),
    Text("{$app->left()} left")->foregroundStyle(Color::secondary),
)->padding();
```

The view runs for every frame, maybe several times for one event, as
SwiftUI's body runs for every change: it reads the state and returns views,
and never changes the state or waits while it builds. What the user does
changes the state through actions: `Button(…, action:)`, `->onSubmit()`,
`->onTapGesture()`, `->onChange(of:)`, the controls' bindings.

## View files and hot reload

An app keeps its view in a file of its own, which returns it:
`Content::file(__DIR__ . '/view.php', $app)` loads `view.php` bound to
`$app`, as a method of its class: `$this` in the file, and in every closure
it makes, is `$app`, its private members and `self::` included.

<!-- no-test -->
```php
<?php // view.php
declare(strict_types=1);

/** A part of the view: a closure, made once as the file loads. */
$row = fn(array $todo, int $i) => Toggle($todo['title'], isOn: $this->todos[$i]['done']);

return fn() => ListView($this->todos, id: 'id')($row);
```

In development (`App::isDev()`), `myphp dev` and `myphp run` hot reload it,
as Flutter's run does: saved (or `r` pressed), the file loads again and the
next frame shows the new code with the state as it was, the scroll
positions, focus and text fields too, which the window keeps. They ask the
app through a file that lists what changed: `MYPHP_HOT_RELOAD` under
`myphp dev`, `.myphp-reload` beside the app's entry on a phone.

- PHP declares a named class, function, interface, trait or enum once:
  a view file holding one is not loaded again; the log says so, and `R`
  (a restart) takes the change. Keep the state's class, and the methods
  that change it, in `src/`; the view and its parts as closures here.
- A closure calling one made after it takes it by reference,
  `use (&$part)`.
- A file that fails to load (a syntax error) keeps the view it had, the
  error in the log; the next save tries again.
- Other files saved (the state's class, `app.php`) are named in the log:
  `R` restarts the app with them.

An arrow function (`fn() =>`) copies the local variables it reads as it is
made; `$this`, the state, is an object, always current.

## Identity

A view is the same view from frame to frame by its place among its
siblings, as SwiftUI's: what the framework keeps for it (a field's cursor
and selection, a list's scroll, a disclosure group's openness) follows it
there. Rows that come, go or reorder need an id: `Each($items, id: 'id')`
and `ListView($items, id: 'id')` key each row by its item's `id` (a key or
a closure of the item), and `->id($key)` gives any view one, as SwiftUI's
`.id()`: a new id is a new view, its kept state gone.

## Change the state from outside the view

PHP runs the app on one thread. Work that waits, as a download, runs in a
fiber of its own with `App::go()`, which lets the app draw and take events
while the fiber waits: an HTTP request with `MyPHP\Http` (see [from PHP or
native UI](../plugins/fetch.md#from-php-or-native-ui)), `App::sleep()`,
`App::wait()`. `->task(fn() => …)` starts one as a view appears, as
SwiftUI's. `Window::update()` runs a function that changes the state and
then draws a new frame:

<!-- no-test -->
```php
$win = new Window(new WindowOptions(content: Content::file(__DIR__ . '/view.php', $app)));
App::go(function () use ($win, $app) {
    $items = Http::get('https://example.com/items.json')->json(); // waits in the fiber, the app going on
    $win->update(function () use ($app, $items) { $app->items = $items; });
});
```

`Window::invalidate()` only draws a new frame, for state changed some other
way. `App::later()` and `App::after()` run a function on a later turn of
the run loop. A SQLite query does not wait in a fiber: it is synchronous
(see [SQLite](../plugins/sqlite.md#from-php-or-native-ui)).

## Something that changes with time

A `TimelineView` builds its view anew every frame, given the time, as
SwiftUI's `TimelineView(.animation)`: a clock, a countdown, a progress that
follows the time. Work it out from the time rather than counting frames:

```php
$app = new class { public float $end; public function __construct() { $this->end = microtime(true) + 90; } };
$view = fn() => TimelineView()(fn(TimelineViewContext $context) => Text(verbatim: sprintf('%d s left', max(0, (int)ceil($app->end - microtime(true)))))
    ->font(Font::largeTitle)->monospaced())->padding();
```

`TimelineView(paused: true)` stops asking for frames. Neither the view nor
`App` has a repeating timer: what is not on screen does not need one, and a
fiber that sleeps (`App::go()` with `App::sleep()`) does what one would.

## Lifecycle

`->onAppear()` runs as a view first shows and `->onDisappear()` as it goes,
`->task()` starts a fiber as it shows, as SwiftUI's.
`->onChange(of: $value, action:)` runs after a frame in which the value
changed.

## How views render

`View` values are rendered every frame by `View::render(Context)` into the
engine's builder, which does layout, hit-testing, focus, accessibility,
text, painting and the GPU: a `Button`'s action runs when its node was
clicked; a binding is a PHP reference the control writes; a `TextField`'s
cursor, selection and input method live in a `TextState` the runtime keeps
by the field's identity; inherited modifiers (`font`, `foregroundStyle`,
`tint`, `disabled`) flow down through an environment (`Env`). Sizing is
SwiftUI's: a flexible view takes the room it is offered, and a stack around
one becomes flexible the same way.

## Beyond the views

What no view does is a `Canvas(fn(GraphicsContext $g, CGSize $size) => …)`
for drawing ([drawing](drawing.md)), or a `Representable(fn(Context $c) =>
…)`: the engine's builder inside one view, as SwiftUI's
`NSViewRepresentable`, for a control of your own ([custom
widgets](custom-widgets.md)).
