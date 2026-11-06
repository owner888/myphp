# Desktop APIs

MyPHP wraps the parts of the operating system desktop apps use most, for
apps whose windows show web pages, native UI or both. They are static
classes of `MyPHP`, called on the main thread, from `App::whenReady()` on.

## Dialogs

`Dialog` shows the system's file and message dialogs. Its methods wait
until the user answers, the app drawing and taking events meanwhile:
call them from a menu item, a bound method or `App::later()`. `parent`
attaches a dialog to a window, as a sheet on macOS.

```php
// Pick files.
$paths = Dialog::open(new OpenDialogOptions(
    parent: $win,
    title: 'Import',
    filters: [new FileFilter('Images', ['png', 'jpg'])],
    multiple: true,
)); // [] when canceled

// Choose where to save.
$path = Dialog::save(new SaveDialogOptions(
    parent: $win,
    defaultPath: 'notes.json',
)); // '' when canceled

// Ask a question.
$res = Dialog::message(new MessageOptions(
    parent: $win,
    type: 'warning',
    message: 'Delete 3 notes?',
    detail: 'You cannot undo this.',
    buttons: ['Cancel', 'Delete'],
    destructiveButtons: [1],
    checkboxLabel: "Don't ask again",
));
if ($res->button === 1) { /* Delete */ }

// Report an error.
Dialog::error('Import failed', $e->getMessage());
```

`OpenDialogOptions` also pick directories (`directory`), show hidden files
and, on macOS, a message and a New Folder button. In message dialogs Enter
activates `defaultButton` and Escape `cancelButton` (by default the first
button labeled Cancel or No); on macOS Cmd+. cancels too.
`destructiveButtons` sets apart buttons that delete or can't be undone.
`Dialog::prompt()` asks for a line of text.

## Notifications

```php
$n = new Notification(new NotificationOptions(
    title: 'Export finished',
    body: 'notes.json was saved to Documents.',
));
$n->onClick(fn() => $win->focus());
$n->show();
```

`id` replaces a notification of the same id still showing, `group` groups
them in the notification center, `silent` plays no sound and `subtitle`
shows under the title; `Notification::supported()` tells whether the
system shows them, `close()` takes one away and `Notification::clear()`
all of the app's. `App::onNotificationClick()` hears clicks on
notifications the app showed before it was started again.

## Clipboard

```php
Clipboard::writeText('hello');
$text = Clipboard::readText();

Clipboard::writeHTML('<b>hello</b>');
Clipboard::writeImage($png); // a PNG
$img = Clipboard::readImage();

$formats = Clipboard::availableFormats();
Clipboard::clear();
```

Pages can use the web's `navigator.clipboard` too.

`Clipboard::write($data)` and `Clipboard::read(...$formats)` share the
drag and drop model of `MyPHP\Transfer\Data`: ordered items, alternative
representations, file lists, custom MIME formats, and lazy providers.
`formats()` discovers portable names without requesting bytes;
`readFormat()`, `readFiles()`, `writeFiles()`, and `flush()` provide
explicit negotiation, file transfer, and persistence. See [Clipboard and
drag data](data-transfer.md). The [clipboard example](../examples/clipboard)
demonstrates these operations.

## The shell

```php
Shell::openExternal('https://example.com'); // the default browser
Shell::openPath('/Users/ada/report.pdf');   // the default app for the file
Shell::showItemInFolder($path);             // Finder, the file manager
Shell::trashItem($path);                    // to the trash
Shell::beep();
```

`trashItem()` never deletes for good: when the item cannot go to the
trash it throws and leaves the item where it is.

`Haptics` plays haptic feedback as UIKit's feedback generators do, on a
phone's Taptic Engine or vibrator and on the Mac's trackpad while a
finger rests on it (nothing on Linux): `Haptics::impact('light')`
(`'medium'`, `'heavy'`, `'soft'`, `'rigid'`) for a press or a collision,
`Haptics::selection()` as a picker ticks, `Haptics::success()`,
`warning()` and `error()` for an outcome. Call it from the action, once:
as a switch flips, a long press opens a menu, a save succeeds — not on
every button, which the system's own controls do not do either.

