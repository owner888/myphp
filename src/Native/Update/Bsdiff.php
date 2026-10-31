<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

use MyPHP\Native\Zlib;
use RuntimeException;

/**
 * Binary patches (mygo's bsdiff.go), as Colin Percival's bsdiff: the new
 * file is made of blocks, each adding bytes of the old file to difference
 * bytes, followed by extra bytes that are new. A patch is
 *
 *     the number of blocks, then the lengths of the first two streams (uvarints),
 *     the control stream: per block, the lengths of its difference and extra
 *     bytes (uvarints) and how far the old position moves after it (a varint),
 *     the difference bytes of all blocks, and their extra bytes,
 *
 * each stream compressed with DEFLATE on its own.
 *
 * @internal
 */
final class Bsdiff
{
    /** The patch that turns $old into $new. */
    public static function diff(string $old, string $new): string
    {
        $sa = SuffixArray::of($old);
        $oldLen = strlen($old);
        $newLen = strlen($new);
        $blocks = [];
        $diff = '';
        $extra = '';
        $scan = $pos = $length = 0;
        $lastScan = $lastPos = $lastOffset = 0;
        while ($scan < $newLen) {
            $oldScore = 0;
            $scan += $length;
            for ($scsc = $scan; $scan < $newLen; $scan++) {
                [$length, $pos] = self::search($sa, $old, $new, $scan);
                for (; $scsc < $scan + $length; $scsc++) {
                    if ($scsc + $lastOffset < $oldLen && $old[$scsc + $lastOffset] === $new[$scsc]) {
                        $oldScore++;
                    }
                }
                if ($length === $oldScore && $length !== 0 || $length > $oldScore + 8) {
                    break;
                }
                if ($scan + $lastOffset < $oldLen && $old[$scan + $lastOffset] === $new[$scan]) {
                    $oldScore--;
                }
            }
            if ($length === $oldScore && $scan !== $newLen) {
                continue;
            }
            // Extend the previous match forwards and this one backwards, as long as at least half of the bytes match.
            $lenF = 0;
            for ($i = 0, $s = 0, $best = 0; $lastScan + $i < $scan && $lastPos + $i < $oldLen;) {
                if ($old[$lastPos + $i] === $new[$lastScan + $i]) {
                    $s++;
                }
                $i++;
                if ($s * 2 - $i > $best * 2 - $lenF) {
                    $best = $s;
                    $lenF = $i;
                }
            }
            $lenB = 0;
            if ($scan < $newLen) {
                for ($i = 1, $s = 0, $best = 0; $scan >= $lastScan + $i && $pos >= $i; $i++) {
                    if ($old[$pos - $i] === $new[$scan - $i]) {
                        $s++;
                    }
                    if ($s * 2 - $i > $best * 2 - $lenB) {
                        $best = $s;
                        $lenB = $i;
                    }
                }
            }
            $overlap = $lastScan + $lenF - ($scan - $lenB);
            if ($overlap > 0) {
                $s = $best = $lenS = 0;
                for ($i = 0; $i < $overlap; $i++) {
                    if ($new[$lastScan + $lenF - $overlap + $i] === $old[$lastPos + $lenF - $overlap + $i]) {
                        $s++;
                    }
                    if ($new[$scan - $lenB + $i] === $old[$pos - $lenB + $i]) {
                        $s--;
                    }
                    if ($s > $best) {
                        $best = $s;
                        $lenS = $i + 1;
                    }
                }
                $lenF += $lenS - $overlap;
                $lenB -= $lenS;
            }
            if ($lenF > 0) {
                $diff .= self::subtract(substr($new, $lastScan, $lenF), substr($old, $lastPos, $lenF));
            }
            $extra .= substr($new, $lastScan + $lenF, $scan - $lenB - ($lastScan + $lenF));
            $b = [$lenF, $scan - $lenB - ($lastScan + $lenF), $pos - $lenB - ($lastPos + $lenF)];
            if ($b[0] === 0 && $b[1] === 0 && $blocks !== []) {
                // Only a move: the previous block makes it, so that every block but the first makes bytes.
                $blocks[count($blocks) - 1][2] += $b[2];
            } else {
                $blocks[] = $b;
            }
            $lastScan = $scan - $lenB;
            $lastPos = $pos - $lenB;
            $lastOffset = $pos - $scan;
        }
        $ctrl = '';
        foreach ($blocks as [$d, $e, $seek]) {
            $ctrl .= Varint::u($d) . Varint::u($e) . Varint::s($seek);
        }
        $ctrl = Zlib::deflate($ctrl, Zlib::RAW);
        $diff = Zlib::deflate($diff, Zlib::RAW);
        $extra = Zlib::deflate($extra, Zlib::RAW);
        return Varint::u(count($blocks)) . Varint::u(strlen($ctrl)) . Varint::u(strlen($diff)) . $ctrl . $diff . $extra;
    }

    /** $a - $b, bytewise mod 256. */
    private static function subtract(string $a, string $b): string
    {
        $out = '';
        $n = strlen($a);
        for ($i = 0; $i < $n; $i++) {
            $out .= chr((ord($a[$i]) - ord($b[$i])) & 0xff);
        }
        return $out;
    }

    /**
     * Bounds the matches search compares (mygo's maxMatch). diff searches at
     * each byte while a match is barely better than the previous alignment,
     * so comparing whole matches makes a long one cost time quadratic in its
     * length; a longer match is still taken, in pieces.
     */
    private const MAX_MATCH = 64 << 10;

