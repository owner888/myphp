<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Compares sequences, as mygo's internal/diff: the lines of two texts,
 * or the words of two lines. It finds the changes with the histogram
 * algorithm of git (xhistogram.c), which anchors on lines that occur
 * rarely and so keeps the matches people expect in source code, falling
 * back to Myers's O(ND) algorithm, with xdiff's cost limit, where every
 * line repeats too often. Groups of changed lines are then slid to start
 * and end where blank lines and indentation make them read as whole
 * blocks, with git's indent heuristic (DiffCompact).
 */
final class Diff
{
    /** How often an element may occur in a region of a for the histogram algorithm to anchor on it, as git's. */
    private const MAX_CHAIN = 64;
    /** Bounds the recursion of histogram, past which a region is compared with Myers's algorithm. */
    private const MAX_DEPTH = 256;
    private const MAX_COST_MIN = 256; // XDL_MAX_COST_MIN
    private const LINE_MAX = PHP_INT_MAX >> 2;

    /** @var list<int> */
    private array $a, $b;
    /** @var ?list<bool> changed elements */
    private ?array $da = null, $db = null;
    /** @var list<int> histogram scratch, by position in a */
    private array $next = [], $cnt = [];
    /** @var array<int, array{0: int, 1: int}> by element: first position, count */
    private array $recs = [];
    private int $recsCap = 0;
    /** @var list<int> */
    private array $myersV = [];
    private int $myersExtra = 0;

    private function __construct(array $a, array $b) { $this->a = $a; $this->b = $b; }

    /** Compares two sequences of interned elements, equal elements having equal values, and returns the hunks turning $a into $b, in order. @return list<Hunk> */
    public static function ints(array $a, array $b): array
    {
        $d = new self($a, $b);
        $d->histogram(0, count($a), 0, count($b), 0);
        return $d->hunks();
    }

    /** Compares the lines of two texts and returns the hunks of lines turning $a into $b, slid to read as blocks of code. @param list<string> $a @param list<string> $b @return list<Hunk> */
    public static function lines(array $a, array $b): array
    {
        [$ia, $ib] = self::intern($a, $b);
        $d = new self($ia, $ib);
        $d->histogram(0, count($ia), 0, count($ib), 0);
        if ($d->da === null) { return []; }
        DiffCompact::compact($d->da, $d->db, $ia, $a);
        DiffCompact::compact($d->db, $d->da, $ib, $b);
        return $d->hunks();
    }

    /** Numbers the distinct strings of $a and $b, equal strings sharing a number. @return array{0: list<int>, 1: list<int>} */
    public static function intern(array $a, array $b): array
    {
        $ids = [];
        $conv = function (array $s) use (&$ids): array {
            $out = [];
            foreach ($s as $v) {
                if (!isset($ids[$v])) { $ids[$v] = count($ids); }
                $out[] = $ids[$v];
            }
            return $out;
        };
        return [$conv($a), $conv($b)];
    }

    private function mark(int $a0, int $a1, int $b0, int $b1): void
    {
        if ($a0 === $a1 && $b0 === $b1) { return; }
        if ($this->da === null) {
            $this->da = array_fill(0, count($this->a), false);
            $this->db = array_fill(0, count($this->b), false);
        }
        for ($i = $a0; $i < $a1; $i++) { $this->da[$i] = true; }
        for ($i = $b0; $i < $b1; $i++) { $this->db[$i] = true; }
    }

    /** Turns the marks into hunks. @return list<Hunk> */
    private function hunks(): array
    {
        if ($this->da === null) { return []; }
        $out = [];
        $na = count($this->a); $nb = count($this->b);
        $i = 0; $j = 0;
        while ($i < $na || $j < $nb) {
            if ($i < $na && $j < $nb && !$this->da[$i] && !$this->db[$j]) { $i++; $j++; continue; }
            $h = new Hunk($i, $i, $j, $j);
            while ($i < $na && $this->da[$i]) { $i++; }
            while ($j < $nb && $this->db[$j]) { $j++; }
            $h->a1 = $i; $h->b1 = $j;
            if ($h->a0 === $h->a1 && $h->b0 === $h->b1) {
                // Unpaired unchanged elements cannot happen; never loop.
                if ($i < $na) { $i++; }
                if ($j < $nb) { $j++; }
                continue;
            }
            $out[] = $h;
        }
        return $out;
    }

