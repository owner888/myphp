<?php
declare(strict_types=1);

namespace MyPHP\Native\Docs;

use MyPHP\Native\ObjC;
use RuntimeException;

/**
 * Documents' files (mygo's document_file.go): their stamps, which tell
 * whether another app changed them, and writing them as NSDocument does,
 * into a new file next to them, flushed, which then replaces them.
 *
 * @internal
 */
final class Files
{
    /** Replaces the file at $path with the file at $tmp, keeping its metadata: NSFileManager's replaceItemAtURL by default; tests set a rename. @var ?\Closure(string, string): void */
    public static ?\Closure $replace = null;

    /**
     * The file's modification time, in seconds as PHP's stat() has it, and size, as the document last read or wrote it,
     * then its identity (device, inode and type), by which a document finds the file where another app renamed it
     * (Watches): it says nothing of the content, so sameStamp() ignores it, and recovery doesn't keep it; null when it
     * doesn't exist.
     * @return ?array{0: int, 1: int, 2?: array{0: int, 1: int, 2: int}}
     */
    public static function stamp(string $path): ?array
    {
        clearstatcache(true, $path);
        $st = @stat($path);
        return $st === false ? null : [$st['mtime'], $st['size'], [$st['dev'], $st['ino'], $st['mode'] & 0o170000]];
    }

    /** Whether stamps $a and $b stamp the same content: the same modification time and size (mygo's fileStamp.same). */
    public static function sameStamp(?array $a, ?array $b): bool
    {
        return $a !== null && $b !== null && $a[0] === $b[0] && $a[1] === $b[1];
    }

    public static function writable(string $path): bool
    {
        clearstatcache(true, $path);
        return is_writable($path);
    }

    /** Whether $a and $b are the same file. */
    public static function same(string $a, string $b): bool
    {
        $sa = @stat($a);
        $sb = @stat($b);
        return $sa !== false && $sb !== false && $sa['dev'] === $sb['dev'] && $sa['ino'] === $sb['ino'];
    }

    /**
     * Writes $bytes as the file at $path safely: a symlink's target is
     * replaced, and a file with other hard links, or in a directory the
     * user can't create files in, is written in place, as GIO does.
     * Returns the file's new stamp.
     * @return array{0: int, 1: int}
     */
    public static function writeSafely(string $path, string $bytes): array
    {
        $real = realpath($path);
        if ($real !== false) {
            $path = $real;
        }
        clearstatcache(true, $path);
        $st = @stat($path);
        if ($st !== false && $st['nlink'] > 1) {
            return self::writeInPlace($path, $bytes);
        }
        $tmp = self::sibling($path);
        if ($tmp === null) {
            if ($st !== false) {
                return self::writeInPlace($path, $bytes);
            }
            throw is_writable(dirname($path)) ? new RuntimeException('myphp: cannot create a temporary file next to ' . $path) : new NotWritable(dirname($path));
        }
        try {
            self::put($tmp, $bytes);
            (self::$replace ?? self::replaceItem(...))($path, $tmp);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
        return self::stamp($path) ?? throw new RuntimeException("myphp: $path is gone after it was written");
    }

    /** Creates a hidden file next to $path, or null when the directory takes none. */
    public static function sibling(string $path): ?string
    {
        $dir = dirname($path);
        for ($i = 0; $i < 10; $i++) {
            $name = "$dir/." . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $f = @fopen($name, 'x');
            if ($f !== false) {
                fclose($f);
                return $name;
            }
            if (!is_writable($dir)) {
                return null;
            }
        }
        return null;
    }

    /** Writes $bytes into $path and flushes it to the disk. */
    public static function put(string $path, string $bytes): void
    {
        $f = @fopen($path, 'w');
        if ($f === false) {
            clearstatcache(true, $path);
            throw file_exists($path) && !is_writable($path) ? new NotWritable($path) : new RuntimeException("myphp: cannot write $path: " . (error_get_last()['message'] ?? 'failed'));
        }
        try {
            if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) {
                throw new RuntimeException("myphp: cannot write $path");
            }
        } finally {
            fclose($f);
        }
    }

    /** @return array{0: int, 1: int} */
    private static function writeInPlace(string $path, string $bytes): array
    {
        self::put($path, $bytes);
        return self::stamp($path) ?? throw new RuntimeException("myphp: $path is gone after it was written");
    }

    /** NSFileManager's replaceItemAtURL:withItemAtURL:, which keeps the file's permissions, creation date, tags, extended attributes, ACLs and identity, so that aliases still find it; a new file is moved into place. */
    private static function replaceItem(string $path, string $tmp): void
    {
        if (!file_exists($path)) {
            if (!@rename($tmp, $path)) {
                throw new RuntimeException("myphp: cannot write $path");
            }
            return;
        }
        $fm = ObjC::msg('p:', ObjC::cls('NSFileManager'), 'defaultManager');
        $url = fn(string $p) => ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($p));
        $err = ObjC::$c->new('void*[1]');
        if (!ObjC::msg('b:ppplpp', $fm, 'replaceItemAtURL:withItemAtURL:backupItemName:options:resultingItemURL:error:', $url($path), $url($tmp), null, 0, null, $err)) {
            throw new RuntimeException('myphp: ' . ObjC::phpStr(ObjC::msg('p:', $err[0], 'localizedDescription')));
        }
    }

    /** Writes a file of the app's own: a new file renamed over it, so that it is whole or not there (mygo's writeAppFile). */
    public static function writeAppFile(string $path, string $bytes): void
    {
        $tmp = self::sibling($path) ?? throw new RuntimeException("myphp: cannot write $path");
        try {
            self::put($tmp, $bytes);
            if (!@rename($tmp, $path)) {
                throw new RuntimeException("myphp: cannot write $path");
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }
}
