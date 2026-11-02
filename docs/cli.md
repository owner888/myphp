# The myphp CLI

`bin/myphp` creates, runs and packages apps. It is plain PHP, with no
dependencies: `myphp help` lists its commands, and `myphp <command> -h` the
flags of one.

## Install

A checkout of MyPHP runs it as `bin/myphp`; a project that installed MyPHP
with Composer (the package `owner888/myphp`) has it in
`vendor/owner888/myphp/bin/myphp`. Projects with a web frontend also
depend on it through npm, as the `myphp-cli` package, which runs the
project's Composer-installed MyPHP, so `bun run dev` and `bun run build`
reach it:

```sh
bin/myphp init my-app          # from a checkout of MyPHP
cd my-app && bun run dev       # in a project with a frontend: myphp-cli dev
```

`myphp doctor` checks that the machine has what MyPHP needs: PHP 8.3 or
later with `ext-ffi`, and the system's libraries.

## myphp init

```sh
myphp init my-app                  # native UI
myphp init -template web my-app    # a TypeScript frontend built with Vite
```

Creates a new project. The native template shows a user interface MyPHP
draws itself, written in PHP with `MyPHP\UI`: `app.php` and `myphp.json`.
The web template has a TypeScript frontend built with Vite: Bun installs
its dependencies, `myphp-cli` and `myphp-runtime` among them, and runs its
scripts, `bun run dev` and `bun run build`. Both get the app's icon,
`resources/icon.png`, and the bundled agent skills.

