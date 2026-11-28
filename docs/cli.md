# The myphp CLI

`bin/myphp` creates, runs and packages apps. It is plain PHP, with no
dependencies: `myphp help` lists its commands, and `myphp <command> -h` the
flags of one.

## Install

`curl -fsSL https://myphp.ai/install.sh | sh` checks PHP (8.4 or later, with
`ext-ffi`), installs Composer if it is missing, installs the package
`owner888/myphp` with `composer global require`, and puts `myphp` on your
PATH. A checkout of MyPHP runs it as `bin/myphp`; a project that has MyPHP
as its dependency has it in `vendor/bin/myphp`:

```sh
bin/myphp init my-app
cd my-app && myphp dev
```

`myphp doctor` checks that the machine has what MyPHP needs: PHP 8.4 or
later with `ext-ffi`, and the system's libraries.

## myphp create (alias: init)

```sh
myphp create my-app                  # 2D declarative SwiftUI app
myphp create -template 3d my-3d-app  # 5-platform 3D scene (SceneView + PBR + particles)
```

Creates a new project. The `native` template shows a 2D declarative user
interface MyPHP draws on the GPU; the `3d` template scaffolds a 5-platform
3D scene (`SceneView` with Cook-Torrance PBR material, PCF soft shadows,
orbit controls and 3D particles). Both templates include `resources/icon.png`
and keep the repository clean without copying `.agents/` into it.