```php
if ($c->changed($c->switch('Wi-Fi', $app->wifi))) { Haptics::impact('light'); }
```

`fileIcon()` returns the icon the file manager shows for a file, a folder
or an application, as a PNG of the size asked for in pixels: pass the size
in points times the display's scale factor.

```php
$png = Shell::fileIcon('/Applications/Safari.app', 64);
```

On macOS it is Finder's icon (`NSWorkspace`); on Linux the icon of the
file's content type from the icon theme, and for a desktop entry, the icon
of its application.

`installedApps()` lists the applications installed on the computer,
sorted by name, as a launcher shows them, and `launchApp()` starts one, or
brings it to the front:

```php
$apps = Shell::installedApps();
foreach ($apps as $app) {
    echo $app->name, ' ', $app->id, "\n";
}
$icon = Shell::fileIcon($apps[0]->path, 64);
Shell::launchApp($apps[0]);
```

Each app has the `name` the system shows, in the user's language, an `id`
and the `path` whose icon `fileIcon()` draws: the `.app` bundles of
`/Applications` and the system's on macOS, with the bundle identifier as
their id; the desktop entries menus show on Linux, with the desktop file
ID. `Shell::appIcon($size)` is the app's own icon.

## Displays

`Screen` describes the connected displays, in points with the origin at
the top-left corner of the primary display:

```php
$primary = Screen::primaryDisplay();
[$bounds, $workArea, $scale] = [$primary->bounds, $primary->workArea, $primary->scaleFactor];

foreach (Screen::displays() as $d) {
    echo $d->label, ' ', $d->bounds->width, '×', $d->bounds->height, "\n";
}

$p = Screen::cursorScreenPoint();
$under = Screen::displayNearestPoint($p);
```

A `Display` has its `bounds`, its `workArea` (without the menu bar and the
Dock), its `scaleFactor`, `rotation`, and whether it is `internal` or the
`primary` one. `displayMatching($rect)` returns the display a rectangle
is mostly on, and `onDisplaysChanged()` hears displays come, go and
change.

## Dark mode

```php
$dark = Theme::isDark();
Theme::onUpdated(fn() => applyTheme(Theme::isDark()));
Theme::setSource(ThemeSource::Dark); // or Light; System follows the system
```

