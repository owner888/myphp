# Building and distributing

`myphp build` turns a project into apps people install: an app bundle and a
disk image on macOS; a launcher, a desktop entry, an archive, a Debian
package and a PKGBUILD for Arch Linux on Linux.

```sh
bun run build        # myphp build, in a project with a web frontend
myphp build          # in a project of native UI
```

It:

1. with a web frontend, writes the TypeScript client, so the frontend
   builds against the PHP code, and runs `buildCommand` from the
   configuration, which builds the frontend into `frontendDist`;
2. copies the app's PHP files and the framework's into the app, with the
   frontend when there is one, for production: without the inspector of
   native UI, and with the name, identifier and version of the
   configuration;
3. packages it for each platform in `dist/<os>-<arch>/` (`out` in the
   configuration);
4. signs what it can, and with [updates](plugins/updater.md) configured,
   writes the signed update archives, and delta updates from the published
   versions.

## Platforms

`-platform` lists targets, by default the machine's own:

```sh
myphp build -platform darwin/universal,linux/amd64,linux/arm64
```

`darwin/universal` combines arm64 and amd64 in one app. macOS apps are
made, signed and put in disk images on macOS only.

The app runs on PHP. `-launcher exec` (the default) makes a small launcher
that runs the PHP that built the app, which the machines the app installs
on need too: the Linux packages depend on `php<version>-cli`. `-launcher
micro` makes the app one static binary of PHP, with its extensions and the
app's files, built by `myphp micro` from static-php-cli; `-php` picks the
PHP version.