    /** Compares a[$a0:$a1] with b[$b0:$b1]: finds the longest common run of elements that occur least often in a, and compares what is before and after it the same way. */
    private function histogram(int $a0, int $a1, int $b0, int $b1, int $depth): void
    {
        while (true) {
            // Common prefix and suffix.
            while ($a0 < $a1 && $b0 < $b1 && $this->a[$a0] === $this->b[$b0]) { $a0++; $b0++; }
            while ($a0 < $a1 && $b0 < $b1 && $this->a[$a1 - 1] === $this->b[$b1 - 1]) { $a1--; $b1--; }
            if ($a0 === $a1 || $b0 === $b1) { $this->mark($a0, $a1, $b0, $b1); return; }
            if ($depth > self::MAX_DEPTH) { $this->myers($a0, $a1, $b0, $b1); return; }
            $l = $this->lcs($a0, $a1, $b0, $b1);
            if ($l === null) { $this->myers($a0, $a1, $b0, $b1); return; }
            [$la0, $la1, $lb0, $lb1] = $l;
            if ($la0 === $la1) { $this->mark($a0, $a1, $b0, $b1); return; } // nothing in common
            $depth++;
            // Recurse into the smaller side, loop on the other.
            if ($la0 - $a0 + $lb0 - $b0 < $a1 - $la1 + $b1 - $lb1) {
                $this->histogram($a0, $la0, $b0, $lb0, $depth);
                $a0 = $la1; $b0 = $lb1;
            } else {
                $this->histogram($la1, $a1, $lb1, $b1, $depth);
                $a1 = $la0; $b1 = $lb0;
            }
        }
    }

    /** The longest run common to a[$a0:$a1] and b[$b0:$b1] whose rarest element occurs least often in a; null when every element both have occurs too often in a, for Myers's algorithm. @return ?array{0: int, 1: int, 2: int, 3: int} */
    private function lcs(int $a0, int $a1, int $b0, int $b1): ?array
    {
        $n = $a1 - $a0;
        $next = array_fill(0, max($n, 1), -1); $cnt = array_fill(0, max($n, 1), 0);
        // Clearing costs the map's capacity: a small region takes a new one.
        if ($this->recsCap > 8 * $n + 64) { $this->recs = []; $this->recsCap = $n; } else { $this->recs = []; }
        // From the end, so each record's chain goes forward from its first.
        for ($i = $a1 - 1; $i >= $a0; $i--) {
            $e = $this->a[$i];
            if (isset($this->recs[$e])) {
                $next[$i - $a0] = $this->recs[$e][0];
                $this->recs[$e][1]++;
            } else {
                $next[$i - $a0] = -1;
                $this->recs[$e] = [0, 1];
            }
            $this->recs[$e][0] = $i;
        }
        // How rare each line is: its record's count in the region, as git's CNT, not the count from it to the region's end, which makes the last of many blank lines look unique.
        for ($i = $a0; $i < $a1; $i++) { $cnt[$i - $a0] = $this->recs[$this->a[$i]][1]; }
        $best = self::MAX_CHAIN + 1;
        $common = false;
        $la0 = $a0; $la1 = $a0; $lb0 = $b0; $lb1 = $b0;
        for ($bi = $b0; $bi < $b1;) {
            $bnext = $bi + 1;
            $r = $this->recs[$this->b[$bi]] ?? null;
            if ($r === null) { $bi = $bnext; continue; }
            $common = true;
            if ($r[1] > $best) { $bi = $bnext; continue; }
            for ($as = $r[0]; $as >= 0;) {
                $s = $as; $bs = $bi; $e = $as + 1; $be = $bi + 1;
                $rc = $cnt[$as - $a0];
                while ($s > $a0 && $bs > $b0 && $this->a[$s - 1] === $this->b[$bs - 1]) {
                    $s--; $bs--;
                    if ($rc > 1) { $rc = min($rc, $cnt[$s - $a0]); }
                }
                while ($e < $a1 && $be < $b1 && $this->a[$e] === $this->b[$be]) {
                    if ($rc > 1) { $rc = min($rc, $cnt[$e - $a0]); }
                    $e++; $be++;
                }
                if ($bnext < $be) { $bnext = $be; }
                if ($la1 - $la0 < $e - $s || $rc < $best) {
                    $la0 = $s; $la1 = $e; $lb0 = $bs; $lb1 = $be; $best = $rc;
                }
                // The next occurrence past this run.
                $np = $next[$as - $a0];
                while ($np >= 0 && $np < $e) { $np = $next[$np - $a0]; }
                $as = $np;
            }
            $bi = $bnext;
        }
        if ($common && $best > self::MAX_CHAIN) { return null; }
        return [$la0, $la1, $lb0, $lb1];
    }

    /**
     * Myers's algorithm in linear space, as git's xdiff runs it (xdiffi.c):
     * the middle snake of each region splits it in two, until the cost of a
     * region passes a limit, where the furthest diagonals reached split it,
     * which bounds the time on large unrelated texts at the price of a
     * minimal diff.
     */
    private function myers(int $a0, int $a1, int $b0, int $b1): void
    {
        $n = count($this->a) + count($this->b) + 3;
        if (count($this->myersV) < 2 * $n) { $this->myersV = array_fill(0, 2 * $n, 0); }
        $cost = 1;
        while ($cost * $cost < $n) { $cost <<= 1; }
        $this->myersExtra = max($cost, self::MAX_COST_MIN);
        $this->recsCmp($a0, $a1, $b0, $b1, false);
    }

