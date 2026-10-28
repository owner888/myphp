<?php
declare(strict_types=1);

namespace MyPHP\Media;

use MyPHP\App;
use MyPHP\Native\{ImageIO, Mem};
use MyPHP\PathName;
use RuntimeException;

/**
 * The seek bar's thumbnails of films seen, kept on disk as IINA's
 * ThumbnailCache keeps them: a file for each film (by the md5 of its
 * path) in thumb_cache of App::path(Cache), valid while the film's size
 * and modification time are those it was made of; its thumbnails JPEGs
 * of quality 0.75. Past MAX bytes in all, the least recently used half of
 * that goes before another is written (IINA's CacheManager). A file cut
 * short is deleted and its film's thumbnails made again, as IINA does.
 *
 * A file: "MTC", its version, the film's size and modification time and
 * the thumbnails' width (all 64-bit little-endian); then each thumbnail,
 * its time (a double), width and height (16 bits each), its JPEG's length
 * (32 bits) and the JPEG.
 */
final class ThumbnailCache
{
    public const VERSION = 1;
    /** IINA's maxThumbnailPreviewCacheSize, 500 MiB; its JPEG's quality. */
    public const MAX = 500 << 20, QUALITY = 0.75;
    private const MAGIC = 'MTC';
    private static ?self $default = null;

    public function __construct(private readonly string $dir, private readonly int $max = self::MAX) {}

    /** The app's: thumb_cache in its caches. */
    public static function default(): self
    {
        return self::$default ??= new self(App::path(PathName::Cache) . '/thumb_cache');
    }

    /**
     * The thumbnails of the film at $path, $width wide, as they were put;
     * null when none were, or the film changed since (its size or its
     * modification time), or they were of another width. Their pixels
     * decode when first asked.
     * @return ?list<Thumbnail>
     */
    public function get(string $path, int $width): ?array
    {
        $file = $this->file($path);
        $s = is_file($file) ? @file_get_contents($file) : false;
        if ($s === false || strlen($s) < 28 || substr($s, 0, 3) !== self::MAGIC || ord($s[3]) !== self::VERSION) {
            return null;
        }
        ['size' => $size, 'mtime' => $mtime, 'width' => $w] = unpack('Psize/Pmtime/Pwidth', $s, 4);
        if ([$size, $mtime] !== $this->stamp($path) || $w !== $width) {
            return null;
        }
        @touch($file, (int)filemtime($file), time()); // used now: its access time, which eviction goes by
        $out = [];
        for ($p = 28, $n = strlen($s); $p < $n;) {
            if ($p + 16 > $n) { @unlink($file); return null; }
            ['t' => $t, 'w' => $tw, 'h' => $th, 'n' => $len] = unpack('et/vw/vh/Vn', $s, $p);
            $jpeg = substr($s, $p + 16, $len);
            if (strlen($jpeg) !== $len) { // cut short (a disk's fault: it is written whole, through a temporary file): gone, made again
                @unlink($file);
                return null;
            }
            $out[] = new Thumbnail($t, $tw, $th, static function () use ($jpeg, $tw, $th): string {
                $img = ImageIO::decode($jpeg, max($tw, $th));
                $rgba = \FFI::string($img['pix'], $img['w'] * $img['h'] * 4);
                Mem::free($img['pix']);
                return $rgba;
            });
            $p += 16 + $len;
        }
        return $out;
    }

    /** $t as the cache keeps it: a JPEG, of a fraction of a millisecond, made as each thumbnail is (put() writes them at once). */
    public static function jpeg(Thumbnail $t): string
    {
        return ImageIO::jpeg($t->rgba(), $t->width, $t->height, self::QUALITY);
    }

    /**
     * Keeps $thumbnails of the film at $path, all $width wide, for get(),
     * each its JPEG of $jpegs (jpeg()): written through a temporary file,
     * the least recently used gone first when the cache is past its size.
     * @param list<Thumbnail> $thumbnails
     * @param list<string> $jpegs
     */
    public function put(string $path, int $width, array $thumbnails, array $jpegs): void
    {
        if (count($jpegs) !== count($thumbnails)) {
            throw new RuntimeException('myphp: a JPEG for each thumbnail');
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new RuntimeException("myphp: cannot create {$this->dir}");
        }
        [$size, $mtime] = $this->stamp($path);
        $s = self::MAGIC . chr(self::VERSION) . pack('PPP', $size, $mtime, $width);
        foreach ($thumbnails as $k => $t) {
            $s .= pack('evvV', $t->time, $t->width, $t->height, strlen($jpegs[$k])) . $jpegs[$k];
        }
        $this->evict(strlen($s));
        $file = $this->file($path);
        $tmp = "$file." . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $s) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("myphp: cannot write the thumbnails' cache $file");
        }
    }

    /** Makes room for $bytes more: past the cache's size, the least recently used files go until half of it is free, as IINA's clearOldCache. */
    private function evict(int $bytes): void
    {
        $files = [];
        $total = $bytes;
        foreach (scandir($this->dir) ?: [] as $name) {
            $f = "{$this->dir}/$name";
            if ($name[0] === '.' || !is_file($f)) { continue; }
            $st = stat($f);
            $files[] = [$st['atime'], $st['size'], $f];
            $total += $st['size'];
        }
        if ($total <= $this->max) {
            return;
        }
        sort($files);
        $freed = 0;
        foreach ($files as [, $size, $f]) {
            if ($freed >= $this->max / 2) { break; }
            @unlink($f);
            $freed += $size;
        }
    }

    /** The film's size and modification time, which its thumbnails are of. @return array{0: int, 1: int} */
    private function stamp(string $path): array
    {
        clearstatcache(true, $path);
        $size = @filesize($path); $mtime = @filemtime($path);
        if ($size === false || $mtime === false) {
            throw new RuntimeException("myphp: no film at $path");
        }
        return [$size, $mtime];
    }

    private function file(string $path): string
    {
        return $this->dir . '/' . md5($path);
    }
}
