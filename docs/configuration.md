# Configuration

`myphp.json`, at the root of a project, describes the app and how the CLI
develops and builds it; `myphp init` makes one. A project with a web
frontend may have `myphp.config.ts` instead, whose default export is the
configuration, typed by `defineConfig` from the `myphp-cli` package, so
editors complete and document every field:

```ts
import { defineConfig } from "myphp-cli";

export default defineConfig({
  name: "My App",
  slug: "my-app",
  identifier: "com.example.myapp",
  version: "1.2.0",
  copyright: "© 2026 Example Inc.",
  entry: "app.php",

  devUrl: "http://localhost:5173",
  devCommand: "bun run dev:web",
  buildCommand: "bun run build:web",
  frontendDist: "dist",
  bindings: "src/myphp.ts",
  out: "build",

  resources: ["third_party/licenses"],
  urlSchemes: ["myapp"],
  fileAssociations: [{ ext: ["md"], name: "Markdown Document", mimeType: "text/markdown" }],

  i18n: { dir: "locales", source: "en" },
  updates: { publicKey: "…", github: "you/my-app" },
  macos: { signingIdentity: "Developer ID Application: Example Inc. (TEAMID)" },
  linux: { maintainer: "Example Inc. <dev@example.com>" },
});
```

Every field is optional. Projects of [native UI](ui/README.md) have no
JavaScript, and no frontend to develop or build, so they leave out the
fields of [the frontend](#development-and-the-frontend) and keep the
configuration in myphp.json:

```json
{
  "name": "My App",
  "identifier": "com.example.myapp",
  "version": "0.1.0",
  "entry": "app.php",
  "out": "build"
}
```

A project has either `myphp.config.ts` or `myphp.json`, not both. The CLI
evaluates `myphp.config.ts` with Bun, or else Node.js 22.6 or later, in the
project directory; its default export may also be a function of the
command running (`"dev"`, `"build"`, `"generate"`), which computes fields,
as a version read from package.json.

## The app

| Field | Default | |
|---|---|---|
| `name` | the directory's name | the name users see: the app, menus, installers |
| `slug` | the name in lower case, with hyphens between its words, e.g. `my-app` of `My App`; Chinese characters take their most common Mandarin reading without tones, e.g. `yun-yin-le` of `云音乐` | the name of the app's files where a name must be plain ASCII: the Linux launcher, its desktop entry, icon, Debian package and `/opt` directory, and the [update archives](plugins/updater.md); lower-case letters and digits with single hyphens between them |
| `identifier` | `com.myphp.` and the name's letters and digits | a reverse DNS name unique to the app, e.g. `com.example.myapp`; systems key preferences, permissions and registrations on it |
| `version` | `0.1.0` | the version of the app, which `App::version()` returns and updates compare |
| `copyright` | | the copyright notice of the app, in its `Info.plist` |
| `icon` | `resources/icon.png` when it exists | a square PNG, ideally 1024×1024 |
| `entry` | `app.php`, else `main.php` | the PHP file the app runs |
| `framework` | | where MyPHP is, for a project outside a Composer install |

## Development and the frontend

| Field | Default | |
|---|---|---|
| `devUrl` | | the dev server that `myphp dev` points the app at, an `http(s)` URL; windows load it for URLs without a scheme, such as `/` |
| `devCommand` | | runs in the project directory while `myphp dev` runs, e.g. a Vite dev server; the app starts once `devUrl` answers |
| `buildCommand` | | builds the frontend before `myphp build` makes the app |
| `frontendDist` | | the directory of the built frontend, which `myphp build` puts into the app; `myphp dev` serves it from disk when there is no `devUrl` |
| `bindings` | `src/myphp.ts` in the web template | where `myphp generate` writes the TypeScript client |
| `out` | `dist` | where `myphp build` writes the builds, one directory per platform |

See [the frontend](frontend.md#how-pages-load) for how they fit together.

## Integration

| Field | |
|---|---|
| `resources` | files and directories copied into the app, next to the contents of the `resources` directory, under their base names; see [resources](distribution.md#resources) |
| `urlSchemes` | the URL schemes the app opens, such as `myapp` for `myapp://…`, which reach `App::onOpenURL()`; see [deep links](app.md#deep-links) |
| `fileAssociations` | the file types the app opens, which reach `App::onOpenFile()`; see [file associations](app.md#file-associations) |

Each file association has `ext`, the file name extensions without dots;
`name`, what the files are; `mimeType`, by which Linux identifies files;
and `role`, `Editor` (the default) or `Viewer`, on macOS.

## i18n

The catalogs of the app's messages, a JSON file per language; see
[Languages](i18n.md). Without `i18n`, the app ships English only.

| Field | Default | |
|---|---|---|
| `dir` | `locales` | the directory of the catalogs, `<tag>.json` for each language the app ships, where `myphp generate` writes the PHP class of the messages |
| `source` | `en` | the language the app is written in, whose catalog has every message and which the others fall back to |

## updates

Signed auto-updates, with the [updater plugin](plugins/updater.md).
`publicKey` and one of `github` and `url` are required.

| Field | Default | |
|---|---|---|
| `publicKey` | | the public key of `myphp keygen` |
| `github` | | a public repository, `owner/name`, whose releases hold the updates |
| `tagPrefix` | `v` | what precedes the version in release tags |
| `url` | | instead of `github`, the HTTPS URL of a directory holding the updates |
| `s3` | | the bucket that serves `url`, which `myphp build -upload` uploads to |
| `privateKey` | | the path of the private key, for `myphp build`; `MYPHP_UPDATER_PRIVATE_KEY`, holding the key, takes precedence |
| `changelog` | `CHANGELOG.md` when it exists | a Markdown file whose `## <version>` section becomes the release notes |
| `deltas` | `3` | how many earlier versions get a delta update; `0` for none |

## macos

| Field | Default | |
|---|---|---|
| `icon` | | the icon as an `Assets.car`, an Icon Composer `.icon` or an `.icns` |
| `minimumSystemVersion` | `12.0` | the oldest macOS the app runs on |
| `signingIdentity` | `-`, ad hoc | the identity that signs the app and its disk image, e.g. `Developer ID Application: Example Inc. (TEAMID)` |
| `entitlements` | | a property list of entitlements to sign the app with |
| `provisioningProfile` | | a profile embedded before signing |
| `helperEntitlements` | | property lists of entitlements for programs among the resources, by their path there |
| `infoPlist` | | keys added to the app's `Info.plist`, e.g. `NSCameraUsageDescription` |
| `dmgTitle` | `name` | the volume name of the disk image |
| `notarize` | | notarizes production disk images: `keychainProfile`, the profile of `xcrun notarytool store-credentials`, and optionally `keychain` |

See [macOS](distribution.md#macos).

## linux

| Field | Default | |
|---|---|---|
| `maintainer` | `author` in package.json, else `name` | `Name <email>`, the maintainer of the Debian package and of the PKGBUILD |
| `comment` | | a short description, for the desktop entry and the package |
| `categories` | `["Utility"]` | the categories of the desktop entry |
| `depends` | | Debian packages the app needs besides GTK, PHP and WebKitGTK |
| `webview` | whether the app shows pages | `false` for an app that shows no web page, as one of native UI: the packages depend on GTK alone |
| `command` | none | a command that runs the app, or a list of names: `/usr/bin/<command>` from the Debian package and the PKGBUILD |

See [Linux](distribution.md#linux).
