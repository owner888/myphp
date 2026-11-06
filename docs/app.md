# The application

`MyPHP\App` is the application: its lifecycle, its identity, and how it
meets the rest of the system. Its methods are static.

## The main script

```php
<?php
declare(strict_types=1);

use MyPHP\{App, Ipc, Window, WindowOptions};

require __DIR__ . '/vendor/autoload.php';

Ipc::bind(new Notes());                 // services the frontend calls
App::whenReady(function () {            // once the app has launched
    new Window(new WindowOptions(url: '/'));
});
App::run();                             // the event loop, until the app quits
```

`App::run()` runs the native event loop and returns when the app quits.
Windows exist only once the app is ready: create them in `whenReady()`.

Before `run()`, the script sets the app up: it binds services, handles
protocols, adds listeners, and makes settings such as `App::setName()`,
`App::setMenu()`, `Dock::setMenu()` and `App::setTheme()`, which take
effect when the app starts. It may also read what needs no running app,
such as `App::path()` or `App::locale()`, and register the app with the
system (`App::setOpenAtLogin()`, `App::registerURLScheme()`). The
clipboard, displays, dialogs, notifications, global shortcuts, trays and
the like need the running app: use them from `whenReady()` on.

## The log

`Logger::verbose()`, `debug()`, `info()`, `warn()`, `error()` and `fault()`
write a line of the app's log, `[subsystem][W] message`, under a
subsystem of the app's naming ("app" when none): kept for the log viewer
(`Context::logViewer`), written to the terminal in development, to a
file with `Logger::logToFile(true)`, and on iOS and Android to the system
log, which `myphp run` shows, each line's level a letter (E, F, W, I, D,
V) and colored. `Logger::setLevel(Logger::INFO)` drops what is under it;
in development it is `DEBUG`, built for production `WARNING`.

## One thread

PHP runs the app on one thread, the main one, which also draws the UI:

- Event listeners, such as a window's `close` or `App::onActivate()`, menu
  item clicks, global shortcuts and the methods pages call run on it: keep
  them short.