| Flag | |
|---|---|
| `-name name` | the application name (default: the directory's name) |
| `-template native\|3d` | the project template (default `native`) |

## myphp analyze

```sh
myphp analyze [dir]
```

Checks PHP syntax (`php -l`) across the project, enforces hot-reload rules (no
`class` or named `function` declarations in `view.php` or `pages/*.php`),
catches common Swift/PHP mixups (`Color.`, `->foregroundColor()`), and prints
SwiftUI best-practice hints.

## myphp clean

```sh
myphp clean [dir]
```

Deletes build artifacts (`build/`, `dist/`, `.myphp/`, and `myphp.json`'s `out`
directory) inside the project.

## myphp logs

```sh
myphp logs [-d device] [-v] [dir]
```

Streams live logs from a running Android device/emulator or booted iOS
simulator without rebuilding or reinstalling the app.

## myphp install-skills

```sh
myphp install-skills
```

Links the bundled `myphp-app` agent skill (`bin/skills/myphp-app`) into your
global user agent directories (`~/.agents/skills`, plus detected `~/.claude/skills`,
`~/.pig/agent/skills`, and `~/.codex/skills`) on macOS/Linux, and copies on
Windows (automatically refreshed by `myphp upgrade`).

## myphp dev

Develops the app with live reload. The development app is `<name> Dev` with the
identifier `<identifier>.dev`, in `.myphp/dev`: a real bundle, with the
app's icon and resources, whose launcher runs the project's files.
What follows the folder goes to the app, its `$argv`, at each launch:
`myphp dev examples/photos ~/Pictures` opens that folder; a path is made
absolute, as the app runs in its project's folder.

A saved PHP file hot reloads the app, as Flutter's run: the app loads its
views of files (`Content::file`) again with its state kept, and says in its
log which other files changed, which `R` takes ([view
files](ui/views.md#view-files-and-hot-reload)). A saved entry
(`main.php`, whose classes the state is made of) or a framework file is
what a hot reload cannot take: the app restarts, as `R` does. A view that
throws shows its error in the window, as Flutter's red screen, the app and
its state kept, until the view, saved fixed, reloads (`UI\ViewError`; a
packaged app ends on the error as before). Changes to the
configuration, the icon, the resources or the catalogs of messages, and to
PHP files when the app has a TypeScript client, rebuild the app,
regenerate the TypeScript client and the accessors of the catalogs, and
relaunch it. A build that fails keeps the previous one running; the next
change tries again. Frontend changes are left to the dev server. Quitting
the app ends `myphp dev`.

On a terminal, as in Flutter's `run`, press `r` (no Enter) to hot reload,
`R` to rebuild and restart the app, `c` to clear the console, `q` to quit,
`h` for help.

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
| `-format apk\|aab` | the Android package: `apk` (the default), or `aab`, the app bundle Google Play takes (signed with the upload key of `ANDROID_KEYSTORE_*`; Play makes the APKs and signs them with its own key) |
| `-launcher micro\|exec` | `micro` (the default) makes the app one static binary of PHP, which runs where no PHP is installed (see `myphp micro`); `exec` makes a launcher that runs the PHP that built the app, by its path. Windows apps hold the PHP that builds them, phones' their own runtime |
| `-php version` | the PHP version of micro.sfx |
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

## myphp run

`myphp run [-d device] [-v] [dir]` builds the app and runs it: on an
Android phone over adb (the APK packaged and signed, installed, started),
in an iOS simulator (the .app installed and launched), or on the desktop,
and follows the app's log. A phone's log is printed short, for the people
and coding agents reading it — `11:00:21.767 W [cache] the cache is full`:
the time, the level as a letter (E error, F fault, W warning, I info, D
debug, V verbose), colored in a terminal, and the message — and only what
tells something: the app's own lines (`Logger`'s, at the level it logs
at), PHP's warnings and errors, and the system's warnings, errors and
faults about the app. The build's output shows only when it fails. `-v`
prints the build's output and every line of the log. `-d` takes a device id or name from
`myphp devices`, or a platform (`android`, `ios`, `macos`, `linux`). The
first run for a phone builds its PHP runtime (libffi, OpenSSL, libcurl,
libphp; 10-20 minutes), built again when its build script changes; `myphp
doctor` tells where it stands. As Flutter's `run`, it is the one way to a
device: the scripts under `scripts/` are its internals (docs/mobile.md).

On a phone it then reloads the app as Flutter's run does, without a build,
the log going on. A PHP file saved (the app's or the framework's) is
copied into the installed app: a hot reload, the app's views of files
(`Content::file`) loaded again with the state kept, the log naming any
other file changed, which a restart takes ([view
files](ui/views.md#view-files-and-hot-reload)). On a terminal the keys are
Flutter's, pressed without Enter: `r` hot reloads, `R` hot restarts (the
app starts again with the files, its state lost), `c` clears the console,
`q` quits, `h` lists them. A change to native code (the launcher, the
Java, the runtime) needs `myphp run` again. On Android the APK `myphp run` builds is
debuggable, as Flutter's debug builds, for `run-as` to copy the files in;
`myphp build`'s is not.

## myphp devices

Lists the phones connected over adb and the iOS simulators, with their ids
and states, for `myphp run -d`.

## myphp emulators

As Flutter's `emulators`: lists the emulators — the Android SDK's virtual
devices (AVDs) and the iOS Simulator — and starts or makes one. A started
one is a device for `myphp run -d`.

```sh
myphp emulators                              # their ids and names
myphp emulators --launch pixel_11_pro        # starts it, waits until it has booted
myphp emulators --launch apple_ios_simulator # the Simulator app
myphp emulators --create                     # an AVD of the newest system image and the newest Pixel, downloaded as needed
myphp emulators --profiles                   # the device profiles, for --device
myphp emulators --images                     # the system images for this machine, those installed marked *
myphp emulators --delete pixel_11_pro        # deletes an emulator
myphp emulators --uninstall "system-images;android-35;google_apis;arm64-v8a"   # an image no emulator uses
myphp emulators --create --name p9 --device pixel_9_pro --image "system-images;android-36;google_apis_playstore;arm64-v8a"
```

`--create` does what the SDK's tools would be asked for one by one: it
installs the emulator and the platform tools when they are missing, takes
the newest system image for the machine's architecture (arm64-v8a on Apple
Silicon, which runs natively and is the one myphp's runtime is built for;
API 31 and over), Google Play's first — an installed one, else the newest
the SDK offers, which it downloads (1-2 GB), accepting the SDK's licenses
— and the newest Pixel profile, a Pro first, and names the AVD after the
profile. It needs the SDK's command-line tools (Android Studio's SDK
Manager installs them, or `brew install --cask android-commandlinetools`).
`--cold` boots afresh rather than from the last snapshot.

## myphp micro

Gets the static PHP (phpmicro of static-php-cli, with the extensions MyPHP
uses) that the apps `myphp build` makes carry: the prebuilt one of this
machine, downloaded from MyPHP's releases, else built here once, which
compiles PHP. `myphp build` does the same the first time it needs one.

## myphp generate

Writes the TypeScript client of the bound services and declared events
(see [bindings](bindings.md#the-generated-client)) and the accessors of the
app's catalogs of messages (see [languages](i18n.md)). `myphp dev` and
`myphp build` run it, so you rarely do.

## myphp ffigen and myphp jnigen

Generates type-safe PHP 8.5 wrapper classes for C headers (`.h`), Apple
Objective-C `@interface` headers (`-objc <Class> -framework <Framework>`),
and Android Java/Kotlin classes (`myphp jnigen <java.class.Name>`). See
[Native interop](interop.md).

## myphp i18n check

Checks the app's catalogs of messages, as `myphp dev` and `myphp build` do:
see [checking the catalogs](i18n.md#checking-the-catalogs).

## myphp keygen

Creates the Ed25519 key pair that signs updates, in `~/Library/Application
Support/myphp/update-keys` unless `-out` says otherwise: the public key goes
in `updates.publicKey`, and the private one stays secret, in
`MYPHP_UPDATER_PRIVATE_KEY` where builds run.

## myphp doctor

Checks that the development environment is ready: MyPHP itself (whether a
newer release is out), PHP and its extensions, Xcode's tools on macOS, GTK
and WebKitGTK on Linux. Failed checks exit with status 1.

## myphp upgrade

```sh
myphp upgrade           # to the latest release
myphp upgrade --check   # only say whether there is one
```

Upgrades MyPHP to its latest release, as `flutter upgrade`, the way it was
installed: Composer's global install, the one `install.sh` makes, runs
`composer global update owner888/myphp`; a git checkout pulls, refusing while
it has changes of yours. A project that has MyPHP as its own dependency (its
`vendor/`) updates it there, as any package: `composer update owner888/myphp`.

## myphp test

```sh
myphp test                     # tests/*_test.php of the project in this directory
myphp test tests/ui_test.php   # the files given
```

Runs the project's tests, as `flutter test`: each file in a PHP of its own
with FFI on and `$MYPHP_HOME` this MyPHP (or the checkout `myphp.json`'s
`framework` names), which the project's `boot.php` and its tests load the
framework from. It fails when one of them does.

## One MyPHP for every project

MyPHP is installed once on a machine, as the Flutter SDK is, and need not be
a Composer dependency of a project: `myphp dev`, `run`, `test`, `screenshot`
and `build` hand the project the MyPHP they belong to, and `myphp upgrade`
moves every project to the new release at once. A project says the least
version it needs in `myphp.json`:

```json
{ "myphp": "1.205.4" }
```

(`">=1.205.4"` and `"^1.205.4"` say the same.) With an older MyPHP every
command that reads the project stops and says `myphp upgrade`. A project that
sets `framework` to a checkout of its own is not checked.

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
| `MYPHP_HTTP=curl` | switches `Http`, `URLSession`, the updater (`Updater`) and the WebSocket plugin (`Plugins\WebSocket`) back to the system's libcurl (`Http::useTransport('curl')` does the same from code); by default they run over MyPHP's own pure-PHP network stack (`stream_select` + fibers, HTTP/1.1 and HTTP/2, TLS by PHP's `openssl` extension, published standalone as `owner888/php-http`) |
| `MYPHP_AUTOMATION` | lets another process drive the app, with the [automation plugin](automation.md); a run from the project's files in development listens without adding it |
| `MYPHP_THEME=light\|dark` | in a run from the project's files in development, the appearance the app shows (`myphp screenshot -theme`) |
| `MYPHP_DATA_HOME=dir` | in a run from the project's files in development on the Mac, where `App::path` looks in place of `~/Library` (`myphp screenshot -data`) |