    private function recsCmp(int $off1, int $lim1, int $off2, int $lim2, bool $needMin): void
    {
        while ($off1 < $lim1 && $off2 < $lim2 && $this->a[$off1] === $this->b[$off2]) { $off1++; $off2++; }
        while ($off1 < $lim1 && $off2 < $lim2 && $this->a[$lim1 - 1] === $this->b[$lim2 - 1]) { $lim1--; $lim2--; }
        if ($off1 === $lim1 || $off2 === $lim2) { $this->mark($off1, $lim1, $off2, $lim2); return; }
        [$i1, $i2, $minLo, $minHi] = $this->split($off1, $lim1, $off2, $lim2, $needMin);
        $this->recsCmp($off1, $i1, $off2, $i2, $minLo);
        $this->recsCmp($i1, $lim1, $i2, $lim2, $minHi);
    }

    /** Where to split a[$off1:$lim1] and b[$off2:$lim2]: the middle snake, or past the cost limit the best diagonal reached. @return array{0: int, 1: int, 2: bool, 3: bool} */
    private function split(int $off1, int $lim1, int $off2, int $lim2, bool $needMin): array
    {
        $ha1 = $this->a; $ha2 = $this->b;
        $nd = count($this->a) + count($this->b) + 3;
        $base = count($this->b) + 1;
        $fb = 0; $bb = $nd; // the forward and backward vectors start at these of myersV; diagonal k is at base+k of each
        $v = &$this->myersV;
        $dmin = $off1 - $lim2; $dmax = $lim1 - $off2;
        $fmid = $off1 - $off2; $bmid = $lim1 - $lim2;
        $odd = (($fmid - $bmid) & 1) !== 0;
        $fmin = $fmid; $fmax = $fmid; $bmin = $bmid; $bmax = $bmid;
        $v[$fb + $base + $fmid] = $off1;
        $v[$bb + $base + $bmid] = $lim1;
        for ($ec = 1;; $ec++) {
            // Forward.
            if ($fmin > $dmin) { $fmin--; $v[$fb + $base + $fmin - 1] = -1; } else { $fmin++; }
            if ($fmax < $dmax) { $fmax++; $v[$fb + $base + $fmax + 1] = -1; } else { $fmax--; }
            for ($k = $fmax; $k >= $fmin; $k -= 2) {
                $i1 = $v[$fb + $base + $k - 1] >= $v[$fb + $base + $k + 1] ? $v[$fb + $base + $k - 1] + 1 : $v[$fb + $base + $k + 1];
                $i2 = $i1 - $k;
                while ($i1 < $lim1 && $i2 < $lim2 && $ha1[$i1] === $ha2[$i2]) { $i1++; $i2++; }
                $v[$fb + $base + $k] = $i1;
                if ($odd && $bmin <= $k && $k <= $bmax && $v[$bb + $base + $k] <= $i1) { return [$i1, $i2, true, true]; }
            }
            // Backward.
            if ($bmin > $dmin) { $bmin--; $v[$bb + $base + $bmin - 1] = self::LINE_MAX; } else { $bmin++; }
            if ($bmax < $dmax) { $bmax++; $v[$bb + $base + $bmax + 1] = self::LINE_MAX; } else { $bmax--; }
            for ($k = $bmax; $k >= $bmin; $k -= 2) {
                $i1 = $v[$bb + $base + $k - 1] < $v[$bb + $base + $k + 1] ? $v[$bb + $base + $k - 1] : $v[$bb + $base + $k + 1] - 1;
                $i2 = $i1 - $k;
                while ($i1 > $off1 && $i2 > $off2 && $ha1[$i1 - 1] === $ha2[$i2 - 1]) { $i1--; $i2--; }
                $v[$bb + $base + $k] = $i1;
                if (!$odd && $fmin <= $k && $k <= $fmax && $i1 <= $v[$fb + $base + $k]) { return [$i1, $i2, true, true]; }
            }
            if ($needMin || $ec < $this->myersExtra) { continue; }
            // Too costly: split where the furthest diagonals got.
            $fbest = -1; $fbest1 = -1;
            for ($k = $fmax; $k >= $fmin; $k -= 2) {
                $i1 = min($v[$fb + $base + $k], $lim1);
                $i2 = $i1 - $k;
                if ($lim2 < $i2) { $i1 = $lim2 + $k; $i2 = $lim2; }
                if ($fbest < $i1 + $i2) { $fbest = $i1 + $i2; $fbest1 = $i1; }
            }
            $bbest = self::LINE_MAX; $bbest1 = self::LINE_MAX;
            for ($k = $bmax; $k >= $bmin; $k -= 2) {
                $i1 = max($off1, $v[$bb + $base + $k]);
                $i2 = $i1 - $k;
                if ($i2 < $off2) { $i1 = $off2 + $k; $i2 = $off2; }
                if ($i1 + $i2 < $bbest) { $bbest = $i1 + $i2; $bbest1 = $i1; }
            }
            if (($lim1 + $lim2) - $bbest < $fbest - ($off1 + $off2)) { return [$fbest1, $fbest - $fbest1, true, false]; }
            return [$bbest1, $bbest - $bbest1, false, true];
        }
    }
}