- Work that waits, as a download, runs in a fiber of its own with
  `App::go()`: what waits in it (an HTTP request of `MyPHP\Http`, see
  [from PHP or native UI](plugins/fetch.md#from-php-or-native-ui); an
  image decoding) suspends the fiber and lets the app draw and take events
  until it resumes. Outside a fiber, `App::wait($until)` turns the run loop
  until `$until()` is true, asking it again at least every 50 ms, so a
  condition only time makes true ends in time. The fetch plugin's requests
  are the page's and do not wait in PHP: a bound method that returns a
  `Generator` streams each value a turn of the run loop apart. SQLite
  (`Sqlite::open()`) is synchronous: a query runs to its end on the main
  thread. `MyPHP\Process` runs a program without waiting (see
  [processes](native.md#processes)).
- `App::later($fn)` runs a function on the next turn of the run loop, and
  `App::after($seconds, $fn)` after a delay.

## Lifecycle

| Event | When |
|---|---|
| `onReady($fn)` | the app has launched; `whenReady()` also runs its function right away when the app is already ready |
| `onActivate(fn(bool $hasVisibleWindows))` | the app was activated again, e.g. by a click on its Dock icon (macOS) |
| `onDidBecomeActive($fn)`, `onDidResignActive($fn)` | the app became, or stopped being, the active app |
| `onWindowCreated(fn(Window $w))` | a window was created |
| `onWindowAllClosed($fn)` | the last window closed, unless the app is quitting |
| `onBeforeQuit(fn(QuitEvent $e))` | a quit started, before the windows close; `$e->cancel()` cancels it |
| `onWillQuit(fn(QuitEvent $e))` | every window has closed and the app is about to quit; it can still be canceled |
| `onQuit($fn)` | the event loop stopped, right before `run()` returns |

Each returns a function that removes the listener.

### Quitting

When its last window closes, an app quits, unless `onWindowAllClosed()`
has a listener. On macOS apps usually keep running with their Dock icon,
and open a window again when it is clicked:

```php
App::onWindowAllClosed(function () {
    if (PHP_OS_FAMILY !== 'Darwin') {
        App::quit();
    }
});
App::onActivate(function (bool $hasVisibleWindows) {
    if (!$hasVisibleWindows) {
        openMainWindow();
    }
});
```

- `App::quit()` closes every window, which `close` listeners can refuse,
  then quits; `onBeforeQuit()` and `onWillQuit()` listeners can cancel it
  too. Cmd+Q, the Quit menu item and Ctrl+C in the terminal (SIGINT and
  SIGTERM) quit the same way.
- `App::exit($code)` exits right away, without events.
- `App::relaunch()` quits, then starts the app again, for example after a
  setting that needs a restart.

## A single instance

Most desktop apps run once: starting them again brings the running
instance to the front. `requestSingleInstanceLock()` returns false in a
second instance, after passing its command line to the first:

```php
if (!App::requestSingleInstanceLock()) {
    exit; // the running instance takes over
}
App::onSecondInstance(function (array $args, string $workingDir) {
    if (($w = mainWindow()) !== null) {
        $w->restore();
        $w->focus();
    }
});
```

## Deep links

Apps open URLs of their own schemes, such as `myapp://open?note=42`. List
the schemes in myphp.json, which registers them with the system when the
app is installed:

```json
{ "urlSchemes": ["myapp"] }
```

and handle the URLs with `onOpenURL()`, registered before `run()` so it
also gets the URL the app was started with:

```php
App::onOpenURL(function (string $url) {
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    openNote($q['note'] ?? '');
});
```

On Linux a URL starts a new instance of the app: request the [single
instance lock](#a-single-instance), and the URLs of later instances reach
`onOpenURL()` in the first one. Apps not installed with the package
`myphp build` makes register their schemes at run time with
`App::registerURLScheme('myapp')`; `unregisterURLScheme()` and
`isURLSchemeRegistered()` go with it. On macOS schemes must be listed in
the configuration.

## File associations

Apps open files of their types from the file manager. Declare the types in
myphp.json:

```json
{
  "fileAssociations": [
    { "ext": ["md", "markdown"], "name": "Markdown Document", "mimeType": "text/markdown" }
  ]
}
```

and open them with `onOpenFile()`, registered before `run()` so it also
gets the files the app was started with:

```php
App::onOpenFile(function (string $path) {
    openDocument($path);
});
```

It also gets files dropped on the app's Dock icon (macOS) and files passed
on the command line. Apps whose windows each show a file get this and the
rest of what document-based apps do from [documents](documents.md).

## Recent documents

Record the documents the user opens, so that the app and the system offer
them again:

```php
App::addRecentDocument($path);
```

`App::recentDocuments()` returns them, newest first, ten at most, also
those of earlier runs, without the files that no longer exist; the Open
Recent menu (the `openRecent` role, see [menus](menus.md)) lists them.
macOS lists them in the app's Dock menu too, Linux in the user's recently
used files. Choosing one opens the file with the app, which gets it
through `onOpenFile()`. `clearRecentDocuments()` removes them all, as the
Open Recent menu's Clear Menu does.

## Start at login

```php
App::setOpenAtLogin(true);
$enabled = App::openAtLogin();
```

Offer it as a setting. The app becomes a login item of the user on macOS
13 and later (which needs the packaged app), and an XDG autostart entry on
Linux, which the system's settings list and can turn off.
`wasOpenedAtLogin()` tells a launch at login apart, to start in the
background:

```php
new Window(new WindowOptions(url: '/', hidden: App::wasOpenedAtLogin()));
```

## Directories

`App::path()` returns well-known directories:

| Name | Directory |
|---|---|
| `PathName::UserData` | the app's data: `~/Library/Application Support/<Name>` or `~/.config/<Name>`; created on first use |
| `PathName::Cache` | the app's cache: `~/Library/Caches/<Name>` or `~/.cache/<Name>`; created on first use |
| `PathName::Logs` | where to write logs: `~/Library/Logs/<Name>`, else `logs` in the user data directory |
| `PathName::Resources` | the files the app ships with, see [resources](distribution.md#resources) |
| `PathName::AppData` | the per-user configuration directory, without the app's name |
| `PathName::Home`, `Temp`, `Exe` | the home directory, the temporary directory, the executable |
| `PathName::Desktop`, `Documents`, `Downloads`, `Music`, `Pictures`, `Videos` | the user's folders |

```php
use MyPHP\{App, PathName};

$db = App::path(PathName::UserData) . '/notes.db';
```

`<Name>` is `App::name()`: what `App::setName()` set, else the script's
file name; not the `identifier` of the configuration, as mygo's
`Path` joins the application's name. Call `App::setName()` before the first
`App::path()`, which makes the directory. `App::setPath()` overrides a
directory, for example to keep test data apart; `myphp screenshot -data`
moves them all for a run (see [the CLI](cli.md#myphp-screenshot)).

## Name and version

A packaged app knows its name and version from its configuration, which
`myphp build` writes into it: `App::name()` and `App::version()`. A script
run with `php` has no name, unless it calls `App::setName()` and
`App::setVersion()`, before `run()`. The name sets the user data, cache
and log directories and the single instance lock. Development builds of
`myphp dev` are named `<Name> Dev`, so that they keep their data apart
from the installed app's.

`App::isDev()` reports whether the app runs in development: true unless
it was built for production with `myphp build`, or `MYPHP_ENV=production`
is set. `App::isPackaged()` reports whether it runs from an app bundle.
`App::locale()` returns the language the app shows, of those it is written
in, and `App::preferredLanguages()` the languages the user reads, as the
system lists them; see [the language the app shows](i18n.md).

## The Dock

- `App::setBadgeCount($n)` shows a count on the app's icon: the Dock
  (macOS) or the launcher entry (Linux docks that support it); zero clears
  it.
- `Dock` controls the Dock icon on macOS: `setBadge()` shows any text,
  `bounce()` asks for attention, `setIcon()` replaces the icon, `setMenu()`
  adds items to its menu (see [the Dock menu](menus.md#the-dock-menu)), and
  `hide()` and `show()` remove and restore it.
- `App::setActivationPolicy(ActivationPolicy::Accessory)` removes the Dock
  icon of an app that lives in the menu bar, see [tray
  icons](menus.md#tray-icons); its windows can still show.
  `App::activationPolicy()` returns the policy.
- `App::focus()` brings the app to the front, and `App::isActive()`
  reports whether it is there. On macOS, `App::hide()` and `App::show()`
  hide and show all its windows, and `App::showAboutPanel()` shows the
  standard about panel.

## Browsing data

`App::clearBrowsingData()` deletes what the app's pages stored: cookies,
local and session storage, IndexedDB, service workers and caches, for
example when the user signs out. Open pages keep what they hold in memory
until they reload. It clears the default session; `Session::clearData()`
clears another, or some kinds of data only.

### Cookies

`Cookies` reads and changes the cookies of the app's pages, `httpOnly`
ones included: the default cookie store, which all windows share and which
the system webview keeps from one run to the next. Set one, for example,
to sign the pages in with a token the backend got, before the first page
loads, or read the session a page's sign-in set:

```php
Cookies::set(new Cookie(
    name: 'session',
    value: $token,
    domain: 'example.com',
    secure: true,
    httpOnly: true,
    expires: new DateTimeImmutable('+30 days'),
));

// The cookies a request to the URL sends.
$sent = Cookies::list(new CookieFilter(url: 'https://example.com/account'));
// Those named session of example.com and its subdomains.
$sessions = Cookies::list(new CookieFilter(name: 'session', domain: 'example.com'));
// Every cookie, sorted by domain, path and name.
$all = Cookies::list();

Cookies::delete(new Cookie(name: 'session', domain: 'example.com'));
Cookies::clear(); // every cookie, but not the pages' storage
```

A cookie's name, domain and path identify it: `set()` replaces the cookie
of the same name, domain and path, and `delete()` deletes it, whatever its
other fields, so it deletes a cookie `list()` returned. A domain without a
leading dot is a host's, sent to that host only; with one,
`.example.com`, the cookie goes to its subdomains too. An empty path is
`/`. An empty `sameSite` is `Cookie::SAME_SITE_LAX`, and
`SAME_SITE_NONE` needs `secure`. A null `expires` makes a cookie that
lasts until the app quits, and one in the past deletes the cookie.

A filter's fields narrow the list: `url`, an http or https URL, keeps the
cookies a request to it sends; `name` keeps those of a name; `domain`
those of a domain and its subdomains, with or without a leading dot;
`path` those of exactly a path.

Cookies may carry the user's credentials: rather than binding the class to
the pages, bind a method that does what the page needs. On Linux they need
WebKitGTK 2.42 or newer.

### Sessions

Every page shows in the default session unless `PageOptions::$session`
names another: a store of cookies, local and session storage, IndexedDB,
caches and service workers of its own, which the pages of other sessions
don't see, as Electron's partitions. Two accounts of a service side by
side, a private window, a preview that must not see the user's sign-ins,
or a third-party page kept away from the app's own storage each take one:

```php
$work = Session::newSession('work');          // kept on disk from one run to the next
$private = Session::newEphemeralSession();    // in memory only

$win = new Window(new WindowOptions(
    url: 'https://mail.example.com',
    page: new PageOptions(session: $work),
));

$list = $work->cookies->list();
$work->cookies->set(new Cookie(name: 'session', value: $token, domain: 'example.com'));
$work->clearData(SiteData::ALL);                         // signing out
$work->clearData(SiteData::COOKIES | SiteData::CACHES);  // some kinds
```

`newSession()` returns the same session for the same name, whose data
stays on disk as the default session's does. `newEphemeralSession()`
returns a new session every time, which writes nothing to disk: its data
goes when the last page using it closes. `Cookies` and
`App::clearBrowsingData()` are the default session's, which
`Session::defaultSession()` returns too. A window a page opens with
`window.open` is in its opener's session. Custom protocols (`Protocol`)
serve the pages of every session, and a web view in [native
UI](ui/web-view.md) takes a session in its `PageOptions` too.

`clearData()` takes `SiteData::COOKIES`, `LOCAL_STORAGE` (with session
storage), `INDEXED_DB`, `CACHES` (the HTTP cache and Cache Storage),
`SERVICE_WORKERS`, or `ALL`.

| | persistent session | ephemeral session |
|---|---|---|
| macOS | `WKWebsiteDataStore dataStoreForIdentifier:` with a UUID made of the name, from macOS 14; before, an in-memory store that lasts until the app quits | `nonPersistentDataStore` |
| Linux | a web context of its own, with its data under the user data directory | a web context with an ephemeral website data manager |
