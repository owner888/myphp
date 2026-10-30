<?php
declare(strict_types=1);

namespace MyPHP\Native\FileWatch;

use FFI;

/**
 * What the watches ask of the file system: PHP's own lstat, scandir and
 * realpath, with PHP's stat cache cleared first, and libc's errno when one
 * fails, which PHP doesn't give. A stat is [dev, ino, mode, size, mtime],
 * and two with the same dev and ino are the same file (Go's os.SameFile).
 *
 * @internal
 */
final class Fs
{
    private const S_IFMT = 0o170000, S_IFDIR = 0o040000, S_IFREG = 0o100000;
    /** struct dirent's d_type. */
    private const DT_UNKNOWN = 0, DT_DIR = 4, DT_REG = 8;

    private static ?FFI $c = null;

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int} */
    public static function lstat(string $path): array
    {
        for ($attempt = 0; ; $attempt++) {
            clearstatcache();
            $st = @lstat($path);
            if ($st !== false) {
                return [$st['dev'], $st['ino'], $st['mode'], $st['size'], $st['mtime']];
            }
            $buf = self::c()->new('char[512]'); // larger than any struct stat
            if (self::c()->lstat($path, FFI::addr($buf)) !== 0 || $attempt === 2) {
                throw self::error('lstat', $path);
            }
            // It appeared in between: look again.
        }
    }

    /** The names in directory $path, without "." and "..". @return list<string> */
    public static function readDir(string $path): array
    {
        for ($attempt = 0; ; $attempt++) {
            $names = @scandir($path, SCANDIR_SORT_NONE);
            if ($names !== false) {
                return array_values(array_filter($names, fn(string $n): bool => $n !== '.' && $n !== '..'));
            }
            $dir = self::c()->opendir($path);
            if ($dir === null || FFI::isNull($dir) || $attempt === 2) {
                throw self::error('open', $path);
            }
            self::c()->closedir($dir);
        }
    }

    /**
     * The names of the directories in directory $path (mygo's
     * fileWatchSubdirs), from the types readdir gives, so that what isn't a
     * directory is not stat'ed; an entry of unknown type is lstat'ed, so a
     * symlink is never one. @return list<string>
     */
    public static function subdirs(string $path): array
    {
        $c = self::c();
        $dir = $c->opendir($path);
        if ($dir === null || FFI::isNull($dir)) {
            throw self::error('open', $path);
        }
        $names = [];
        try {
            while (($e = $c->readdir($dir)) !== null && !FFI::isNull($e)) {
                $name = FFI::string($e->d_name);
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $type = $e->d_type;
                if ($type === self::DT_UNKNOWN) {
                    try {
                        $type = self::isDir(self::lstat("$path/$name")) ? self::DT_DIR : self::DT_REG;
                    } catch (FsError) {
                        continue; // gone in between
                    }
                }
                if ($type === self::DT_DIR) {
                    $names[] = $name;
                }
            }
        } finally {
            $c->closedir($dir);
        }
        return $names;
    }

    /** $path absolute, with its symlinks resolved (Go's filepath.Abs, then EvalSymlinks). */
    public static function realpath(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            $path = getcwd() . '/' . $path;
        }
        clearstatcache(true);
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        self::lstat($path); // why it failed
        throw new FsError("lstat $path: no such file or directory", 2); // a symlink to nothing
    }

    /** The error of the libc call that just failed on $path, as Go's *os.PathError says it. */
    public static function error(string $op, string $path): FsError
    {
        $errno = self::errno();
        return new FsError("$op $path: " . self::strerror($errno), $errno);
    }

    /** libc's errno. */
    public static function errno(): int
    {
        return PHP_OS_FAMILY === 'Darwin' ? self::c()->__error()[0] : self::c()->__errno_location()[0];
    }

    /** What $errno says, in lower case as Go's syscall.Errno does. */
    public static function strerror(int $errno): string
    {
        return lcfirst(FFI::string(self::c()->strerror($errno)));
    }

    /** @param array{0: int, 1: int, 2: int, 3: int, 4: int} $st */
    public static function isDir(array $st): bool
    {
        return ($st[2] & self::S_IFMT) === self::S_IFDIR;
    }

    /** @param array{0: int, 1: int, 2: int, 3: int, 4: int} $st */
    public static function isRegular(array $st): bool
    {
        return ($st[2] & self::S_IFMT) === self::S_IFREG;
    }

    /**
     * Whether $a and $b are the same file (Go's os.SameFile, false for a missing one), of the same type: a file never
     * changes type, but a file system such as ext4 gives a freed inode to the next file created, so that a directory
     * removed and a symlink created in its place can have the same inode (mygo's 0915563).
     */
    public static function same(?array $a, ?array $b): bool
    {
        return $a !== null && $b !== null && $a[0] === $b[0] && $a[1] === $b[1] && ($a[2] & self::S_IFMT) === ($b[2] & self::S_IFMT);
    }

    /** What same() compares, as a key: device, inode and type. @param array{0: int, 1: int, 2: int, 3: int, 4: int} $st */
    public static function identityKey(array $st): string
    {
        return $st[0] . ':' . $st[1] . ':' . ($st[2] & self::S_IFMT);
    }

    /** Whether a file's content may have changed, as its size or modification time says (mygo's fileInfoChanged). */
    public static function changed(array $a, array $b): bool
    {
        return $a[3] !== $b[3] || $a[4] !== $b[4];
    }

    private static function c(): FFI
    {
        // struct dirent: macOS's of 64-bit inodes, glibc's otherwise.
        [$errno, $dirent] = PHP_OS_FAMILY === 'Darwin'
            ? ['int *__error(void);', 'typedef struct { uint64_t d_ino; uint64_t d_seekoff; uint16_t d_reclen; uint16_t d_namlen; uint8_t d_type; char d_name[1024]; } dirent;']
            : ['int *__errno_location(void);', 'typedef struct { uint64_t d_ino; int64_t d_off; uint16_t d_reclen; uint8_t d_type; char d_name[256]; } dirent;'];
        return self::$c ??= FFI::cdef("$dirent int lstat(const char *, void *); void *opendir(const char *); dirent *readdir(void *); int closedir(void *); char *strerror(int); $errno");
    }
}
