/**
 * Returns the path of the myphp command to run: $MYPHP_CLI_BINARY when
 * set, else bin/myphp of the MyPHP checkout this package is in.
 */
export declare function binaryPath(): string;

/**
 * Types the configuration of myphp.config.ts, which takes the place of
 * myphp.json:
 *
 * ```ts
 * import { defineConfig } from "myphp-cli";
 *
 * export default defineConfig({
 *   name: "My App",
 *   identifier: "com.example.myapp",
 *   devUrl: "http://localhost:5173",
 * });
 * ```
 *
 * A function gets the command running and returns the configuration, or a
 * promise of it.
 */
export declare function defineConfig(config: Config): Config;
export declare function defineConfig(config: Promise<Config>): Promise<Config>;
export declare function defineConfig(config: ConfigFn): ConfigFn;

/** A configuration computed for the command running. */
export type ConfigFn = (env: ConfigEnv) => Config | Promise<Config>;

/** What a configuration function gets. */
export interface ConfigEnv {
  /** The myphp command running. */
  command: "dev" | "build" | "generate" | "init" | "i18n" | "install-skills";
}

/** The configuration of a MyPHP project. Every field is optional. */
export interface Config {
  /** The name users see: the app, its executable, menus and disk image (default: the directory's name). */
  name?: string;
  /**
   * A reverse DNS name unique to the app, such as `com.example.myapp`, on
   * which macOS keys preferences, permissions and registrations (default:
   * `com.myphp.` and the name's letters and digits).
   */
  identifier?: string;
  /** The version of the app, which updates compare (default: `0.1.0`). */
  version?: string;
  /** The copyright notice of the app. */
  copyright?: string;
  /** A square PNG, ideally 1024×1024 (default: `resources/icon.png` when it exists). */
  icon?: string;
  /** The PHP file the app runs, relative to the project (default: `app.php`). */
  entry?: string;
  /** The MyPHP checkout the project runs on, which built apps carry. */
  framework?: string;
  /** Where `myphp build` writes the builds, one directory per platform (default: `dist`). */
  out?: string;
  /** Where `myphp generate` writes the TypeScript client (an app without one, as of native UI, gets none). */
  bindings?: string;

  /**
   * The dev server `myphp dev` points the app at, such as
   * `http://localhost:5173`: windows load it for URLs without a scheme.
   */
  devUrl?: string;
  /** Runs in the project while `myphp dev` runs, e.g. the dev server; the app starts once `devUrl` answers. */
  devCommand?: string;
  /** Builds the frontend before `myphp build` packages the app. */
  buildCommand?: string;
  /**
   * The directory of the built frontend, which `myphp build` puts in the
   * app; `myphp dev` serves it from disk without `devUrl`.
   */
  frontendDist?: string;

  /**
   * Files and directories copied into the app under their base names, next
   * to the contents of the project's resources directory, whose directories
   * named after a platform, such as `resources/darwin-arm64`, hold the files
   * of one platform.
   */
  resources?: string[];
  /** The URL schemes the app opens, such as `myapp` for `myapp://…`, which reach `App::onOpenURL`. */
  urlSchemes?: string[];
  /** The types of files the app opens, which reach `App::onOpenFile`. */
  fileAssociations?: FileAssociation[];
  /**
   * The catalogs of the app's messages, which make the languages it ships.
   * Without it, the app ships English only.
   */
  i18n?: I18nConfig;
  /** Signed updates, which `MyPHP\Updater` installs. */
  updates?: UpdatesConfig;
  /** macOS packaging. */
  macos?: MacOSConfig;
}

/**
 * The catalogs of the app's messages: a JSON file per language in `dir`,
 * named after its BCP 47 tag (`de.json`, `zh-Hant.json`), whose messages
 * `myphp generate` writes typed PHP and TypeScript functions for.
 */
export interface I18nConfig {
  /** The directory of the catalogs (default `locales`). */
  dir?: string;
  /** The language the app is written in, which the others fall back to (default `en`). */
  source?: string;
}

