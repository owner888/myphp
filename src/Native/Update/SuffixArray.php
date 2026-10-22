<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

/**
 * The suffix array of a string, with the empty suffix first, by Larsson
 * and Sadakane's qsufsort, as bsdiff builds it (mygo's suffix.go).
 *
 * @internal
 */
final class SuffixArray
{
    /** @return list<int> */
    public static function of(string $b): array
    {
        $n = strlen($b);
        $I = array_fill(0, $n + 1, 0);
        $V = array_fill(0, $n + 1, 0);
        $buckets = array_fill(0, 256, 0);
        $bytes = $n > 0 ? array_values(unpack('C*', $b)) : [];
        foreach ($bytes as $c) {
            $buckets[$c]++;
        }
        for ($i = 1; $i < 256; $i++) {
            $buckets[$i] += $buckets[$i - 1];
        }
        for ($i = 255; $i > 0; $i--) {
            $buckets[$i] = $buckets[$i - 1];
        }
        $buckets[0] = 0;
        foreach ($bytes as $i => $c) {
            $buckets[$c]++;
            $I[$buckets[$c]] = $i;
        }
        $I[0] = $n;
        foreach ($bytes as $i => $c) {
            $V[$i] = $buckets[$c];
        }
        $V[$n] = 0;
        for ($i = 1; $i < 256; $i++) {
            if ($buckets[$i] === $buckets[$i - 1] + 1) {
                $I[$buckets[$i]] = -1;
            }
        }
        $I[0] = -1;
        unset($bytes);

        // Negative entries of I are lengths of sorted runs; V holds the group of each suffix, by its first h bytes.
        for ($h = 1; $I[0] !== -($n + 1); $h += $h) {
            $run = 0;
            $i = 0;
            while ($i < $n + 1) {
                if ($I[$i] < 0) {
                    $run -= $I[$i];
                    $i -= $I[$i];
                    continue;
                }
                if ($run !== 0) {
                    $I[$i - $run] = -$run;
                }
                $size = $V[$I[$i]] + 1 - $i;
                self::split($I, $V, $i, $size, $h);
                $i += $size;
                $run = 0;
            }
            if ($run !== 0) {
                $I[$i - $run] = -$run;
            }
        }
        for ($i = 0; $i < $n + 1; $i++) {
            $I[$V[$i]] = $i;
        }
        return $I;
    }

    /** Sorts the group of I[start:start+size] by the group of the suffix h bytes further. */
    private static function split(array &$I, array &$V, int $start, int $size, int $h): void
    {
        while ($size >= 16) {
            // Three-way partition around the middle key.
            $x = $V[$I[$start + intdiv($size, 2)] + $h];
            $jj = $kk = 0;
            for ($i = $start; $i < $start + $size; $i++) {
                $v = $V[$I[$i] + $h];
                if ($v < $x) {
                    $jj++;
                } elseif ($v === $x) {
                    $kk++;
                }
            }
            $jj += $start;
            $kk += $jj;
            $i = $start;
            $j = $k = 0;
            while ($i < $jj) {
                $v = $V[$I[$i] + $h];
                if ($v < $x) {
                    $i++;
                } elseif ($v === $x) {
                    [$I[$i], $I[$jj + $j]] = [$I[$jj + $j], $I[$i]];
                    $j++;
                } else {
                    [$I[$i], $I[$kk + $k]] = [$I[$kk + $k], $I[$i]];
                    $k++;
                }
            }
            while ($jj + $j < $kk) {
                if ($V[$I[$jj + $j] + $h] === $x) {
                    $j++;
                } else {
                    [$I[$jj + $j], $I[$kk + $k]] = [$I[$kk + $k], $I[$jj + $j]];
                    $k++;
                }
            }
            if ($jj > $start) {
                self::split($I, $V, $start, $jj - $start, $h);
            }
            for ($i = 0; $i < $kk - $jj; $i++) {
                $V[$I[$jj + $i]] = $kk - 1;
            }
            if ($jj === $kk - 1) {
                $I[$jj] = -1;
            }
            // The greater keys, iteratively.
            $size = $start + $size - $kk;
            $start = $kk;
        }
        if ($size <= 0) {
            return;
        }
        // Selection sort for small groups.
        for ($k = $start, $j = 0; $k < $start + $size; $k += $j) {
            $j = 1;
            $x = $V[$I[$k] + $h];
            for ($i = 1; $k + $i < $start + $size; $i++) {
                $v = $V[$I[$k + $i] + $h];
                if ($v < $x) {
                    $x = $v;
                    $j = 0;
                }
                if ($v === $x) {
                    [$I[$k + $j], $I[$k + $i]] = [$I[$k + $i], $I[$k + $j]];
                    $j++;
                }
            }
            for ($i = 0; $i < $j; $i++) {
                $V[$I[$k + $i]] = $k + $j - 1;
            }
            if ($j === 1) {
                $I[$k] = -1;
            }
        }
    }
}