`MyPHP\Theme` is the appearance of the app's windows and pages; native UI
draws with `MyPHP\UI\Theme`, which follows it (see
[themes](ui/styling.md#themes)). Pages follow it with
`prefers-color-scheme`.

## Accent color and accessibility

`Theme::preferences()` returns the settings of the desktop that the app's
look follows: the `accent` color the user chose, and whether they asked
for `reduceMotion`, `reduceTransparency` or `highContrast`, and the
`textScale`. `Theme::onUpdated()` hears them change too. Pages get them as
`window.myphp` preferences, and native UI as
[`$c->preferences()`](ui/styling.md#the-desktops-preferences).

## Power

```php
Power::onSuspend(fn() => pauseSync());
Power::onResume(fn() => resumeSync());
$release = Power::keepAwake('Exporting video', display: false); // keeps the system awake
// … when done:
$release();
$onBattery = Power::isOnBattery();
$level = Power::batteryLevel(); // null without a battery
$idle = Power::idleTime();      // seconds since the user's last input
```

`onLockScreen()` and `onUnlockScreen()` hear the screen lock.
`keepAwake()` with `display: true` keeps the display on too, as a video
player does.

## Now Playing and media keys

`NowPlaying` tells the system what the app plays, which shows in Control
Center and on the lock screen, and takes the media keys and the AirPods'
controls:

```php
NowPlaying::set(new NowPlayingInfo(
    title: 'Song',
    artist: 'Artist',
    duration: 215,
    position: 12,
    rate: 1,
    commands: MediaCommand::PLAY | MediaCommand::PAUSE | MediaCommand::TOGGLE | MediaCommand::NEXT | MediaCommand::SEEK,
));
NowPlaying::onCommand(function (int $cmd, float $position) use ($player) {
    match ($cmd) {
        MediaCommand::PLAY => $player->play(),
        MediaCommand::PAUSE => $player->pause(),
        default => null,
    };
});
NowPlaying::setPlayback(PlaybackState::Paused, 30.5);
NowPlaying::clear();
```

## Network

```php
$status = Network::status();     // online, metered
Network::onChanged(function (NetworkStatus $s) {
    if (!$s->online) {
        showOffline();
    }
});
```

`online` tells whether the computer has a route to the internet, and
`metered` whether the connection costs, as a phone's hotspot.

### Proxies

MyPHP's own requests (`MyPHP\Http`'s, the updater's, those of the fetch
and WebSocket plugins) go through the proxy the user set in the system's settings, as
the app's pages do, with its exceptions and setup scripts. Loopback hosts
are reached directly. When any of `HTTP_PROXY`, `HTTPS_PROXY`,
`ALL_PROXY` or `NO_PROXY` (or its lowercase name) is set, the environment
decides instead, as curl reads it. The system's answer is kept for 5
seconds, so a change of the settings applies without restarting the app.

## Processes

`MyPHP\Process` runs a program without blocking the app: its output is
read as it comes, polled on the run loop, and a function gets all of
stdout, stderr and the exit code once it ends.

<!-- no-test -->
```php
use MyPHP\Process;

$p = Process::run(['git', 'log', '--oneline', '-20'], '', function (string $out, string $err, int $code) use ($app, $win) {
    $win->update(function () use ($app, $out, $err, $code) {
        $app->log = $code === 0 ? explode("\n", trim($out)) : ["git failed: $err"];
    });
});
// later, to stop it: $done is then not called
$p->kill();
```

- `Process::run($argv, $stdin, $done)`: `$argv` is the program and its
  arguments, not a shell line (no quoting, no globbing); `$stdin` is
  written to its standard input, then closed (`''` for none); `$done(string
  $stdout, string $stderr, int $exitCode)` runs on the main thread once it
  ended. A program that cannot be started throws a `RuntimeException`.
- `$p->ended()` tells whether it ended and `$done` ran; `$p->kill()` ends
  it now (SIGTERM) without calling `$done`.
- The output is handed over whole at the end: for progress as it runs,
  have the program write to a file and read that, or use the APIs made for
  the job, as `MyPHP\Http` for HTTP requests
  ([from PHP or native UI](plugins/fetch.md#from-php-or-native-ui)).

## Secrets

`Secrets` keeps passwords and tokens in the system's keychain (the
Keychain on macOS, the Secret Service on Linux), under the app's name:

```php
Secrets::set('github-token', $token);
try {
    $token = Secrets::get('github-token');
} catch (SecretNotFound) {
    $token = null;
}
Secrets::delete('github-token');

$work = Secrets::service('com.example.work'); // under another service
```

## Watching files

`App::watchFiles()` watches a file or a directory for changes, which a
fiber reads as they come, debounced:

```php
App::go(function () {
    $w = App::watchFiles('/Users/ada/notes', new FileWatchOptions(recursive: true, debounce: 0.1));
    while (($events = $w->next()) !== null) {
        foreach ($events as $e) { // FileEvent: op, path, oldPath, isDir
            reindex($e->path);
        }
    }
});
```

`ignore` leaves out paths, `maxEntries` bounds what a recursive watch
holds, and `hintsOnly` reports that something changed without listing
what. The [watch plugin](plugins/watch.md) gives pages the same.

## Global shortcuts

A global shortcut works while the app is in the background, as a
launcher's:

```php
App::whenReady(function () {
    GlobalShortcut::register('CmdOrCtrl+Shift+Space', fn() => toggleLauncher());
});
GlobalShortcut::unregister('CmdOrCtrl+Shift+Space');
```

They take [accelerators](menus.md#accelerators); `isRegistered()` and
`unregisterAll()` go with them. On Linux under Wayland, global shortcuts
need the desktop's portal.
