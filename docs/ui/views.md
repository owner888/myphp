# Views

The view is a function from your app's state to its interface. MyPHP calls
it on the main thread to build frames after input, model changes and while
something animates. A frame can contain several build passes (up to
three), so it can show the outcome of an action before it is painted.

The window has one `Context` for each build. Containers you open
(`$c->column()`, `$c->row()`…) become the parent of what is built after
them until `$c->end()` closes them. Pass the context to helpers that build
part of the view.

Each `Node` a builder returns belongs to one build pass: style it, ask
`$c->clicked()` and the like of it in that build, and keep it nowhere. The
tree is built again for every frame, and the last frame answers this
frame's questions: `clicked()`, `hovered()` and `focused()` hit-test the
rectangles the last layout gave the node of the same identity. Store a
`Handle` for a control's identity across builds (see [input](input.md)),
and keep model data and widget state such as `ListState` or `TextState` in
your app.

```php
$app = new class {
    /** @var list<array{title: string, done: bool}> */
    public array $todos = [];
    public TextState $draft;
    public function __construct() { $this->draft = new TextState(); }
};

$view = function (Context $c) use ($app) {
    $c->column(16, 8)->fill();
      foreach ($app->todos as $i => $todo) {
          $c->key($i)->checkbox($todo['title'], $app->todos[$i]['done']);
      }
      if ($c->submitted($c->input($app->draft, 'New to-do'))) {
          $app->todos[] = ['title' => $app->draft->text, 'done' => false];
          $app->draft = new TextState();
      }
    $c->end();
};
```

Make the state once, as the app starts, and give its view to the window:

<!-- no-test -->
```php
App::whenReady(function () use ($view) {
    new Window(new WindowOptions(title: 'To-dos', content: Content::view($view)));
});
App::run();
```

`Content::view($view)` is the window's content (`WindowOptions::$content`);
a `Content` can serve several windows, each with its own node state.

## View files and hot reload

An app keeps its view in a file of its own, which returns it:
`Content::file(__DIR__ . '/view.php', $app)` loads `view.php` bound to
`$app`, as a method of its class: `$this` in the file, and in every closure
it makes, is `$app`, its private members and `self::` included.

