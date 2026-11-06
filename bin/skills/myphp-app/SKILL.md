---
name: myphp-app
description: Write new PHP desktop and mobile (Android, iOS) apps, windows and screens with MyPHP, native UI (MyPHP\UI) or a web frontend, from a blank project to a tested, packaged app. Use when creating a MyPHP app, adding a window, page, widget or service to one, or turning a design into MyPHP code.
---

# Writing MyPHP apps

MyPHP is a PHP desktop framework: a window's interface is a PHP closure
that builds nodes every frame, which MyPHP lays out with flexbox and draws
on the GPU (Metal on macOS, OpenGL on Linux). There is no HTML, CSS or
JavaScript in a native UI window. Its API takes the names of mygo, the Go
framework it ports, but it is PHP: write PHP idioms, and never guess an
API from Go, SwiftUI, React or CSS.

## Find the framework and its docs first

`myphp.json` names the checkout in `framework` (or `$MYPHP_HOME` is set).
Everything below is in that checkout:

- `docs/ui/README.md`: the index of the native UI guides and of every
  widget's page. Read it, then the pages for what you build. Every example
  there runs in the framework's tests, so copy their call shapes.
- `docs/app.md`, `docs/windows.md`, `docs/menus.md`, `docs/native.md`
  (dialogs, notifications, tray, clipboard), `docs/plugins/` (fetch,
  sqlite, watch, websocket, updater…).
- This skill's `references/`: `widgets.md` (which widget for which need,
  and how to turn "make me a notepad" into a widget list — start here),
  `pitfalls.md` (read it before you write), `async.md` (HTTP, SQLite,
  child processes, stale answers), `windows.md` (several windows, menus,
  asking before closing) and `web.md` (the web template: PHP services and
  a TypeScript page).
- `docs/swiftui.md` when the request or a design is in SwiftUI terms
  (`VStack`, `@State`, `.sheet`…): it maps each to myphp. `docs/mobile.md`
  for Android and iOS: the safe area, `tabView`, `navigationBar`,
  `sheet`, and `myphp run -d android|ios`.
- `examples/`: whole apps (`counter-native`, `todo`, `notes`, `widgets`,
  `gallery`, `inbox`, `photos`…). Find the closest one and follow it.
- Before calling a method you have not seen in the docs, confirm it in
  `src/UI/` (`grep -n "function name(" src/UI/*.php`). If it is not
  there, it does not exist: see `references/pitfalls.md`.

## From the request to a widget list

Before writing code, turn the request into needs and pick a widget for
each from `references/widgets.md` (the first match is the simpler one),
then read those widgets' pages in `docs/ui/`. Prefer the framework's
widget over a hand-built one every time one fits; say which widgets you
chose and why in a line before you build.

## Shape of an app