    /** The longest prefix of $new from $at, up to MAX_MATCH bytes, found in $old, and where, by a binary search of the suffix array. @param list<int> $sa @return array{0: int, 1: int} */
    private static function search(array $sa, string $old, string $new, int $at): array
    {
        $end = min(strlen($new), $at + self::MAX_MATCH);
        $lo = 0;
        $hi = count($sa) - 1;
        while ($hi - $lo >= 2) {
            $mid = $lo + intdiv($hi - $lo, 2);
            if (self::less($old, $sa[$mid], $new, $at, $end)) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        $x = self::matchLen($old, $sa[$lo], $new, $at, $end);
        $y = self::matchLen($old, $sa[$hi], $new, $at, $end);
        return $x > $y ? [$x, $sa[$lo]] : [$y, $sa[$hi]];
    }

    /** Whether the suffix of $old at $i sorts before $new's bytes [$j, $end) (Go's bytes.Compare < 0). */
    private static function less(string $old, int $i, string $new, int $j, int $end): bool
    {
        $n = min(strlen($old) - $i, $end - $j);
        $c = $n > 0 ? strncmp(substr($old, $i, $n), substr($new, $j, $n), $n) : 0;
        return $c < 0 || $c === 0 && strlen($old) - $i < $end - $j;
    }

    /** How many bytes of $a from $i match $b's [$j, $end). */
    private static function matchLen(string $a, int $i, string $b, int $j, int $end): int
    {
        $n = min(strlen($a) - $i, $end - $j);
        if ($n <= 0) {
            return 0;
        }
        // Binary search for the first difference, comparing chunks.
        $lo = 0;
        $hi = $n;
        if (substr_compare($a, substr($b, $j, $n), $i, $n) === 0) {
            return $n;
        }
        while ($hi - $lo > 1) {
            $mid = intdiv($lo + $hi, 2);
            if (substr_compare($a, substr($b, $j, $mid), $i, $mid) === 0) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * Writes through $write the file of $size bytes that the patch at
     * [$at, $at + $patchSize) of $patch makes of $old, a file of $oldSize
     * bytes. A damaged patch fails cleanly: it cannot make more than $size
     * bytes, bytes read out of $old count as zeros, as in bspatch, and the
     * caller checks the file made.
     *
     * @param resource $old
     * @param resource $patch
     * @param \Closure(string): void $write
     */
    public static function patch(\Closure $write, int $size, $old, int $oldSize, $patch, int $at, int $patchSize): void
    {
        fseek($patch, $at);
        $head = (string)fread($patch, (int)min($patchSize, 30));
        $o = 0;
        $blocks = Varint::readU($head, $o);
        $ctrlLen = Varint::readU($head, $o);
        $diffLen = Varint::readU($head, $o);
        if ($blocks === null || $ctrlLen === null || $diffLen === null || $size < 0 || $blocks > $size + 1
            || $ctrlLen > $patchSize - $o || $diffLen > $patchSize - $o - $ctrlLen) {
            throw new DamagedPatch;
        }
        $start = $at + $o;
        try {
            $ctrl = Zlib::inflate(self::section($patch, $start, $ctrlLen), Zlib::RAW);
        } catch (RuntimeException) {
            throw new DamagedPatch;
        }
        $diff = new InflateReader($patch, $start + $ctrlLen, $diffLen);
        $extra = new InflateReader($patch, $start + $ctrlLen + $diffLen, $patchSize - $o - $ctrlLen - $diffLen);
        $oldPos = 0;
        $left = $size;
        $c = 0;
        for ($k = 0; $k < $blocks; $k++) {
            $d = Varint::readU($ctrl, $c);
            $e = Varint::readU($ctrl, $c);
            $seek = Varint::readS($ctrl, $c);
            if ($d === null || $e === null || $seek === null || $d > $left || $e > $left - $d) {
                throw new DamagedPatch;
            }
            $left -= $d + $e;
            while ($d > 0) {
                $n = min($d, 1 << 15);
                $bytes = $diff->read($n);
                if (strlen($bytes) !== $n) {
                    throw new DamagedPatch;
                }
                // Only the part of the block inside old is read.
                $from = max($oldPos, 0);
                $to = min($oldPos + $n, $oldSize);
                $base = str_repeat("\0", $n);
                if ($from < $to) {
                    fseek($old, $from);
                    $base = substr_replace($base, (string)fread($old, $to - $from), $from - $oldPos, $to - $from);
                }
                $write(self::add($bytes, $base));
                $oldPos += $n;
                $d -= $n;
            }
            while ($e > 0) {
                $n = min($e, 1 << 16);
                $bytes = $extra->read($n);
                if (strlen($bytes) !== $n) {
                    throw new DamagedPatch;
                }
                $write($bytes);
                $e -= $n;
            }
            $oldPos += $seek;
            if ($oldPos < -(1 << 40) || $oldPos > 1 << 40) {
                throw new DamagedPatch;
            }
        }
        if ($left !== 0) {
            throw new DamagedPatch;
        }
    }

    /** $a + $b, bytewise mod 256. */
    private static function add(string $a, string $b): string
    {
        $out = '';
        $n = strlen($a);
        for ($i = 0; $i < $n; $i++) {
            $out .= chr((ord($a[$i]) + ord($b[$i])) & 0xff);
        }
        return $out;
    }

    /** @param resource $f */
    public static function section($f, int $at, int $n): string
    {
        fseek($f, $at);
        $s = $n > 0 ? (string)fread($f, $n) : '';
        if (strlen($s) !== $n) {
            throw new DamagedPatch;
        }
        return $s;
    }
}