<!-- no-test -->
```php
<?php // view.php
declare(strict_types=1);

use MyPHP\UI\Context;

/** A part of the view: a closure, made once as the file loads. */
$row = function (Context $c, int $i): void {
    $c->key($i)->checkbox($this->todos[$i]['title'], $this->todos[$i]['done']);
};

return function (Context $c) use ($row): void {
    $c->column(16, 8)->fill();
      foreach (array_keys($this->todos) as $i) { $row($c, $i); }
    $c->end();
};
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
  `use (&$page)`.
- A file that fails to load (a syntax error) keeps the view it had, the
  error in the log; the next save tries again.
- Other files saved (the state's class, `app.php`) are named in the log:
  `R` restarts the app with them.

The examples in these guides are parts of such a view: `$c` is the view's
`Context`, and `$app` an object of your own that holds the state, as the
to-do list here. A property such as `$app->volume` or a method such as
`$app->save()` is one you declare on it. Examples that need state make it
first, outside the view, and give it to the view with `use`; values that
last, such as a [router](navigation.md), a `TextState` or a `ListState`,
are made with the rest of the state, never in the view, which runs for
every frame.

An arrow function (`fn() =>`) copies the variables it reads as it is made:
a closure that must see a variable change later takes it by reference,
`function () use (&$v)`. Objects and `$this` are fine either way.

## Events and actions

`$c->clicked()` reports a click since the last frame and keeps ordinary
control flow beside the control:

```php
$app = new class { public int $saves = 0; public function save(): void { $this->saves++; } };
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->button('Save'))) {
        $app->save();
    }
};
```

A control takes the user's input as its builder returns, so an inline save
sees the latest edits of the controls built before it.

`onClick()` schedules the action after the view finishes building and bound
input has been applied:

```php
$app = new class { public bool $saving = false; public int $saves = 0; public function save(): void { $this->saves++; } };
$view = function (Context $c) use ($app) {
    $c->button('Save')->disabled($app->saving)->onClick(fn() => $app->save());
};
```

Use polling when an inline `return`, `break` or `continue` is useful. Use
callbacks for actions that modify the collection being built, so the loop
finishes before its model changes:

```php
$app = new class {
    public array $items = [['id' => 'a', 'title' => 'Milk'], ['id' => 'b', 'title' => 'Bread']];
    public function removeItem(string $id): void { $this->items = array_values(array_filter($this->items, fn($i) => $i['id'] !== $id)); }
};
$view = function (Context $c) use ($app) {
    foreach ($app->items as $item) {
        $c->key($item['id'])->row(gap: 8);
          $c->text($item['title'])->grow(1);
          $c->button('Delete')->onClick(fn() => $app->removeItem($item['id']));
        $c->end();
    }
};
```

Handled input is consumed before rebuilding, so an action runs once for
that input. MyPHP rebuilds changed state before painting the frame.

Widgets that change a value take it by reference, so they need no handler:
`$c->checkbox('Sync', $app->sync)` binds the property to the control. As
in Dear ImGui and egui, the control writes the user's edit through the
reference before its builder returns, so the next statement reads the new
value. `$c->changed()` reports that it changed in this build pass, and
`$c->submitted()` that Enter was pressed in a single-line text input. Text
controls edit a `TextState`, whose `text` is the text.

```php
$app = new class {
    public TextState $query;
    public array $results = [];
    public function __construct() { $this->query = new TextState(); }
};
$view = function (Context $c) use ($app) {
    if ($c->changed($c->input($app->query, 'Search'))) {
        $app->results = [$app->query->text];
    }
};
```

Local copies work too, including values read from an array, compared with
the value they came from or through `$c->changed()`:

```php
$app = new class {
    public array $viewed = ['f1' => false];
    public string $language = 'en';
    public array $languages = ['en' => 'English', 'fr' => 'Français'];
    public function setLanguage(string $tag): void { $this->language = $tag; }
};
$view = function (Context $c) use ($app) {
    $viewed = $app->viewed['f1'];
    if ($c->changed($c->checkbox('Viewed', $viewed))) {
        $app->viewed['f1'] = $viewed;
    }

    $chosen = $app->language;
    $c->radioGroup(function () use ($c, $app, &$chosen) {
        foreach ($app->languages as $tag => $name) {
            $c->radio($name, $chosen, $tag);
        }
    });
    if ($chosen !== $app->language) {
        $app->setLanguage($chosen);
    }
};
```

A container takes its input with its children, as the call that built it
returns: a radio group's arrow keys move among all of its radios. Input
follows the options of the frame the user acted on: an input shown
read-only takes no typing, and `disabled()` or `TextState::$readOnly` set
in this build govern the input of the frames after it, as they show.

`onChange()` and `onSubmit()` run after construction and bound input,
before the rebuild. Use callbacks when an action should wait until the
view has finished building, such as modifying the collection being
rendered. They can also commit local copies:

```php
$app = new class { public array $viewed = ['f1' => false]; };
$view = function (Context $c) use ($app) {
    $viewed = $app->viewed['f1'];
    $c->checkbox('Viewed', $viewed)->onChange(function () use ($app, &$viewed) {
        $app->viewed['f1'] = $viewed;
    });
};
```

## Keys

Give a control its key before construction, so its state follows the item
when the surrounding collection changes:

```php
$app = new class {
    public array $todos = [['id' => 7, 'title' => 'Write docs', 'done' => false]];
    public TextState $query;
    public function __construct() { $this->query = new TextState(); }
};
$view = function (Context $c) use ($app) {
    foreach ($app->todos as $i => $todo) {
        $c->key($todo['id'])->checkbox($todo['title'], $app->todos[$i]['done']);
    }
    $c->key('search')->input($app->query);
};
```

`$c->key()` keys the next node built, and returns the context to build it
with. Keys are strings or ints, unique among siblings; a node's identity is
its parent's mixed with its key, or, without one, with its place among its
siblings. So content shown or hidden renumbers the siblings after it, and a
scroll, a field's focus or an animation among them takes another's state:
key what comes and goes, or what follows it, or put it in a container of
its own.

## Change the state from outside the view

The view reads your state on the main thread, and PHP runs the app on one
thread. Work that waits, as a download, runs in a fiber of its own with
`App::go()`, which lets the app draw and take events while the fiber
waits: an HTTP request with `MyPHP\Http` (see
[from PHP or native UI](../plugins/fetch.md#from-php-or-native-ui)),
`App::wait()`. A SQLite query does not wait in a fiber:
it is synchronous (see [SQLite](../plugins/sqlite.md#from-php-or-native-ui)).
`Window::update()` runs a function that changes the state and then draws a
new frame:

<!-- no-test -->
```php
$win = new Window(new WindowOptions(content: Content::view($view)));
App::go(function () use ($win, $app) {
    $items = Http::get('https://example.com/items.json')->json(); // waits in the fiber, the app going on (MyPHP\Http)
    $win->update(function () use ($app, $items) { $app->items = $items; });
});
```

`Window::invalidate()` only draws a new frame, for state you changed
yourself. `App::later()` and `App::after()` run a function on a later turn
of the run loop. Within the view, `$c->animationFrame()` asks for another
frame as soon as the display can show it, and `$c->after($seconds)` for one
after a delay, such as a clock's next second.

## Something that changes with time

Neither the view nor `App` has a repeating timer, as mygo has none: a view
that changes with time asks for its next frame each time it builds, with
`$c->after()`, and works out what changed from the time, at the top of the
view. Frames come for other reasons too (the pointer moving, a key), and
`after()` may wake it a little late, so the work goes by the clock, not by
counting frames, and does nothing when nothing is due. A countdown that
ends on its own:

```php
$app = new class {
    public bool $running = true;
    public float $endsAt;
    public function __construct() { $this->endsAt = microtime(true) + 25 * 60; }
    /** Idempotent: what the clock says now, however many frames came. */
    public function tick(): void
    {
        if ($this->running && microtime(true) >= $this->endsAt) {
            $this->running = false; // done once, whichever frame sees it first
        }
    }
};
$view = function (Context $c) use ($app) {
    $app->tick();
    $left = max(0, (int)ceil($app->endsAt - microtime(true)));
    $c->text(sprintf('%02d:%02d', intdiv($left, 60), $left % 60));
    if ($app->running) {
        $c->after(1 - fmod(microtime(true), 1)); // the next second, while it runs
    }
};
```

For motion, `$c->animationFrame()` in every frame while it moves; for work
outside the view, `App::after($seconds, $fn)` once, which can call itself
again, with `Window::update()` to change the state and draw.

## State of a node

A node keeps state of its own from frame to frame with
`$c->runtime->local($node->id, fn() => new State())`, for widgets you build
yourself; see [custom widgets](custom-widgets.md).

See [Performance](performance.md) for measured frame costs, and
`$c->memo()` there for parts of a view that are built again only when what
they show changes.
