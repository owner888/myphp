<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

use MyPHP\Native\Zlib;
use RuntimeException;

/**
 * Delta updates (mygo's delta.go), as Sparkle's: a delta turns the app of
 * an earlier version into the new one, and holds only what changed. Its
 * index lists the tree of the new app: directories, links, and files with
 * their size and SHA-256, each a copy of a file of the old app, a patch of
 * one (Bsdiff), or new. A delta is
 *
 *     "mygo delta 1\n", the length of the index (uvarint), the index (JSON, DEFLATE),
 *     the data of the files, in the order of the index: a patch, or the
 *     file compressed with DEFLATE,
 *
 * and is signed like an archive. Applying it checks every file it makes,
 * so that a delta applied to another app than the one it was made from
 * fails: the updater then downloads the archive.
 *
 * @internal
 */
final class Delta
{
    private const MAGIC = "mygo delta 1\n";
    /** The largest files deltas patch; larger ones that changed are included whole. PHP's bsdiff takes about 70 bytes of memory and 3 µs a byte. */
    public const MAX_DIFF = 32 << 20;
    /** Where stapling writes the notarization ticket of a bundle, which deltas include whole. */
    private const STAPLED_TICKET = 'Contents/CodeResources';
    private const MAX_INDEX = 16 << 20;

