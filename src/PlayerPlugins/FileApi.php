<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\Shell;

/**
 * IINA's iina.file, by IINA's paths (Api::parsePath): the plugin's @tmp
 * and @data as its own, the rest of the file system's permission; a file
 * overwritten, or deleted, only of its own.
 */
final class FileApi extends Api
{
    public function exists(string $path): bool
    {
        return file_exists($this->parsePath($path)[0]);
    }

    /** What folder $path has (in its folders too where $options' includeSubDir): filename, path (under the folder), isDir. @param array<string, mixed> $options @return ?list<array{filename: string, path: string, isDir: bool}> */
    public function list(string $path, array $options = []): ?array
    {
        $dir = rtrim($this->parsePath($path)[0], '/');
        if (!is_dir($dir)) { return null; }
        $out = [];
        $walk = function (string $d) use (&$walk, &$out, $dir, $options): void {
            foreach (scandir($d) ?: [] as $f) {
                if ($f === '.' || $f === '..') { continue; }
                $full = "$d/$f";
                $out[] = ['filename' => $f, 'path' => substr($full, strlen($dir)), 'isDir' => is_dir($full)];
                if (($options['includeSubDir'] ?? false) === true && is_dir($full) && !is_link($full)) { $walk($full); }
            }
        };
        $walk($dir);
        return $out;
    }

    public function write(string $path, string $content): void
    {
        $this->log("file.write $path: " . mb_strlen($content));
        [$file, $local] = $this->parsePath($path);
        if (!$local && file_exists($file)) {
            $this->fail("Cannot overwrite existing file at $path. Overwriting is only supported for @tmp and @data files.");
        }
        if (@file_put_contents($file, $content) === false) { $this->fail("Cannot write file: $file"); }
    }

    /** Its text, of $options' encoding (iconv's names), UTF-8 by default. @param array<string, mixed> $options */
    public function read(string $path, array $options = []): ?string
    {
        $file = $this->parsePath($path)[0];
        $s = @file_get_contents($file);
        if ($s === false) { $this->fail("Cannot read file: $file"); }
        $enc = $options['encoding'] ?? null;
        if (is_string($enc) && strcasecmp($enc, 'utf-8') !== 0 && strcasecmp($enc, 'utf8') !== 0) {
            $t = @iconv($enc, 'UTF-8', $s);
            if ($t === false) { $this->fail("Unknown encoding \"$enc\""); }
            return $t;
        }
        return $s;
    }

    public function trash(string $path): void
    {
        $this->log("file.trash $path");
        Shell::trashItem($this->parsePath($path)[0]);
    }

    public function delete(string $path): void
    {
        $this->log("file.delete $path");
        [$file, $local] = $this->parsePath($path);
        if (!$local) { $this->fail('file.delete can only be called for @tmp and @data files.'); }
        if (is_dir($file) ? !@rmdir($file) : !@unlink($file)) { $this->fail("Cannot delete file: $file"); }
    }

    public function move(string $source, string $dest): void
    {
        $this->log("file.move $source -> $dest");
        if (!@rename($this->parsePath($source)[0], $this->parsePath($dest)[0])) { $this->fail("Cannot move file: $source"); }
    }

    public function showInFinder(string $path): void
    {
        Shell::showItemInFolder($this->parsePath($path)[0]);
    }

    /** A handle of the file, to "read" or "write" its bytes. */
    public function handle(string $path, string $mode): FileHandle
    {
        $file = $this->parsePath($path)[0];
        if ($mode !== 'read' && $mode !== 'write') { $this->fail('file.handle: mode should be "read" or "write".'); }
        $h = @fopen($file, $mode === 'read' ? 'rb' : 'r+b');
        if ($h === false) { $this->fail('file.handle: cannot create file handle'); }
        return new FileHandle($h, $mode === 'read');
    }
}
