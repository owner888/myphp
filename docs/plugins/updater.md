# Updater

The updater plugin gives an app the update window that Mac users know from
Sparkle: it checks for updates in the background, shows the release notes
of a new version and offers to install it, skip it or remind the user
later, then downloads it with a progress bar and relaunches the app into
it. It is all PHP, with no npm package, and works in apps of web pages and
of [native UI](../ui/README.md) alike. Its window is a web page, or native
UI with [`Native::plugin()`](#native-ui), for apps that show no web page
at all.

It is built on `MyPHP\Updater`, so the app must be built with updates:
`updates` in the [configuration](../configuration.md#updates), signed with
the key of `myphp keygen` and published with `myphp build -upload` (see
[publishing](../distribution.md#publishing)).

## Set up

Use the plugin in PHP:

<!-- no-test -->
```php
use MyPHP\App;
use MyPHP\Plugins\Updater\Updater;

App::use(Updater::plugin());
// ...
```

- It checks in the background 10 seconds after launch, then once a day
  while the app runs, so a version that came out since the app last ran
  shows when it opens again. When a new version is out, a window shows its
  release notes and offers **Install Update**, **Remind Me Later** and
  **Skip This Version**. Background checks do not offer a skipped version
  again, but offer the next one.
- Installing downloads the update with a progress bar, then offers to
  relaunch; otherwise the update runs at the next launch.
- With the window's "Automatically download and install updates in the
  future" checked, background checks install updates without asking, and
  they run at the next launch.

Builds that cannot update themselves, such as development builds, never
check in the background, and the window says why when the user checks.

## Checking from the menu

`Updater::checkForUpdates()` checks as the user asked: the window shows at
once, and says when the app is up to date or the check failed. It also
shows updates the user skipped. `Updater::menuItem()` is a "Check for
Updates…" item that calls it, which goes after About on macOS:

<!-- no-test -->
```php
App::setMenu(new Menu([
    new MenuItem(label: 'My App', submenu: [
        new MenuItem(role: 'about'),
        Updater::menuItem(),
        MenuItem::separator(),
        new MenuItem(role: 'quit'),
    ]),
    new MenuItem(role: 'editMenu'),
    new MenuItem(role: 'windowMenu'),
]));
```

A button of the app's page can call `Updater::checkForUpdates()` through a
bound method, too. While a check is under way, calling it again brings its
window to the front.

## Options

`Updater::new()` takes options instead of the defaults of
`Updater::plugin()`:

<!-- no-test -->
```php
use MyPHP\Plugins\Updater\{Options, Updater};

App::use(Updater::new(new Options(
    interval: 12 * 3600,           // seconds between checks (default a day)
    disableAutomaticChecks: true,  // until setAutomaticChecks(true)
    icon: $iconPNG,                // default: the app's icon.png
)));
```

- `interval` is the time between background checks, in seconds, a day by
  default.
- `disableAutomaticChecks` turns background checks off until
  `Updater::setAutomaticChecks(true)`, for apps that ask the user first or
  only check from the menu.
- `icon` is the PNG image of the window; by default, the app's icon, when
  there is one.
- `language` and `strings` choose and change the window's texts (see
  [languages](#languages)).

## Preferences

The user's choices are kept in `updater.json` in
`App::path(PathName::UserData)`. `Updater::automaticChecks()` and
`automaticDownloads()` read them and `setAutomaticChecks()` and
`setAutomaticDownloads()` change them, for a preferences page;
`lastCheck()` returns when the app last checked, and `availableVersion()`
the newer version the last check found. `onChange()` calls a function on
the main thread whenever one of them changed, after a check or an answer
in the update window as well, so the page can show them again:

<!-- no-test -->
```php
// Tells the preferences page what they are now.
$updatePreferences = new Event('update-preferences', 'array');

Updater::onChange(function () use ($updatePreferences) {
    $updatePreferences->broadcast([
        'automaticChecks' => Updater::automaticChecks(),
        'automaticDownloads' => Updater::automaticDownloads(),
        'lastCheck' => Updater::lastCheck()?->format(DATE_ATOM),
    ]);
});
```

Sparkle asks on the second launch whether to check automatically: apps
that want to ask set `disableAutomaticChecks` and call
`setAutomaticChecks()` with the answer.

## Languages

The window speaks the user's language (`App::locale()`) when the plugin
has it: English, Chinese (Simplified and Traditional), Dutch, French,
German, Italian, Japanese, Korean, Polish, Portuguese (Brazil), Russian,
Spanish, Turkish and Ukrainian, with their decimal marks and units ("1,5
Mo"). Other languages get English. `language` picks one, such as the
language the app itself shows, and `strings` changes texts or adds
languages, by language tag; the fields an app leaves empty keep the
plugin's texts:

<!-- no-test -->
```php
use MyPHP\Plugins\Updater\{Options, Strings, Updater};

App::use(Updater::new(new Options(
    language: $settings->language, // '' follows the system
    strings: [
        'en' => new Strings(install: 'Update Now'),
        'sv' => new Strings(title: 'Programuppdatering', install: 'Installera uppdatering' /* … */),
    ],
)));
```

A window speaks the language the app shows when its check begins, and
keeps it while it is open. After `App::setLocale()`, or a change of the
user's languages while the app follows them, the next window takes the new
one, as does an item that `menuItem()` returns then: an app that rebuilds
its menu in `App::onLocaleChanged()` gets "Check for Updates…" in the new
language.

Some texts are `sprintf` formats, such as `availableMessage`, whose
arguments are the app's name, the new version and the running one:
numbered arguments (`%2$s`) let a language order them. `App::use()` fails
when a format does not fit its arguments. The properties of
`MyPHP\Plugins\Updater\Strings` list the texts and the arguments of the
formats.

Languages written from right to left, such as Arabic and Hebrew, get a
mirrored window, and release notes take the direction of the language
they are written in.

## Native UI

The window of `Updater::plugin()` is a web page, so it needs WebKitGTK on
Linux even in an app whose own windows all show [native
UI](../ui/README.md). `MyPHP\Plugins\Updater\Native\Native` draws the same
window in native UI instead, and needs no webview:

<!-- no-test -->
```php
use MyPHP\Plugins\Updater\Native\Native;

App::use(Native::plugin()); // or Native::new(new Options(...))
```

It takes the same options and speaks the same languages, and everything
else is `Updater`'s: `Updater::menuItem()`, `Updater::checkForUpdates()`
and the preferences work as with `Updater::plugin()`. Use one of the two
plugins only. The window looks the same; its release notes are the same
Markdown, with text to select and links that open in the browser.

## Your own update UI

The window is one way to offer updates. For an interface of your own, such
as a banner in the app's page, use `MyPHP\Updater` instead of the plugin:

<!-- no-test -->
```php
App::go(function () {
    $update = Updater::check(); // MyPHP\Updater: null when up to date
    if ($update !== null) {     // its version, notes and date
        $update->install(fn(int $done, int $total) => showProgress($done, $total));
        App::relaunch();
    }
});
```

`check()` and `install()` wait for the network inside `App::go()` without
blocking the app. `Updater::enabled()` says whether the build can update
itself, and `setPrereleases()` and `setSource()` choose what it checks.
