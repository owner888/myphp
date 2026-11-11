---
name: myphp-app
description: Write new PHP desktop (macOS, Windows, Linux) and mobile (Android, iOS, iPadOS) apps, windows and screens with MyPHP, native UI in SwiftUI-style declarative views or a web frontend, from a blank project to a tested, packaged app. Use when creating a MyPHP app, adding a window, page, view or service to one, or turning a design (SwiftUI or otherwise) into MyPHP code.
---

# Writing MyPHP apps

MyPHP is a PHP framework for desktop and mobile apps whose interface is
written as SwiftUI's is: a view is a value built from the state, containers
take their content as a list, actions are closures, controls bind to the
state. MyPHP lays the views out and draws them on the GPU (Metal on macOS
and iOS, Direct3D 11 on Windows, OpenGL on Linux and Android). There is no HTML, CSS or JavaScript
in a native window. Write SwiftUI's names, argument labels and order in
PHP syntax; the few places PHP differs are listed below. Never guess an API
from React or CSS.

## Find the framework and its docs first

`myphp.json` names the checkout in `framework` (or `$MYPHP_HOME` is set).
Everything below is in that checkout:

- `docs/swiftui.md`: every SwiftUI view, modifier and pattern and how it is
  written in MyPHP. Start there; the views are global functions
  (`VStack`, `Text`, `Button`…), the styles global enums (`Font`,
  `Color`, `ButtonStyle`…), all in `src/UI/Declarative/functions.php`.
- `docs/ui/README.md`: the guides (layout, text, lists, navigation,
  styling, testing…) and a page per control. Every example there runs in
  the framework's tests, so copy their call shapes.
- `docs/app.md`, `docs/windows.md`, `docs/menus.md`, `docs/native.md`
  (dialogs, notifications, tray, clipboard), `docs/plugins/` (fetch,
  sqlite, watch, websocket, updater…), `docs/mobile.md` for Android and
  iOS.
- This skill's `references/`: `widgets.md` (which view for which need,
  and how to turn "make me a notepad" into a list of views — start here),
  `pitfalls.md` (read it before you write), `async.md` (HTTP, SQLite,
  child processes, stale answers), `windows.md` (several windows, menus,
  asking before closing) and `web.md` (the web template: PHP services and
  a TypeScript page).
- `examples/`: whole apps (`counter-native`, `notes`, `widgets`,
  `gallery`, `inbox`, `photos`, `airpods` for a phone…). Find the closest
  one and follow it.
- Before calling a view or modifier you have not seen in the docs, confirm
  it: `grep -n "function Name(" src/UI/Declarative/functions.php` for a
  view, `grep -n "function name(" src/UI/Declarative/*.php` for a
  modifier. If it is not there, it does not exist: see
  `references/pitfalls.md`.

## From the request to a list of views

Before writing code, turn the request into needs and pick a view for each
from `references/widgets.md` (the first match is the simpler one), then
read their pages in `docs/ui/`. Prefer the framework's view over a
hand-built one every time one fits; say which you chose and why in a line
before you build.

## Shape of an app

