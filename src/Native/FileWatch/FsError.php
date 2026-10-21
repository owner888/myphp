<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

/**
 * A system call that failed on a path, with its errno (Go's
 * *os.PathError over a syscall.Errno).
 *
 * @internal
 */
final class FsError extends \RuntimeException
{
    private const ENOENT = 2, EPERM = 1, EACCES = 13;

    public function __construct(string $message, public readonly int $errno, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Whether $e, or what it wraps, says a file doesn't exist (Go's errors.Is(err, fs.ErrNotExist)). */
    public static function notExist(?\Throwable $e): bool
    {
        return self::errnoOf($e) === self::ENOENT;
    }

    /** Whether $e, or what it wraps, says permission was denied (Go's errors.Is(err, fs.ErrPermission)). */
    public static function permission(?\Throwable $e): bool
    {
        return in_array(self::errnoOf($e), [self::EPERM, self::EACCES], true);
    }

    /** Whether $e or what it wraps is a $class (Go's errors.Is). @param class-string $class */
    public static function is(?\Throwable $e, string $class): bool
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof $class) {
                return true;
            }
        }
        return false;
    }

    private static function errnoOf(?\Throwable $e): int
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof self) {
                return $e->errno;
            }
        }
        return 0;
    }
}
