<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\App;
use RuntimeException;

/**
 * Deep links and file associations (mygo's deeplink.go): URLs of the
 * schemes the app handles reach App::onOpenURL, files of the types it
 * opens App::onOpenFile. macOS hands them to the bundle that declares
 * them (myphp.json's "urlSchemes" and "fileAssociations", which `myphp
 * build` writes in Info.plist) through its delegate's application:openURLs:;
 * the arguments of the launch, and those a second instance forwards
 * (App::requestSingleInstanceLock), are searched for them too.
 *
 * @internal
 */
final class DeepLink
{
    /** @var array<int, \Closure(string): void> */
    public static array $onURL = [], $onFile = [];
    private static int $next = 0;
    /** @var array<string, true> the schemes registerURLScheme() took */
    private static array $registered = [];

    /** @param array<int, \Closure> $list */
    public static function add(array &$list, \Closure $fn): \Closure
    {
        $k = self::$next++;
        $list[$k] = $fn;
        return function () use (&$list, $k): void { unset($list[$k]); };
    }

    /** Whether $s is a URL scheme: a letter, then letters, digits, "+", "-" and ".". */
    public static function isScheme(string $s): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/', $s) === 1;
    }

    /** The URL schemes the bundle declares (CFBundleURLTypes), lowercase. @return list<string> */
    public static function declared(): array
    {
        $out = [];
        foreach (Bundle::plist()['CFBundleURLTypes'] ?? [] as $t) {
            foreach ($t['CFBundleURLSchemes'] ?? [] as $s) {
                $out[] = strtolower((string)$s);
            }
        }
        return $out;
    }

    /** The file name extensions of the bundle's document types (CFBundleDocumentTypes), lowercase. @return list<string> */
    public static function extensions(): array
    {
        $out = [];
        foreach (Bundle::plist()['CFBundleDocumentTypes'] ?? [] as $t) {
            foreach ($t['CFBundleTypeExtensions'] ?? [] as $e) {
                $out[] = strtolower((string)$e);
            }
        }
        return $out;
    }

    /** Whether URLs of $scheme reach onOpenURL. */
    public static function handles(string $scheme): bool
    {
        $scheme = strtolower($scheme);
        return in_array($scheme, self::declared(), true) || isset(self::$registered[$scheme]);
    }

    /** The arguments that are URLs of schemes the app handles. @param list<string> $args @return list<string> */
    public static function urlArgs(array $args): array
    {
        $urls = [];
        foreach ($args as $a) {
            $scheme = explode(':', $a, 2);
            // A single letter is a drive, as in C:\.
            if (count($scheme) === 2 && strlen($scheme[0]) > 1 && self::isScheme($scheme[0]) && self::handles($scheme[0])) {
                $urls[] = $a;
            }
        }
        return $urls;
    }

    /** The arguments that are files the app opens, of the extensions of its file associations, as absolute paths; relative ones are relative to $wd; file URLs too. @param list<string> $args @return list<string> */
    public static function fileArgs(array $args, string $wd): array
    {
        $exts = self::extensions();
        if ($exts === []) {
            return [];
        }
        $files = [];
        foreach ($args as $a) {
            $p = str_starts_with($a, 'file://') ? rawurldecode((string)parse_url($a, PHP_URL_PATH)) : $a;
            if (!in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), $exts, true)) {
                continue;
            }
            if (!str_starts_with($p, '/')) {
                $p = "$wd/$p";
            }
            if (is_file($p)) {
                $files[] = $p;
            }
        }
        return $files;
    }

    /** Passes the URLs among $args to onOpenURL and the files to onOpenFile. @param list<string> $args */
    public static function deliverArgs(array $args, string $wd): void
    {
        self::openURLs(self::urlArgs($args));
        self::openFiles(self::fileArgs($args, $wd));
    }

    /** @param list<string> $urls */
    public static function openURLs(array $urls): void
    {
        foreach ($urls as $u) {
            foreach (self::$onURL as $fn) {
                $fn($u);
            }
        }
    }

    /** @param list<string> $paths */
    public static function openFiles(array $paths): void
    {
        foreach ($paths as $p) {
            foreach (self::$onFile as $fn) {
                $fn($p);
            }
        }
    }

    public static function register(string $scheme): void
    {
        self::isScheme($scheme) || throw new RuntimeException("myphp: invalid URL scheme \"$scheme\"");
        $scheme = strtolower($scheme);
        self::$registered[$scheme] = true;
        if (!in_array($scheme, self::declared(), true)) {
            throw new RuntimeException("myphp: the app does not declare the URL scheme \"$scheme\": list it in urlSchemes in myphp.json");
        }
        $status = LaunchServices::setDefaultHandler($scheme, Bundle::info('CFBundleIdentifier') ?? '');
        if ($status !== 0) {
            throw new RuntimeException("myphp: cannot handle the URL scheme \"$scheme\" (Launch Services error $status)");
        }
    }

    public static function isRegistered(string $scheme): bool
    {
        if (!self::isScheme($scheme)) {
            return false;
        }
        $id = Bundle::info('CFBundleIdentifier');
        $handler = LaunchServices::defaultHandler(strtolower($scheme));
        return $id !== null && $handler !== null && strcasecmp($handler, $id) === 0;
    }

    /** Forgets what tests registered. @internal */
    public static function reset(): void
    {
        self::$onURL = self::$onFile = self::$registered = [];
    }
}
