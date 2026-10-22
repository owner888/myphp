<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

use MyPHP\Native\Zlib;
use RuntimeException;

/**
 * The archives of updates (mygo's WriteArchive and ExtractArchive): gzip
 * compressed tar, of files, directories and symbolic links with their
 * permissions, written and read here in plain PHP over the system's zlib.
 * Names that don't fit ustar's go in PAX headers, as Go's archive/tar
 * writes them.
 *
 * @internal
 */
final class Archive
{
    private const BLOCK = 512;

    /** Writes the entries of $dir (names in it) as an archive to $path, and returns its SHA-256. */
    public static function write(string $path, string $dir, array $entries): string
    {
        $out = fopen($path, 'wb') ?: throw new RuntimeException("cannot create $path");
        $sum = hash_init('sha256');
        $z = Zlib::deflater(Zlib::GZIP);
        $emit = function (string $bytes) use ($out, $sum, $z): void {
            $gz = $z->update($bytes);
            if ($gz !== '') {
                hash_update($sum, $gz);
                fwrite($out, $gz);
            }
        };
        try {
            foreach ($entries as $entry) {
                self::walk($dir, $entry, $emit);
            }
            $emit(str_repeat("\0", 2 * self::BLOCK));
            $gz = $z->finish();
            hash_update($sum, $gz);
            fwrite($out, $gz);
        } finally {
            fclose($out);
        }
        return hash_final($sum, true);
    }

    /** Writes $rel of $dir, and what is in it, in lexical order as Go's WalkDir. @param \Closure(string): void $emit */
    private static function walk(string $dir, string $rel, \Closure $emit): void
    {
        $p = "$dir/$rel";
        clearstatcache(false, $p);
        $st = lstat($p) ?: throw new RuntimeException("lstat $p failed");
        $type = $st['mode'] & 0o170000;
        $perm = $st['mode'] & 0o7777;
        if ($type === 0o040000) {
            $emit(self::header("$rel/", '5', $perm, 0, $st['mtime'], ''));
            $names = scandir($p) ?: throw new RuntimeException("cannot list $p");
            foreach ($names as $n) {
                if ($n !== '.' && $n !== '..') {
                    self::walk($dir, "$rel/$n", $emit);
                }
            }
        } elseif ($type === 0o120000) {
            $emit(self::header($rel, '2', $perm, 0, $st['mtime'], readlink($p) ?: throw new RuntimeException("readlink $p failed")));
        } elseif ($type === 0o100000) {
            $emit(self::header($rel, '0', $perm, $st['size'], $st['mtime'], ''));
            $f = fopen($p, 'rb') ?: throw new RuntimeException("cannot open $p");
            $n = 0;
            while (!feof($f) && ($b = fread($f, 1 << 16)) !== false && $b !== '') {
                $n += strlen($b);
                $emit($b);
            }
            fclose($f);
            if ($n !== $st['size']) {
                throw new RuntimeException("$p changed while it was archived");
            }
            if ($n % self::BLOCK !== 0) {
                $emit(str_repeat("\0", self::BLOCK - $n % self::BLOCK));
            }
        } else {
            throw new RuntimeException("$p is not a file, directory or link");
        }
    }

    /** The header of an entry, after a PAX header when its names don't fit ustar's. */
    private static function header(string $name, string $type, int $mode, int $size, int $mtime, string $link): string
    {
        $pax = '';
        [$prefix, $short] = self::split($name);
        if ($short === null) {
            $pax .= self::record('path', $name);
            [$prefix, $short] = ['', substr($name, 0, 100)];
        }
        if (strlen($link) > 100) {
            $pax .= self::record('linkpath', $link);
            $link = substr($link, 0, 100);
        }
        $out = '';
        if ($pax !== '') {
            $out .= self::block('PaxHeaders/' . substr(basename(rtrim($name, '/')), 0, 80), 'x', 0o644, strlen($pax), $mtime, '', '');
            $out .= $pax . str_repeat("\0", (self::BLOCK - strlen($pax) % self::BLOCK) % self::BLOCK);
        }
        return $out . self::block($short, $type, $mode, $size, $mtime, $link, $prefix);
    }