| Flag | |
|---|---|
| `-name name` | the application name (default: the directory's name) |
| `-template native\|web` | the project (default native) |

## myphp install-skills

Installs or updates the agent skills MyPHP bundles (`bin/skills`) in a
project, for coding agents that write MyPHP apps.

## myphp dev

Develops the app with live reload. It writes the TypeScript client of a
frontend, runs `devCommand` (such as a Vite dev server), waits for `devUrl`
to answer, then builds a development app, which loads `devUrl` in place of
its built frontend, and launches it. Without `devUrl`, the app serves
`frontendDist` from disk. The development app is `<name> Dev` with the
identifier `<identifier>.dev`, in `.myphp/dev`: a real bundle, with the
app's icon and resources, whose launcher runs the project's files.

Changes to the PHP files, the configuration, the icon, the resources or
the catalogs of messages rebuild the app, regenerate the TypeScript client
and the accessors of the catalogs, and relaunch it. A build that fails
keeps the previous one running; the next change tries again. Frontend
changes are left to the dev server. Quitting the app ends `myphp dev`.

On a terminal, type `r` and Enter to rebuild and restart the app, `c` to
clear the console, `q` to quit, `h` for help.

| Flag | |
|---|---|
| `-lang tag` | run the app in this language, a BCP 47 tag such as `de` or `zh-Hant`, or a pseudo locale, `en-XA` (accented and longer text) or `ar-XB` (right to left), without keeping it |
| `-sign identity` | the signing identity of the development app (default `-`) |

## myphp build

Builds the production app for each platform. It runs `buildCommand`, then
makes `<out>/darwin-<arch>/<name>.app`: the app's PHP files and the
framework's, the `frontendDist` files, served at `myphp://localhost/`, the
contents of the resources directory and the resources listed in the
configuration, signed with `macos.signingIdentity` (ad hoc by default),
and `<name> <version> <arch>.dmg`, whose window invites dragging the app to
Applications.

For `linux/amd64` and `linux/arm64` it makes `<out>/linux-<arch>`: the
launcher, named after the slug, with the app's PHP files and the
framework's, the frontend, the resources, a desktop entry and the icon;
the app as `<slug>-<version>-linux-<arch>.tar.gz`; a Debian package, which
installs it in `/opt/<slug>`; and a PKGBUILD for Arch Linux.

With `updates` in the configuration and the key of `myphp keygen` in
`MYPHP_UPDATER_PRIVATE_KEY`, each platform also gets a signed update
archive, delta updates from the last versions published, and
`update-<platform>.json`.

| Flag | |
|---|---|
| `-platform list` | comma separated targets: `darwin/arm64`, `darwin/amd64`, `darwin/universal`, `linux/amd64`, `linux/arm64` (default: this machine's) |
| `-launcher exec\|micro` | `exec` runs the PHP that built the app; `micro` makes one static binary (see `myphp micro`) |
| `-php version` | the PHP version of micro.sfx, when `myphp micro` builds it |
| `-debug` | keep development features such as the inspector |
| `-skip-build-command` | do not run `buildCommand` |
| `-skip-dmg` | do not create a disk image |
| `-skip-notarize` | do not notarize even when `macos.notarize` is set |
| `-sign identity` | the signing identity (default: `macos.signingIdentity`, or `-` for ad hoc) |
| `-o dir` | the output directory (default: `out`, or `dist`) |
| `-upload` | upload the disk images and updates to the GitHub release of this version, as a draft, or to the bucket of `updates.s3` |

`MYPHP_INSPECTOR=1` keeps the [inspector](inspector.md) of native UI in a
production build. See [Building and distributing](distribution.md).

## myphp screenshot

```sh
myphp screenshot [dir | file.php] [-out shot.png] [-tree tree.json] [-window N] [-size WxH] [-theme light|dark] [-data dir] [-timeout seconds]
```

Lets a coding agent, or a script, see an app: it runs the app of the
project in `dir` (default `.`, its `entry` from the configuration) once,
from its files, or the script `file.php` as the entry of the project in
its folder (`myphp screenshot examples/counter-native/main.php`), waits until its window shows and has settled (the
automation plugin's `idle`: native UI asks for no frame; a web page has
loaded, and then the calls of PHP methods it made were answered and it drew
two frames more, twice in a row, so what it renders from their replies
shows), saves the window's capture as a PNG
(default `<dir>/.myphp/screenshot.png`), prints the roles, labels and keys
of the elements it shows, and quits the app as Quit does (SIGTERM). The
app needs no change: run from its project in development with
`MYPHP_AUTOMATION` set, it is driven by the [automation
plugin](automation.md#turning-it-on), here on a socket of its own.

`-tree` writes the elements as JSON too, the `tree` request's answer, for
agents that read no images. A window showing a web page has none: the PNG
is saved alone, with a warning, and the page is read with the automation
plugin's `eval` (docs/automation.md). On Linux
without a display (`DISPLAY` and `WAYLAND_DISPLAY` unset), the app runs on
an X server the command starts, Xvfb (`apt install xvfb`). A frontend shows
from `frontendDist`, built: `devCommand` and `devUrl` are left alone. It
exits with status 1, saying why, when the app exits before it is captured
(its output is shown), shows no window, or does not settle in time.
Stopped by a signal (Ctrl-C, the SIGTERM of a tool's timeout), it ends the
app and its Xvfb first, as on a failure; only a SIGKILL leaves them
running.

| Flag | |
|---|---|
| `-out file` | the PNG (default `<dir>/.myphp/screenshot.png`) |
| `-tree file` | write the window's elements as JSON too (a web page: none, a warning) |
| `-window N` | the window, by the ID the automation plugin lists (default: the focused window, else the first that shows) |
| `-size WxH` | resize the window's content to W×H points before capturing |
| `-theme light\|dark` | show the app in that appearance, whatever the system's |
| `-data dir` | keep the app's data in `dir` (made if missing), apart from the user's |
| `-timeout s` | seconds to wait for the window and for it to settle (default 30) |

`-theme` sets `MYPHP_THEME`, which `App::run` applies as
`Theme::setSource(ThemeSource::Light)` or `Dark` would, as it starts: over
a `Theme::setSource()` the app called before `App::run()`, but under one
it calls later, from `whenReady()` or a saved preference applied then,
which wins, as it would for the user. An app that fixes its UI theme with
`App::setTheme()` keeps it too. To capture such an app in the other
appearance, have it read its preference from its data and give it one with
`-data`. On Linux the appearance reaches GTK and WebKitGTK as it does
outside the command (`gtk-application-prefer-dark-theme`), so a web page's
`prefers-color-scheme` follows `-theme`. `-data` gives the app a data directory of its own, so that what it
saves as it starts, or a window's state, goes there and not into the
user's: on Linux the command sets `XDG_CONFIG_HOME`, `XDG_CACHE_HOME`,
`XDG_DATA_HOME` and `XDG_STATE_HOME` to `dir/config`, `dir/cache`,
`dir/data` and `dir/state`, so `App::path(PathName::UserData)` is
`dir/config/<name>` (`<name>` is `App::name()`, what `App::setName()` set);
on the Mac it sets `MYPHP_DATA_HOME`, which `App::path` reads in place of
`~/Library`: UserData is `dir/Application Support/<name>`, Cache
`dir/Caches/<name>`, Logs `dir/Logs/<name>`. The Keychain (`Secrets`) and
the system's own preferences stay the user's. Put files the app should
find there before the run, as a sample document:

```sh
mkdir -p /tmp/shots/config/Notes && cp sample.json /tmp/shots/config/Notes/notes.json
myphp screenshot -data /tmp/shots -theme dark -out dark.png
```

Both variables count only where the automation plugin does: in a run from
the project's files in development (`App::isDev()`, not packaged).

## myphp micro

Builds the static PHP (phpmicro of static-php-cli) that apps built with
`-launcher micro` run on, with the extensions MyPHP uses.

## myphp generate

Writes the TypeScript client of the bound services and declared events
(see [bindings](bindings.md#the-generated-client)) and the accessors of the
app's catalogs of messages (see [languages](i18n.md)). `myphp dev` and
`myphp build` run it, so you rarely do.

## myphp i18n check

Checks the app's catalogs of messages, as `myphp dev` and `myphp build` do:
see [checking the catalogs](i18n.md#checking-the-catalogs).

## myphp keygen

Creates the Ed25519 key pair that signs updates, in `~/Library/Application
Support/myphp/update-keys` unless `-out` says otherwise: the public key goes
in `updates.publicKey`, and the private one stays secret, in
`MYPHP_UPDATER_PRIVATE_KEY` where builds run.

## myphp doctor

Checks that the development environment is ready: PHP and its extensions,
Bun for the web template, Xcode's tools on macOS, GTK and WebKitGTK on
Linux. Failed checks exit with status 1.

## myphp version

Prints the MyPHP version.

## Environment variables

| Variable | |
|---|---|
| `MYPHP_ENV=production` | makes any run behave as a production build (`App::isDev()` false) |
| `MYPHP_DIRECTION=rtl` | lays every window of native UI out right to left (`ltr` left to right) |
| `MYPHP_FRAME_STATS` | logs slow frames of native UI, see [rendering](ui/rendering.md) |
| `MYPHP_STATS=1` | logs a line of frame statistics a second |
| `MYPHP_GENERATE=path` | makes `App::run()` write the TypeScript client and return |
| `MYPHP_INSPECTOR=1` | keeps the inspector in a production build |
| `MYPHP_UPDATER_PRIVATE_KEY` | the private key that signs updates |
| `MYPHP_AUTOMATION` | lets another process drive the app, with the [automation plugin](automation.md); a run from the project's files in development listens without adding it |
| `MYPHP_THEME=light\|dark` | in a run from the project's files in development, the appearance the app shows (`myphp screenshot -theme`) |
| `MYPHP_DATA_HOME=dir` | in a run from the project's files in development on the Mac, where `App::path` looks in place of `~/Library` (`myphp screenshot -data`) |