Keep the state and its actions in a class, and the view in `view.php`, a
file that returns a closure: `Content::file()` runs it bound to the
state (`$this` is the state, private members and `self::` included) and,
in development, loads it again when it is saved, the state kept (hot
reload, as Flutter's). A test builds the same view without a window.

```
my-app/
  myphp.json        name, identifier, entry "app.php", framework
  boot.php          loads MyPHP (from myphp init; keep it)
  app.php           makes the state, opens the windows, runs the app
  src/Todos.php     the state and its actions (no view)
  view.php          return function (Context $c): void { … } — the view
  tests/ui_test.php builds the view headless and drives it
  resources/icon.png
```

```php
<?php // src/Todos.php
declare(strict_types=1);

use MyPHP\UI\TextState;

final class Todos
{
    /** @var list<array{id: int, title: string, done: bool}> */
    public array $items = [];
    public TextState $draft;   // widget state lives here, made once
    private int $next = 1;

    public function __construct() { $this->draft = new TextState(); }

    public function add(): void
    {
        $title = trim($this->draft->text);
        if ($title === '') { return; }
        $this->items[] = ['id' => $this->next++, 'title' => $title, 'done' => false];
        $this->draft = new TextState();
    }
}
```

```php
<?php // view.php — $this is the Todos app.php passes to Content::file
declare(strict_types=1);

use MyPHP\UI\Context;

/** A row of the list: a part of the view is a closure here, not a method. */
$row = function (Context $c, int $i): void {
    $c->key($this->items[$i]['id'])->checkbox($this->items[$i]['title'], $this->items[$i]['done']);
};

return function (Context $c) use ($row): void {
    $t = $c->theme;
    $c->column(16, 8)->fill()->background($t->background);
      $c->row(gap: 8);
        $field = $c->key('draft')->input($this->draft, 'New to-do')->grow(1);
        $add = $c->primaryButton('Add');
        if ($c->submitted($field) || $c->clicked($add)) { $this->add(); }
      $c->end();
      foreach (array_keys($this->items) as $i) { $row($c, $i); }
      $left = count(array_filter($this->items, fn($i) => !$i['done']));
      $c->text("$left left")->textColor($t->textMuted);
    $c->end();
};
```

```php
<?php // app.php
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/boot.php';
require __DIR__ . '/src/Todos.php';

$app = new Todos();
App::setName('Todos');
App::whenReady(function () use ($app) {
    new Window(new WindowOptions(title: 'Todos', width: 420, height: 520,
        content: Content::file(__DIR__ . '/view.php', $app)));
});
App::run();
```

- `view.php` holds closures only: its parts (`$row`, `$sidebar`, a page
  of a router…) are closures made before the `return`, handed to it with
  `use`; a closure that calls one made after it takes it by reference
  (`use (&$page)`). A named class or function there cannot be loaded
  twice: its change waits for a restart, as the log says.
- What the view does to the state goes through the class's methods
  (`$this->add()`); what only draws stays in `view.php`. A window of its
  own (a flyout, a second window) has a file of its own:
  `Content::file(__DIR__ . '/list.php', $this)`.
- `myphp dev` and `myphp run` hot reload on a save (or `r`); `R`
  restarts the app, its state lost. A change to the class, `app.php` or a
  constant needs `R`.

Start a project with `myphp init -template native <dir>` (from the
framework's `bin/myphp`): it makes this shape, `app.php` and `view.php`.

## Rules of the view

- The view runs for every frame, maybe several times for one event. It
  only reads state and builds nodes. Make state (`TextState`,
  `ListState`, `ScrollState`, routers, loaded images) once, outside it.
- Every container (`column`, `row`, `scroll`, `grid`, `box` with
  children…) is closed by `$c->end()`. Indent the children one step so
  the pairs show; a missing `end()` nests everything after it.
- A builder returns its `Node` for this frame only: style it
  (`->padding(8)->radius(6)`), ask `$c->clicked($node)`,
  `$c->changed($node)`, `$c->submitted($node)` of it, and keep it
  nowhere. Persistent identity is a `Handle` (`->bind($handle)`).
- Key what repeats or comes and goes: `$c->key($item['id'])->...`, with a
  stable ID, never the loop index of a list that can reorder or shrink.
- Widgets that edit a value take it by reference
  (`$c->checkbox('Sync', $this->sync)`); bind to the real property or
  array element, not to a copy, unless you commit the copy yourself.
- Change a collection being looped over from `->onClick(fn() => ...)`,
  which runs after the build, not inline in the loop.
- `fn() =>` captures by value when made; a closure that must see a
  variable change later is `function () use (&$v)`. Objects are fine.
- Colors are ints `0xRRGGBB`, with alpha as a separate 0–1 argument.
  Use the theme's colors (`$c->theme->background`, `surface`, `text`,
  `textMuted`, `border`, `accent`…) and sizes (`$t->space(n)`,
  `$t->rem(n)`) so light and dark mode and the desktop's settings follow.
  Times are seconds.
- Style is code: hover, selection and disabled looks are `if`s in the
  view, not selectors.
- Something that changes with time (a timer reaching zero) is worked
  out by the clock at the top of the view, in a method that does nothing
  when nothing is due; the view asks for its next frame with
  `$c->after($seconds)`. There is no repeating timer
  (`docs/ui/views.md`, "Something that changes with time").
- One thread: never block the view with I/O. Do slow work in
  `App::go(fn() => ...)`, a fiber, where `MyPHP\Http` waits without
  stopping the window, then publish with `$window->update(fn() => ...)`.
  SQLite is synchronous: small queries only. See `references/async.md`.
- Prefer the framework's widgets over hand-built ones: they carry focus,
  keyboard, disabled, accessibility and theme behavior. Give icon-only
  buttons a `->label('…')` for screen readers and tests.

## Verify before you say it is done

1. `php -l` every file you wrote.
2. A headless test of the view, which needs no display:

   ```php
   <?php // tests/ui_test.php
   declare(strict_types=1);

   $framework = getenv('MYPHP_HOME') ?: json_decode(file_get_contents(__DIR__ . '/../myphp.json'), true)['framework'];
   require "$framework/tests/Headless.php";
   require __DIR__ . '/../src/Todos.php';

   use MyPHP\Tests\Headless;
   use MyPHP\UI\{Content, Key};
   use function MyPHP\Tests\{check, same};

   $app = new Todos();
   $h = new Headless(Content::file(__DIR__ . '/../view.php', $app)->view, 420, 520);
   $h->frame();
   $h->clickText('New to-do');
   $h->type('Milk');
   $h->enter();
   $h->frame();
   same(1, count($app->items), 'Enter adds the to-do');
   check($h->byText('1 left') !== null, 'the count shows: ' . json_encode($h->texts()));
   exit(empty($GLOBALS['failures']) ? 0 : 1);
   ```

   Run it with `php -d ffi.enable=1 tests/ui_test.php`. `Headless` finds
   nodes by text or label (`byText`, `byLabel`, `clickText`,
   `clickLabel`), types, presses keys (`enter()`, `tab()`, `escape()`…)
   and lists what shows (`texts()`); see `docs/ui/testing.md`. With
   `$h->rt->strict = true` duplicate keys throw instead of only logging.
   To replace a field's text, click it, select all with
   `$h->shortcut('a', Key::COMMAND)`, then `type()`. `byLabel()` takes the
   first match in tree order, which can be a header or the field's own
   label before the control.
3. Look at the real window: `myphp screenshot` runs the app once and
   writes a PNG of its first window (`-out shot.png`), and with
   `-tree tree.json` the elements it shows, with their roles, labels and
   bounds, for when you cannot view images. Always pass `-data <tmp dir>`
   so the run cannot touch the user's real data, and take one with
   `-theme dark` too; `-size WxH` checks a smaller window. Judge it as a
   designer would: alignment, spacing, contrast, clipping, empty states,
   not only that it runs. On Linux without a display it starts Xvfb.
4. `myphp dev` runs it with live reload for the user; `myphp build`
   packages it (`docs/distribution.md`).
5. On a phone (an Android or iOS app), run it on a device or an emulator,
   always through `myphp` (never adb, xcrun or `scripts/`):

   ```sh
   myphp devices                          # what is connected or booted, with ids
   myphp emulators                        # the emulators (AVDs, iOS Simulator)
   myphp emulators --launch <id>          # starts one and waits until it has booted
   myphp emulators --create               # no AVD yet: makes one, downloading the image
   myphp run -d android .                 # or -d ios, or -d <id from devices>
   ```

   `myphp doctor` says what is missing (SDK, Xcode, the phone's PHP
   runtime) when one of these fails. `myphp run` prints only what tells
   something, one line each, `11:00:21.767 W [cache] the cache is full`:
   the level is a letter, E error, F fault, W warning, I info, D debug,
   V verbose. Read every E and F line, and any PHP error, before you say
   it runs; `-v` adds the build's output and the whole system log, for
   when the short log does not explain a failure. In the app, log with
   `MyPHP\Logger::info('…')` (`verbose`, `debug`, `info`, `warn`,
   `error`, `fault`), not `echo` or `error_log`, which a phone does not
   show (`docs/app.md`, "The log"). Left running, `myphp run` hot reloads
   the app on the phone each time you save a PHP file (`view.php` loaded
   again, the state kept; other files want `R`, a restart, as the log
   says): run it in the background and read the log after each change
   rather than building again. `docs/cli.md` has every flag.

Report what you could not check on this machine (another platform, a
real display, hardware).
