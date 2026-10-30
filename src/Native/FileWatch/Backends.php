<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

use Closure;

/**
 * The backend of a new watch: FSEvents on macOS, inotify on Linux, where
 * the tests run.
 *
 * @internal
 */
final class Backends
{
    /** What tests watch with instead (mygo's fake backend): returns a Backend. @var ?Closure(): Backend */
    public static ?Closure $fake = null;

    public static function open(): Backend
    {
        if (self::$fake !== null) {
            return (self::$fake)();
        }
        return match (PHP_OS_FAMILY) {
            'Darwin' => new FsEvents,
            'Linux' => new Inotify,
            default => throw new Unsupported('myphp: watch: file watches are not supported on ' . PHP_OS_FAMILY),
        };
    }
}
