<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * Paths of the file system as the system has them (Go's filepath.IsAbs and
 * Abs): on Windows a drive's ("C:\", "C:/"), a share's ("\\server\share")
 * and one from the current drive's root ("\x") are absolute, a slash or a
 * backslash separating; elsewhere a path from "/" is. URLs' and routes'
 * paths, always of slashes, are not these.
 */
final class Paths
{
    public static function isAbsolute(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return str_starts_with($path, '/');
        }
        return preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path) === 1;
    }

    /** $path, absolute: as it is, or under $base (the working directory for none). */
    public static function absolute(string $path, ?string $base = null): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }
        $base ??= (string)getcwd();
        return rtrim($base, PHP_OS_FAMILY === 'Windows' ? '\\/' : '/') . DIRECTORY_SEPARATOR . $path;
    }
}