Keep the state and its actions in a class, and the view in `view.php`, a
file that returns a closure returning the root view: `Content::file()`
runs it bound to the state (`$this` is the state, private members and
`self::` included) and, in development, loads it again when it is saved,
the state kept (hot reload, as Flutter's). A test builds the same view
without a window.

```
my-app/
  myphp.json        name, identifier, entry "app.php", framework
  boot.php          loads MyPHP (from myphp init; keep it)
  app.php           makes the state, opens the windows, runs the app
  src/Todos.php     the state and its actions (no view)
  view.php          return fn() => VStack()(…); — the view
  pages/note.php    a page of a NavigationStack (several pages)
  tests/ui_test.php builds the view headless and drives it
  resources/icon.png
```

```php
<?php // src/Todos.php
declare(strict_types=1);

final class Todos
{
    /** @var list<array{id: int, title: string, done: bool}> */
    public array $items = [];
    public string $draft = '';   // the field's text, bound by TextField
    private int $next = 1;

    public function add(): void
    {
        $title = trim($this->draft);
        if ($title === '') { return; }
        $this->items[] = ['id' => $this->next++, 'title' => $title, 'done' => false];
        $this->draft = '';
    }

    public function left(): int
    {
        return count(array_filter($this->items, fn(array $i) => !$i['done']));
    }
}
```

```php
<?php // view.php — $this is the Todos app.php passes to Content::file
declare(strict_types=1);

/** A row of the list: a part of the view is a closure here, not a method or a class. */
$row = fn(array $item, int $i) => Toggle($item['title'], isOn: $this->items[$i]['done']);

return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
    HStack(spacing: 8)(
        TextField('New to-do', text: $this->draft)->onSubmit(fn() => $this->add()),
        Button('Add', action: fn() => $this->add())->buttonStyle(ButtonStyle::borderedProminent)->disabled(trim($this->draft) === ''),
    ),
    ListView($this->items, id: 'id')($row),
    Text('{n} left', n: $this->left())->foregroundStyle(Color::secondary), // a key and its placeholder, translatable (an ICU message: '{n, plural, one {# word} other {# words}}' too); not "{$n} left", a key for every value; Text(verbatim:) for what the user wrote
)->padding(16);
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

Where PHP differs from SwiftUI (the only differences, `docs/swiftui.md`):

| SwiftUI | MyPHP |
|---|---|
| `VStack(spacing: 12) { … }` | `VStack(spacing: 12)( … )`: the second parentheses are the braces, children separated by commas |
| `if cond { Text("a") }` | `$cond ? Text('a') : null`: `null` is no view, an array is several |
| `ForEach(items, id: \.id) { … }` | `Each($items, id: 'id')(fn(array $item) => …)` |
| `List { … }` | `ListView( … )` (`list` is a PHP keyword) |
| `Link("Docs", destination: url)` | `WebLink('Docs', destination: $url)` |
| `@State var n = 0` | a property of the state class |
| `$binding` | the property itself: `Toggle('Wi-Fi', isOn: $this->wifi)` (PHP passes by reference) |
| `.font(.largeTitle)` | `->font(Font::largeTitle)`: the enum is named |
| `struct Row: View` | `$row = fn(Item $i) => …` in the view file |

- `view.php` holds closures only: its parts (`$row`, `$sidebar`…) are
  closures made before the `return`; a closure that calls one made after
  it takes it by reference (`use (&$part)`). A named class or function
  there cannot be loaded twice: its change waits for a restart.
- Several pages: `NavigationStack(path: $this->path)( root )` with
  `->navigationDestination(for: '/notes/{id}', destination: __DIR__ .
  '/pages/note.php')`; a page file returns `fn(int $id) => …`, `{id}`
  given by name and type, `$this` the state too; `NavigationLink($title,
  value: "/notes/$id")` goes there (`examples/router`).
- What the view does to the state goes through the class's methods
  (`$this->add()`); what only draws stays in `view.php`. A window of its
  own has a file of its own: `Content::file(__DIR__ . '/list.php', $this)`.
- `myphp dev` and `myphp run` hot reload on a save (or `r`); `R`
  restarts the app, its state lost. A change to the class, `app.php` or a
  constant needs `R`.

Start a project with `myphp init -template native <dir>` (from the
framework's `bin/myphp`): it makes this shape, `app.php` and `view.php`.

## Navigation for every screen

An app of a few top-level pages is one `TabView(selection:)` with
`->tabViewStyle(TabViewStyle::sidebarAdaptable)`, each page a
`Tab(title, systemImage:, value:)`: the framework shows it as a sidebar
(a `NavigationSplitView`) on the desktop and on a tablet on its side, a
capsule of tabs at the top of a tablet held upright, and the tab bar
across the bottom of a phone. Do not test for the platform or the
window's size to switch between a sidebar and tabs yourself; do not use
`NavigationSplitView` for an app's pages, which on a phone would keep a
desktop's sidebar — it is for a sidebar of the app's data on the desktop.
Give the window `titleBarStyle: 'hiddenInset', vibrancy: 'sidebar'` for
the Mac's look, and each page its `navigationTitle`. `examples/airpods`
is the model. A search tab is `Tab(value: 'search', role:
TabRole::search)` with `->searchable()` on its page.

## Rules of the view

- The view runs for every frame, maybe several times for one event. It
  only reads the state and returns views; actions (`action:`,
  `onSubmit`, `onTapGesture`…) change the state. Never change the state
  or do I/O while building.
- Everything the app keeps is a property of the state class; the
  framework keeps what is the controls' own (a field's cursor, a list's
  scroll) by the view's place and its `id`.
- Give what repeats a stable id: `Each($items, id: 'id')`,
  `ListView($items, id: 'id')`, or `->id($key)` on a view that comes and
  goes; never the index of a list that can reorder or shrink.
- Controls bind to the property itself, by reference:
  `Toggle($t['title'], isOn: $this->items[$i]['done'])`, not to the
  copy a loop or a closure parameter holds.
- Colors follow the theme, light or dark, by SwiftUI's names:
  `Color::primary`, `::secondary`, `::accentColor`, `::background`,
  `::secondarySystemBackground`… (`docs/swiftui.md`); `Color::rgb(0x336699)`
  is one of the app's own. Fonts are text styles (`Font::headline`,
  `Font::caption`), which follow the system's text size. Times are
  seconds.
- Something that changes with time (a clock, a countdown) is a
  `TimelineView()(fn(TimelineViewContext $context) => …)`; work that
  starts with a view is its `->task(fn() => …)`, a fiber.
- One thread: never block the view with I/O. Do slow work in
  `App::go(fn() => ...)` (or `->task(fn() => ...)`), a fiber, where
  `URLSession::shared()` (`data(from:|for:)`, `json()`, `decode()`,
  `upload()`, `download()`, with `URLRequest`) waits without stopping the
  window, then publish with `$window->update(fn() => ...)`. Remote images
  use `AsyncImage($url)`. Always prefer SwiftUI/Foundation APIs
  (`URLSession`, `URLRequest`, `AsyncImage`, `ContentUnavailableView`,
  `FlowLayout`, `LazyVGrid`) over low-level helpers (`MyPHP\Http`,
  `Image::fromBytes`). SQLite is synchronous: small queries only. See
  `references/async.md`.
- Prefer the framework's views over hand-built ones: they carry focus,
  keyboard, disabled, accessibility and theme behavior. Give icon-only
  buttons an `->accessibilityLabel('…')` for screen readers and tests.
- What no view does is a `Canvas(fn(GraphicsContext $g, CGSize $size) =>
  …)` for a drawing of your own, or a `Representable(fn(Context $c) =>
  …)` for a control of your own: the engine's builder inside one view, as
  SwiftUI's `NSViewRepresentable` (`docs/ui/custom-widgets.md`). Nowhere
  else does a view take a `Context`.

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
   use MyPHP\UI\Content;
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
   $h->clickText('Milk');
   $h->frame();
   same(true, $app->items[0]['done'], 'a click on the to-do checks it');
   exit(empty($GLOBALS['failures']) ? 0 : 1);
   ```

   Run it with `php -d ffi.enable=1 tests/ui_test.php`. `Headless` finds
   nodes by text or label (`byText`, `byLabel`, `clickText`,
   `clickLabel`), types, presses keys (`enter()`, `tab()`, `escape()`…)
   and lists what shows (`texts()`); see `docs/ui/testing.md`. With
   `$h->rt->strict = true` duplicate ids throw instead of only logging.
   To replace a field's text, click it, select all with
   `$h->shortcut('a', Key::COMMAND)` (`use MyPHP\UI\Key`), then `type()`.
   For a phone's look, set `$h->rt->touch = true` and
   `$h->theme = MyPHP\UI\Theme::phone(false)` before the first frame.
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