    /** $name as ustar's prefix and name, or [.., null] when it doesn't fit. @return array{0: string, 1: ?string} */
    private static function split(string $name): array
    {
        if (strlen($name) <= 100) {
            return ['', $name];
        }
        for ($i = strpos($name, '/'); $i !== false; $i = strpos($name, '/', $i + 1)) {
            if ($i <= 155 && strlen($name) - $i - 1 <= 100 && $i > 0) {
                return [substr($name, 0, $i), substr($name, $i + 1)];
            }
        }
        return ['', null];
    }

    /** A PAX record: "<length> <key>=<value>\n", the length counting itself. */
    private static function record(string $key, string $value): string
    {
        $body = " $key=$value\n";
        $n = strlen($body) + 1;
        while (strlen((string)$n) + strlen($body) !== $n) {
            $n = strlen((string)$n) + strlen($body);
        }
        return $n . $body;
    }

    private static function block(string $name, string $type, int $mode, int $size, int $mtime, string $link, string $prefix): string
    {
        $oct = fn(int $v, int $width): string => str_pad(decoct($v), $width - 1, '0', STR_PAD_LEFT) . "\0";
        $h = str_pad($name, 100, "\0") . $oct($mode, 8) . $oct(0, 8) . $oct(0, 8) . $oct($size, 12) . $oct($mtime, 12)
            . '        ' . $type . str_pad($link, 100, "\0") . "ustar\00000" . str_repeat("\0", 32) . str_repeat("\0", 32)
            . $oct(0, 8) . $oct(0, 8) . str_pad($prefix, 155, "\0") . str_repeat("\0", 12);
        $sum = array_sum(unpack('C*', $h));
        return substr_replace($h, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
    }

    /**
     * Unpacks the archive at $path into $dir. It refuses entries outside
     * $dir, links pointing out of it and anything but files, directories
     * and symbolic links.
     */
    public static function extract(string $path, string $dir): void
    {
        $in = fopen($path, 'rb') ?: throw new RuntimeException("cannot open $path");
        $z = Zlib::inflater(Zlib::GZIP);
        $buf = '';
        $file = null; // the file being written, and the bytes it still takes
        $left = 0;
        $pad = 0;
        $pax = [];
        $done = false;
        try {
            while (!$done) {
                $chunk = feof($in) ? '' : (string)fread($in, 1 << 16);
                $buf .= $chunk === '' ? $z->finish() : $z->update($chunk);
                $eof = $chunk === '';
                while (true) {
                    if ($left > 0) {
                        $take = substr($buf, 0, $left);
                        $buf = (string)substr($buf, strlen($take));
                        $left -= strlen($take);
                        fwrite($file, $take);
                        if ($left > 0) {
                            break;
                        }
                        fclose($file);
                        $file = null;
                    }
                    if ($pad > 0) {
                        if (strlen($buf) < $pad) {
                            break;
                        }
                        $buf = (string)substr($buf, $pad);
                        $pad = 0;
                    }
                    if (strlen($buf) < self::BLOCK) {
                        break;
                    }
                    $h = substr($buf, 0, self::BLOCK);
                    if (trim($h, "\0") === '') {
                        $done = true; // the end
                        break;
                    }
                    $size = octdec(rtrim(substr($h, 124, 12), "\0 "));
                    $type = $h[156];
                    $data = $size;
                    $padTo = ($data + self::BLOCK - 1) & ~(self::BLOCK - 1);
                    if ($type === 'x' || $type === 'g' || $type === 'L' || $type === 'K') {
                        if (strlen($buf) < self::BLOCK + $padTo) {
                            break; // the whole of it first
                        }
                        $body = substr($buf, self::BLOCK, $data);
                        $buf = (string)substr($buf, self::BLOCK + $padTo);
                        if ($type === 'x') {
                            $pax = self::parsePax($body);
                        } elseif ($type === 'L') {
                            $pax['path'] = rtrim($body, "\0");
                        } elseif ($type === 'K') {
                            $pax['linkpath'] = rtrim($body, "\0");
                        }
                        continue;
                    }
                    $buf = (string)substr($buf, self::BLOCK);
                    $name = rtrim(substr($h, 0, 100), "\0");
                    $prefix = substr($h, 257, 6) === "ustar\0" ? rtrim(substr($h, 345, 155), "\0") : '';
                    if ($prefix !== '') {
                        $name = "$prefix/$name";
                    }
                    $name = $pax['path'] ?? $name;
                    $link = $pax['linkpath'] ?? rtrim(substr($h, 157, 100), "\0");
                    $pax = [];
                    $mode = octdec(rtrim(substr($h, 100, 8), "\0 ")) & 0o777;
                    $clean = self::clean($name);
                    if (!self::validPath($clean) || $clean === '.') {
                        throw new RuntimeException('invalid path in update archive: ' . json_encode($name));
                    }
                    $target = "$dir/$clean";
                    switch ($type) {
                        case '5':
                            self::mkdirAll($target);
                            break;
                        case '0':
                        case "\0":
                            self::mkdirAll(dirname($target));
                            $file = fopen($target, 'wb') ?: throw new RuntimeException("cannot create $target");
                            chmod($target, $mode | 0o200);
                            $left = $data;
                            $pad = $padTo - $data;
                            if ($left === 0) {
                                fclose($file);
                                $file = null;
                            }
                            break;
                        case '2':
                            $resolved = self::clean(self::dirOf($clean) . '/' . $link);
                            if (str_starts_with($link, '/') || !self::validPath($resolved)) {
                                throw new RuntimeException('update archive link ' . json_encode($name) . ' points outside the app');
                            }
                            self::mkdirAll(dirname($target));
                            if (!@symlink($link, $target)) {
                                throw new RuntimeException("cannot link $target");
                            }
                            break;
                        default:
                            throw new RuntimeException('unsupported entry in update archive: ' . json_encode($name));
                    }
                }
                if ($eof && !$done) {
                    if ($left > 0 || $buf !== '') {
                        throw new RuntimeException('unexpected EOF');
                    }
                    $done = true; // an archive without its end blocks, as Go's reader takes
                }
            }
        } finally {
            if ($file !== null) {
                fclose($file);
            }
            fclose($in);
        }
    }

    /** @return array<string, string> */
    private static function parsePax(string $body): array
    {
        $out = [];
        while ($body !== '') {
            $sp = strpos($body, ' ');
            $n = $sp === false ? 0 : (int)substr($body, 0, $sp);
            if ($n <= $sp || $n > strlen($body)) {
                throw new RuntimeException('invalid PAX header in update archive');
            }
            $rec = substr($body, $sp + 1, $n - $sp - 2);
            [$k, $v] = array_pad(explode('=', $rec, 2), 2, '');
            $out[$k] = $v;
            $body = (string)substr($body, $n);
        }
        return $out;
    }

    private static function mkdirAll(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot make $dir");
        }
    }

    private static function dirOf(string $p): string
    {
        $i = strrpos($p, '/');
        return $i === false ? '.' : substr($p, 0, $i);
    }

    /** Go's path.Clean. */
    public static function clean(string $p): string
    {
        $abs = str_starts_with($p, '/');
        $out = [];
        foreach (explode('/', $p) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);
                } elseif (!$abs) {
                    $out[] = '..';
                }
                continue;
            }
            $out[] = $part;
        }
        $s = implode('/', $out);
        return $abs ? "/$s" : ($s === '' ? '.' : $s);
    }

    /** Go's fs.ValidPath: unrooted, slash-separated, no "." or ".." or empty elements, but "." itself. */
    public static function validPath(string $p): bool
    {
        if (!mb_check_encoding($p, 'UTF-8')) {
            return false;
        }
        if ($p === '.') {
            return true;
        }
        foreach (explode('/', $p) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return true;
    }
}
