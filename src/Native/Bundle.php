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
    /** @var array<string, string>|null */
    private static ?array $info = null;

    /** The bundle's path, null when the app runs from no bundle (myphp dev, php app.php). */
    public static function path(): ?string
    {
        $phar = class_exists(\Phar::class) ? \Phar::running(false) : '';
        if ($phar !== '' && preg_match('~^(.*\.app)/Contents/MacOS/[^/]+$~', $phar, $m) === 1) {
            return $m[1];
        }
        $src = realpath(__DIR__ . '/..');
        if ($src !== false && preg_match('~^(.*\.app)/Contents/Resources/app/~', $src . '/', $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /** The string $key of the bundle's Info.plist, as `myphp build` writes it, or null. */
    public static function info(string $key): ?string
    {
        if (self::$info === null) {
            self::$info = [];
            $app = self::path();
            $plist = $app === null ? false : @file_get_contents("$app/Contents/Info.plist");
            if ($plist !== false) {
                preg_match_all('~<key>([^<]*)</key>\s*<string>([^<]*)</string>~', $plist, $m, PREG_SET_ORDER);
                foreach ($m as [, $k, $v]) {
                    self::$info[html_entity_decode($k, ENT_XML1)] = html_entity_decode($v, ENT_XML1);
                }
            }
        }
        return self::$info[$key] ?? null;
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
