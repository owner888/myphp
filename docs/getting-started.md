# Getting started

A MyPHP app shows its windows with a web frontend, in the system's webview,
or with [native UI](ui/README.md) that MyPHP draws itself, written in PHP.
`myphp init` starts a project of either kind, and an app can add windows of
the other kind later.

## Install

Install PHP 8.3 or later with `ext-ffi` (`ffi.enable=1` for scripts run
outside `myphp dev`). A project with a web frontend also needs
[Bun](https://bun.sh), which runs its tools, and apps with web pages need
the system's webview: on Linux, GTK 3 and WebKitGTK 4.1, e.g. `sudo apt
install libwebkit2gtk-4.1-0` on Debian and Ubuntu.

Native UI needs nothing more: it runs on what the system has (GTK 3 on
Linux).

The `myphp` command line tool creates, runs and packages apps. It is a PHP
script, `bin/myphp` in a checkout of MyPHP:

```sh
bin/myphp init my-app
```

Projects with a web frontend also depend on it through npm, as the
`myphp-cli` package, which their scripts run. See [the CLI](cli.md).

`myphp doctor` checks that the machine has what MyPHP needs.

## Create a project

### A web frontend

`myphp init -template web my-app` creates the PHP app and a TypeScript
frontend built with [Vite](https://vite.dev), side by side, and installs
their dependencies:

```
my-app/
├── app.php           the app: its windows and the PHP code the page calls
├── boot.php          loads MyPHP
├── myphp.config.ts   the app's name, identifier and version, and how to build it
├── package.json      the scripts, and the frontend's dependencies
├── index.html        the page
├── src/
│   ├── main.ts       the page's code
│   ├── style.css
│   └── myphp.ts      the typed client of the PHP code, generated
├── vite.config.ts
├── tsconfig.json
└── resources/
    └── icon.png      the app icon, a 1024×1024 PNG
```

Builds go to `dist/` (the frontend) and `build/` (the packaged apps), and
the development app to `.myphp/`; `.gitignore` leaves them out. See
[configuration](configuration.md) for the fields of `myphp.config.ts`.

### Native UI

`myphp init my-app` (or `-template native`) creates an app whose window
shows [native UI](ui/README.md): PHP alone, with no frontend and no Bun.

```
my-app/
├── app.php          the app: its state, its view and its window
├── boot.php         loads MyPHP
├── myphp.json       the app's name, identifier and version
└── resources/
    └── icon.png     the app icon, a 1024×1024 PNG
```

`boot.php` loads MyPHP from the checkout that `framework` in the
configuration names, or from `MYPHP_HOME`, which `myphp dev` and `myphp
generate` set, and from the copy inside the app once it is built.

## Develop

```sh
cd my-app
bun run dev        # a web frontend: runs myphp dev
myphp dev          # native UI
```

`myphp dev` builds a development version of the app and starts it, then
rebuilds and restarts it when you edit a `.php` file, the configuration,
the icon or a resource. A build that fails keeps the previous one running.
Quit the app, or press Ctrl+C, to stop.

With a web frontend, it also writes `src/myphp.ts` and starts the Vite dev
server, from which the development app loads its pages: edit `src/main.ts`
or `src/style.css` and Vite updates the page right away. Development builds
have the web inspector: right-click the page and choose Inspect Element,
or call `$win->page()->openDevTools()`.

On macOS the development app is a real app bundle, `My App Dev` with the
identifier of the app plus `.dev`, so that it keeps its data, preferences
and permissions apart from the installed app.

## Call PHP

### From the page

`app.php` binds a PHP object, whose public methods the page can call:

<!-- no-test -->
```php
/** Greeter is callable from the frontend: `myphp generate` turns its methods into typed TypeScript functions in src/myphp.ts. */
final class Greeter
{
    /** Greet returns a greeting for name. */
    public function greet(string $name): string
    {
        return 'Hello, ' . ($name === '' ? 'stranger' : $name) . '! This message comes from PHP.';
    }
}

/** Tick is sent to the page every second: the time, in milliseconds since 1970. */
$tick = new Event('tick', 'int');

Ipc::bind(new Greeter());
```

`src/myphp.ts`, which `myphp dev` keeps up to date, turns them into typed
functions and events:

```ts
import { Greeter, events } from "./myphp";

const greeting = await Greeter.greet("world"); // Promise<string>
events.tick.on((time) => console.log(time)); // time: number
```

`app.php` calls `App::setName()` first, before it makes its services:
`App::path()` names the app's data folder (`PathName::UserData`, …) after
the name, and keeps the folder it found, so a service made before the name
was set, as `new Notes(App::path(PathName::UserData) . '/notes.json')`,
would keep its data under the wrong folder.

Add a method to `Greeter`, save, and it is there to call once the app has
restarted. [Calling PHP from the frontend](bindings.md) covers what methods
can take and return, errors, events and security.

### From native UI

Native UI is PHP, so it calls your code directly, with no bindings. The
view is a function of your app's state that MyPHP calls to build each
frame; it asks its elements what happened since the last one:

```php
$app = new class {
    public TextState $name;
    public string $greeting = '';
    public function __construct() { $this->name = new TextState(); }
};

$view = function (Context $c) use ($app) {
    $c->column(0, 16, 1)->fill()->alignItems(Node::CENTER)->justify(Node::CENTER);
      $c->input($app->name, 'Your name', 240);
      if ($c->clicked($c->primaryButton('Greet'))) {
          $app->greeting = 'Hello, ' . $app->name->text . '!'; // your PHP code
      }
      $c->text($app->greeting);
    $c->end();
};
```

`tests/Headless.php` clicks and types into a view without a window, as
fast as a unit test (see [testing](ui/testing.md)). [Native
UI](ui/README.md) covers layout, widgets, text, input, drawing and
changing the state from other work.

## Build

```sh
bun run build        # a web frontend: runs myphp build
myphp build          # native UI
```

`myphp build` makes the app, with the frontend built by Vite in it when
there is one, and packages it for the machine's platform in
`build/<os>-<arch>/` (the templates set `out` to `build`):

| Platform | Output |
|---|---|
| macOS | `My App.app`, and a disk image `My App 0.1.0 arm64.dmg` |
| Linux | the launcher `my-app` with its desktop entry and icon, their archive, a `.deb` package and a PKGBUILD |

```sh
myphp build -platform darwin/universal
```

The apps run where they are, but to ship them to users, sign them: see
[Building and distributing](distribution.md).

## Next steps

- [Windows](windows.md) and [the application](app.md), to shape the app.
- [Native UI](ui/README.md), to build interfaces in PHP, or
  [the frontend](frontend.md), for web pages.
- [Menus and the tray](menus.md) and the [desktop APIs](native.md).
- The examples in the repository, which `./run.sh <name>` runs:
  - with web pages: `examples/hello` (the smallest app), `examples/todo`
    (typed services and events, persistence, dialogs, menus, several
    windows), `examples/frameless` (a custom title bar) and
    `examples/native` (menus, a tray icon, dialogs, notifications, a
    global shortcut, the clipboard and dark mode);
  - with native UI: `examples/counter-native` (a counter),
    `examples/clipboard` (copying and dragging text, HTML, lazy JSON, and
    file lists), `examples/effort-slider`, `examples/confetti`,
    `examples/blackhole` and `examples/chat-glow` (shaders and motion),
    `examples/timeline` (an intro on one playhead) and `examples/gallery`
    (a tour of the toolkit: layout, widgets, text editing, a long list,
    drawing and overlays).