/** A type of file the app opens. */
export interface FileAssociation {
  /** The file name extensions, without dots, e.g. `["md"]`. */
  ext: string[];
  /** What the files are, e.g. `"Markdown Document"`. */
  name?: string;
  /** The MIME type of the files. */
  mimeType?: string;
  /** The app's role for the files (default: `"Editor"`). */
  role?: "Editor" | "Viewer";
}

/** Signed updates: `publicKey` and one of `github` and `url` are required. */
export interface UpdatesConfig {
  /** The contents of myphp-update.pub, from `myphp keygen`. */
  publicKey: string;
  /** A public GitHub repository, `owner/name`, whose releases hold the updates. */
  github?: string;
  /**
   * What precedes the version in release tags (default: `v`). Another prefix,
   * such as `desktop-v`, lets the repository hold other releases: apps find
   * the newest release tagged with it through the GitHub API instead of
   * reading the latest release.
   */
  tagPrefix?: string;
  /** Instead of `github`, the HTTPS URL of a directory holding the updates. */
  url?: string;
  /** The bucket that serves `url`, which `myphp build -upload` uploads to. */
  s3?: S3Config;
  /** The path of myphp-update.key; `MYPHP_UPDATER_PRIVATE_KEY`, holding the key, takes precedence. */
  privateKey?: string;
  /** A Markdown file whose `## <version>` section becomes the release notes (default: `CHANGELOG.md` when it exists). */
  changelog?: string;
  /** How many earlier versions get a delta update, a smaller download of what changed (default: `3`; `0` for none). */
  deltas?: number;
}

/**
 * A bucket of Amazon S3, or of a compatible service such as Cloudflare R2.
 * The credentials come from `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and
 * `AWS_SESSION_TOKEN`.
 */
export interface S3Config {
  /** The name of the bucket. */
  bucket: string;
  /** The directory of the files in the bucket, e.g. `my-app` (default: its root). */
  prefix?: string;
  /** The region of the bucket (default: `AWS_REGION`, else `AWS_DEFAULT_REGION`, else `us-east-1`). */
  region?: string;
  /** The endpoint of a compatible service, e.g. `https://<account>.r2.cloudflarestorage.com` (default: Amazon S3's). */
  endpoint?: string;
  /** Names the bucket in the path of URLs rather than in the host name (default: with `endpoint`, or a bucket name with dots). */
  pathStyle?: boolean;
}

/** macOS packaging. */
export interface MacOSConfig {
  /**
   * The icon as an asset catalog: an `Assets.car` compiled with actool (its
   * app icon named `AppIcon`, unless `infoPlist` sets `CFBundleIconName`), or
   * an Icon Composer `.icon`, which builds compile with Xcode 26 or later.
   * The `.icns` made from `icon` remains the fallback.
   */
  icon?: string;
  /** The oldest macOS the app runs on (default: `12.0`). */
  minimumSystemVersion?: string;
  /** The identity that signs the app and its disk image, e.g. `Developer ID Application: Jane Doe (TEAMID)` (default: `-`, ad hoc). */
  signingIdentity?: string;
  /** A property list of entitlements to sign the app with. */
  entitlements?: string;
  /**
   * Property lists of entitlements for code among the resources, by its path
   * there, e.g. `{ "bin/server": "server.entitlements.plist" }` for a helper
   * that needs `com.apple.security.cs.allow-jit` under the hardened runtime.
   * Other code keeps the entitlements it is signed with.
   */
  helperEntitlements?: Record<string, string>;
  /** Keys added to the app's Info.plist, replacing MyPHP's, e.g. `NSCameraUsageDescription`. */
  infoPlist?: Record<string, unknown>;
  /** The volume name of the disk image (default: the name). */
  dmgTitle?: string;
  /** Notarizes production disk images. */
  notarize?: NotarizeConfig;
}

/** Credentials of `xcrun notarytool store-credentials <profile>`. */
export interface NotarizeConfig {
  /** The profile holding the credentials. */
  keychainProfile: string;
  /** The keychain holding the profile (default: the login keychain). */
  keychain?: string;
}
