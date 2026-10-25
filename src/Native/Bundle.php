<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * The .app bundle the app runs from, as `myphp build` lays it out: the phar
 * of a static app's Contents/MacOS/<Name>, or the framework under
 * Contents/Resources/app/myphp/src; and what its Info.plist says (mygo's
 * packageInfo).
 *
 * @internal
 */
final class Bundle
{
    /** @var array<string, mixed>|null */
    private static ?array $info = null;

    /** @var string|false|null the bundle the launcher named, false for none, null before it was read */
    private static string|false|null $launched = null;

    /**
     * The bundle's path, null when the app runs from no bundle (php
     * app.php): the micro launcher's phar is the bundle's executable, the
     * exec launcher of `myphp build` and `myphp dev` names its bundle in
     * MYPHP_BUNDLE, which is not meant for the processes the app starts.
     */
    public static function path(): ?string
    {
        $phar = class_exists(\Phar::class) ? \Phar::running(false) : '';
        if ($phar !== '' && preg_match('~^(.*\.app)/Contents/MacOS/[^/]+$~', $phar, $m) === 1) {
            return $m[1];
        }
        if (self::$launched === null) {
            $env = getenv('MYPHP_BUNDLE');
            self::$launched = is_string($env) && $env !== '' ? $env : false;
            putenv('MYPHP_BUNDLE');
        }
        return self::$launched === false ? null : self::$launched;
    }

    /** The string $key of the bundle's Info.plist, as `myphp build` writes it, or null. */
    public static function info(string $key): ?string
    {
        $v = self::plist()[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    /** What the bundle's Info.plist says; nothing without a bundle. @return array<string, mixed> */
    public static function plist(): array
    {
        if (self::$info === null) {
            $app = self::path();
            $xml = $app === null ? false : @file_get_contents("$app/Contents/Info.plist");
            $plist = $xml === false ? [] : Plist::parse($xml);
            self::$info = is_array($plist) ? $plist : throw new \RuntimeException("$app/Contents/Info.plist is no dictionary");
        }
        return self::$info;
    }

    /** The executable that starts the app: the bundle's, else the PHP that runs it with the script. @return list<string> */
    public static function command(): array
    {
        $app = self::path();
        $exe = $app === null ? null : self::info('CFBundleExecutable');
        if ($app !== null && $exe !== null) {
            return ["$app/Contents/MacOS/$exe"];
        }
        return [PHP_BINARY, '-d', 'ffi.enable=1', ...array_slice($_SERVER['argv'] ?? [], 0)];
    }
}