    /**
     * Writes to $path the delta that turns the app in $oldDir, of version
     * $from, into the app in $newDir, of version $to: the $entries of
     * $newDir, or all of it when null. Returns its SHA-256.
     *
     * @param ?list<string> $entries
     */
    public static function write(string $path, string $from, string $to, string $oldDir, string $newDir, ?array $entries): string
    {
        // The files of the old app, and the first of each content.
        $old = [];
        $bySum = [];
        self::walk($oldDir, '', function (string $rel, string $p, array $st) use (&$old, &$bySum): void {
            if (($st['mode'] & 0o170000) !== 0o100000 || $rel === self::STAPLED_TICKET) {
                return;
            }
            $sum = hash_file('sha256', $p);
            $old[$rel] = [$sum, $st['size']];
            $bySum[$sum] ??= $rel;
        });
        if ($entries === null) {
            $entries = array_values(array_diff(scandir($newDir) ?: [], ['.', '..']));
            sort($entries, SORT_STRING);
        }
        $index = ['from' => $from, 'version' => $to, 'entries' => []];
        $data = [];
        foreach ($entries as $top) {
            self::walk($newDir, $top, function (string $rel, string $p, array $st) use (&$index, &$data, $old, $bySum, $oldDir): void {
                $e = ['path' => $rel];
                $type = $st['mode'] & 0o170000;
                if ($type === 0o040000) {
                    $e['dir'] = true;
                } elseif ($type === 0o120000) {
                    $e['link'] = (string)readlink($p);
                } elseif ($type === 0o100000) {
                    $b = (string)file_get_contents($p);
                    $sum = hash('sha256', $b);
                    $e += ['mode' => $st['mode'] & 0o777, 'size' => strlen($b), 'sha256' => $sum];
                    $o = $old[$rel] ?? null;
                    if ($o !== null && $o[0] === $sum) {
                        $e['from'] = $rel;
                    } elseif (isset($bySum[$sum])) {
                        $e['from'] = $bySum[$sum]; // moved, or copied
                    } else {
                        $blob = Zlib::deflate($b, Zlib::RAW);
                        if ($o !== null && $o[1] > 0 && $o[1] <= self::MAX_DIFF && strlen($b) <= self::MAX_DIFF) {
                            $patch = Bsdiff::diff((string)file_get_contents("$oldDir/$rel"), $b);
                            if (strlen($patch) < strlen($blob)) {
                                $e['from'] = $rel;
                                $blob = $patch;
                            }
                        }
                        $e['data'] = strlen($blob);
                        $data[] = $blob;
                    }
                } else {
                    throw new RuntimeException("$p is not a file, directory or link");
                }
                $index['entries'][] = $e;
            });
        }
        $js = Zlib::deflate(json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), Zlib::RAW);
        $out = fopen($path, 'wb') ?: throw new RuntimeException("cannot create $path");
        $sum = hash_init('sha256');
        foreach ([self::MAGIC . Varint::u(strlen($js)) . $js, ...$data] as $chunk) {
            hash_update($sum, $chunk);
            fwrite($out, $chunk);
        }
        fclose($out);
        return hash_final($sum, true);
    }

    /** Calls $fn with every entry under $top of $dir, in lexical order as Go's WalkDir ($top '' for all of $dir, which is not an entry). @param \Closure(string, string, array): void $fn */
    private static function walk(string $dir, string $top, \Closure $fn): void
    {
        $p = $top === '' ? $dir : "$dir/$top";
        clearstatcache(false, $p);
        $st = lstat($p) ?: throw new RuntimeException("lstat $p failed");
        if ($top !== '') {
            $fn($top, $p, $st);
        }
        if (($st['mode'] & 0o170000) === 0o040000) {
            $names = array_values(array_diff(scandir($p) ?: [], ['.', '..']));
            sort($names, SORT_STRING);
            foreach ($names as $n) {
                self::walk($dir, $top === '' ? $n : "$top/$n", $fn);
            }
        }
    }

    /**
     * Makes in $newDir, which must not exist, the app that the delta at
     * $path, of $size bytes, makes of the app in $oldDir. The delta must
     * update version $from to version $to, and every file it makes must
     * have the size and SHA-256 its index gives.
     */
    public static function apply(string $path, int $size, string $from, string $to, string $oldDir, string $newDir): void
    {
        $f = fopen($path, 'rb') ?: throw new RuntimeException("cannot open $path");
        try {
            $head = (string)fread($f, strlen(self::MAGIC) + 10);
            if (!str_starts_with($head, self::MAGIC)) {
                throw new RuntimeException('not a delta update');
            }
            $o = strlen(self::MAGIC);
            $indexLen = Varint::readU($head, $o);
            $start = $o;
            if ($indexLen === null || $indexLen > $size - $start) {
                throw new DamagedPatch('damaged delta update');
            }
            try {
                $js = Zlib::inflate(Bsdiff::section($f, $start, $indexLen), Zlib::RAW);
            } catch (RuntimeException) {
                throw new DamagedPatch('damaged delta update');
            }
            $index = strlen($js) > self::MAX_INDEX ? null : json_decode($js, true, 8);
            if (!is_array($index) || !is_array($index['entries'] ?? null)) {
                throw new DamagedPatch('damaged delta update');
            }
            if (($index['from'] ?? '') !== $from || ($index['version'] ?? '') !== $to) {
                throw new RuntimeException("the delta updates {$index['from']} to {$index['version']}, not $from to $to");
            }
            // Check the index before making anything.
            $offset = $start + $indexLen;
            $seen = [];
            $entries = [];
            foreach ($index['entries'] as $e) {
                $e = [
                    'path' => (string)($e['path'] ?? ''), 'dir' => (bool)($e['dir'] ?? false), 'link' => (string)($e['link'] ?? ''),
                    'mode' => (int)($e['mode'] ?? 0), 'size' => (int)($e['size'] ?? 0), 'sha256' => (string)($e['sha256'] ?? ''),
                    'from' => (string)($e['from'] ?? ''), 'data' => (int)($e['data'] ?? 0),
                ];
                $file = !$e['dir'] && $e['link'] === '';
                if (!Archive::validPath($e['path']) || $e['path'] === '.' || isset($seen[$e['path']]) || $e['size'] < 0 || $e['data'] < 0 || $e['data'] > $size - $offset
                    || $e['dir'] && $e['link'] !== '' || !$file && ($e['size'] !== 0 || $e['data'] !== 0 || $e['from'] !== '')
                    || $e['from'] !== '' && !Archive::validPath($e['from']) || $file && $e['from'] === '' && $e['data'] === 0) {
                    throw new DamagedPatch('damaged delta update');
                }
                if ($e['link'] !== '') {
                    $dir = str_contains($e['path'], '/') ? substr($e['path'], 0, strrpos($e['path'], '/')) : '.';
                    if (str_starts_with($e['link'], '/') || !Archive::validPath(Archive::clean("$dir/{$e['link']}"))) {
                        throw new RuntimeException('delta update link ' . json_encode($e['path']) . ' points outside the app');
                    }
                }
                $seen[$e['path']] = true;
                $e['at'] = $offset;
                $offset += $e['data'];
                $entries[] = $e;
            }
            if ($offset !== $size) {
                throw new DamagedPatch('damaged delta update');
            }
            if (!@mkdir($newDir, 0o755)) {
                throw new RuntimeException("cannot make $newDir");
            }
            foreach ($entries as $e) {
                $target = "$newDir/{$e['path']}";
                $parent = dirname($target);
                if (!is_dir($parent) && !@mkdir($parent, 0o755, true) && !is_dir($parent)) {
                    throw new RuntimeException("cannot make $parent");
                }
                if ($e['dir']) {
                    if (!is_dir($target) && !@mkdir($target, 0o755, true)) {
                        throw new RuntimeException("cannot make $target");
                    }
                } elseif ($e['link'] !== '') {
                    if (!@symlink($e['link'], $target)) {
                        throw new RuntimeException("cannot link $target");
                    }
                } else {
                    self::applyFile($e, $f, $oldDir, $target);
                }
            }
        } finally {
            fclose($f);
        }
    }

    /** Makes the file of $e at $target from its data in the delta $f. @param array<string, mixed> $e @param resource $f */
    private static function applyFile(array $e, $f, string $oldDir, string $target): void
    {
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException("$target exists");
        }
        $out = fopen($target, 'xb') ?: throw new RuntimeException("cannot create $target");
        chmod($target, ($e['mode'] & 0o777) | 0o200);
        $h = hash_init('sha256');
        $left = $e['size'];
        $write = function (string $b) use ($out, $h, &$left): void {
            if (strlen($b) > $left) {
                throw new DamagedPatch('too long');
            }
            $left -= strlen($b);
            hash_update($h, $b);
            fwrite($out, $b);
        };
        try {
            if ($e['from'] === '') {
                $r = new InflateReader($f, $e['at'], $e['data']);
                while (($b = $r->read(1 << 16)) !== '') {
                    $write($b);
                }
            } else {
                $src = "$oldDir/{$e['from']}";
                clearstatcache(false, $src);
                if (is_link($src) || !is_file($src)) {
                    throw new RuntimeException("{$e['from']} is not a file");
                }
                $old = fopen($src, 'rb') ?: throw new RuntimeException("cannot open $src");
                try {
                    if ($e['data'] === 0) {
                        while (!feof($old) && ($b = fread($old, 1 << 16)) !== false && $b !== '') {
                            $write($b);
                        }
                    } else {
                        Bsdiff::patch($write, $e['size'], $old, (int)filesize($src), $f, $e['at'], $e['data']);
                    }
                } finally {
                    fclose($old);
                }
            }
        } catch (DamagedPatch) {
            $left = -1; // most likely, the app is not the one the delta was made from
        } finally {
            fclose($out);
        }
        if ($left !== 0 || hash_final($h) !== $e['sha256']) {
            throw new RuntimeException("the delta update made {$e['path']} wrong");
        }
    }
}