| Platform | In `dist/<os>-<arch>/` |
|---|---|
| macOS | `My App.app`, signed, and `My App 0.1.0 arm64.dmg` (`My App 0.1.0.dmg` for `darwin/universal`), with [updates](plugins/updater.md) also the update archive and `update-darwin-<arch>.json` |
| Linux | `my-app`, `my-app.desktop`, `my-app.png`, `my-app.xml` for the file types the app defines, the files of the app, their archive `my-app-0.1.0-linux-amd64.tar.gz`, the Debian package `my-app_0.1.0_amd64.deb`, and a [`PKGBUILD`](#arch-linux) |

Other flags: `-debug` keeps development features such as the inspector,
`-skip-dmg` and `-skip-notarize` skip those steps, `-sign` overrides the
signing identity of macOS, and `-o` the output directory. See
[the CLI](cli.md#myphp-build).

## Name, icon and version

The configuration describes the app:

```json
{
  "name": "My App",
  "identifier": "com.example.myapp",
  "version": "1.2.0",
  "copyright": "© 2026 Example Inc."
}
```

- `name` is what users see: the app bundle, menus, the disk image.
- `identifier` is a reverse DNS name unique to the app. Systems key
  preferences, permissions and registrations on it: keep it once released.
- `version` is the app's version, which `App::version()` returns and
  [updates](plugins/updater.md) compare.

The icon is `resources/icon.png`, or the `icon` of the configuration: a
square PNG, ideally 1024×1024. `myphp build` makes it the `.icns` of macOS
and the icon of the Linux desktop entry.

On macOS, `macos.icon` adds an asset catalog, which macOS prefers to the
`.icns`: an `Assets.car` compiled with `actool`, whose app icon is named
`AppIcon` (or what `macos.infoPlist` sets as `CFBundleIconName`), or an
Icon Composer `.icon`, the layered icon of macOS 26, which `myphp build`
compiles with `actool` from Xcode 26 or later. Building elsewhere, or when
the compilation fails, skips the `.icon` with a message, and the app keeps
the PNG's `.icns`. `macos.icon` can also be an `.icns` of your own, which
the app gets in place of the one rendered from the PNG.

```json
{
  "macos": { "icon": "AppIcon.icon" }
}
```

For builds without Xcode, commit the `Assets.car` that `actool` compiles
from the `.icon` and make it `macos.icon`. The `.icon` has to be named
`AppIcon.icon`: `actool` names the icon after the file.

```sh
mkdir -p icon
xcrun actool AppIcon.icon --compile icon --platform macosx --target-device mac \
  --minimum-deployment-target 12.0 --app-icon AppIcon \
  --output-partial-info-plist icon/partial.plist
```

This writes `icon/Assets.car`, for `"macos": { "icon": "icon/Assets.car" }`.

## Resources

Files the app reads at run time, such as a database seed, a helper binary
or the images of a tray icon, ship with it. Everything in the `resources`
directory of the project is copied into the app, as are the files and
directories listed in `resources` in the configuration, under their base
names:

```json
{
  "resources": ["third_party/licenses", "bin/helper"]
}
```

At run time `App::path(PathName::Resources)` is where they are:
`Contents/Resources` in a macOS app, the launcher's directory on Linux,
and the `resources` directory next to the script when it runs outside
an app, as `php app.php`:

```php
$seed = App::path(PathName::Resources) . '/seed.db';
```

`myphp dev` copies them into the development app too, and rebuilds it when
they change. Hidden files are left out.

### Platform resources

Some resources belong to one platform, such as a program built for one
system and processor. Directories of `resources` named after a platform
hold them, laid out like `resources` itself:

```
resources/
├── data/seed.db                    every app
├── darwin/                         macOS apps
├── darwin-arm64/bin/server         macOS on Apple silicon
├── darwin-amd64/bin/server         macOS on Intel
├── linux-amd64/bin/server          Linux on x86-64
└── linux-arm64/bin/server          Linux on ARM64
```

They are named like the targets of `-platform`: a system, `darwin` or
`linux`, for all of its apps, or a system and an architecture, as in
`darwin-arm64`, for one. An app ships the platform directories of its
target merged with the other resources, so the Linux x86-64 app above has
`data/seed.db` and its own `bin/server`, and nothing of the other
platforms. A path that two directories would both install fails the
build, and so does a directory named the way other tools name platforms,
such as `darwin-x64` or `macos`, which would ship to every platform. The
build also notes a target without a directory of its own when another
architecture of its system has one, such as `linux/arm64` next to
`linux-amd64`.

A universal macOS app (`darwin/universal`) ships `darwin` and
`darwin-universal`, and combines `darwin-arm64` with `darwin-amd64`:
every file of one needs its counterpart in the other, programs and
libraries become universal binaries, as `lipo` makes them, and other files
must be identical.

`myphp dev` ships the platform directories of the machine it runs on.

### Helper executables

Programs the app runs, such as a server written in another language or a
tool like `ffmpeg`, are resources too: put the build for each platform in
its [platform directory](#platform-resources) and run it from there.

```php
$server = App::path(PathName::Resources) . '/bin/server';
$proc = proc_open([$server], [], $pipes);
```

`myphp build` signs them with the app on macOS, so that Gatekeeper accepts
them and notarization passes: every Mach-O executable and library among
the resources, and every app, framework or plug-in bundle holding some, is
signed from the inside out with `signingIdentity`, with the hardened
runtime and a timestamp for a Developer ID. Signed ad hoc, the default,
only code without a signature is signed: Apple silicon runs no unsigned
code.

Under the hardened runtime, some programs need entitlements, such as
`com.apple.security.cs.allow-jit` for a JavaScript runtime that compiles
code as it runs. `macos.helperEntitlements` gives code entitlements of its
own, by its path in the app's resources, such as `bin/server` for
`resources/darwin-arm64/bin/server`:

```json
{
  "macos": {
    "signingIdentity": "Developer ID Application: Example Inc. (TEAMID)",
    "helperEntitlements": { "bin/server": "server.entitlements.plist" }
  }
}
```

### Native libraries of plugins

A plugin that loads a native library, as the [terminal
plugin](plugins/terminal.md) loads libghostty-vt, names its builds in a
`mygo-plugin.json` next to its sources, with a file per platform, where to
download it and its SHA-256. `myphp build` and `myphp dev` find these files
in the plugins the app uses, download each library once into the user's
cache, check its SHA-256, and install it at the top of the app's
resources under its name, signed like the other code there; a universal
macOS app gets both architectures in one file. Building for a platform the
plugin has no file for fails.

## macOS

`myphp build` makes an app bundle, signs it, and puts it in a disk image
whose window invites users to drag the app to Applications.

### Signing and notarization

Apps signed ad hoc, the default, run on the Mac that built them, but
Gatekeeper blocks them on others. To ship an app, sign it with a Developer
ID of the [Apple Developer Program](https://developer.apple.com/programs/)
and have Apple notarize it:

```json
{
  "macos": {
    "signingIdentity": "Developer ID Application: Example Inc. (TEAMID)",
    "notarize": { "keychainProfile": "notary" }
  }
}
```

Store the credentials of the notary service in the keychain once:

```sh
xcrun notarytool store-credentials notary
```

`myphp build` then signs the app with the hardened runtime, submits the
disk image to the notary service, and staples the ticket to the disk image
and to the app, which updates ship. `security find-identity -v -p
codesigning` lists your identities.

### Info.plist and entitlements

`macos.infoPlist` adds keys to the app's `Info.plist`, or replaces MyPHP's,
for example the usage descriptions that pages using the camera or the
microphone need:

```json
{
  "macos": {
    "minimumSystemVersion": "13.0",
    "infoPlist": {
      "NSCameraUsageDescription": "Scan documents with the camera.",
      "LSApplicationCategoryType": "public.app-category.productivity"
    },
    "entitlements": "entitlements.plist",
    "dmgTitle": "My App Installer"
  }
}
```

`entitlements` signs the app with a property list of entitlements, and
`helperEntitlements` [helper executables](#helper-executables).
`minimumSystemVersion` is the oldest macOS the app runs on, 12.0 by
default.

### Provisioning profiles

Restricted entitlements, such as Communication Notifications, need a
provisioning profile that authorizes them, including for Developer ID
distribution. Set `macos.provisioningProfile` to its path, relative to the
project or absolute. `build` and `dev` copy it to the bundle's
`Contents/embedded.provisionprofile` before signing.

The profile must match the signing certificate, bundle identifier and
entitlements. A profile cannot be used with ad-hoc signing (`-sign -`).
`dev` signs ad hoc unless it is given `-sign`, and its app's identifier is
`<identifier>.dev`, which the app's profile does not authorize: leave the
profile and the restricted entitlements out of development builds with a
configuration that is a function of the command (in myphp.config.ts),

```ts
export default defineConfig(({ command }) => ({
  macos: {
    signingIdentity: process.env.MACOS_SIGNING_IDENTITY,
    ...(command === "build" && {
      provisioningProfile: process.env.MACOS_PROVISIONING_PROFILE,
      entitlements: "entitlements.plist",
    }),
  },
}));
```

or pass `-sign` and give `dev` a profile for `<identifier>.dev`. Keep
profiles out of version control and provide their paths through the build
environment.

## Linux

`myphp build` writes the launcher, named after the app's
[`slug`](configuration.md#the-app), which is its name in lower case by
default, a desktop entry and the icon, and a Debian package, which
installs the app in `/opt/my-app` with its desktop entry, icons, URL
schemes and file types:

```json
{
  "linux": {
    "maintainer": "Example Inc. <dev@example.com>",
    "comment": "Take notes",
    "categories": ["Office"],
    "depends": ["libayatana-appindicator3-1"],
    "command": "my-app"
  }
}
```

The package depends on GTK 3, WebKitGTK and, with `-launcher exec`, PHP;
`depends` adds more packages. An app that shows no web page, in a window
or a [web view](ui/web-view.md), as an app of [native UI](ui/README.md),
needs GTK alone: `webview: false` leaves WebKitGTK out of its package.
`maintainer` defaults to the `author` of package.json, else the name of
the app. `comment` describes the app in its desktop entry and package, and
`categories` places it in application menus (`Utility` by default). The
app opens from the applications menu. `command` also gives it a command,
`/usr/bin/my-app` from the packages, or several, as `["my-app", "ma"]`,
which adds a short name; without it, they add no command.

Keep the slug of a released app: its Linux files are named after it, and
the Debian package of another slug is another package, installed next to
the old one. Choose the slug before the first release, as `cloud-music`
for `云音乐`, whose own would be `yun-yin-le`.

### Arch Linux

Next to the archive, every Linux build also gets a `PKGBUILD`, from which
`makepkg` packages the archive for Arch Linux, checked against its
SHA-256, and installs it with pacman:

```sh
cd dist/linux-amd64
makepkg -si
```

The package installs what the Debian package does: the app in
`/opt/my-app`, its commands in `/usr/bin`, its desktop entry, icons, URL
schemes and file types. Its `pkgname` is the slug, `pkgver` the version
without its hyphen, `pkgdesc` `linux.comment` (else the name), and
`depends` `gtk3` and, unless `linux.webview` is `false`, `webkit2gtk-4.1`,
then the Arch Linux packages of `linux.depends` that MyPHP knows.

## Languages

An app with [catalogs](i18n.md) declares their languages to each system,
its source language first, which the system falls back to:

- macOS: `CFBundleDevelopmentRegion`, `CFBundleLocalizations` and a
  localization folder per language in `Contents/Resources`, named as Xcode
  names them (`de.lproj`, `zh-Hans.lproj`, `zh-HK.lproj` for `zh-Hant-HK`),
  so that AppKit says its own texts (the Services menu, the panels) in the
  language the app shows, and System Settings lists the app's languages.
- Linux: `Name[xx]` and `Comment[xx]` in the desktop entry, by POSIX
  locale (`de`, `zh_CN`, `zh_TW`, `sr@latin`).

The app's name and description in a language are its catalog's
`myphp.appName` and `myphp.appDescription`; the configuration's `name` and
`linux.comment` are those of the source language. An app without catalogs
declares no languages.

On macOS, the other strings of `Info.plist`, such as the reasons the
system shows as it asks for the microphone, are translated as Xcode's
projects translate them: by an `InfoPlist.strings` of the language among
the app's [resources](#platform-resources), which the build keeps, adding
the app's name where the file has none.

```
resources/darwin/
├── ja.lproj/InfoPlist.strings        "NSMicrophoneUsageDescription" = "音声メモを録音します。";
└── zh-Hans.lproj/InfoPlist.strings   "NSMicrophoneUsageDescription" = "用麦克风录语音笔记。";
```

## URL schemes and file types

`urlSchemes` and `fileAssociations` in the configuration (see [deep
links](app.md#deep-links) and [file associations](app.md#file-associations))
are registered by each package: in the macOS app's `Info.plist`, and by
the Linux desktop entry, Debian package and PKGBUILD.

## Publishing

With `updates.github` set in the configuration, `myphp build -upload`
uploads the disk images, packages, Linux archives and update files to the
GitHub release of the version, tagged `v1.2.0`, creating it as a draft.
Review the draft and publish it. It needs the [GitHub
CLI](https://cli.github.com) (`gh`), signed in. With `updates.s3`, it
uploads them to a bucket of Amazon S3 or of a compatible service, such as
Cloudflare R2, with the credentials in `AWS_ACCESS_KEY_ID` and
`AWS_SECRET_ACCESS_KEY`.

Build on each platform in CI and upload to the same release: macOS runners
sign, notarize and make the disk images; Linux runners package Linux apps.
Keep the signing secrets in CI secrets: `MYPHP_UPDATER_PRIVATE_KEY`, and
the notary credentials in a keychain of the macOS runner.
