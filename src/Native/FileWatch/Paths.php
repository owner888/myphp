<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

use RuntimeException;
use Throwable;

/**
 * The paths of a watch, relative to its root and separated by slashes ("."
 * for the root), as Scan and Hints both handle them.
 *
 * @internal
 */
trait Paths
{
    /** Whether $name names a child, or for a subtree a path beneath it. */
    private static function validName(string $name, bool $subtree): bool
    {
        if (str_contains($name, "\0") || !$subtree && str_contains($name, '/')) {
            return false;
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return true;
    }

    /** $e as the error of watching $path (Go's &os.PathError{Op: "watch"}), which FsError's checks see through. */
    private static function wrap(string $path, Throwable $e): Throwable
    {
        return $e instanceof FsError ? new FsError("watch $path: " . self::reason($e), $e->errno, $e) : new RuntimeException("watch $path: {$e->getMessage()}", 0, $e);
    }

    /** What an FsError says, without the path it names. */
    private static function reason(FsError $e): string
    {
        return Fs::strerror($e->errno);
    }

    /** The keys of $a as strings, which PHP keeps as integers when they look like ones. @return list<string> */
    private static function keys(array $a): array
    {
        return array_map('strval', array_keys($a));
    }

    private static function dir(string $p): string
    {
        $i = strrpos($p, '/');
        return $i === false ? '.' : substr($p, 0, $i);
    }

    private static function base(string $p): string
    {
        $i = strrpos($p, '/');
        return $i === false ? $p : substr($p, $i + 1);
    }

    private static function join(string $dir, string $name): string
    {
        return $dir === '.' ? $name : "$dir/$name";
    }
}
