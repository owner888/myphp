<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\UI\DisplayList;

/**
 * What changed from one frame of a window to the next, for Metal to draw
 * only there (mygo's scene.Differ and gpu.Ages, gpu.Part): the frames'
 * quads compared from both ends, as bytes, the boxes of those that differ
 * in between (clipped as they draw, where they drew and where they draw
 * now) in device pixels, in rectangles apart from each other, at most
 * eight; and, for each drawable of the window's swap chain, what changed
 * since it was drawn (its buffer age), which a frame drawn into it redraws.
 * A frame whose quads are the last one's draws nothing.
 * @internal Metal
 */
final class FrameDamage
{
    /** How many rectangles a part of a frame has at most (mygo's maxRects); how many drawables are tracked (a CAMetalLayer's three, and one more). */
    public const MAX_RECTS = 8, MAX_AGES = 4;
    /** Past this many quads changed in the middle, their boxes are not worked out: the frame is drawn whole. */
    private const MAX_CHANGED = 768;
    private const QUAD = DisplayList::FLOATS_PER_RECT * 4;

    /** The last frame: its quads and transforms as bytes, and what else it was drawn with (size, scale, colors…). */
    private ?string $quads = null, $xforms = null;
    private mixed $with = null;
    /**
     * For each drawable, by its identity: what changed since it was drawn (rectangles, or null for everything), and
     * the frame it was last drawn in.
     * @var array<int, array{0: ?list<array{0: int, 1: int, 2: int, 3: int}>, 1: int}>
     */
    private array $ages = [];
    private int $frame = 0;

    /**
     * Compares the frame of $quads and $xforms (bytes), drawn with $with at $scale pixels a point into $w × $h pixels,
     * with the last, which it then is: the rectangles where they differ (x0, y0, x1, y1 in pixels), none when they are
     * the same, or null when everything may have (none was kept, another size or scale, other transforms, too many
     * changes).
     * @return ?list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public function diff(string $quads, string $xforms, mixed $with, float $scale, int $w, int $h): ?array
    {
        [$a, $ax, $was] = [$this->quads, $this->xforms, $this->with];
        [$this->quads, $this->xforms, $this->with] = [$quads, $xforms, [$with, $scale, $w, $h]];
        if ($a === null || $was !== $this->with || $ax !== $xforms) {
            return null;
        }
        $n = intdiv(strlen($a), self::QUAD); $m = intdiv(strlen($quads), self::QUAD);
        $pre = self::common($a, $quads, min($n, $m), false);
        $suf = self::common($a, $quads, min($n, $m) - $pre, true);
        $damage = [];
        if ($n === $m) {
            if ($n - $suf - $pre > self::MAX_CHANGED) { return null; }
            for ($k = $pre; $k < $n - $suf; $k++) {
                if (substr($a, $k * self::QUAD, self::QUAD) === substr($quads, $k * self::QUAD, self::QUAD)) { continue; }
                foreach ([$a, $quads] as $s) {
                    $r = self::box($s, $k, $scale);
                    if ($r === false) { return null; }
                    if ($r !== null) { $damage = self::add($damage, $r); }
                }
            }
        } else {
            if ($n - $suf - $pre + $m - $suf - $pre > self::MAX_CHANGED) { return null; }
            foreach ([[$a, $n], [$quads, $m]] as [$s, $count]) {
                for ($k = $pre; $k < $count - $suf; $k++) {
                    $r = self::box($s, $k, $scale);
                    if ($r === false) { return null; }
                    if ($r !== null) { $damage = self::add($damage, $r); }
                }
            }
        }
        return array_values(array_filter(array_map(fn(array $r): array => [max(0, $r[0]), max(0, $r[1]), min($w, $r[2]), min($h, $r[3])], $damage),
            fn(array $r): bool => $r[2] > $r[0] && $r[3] > $r[1]));
    }

    /** Forgets the last frame: the next is drawn whole, into every drawable (a frame not drawn, the window's layer changed). */
    public function forget(): void
    {
        $this->quads = $this->xforms = $this->with = null;
        $this->ages = [];
    }

    /**
     * Notes a frame drawn into drawable $id, which changed $changed of the last (null: everything): what to draw into it,
     * the rectangles changed since it was drawn, or null to draw it whole (it is new to the window, or everything may
     * have changed). $forget is told of each drawable no longer tracked.
     * @param ?list<array{0: int, 1: int, 2: int, 3: int}> $changed
     * @param \Closure(int): void $forget
     * @return ?list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public function take(int $id, ?array $changed, \Closure $forget): ?array
    {
        $this->frame++;
        foreach ($this->ages as $k => [$since]) { // what changed since each was drawn, this frame's too
            if ($since === null || $changed === null) { $this->ages[$k][0] = null; continue; }
            foreach ($changed as $r) { $since = self::add($since, $r); }
            $this->ages[$k][0] = $since;
        }
        $since = isset($this->ages[$id]) ? $this->ages[$id][0] : null;
        if (!isset($this->ages[$id]) && count($this->ages) === self::MAX_AGES) {
            $oldest = array_key_first($this->ages);
            foreach ($this->ages as $k => [, $used]) { if ($used < $this->ages[$oldest][1]) { $oldest = $k; } }
            unset($this->ages[$oldest]);
            $forget($oldest);
        }
        $known = isset($this->ages[$id]);
        $this->ages[$id] = [[], $this->frame];
        return $known ? $since : null;
    }

    /** Whether drawable $id is tracked: the window holds it, so that no other takes its identity. */
    public function has(int $id): bool
    {
        return isset($this->ages[$id]);
    }

    /** The identities of the drawables tracked. @return list<int> */
    public function tracked(): array
    {
        return array_keys($this->ages);
    }

    /** Whether rectangles $rects are worth drawing apart from the rest of a $w × $h frame: together under half of it, as mygo's Part. */
    public static function worth(array $rects, int $w, int $h): bool
    {
        $n = 0;
        foreach ($rects as $r) { $n += ($r[2] - $r[0]) * ($r[3] - $r[1]); }
        return 2 * $n < $w * $h;
    }

    /** How many quads $a and $b share at their starts (or their ends, $fromEnd), of at most $most: by halves, compared as bytes. */
    private static function common(string $a, string $b, int $most, bool $fromEnd): int
    {
        $la = strlen($a); $lb = strlen($b);
        $same = function (int $k) use ($a, $b, $la, $lb, $fromEnd): bool { // whether the first (last) $k quads match
            if ($k === 0) { return true; }
            $len = $k * self::QUAD;
            return $fromEnd ? substr_compare($a, substr($b, $lb - $len), $la - $len) === 0 : strncmp($a, $b, $len) === 0;
        };
        if ($same($most)) { return $most; }
        $lo = 0; $hi = $most; // same($lo), not same($hi)
        while ($hi - $lo > 1) {
            $mid = intdiv($lo + $hi, 2);
            if ($same($mid)) { $lo = $mid; } else { $hi = $mid; }
        }
        return $lo;
    }

    /**
     * The box quad $k of $s draws in, within its clip, in pixels grown by two (its edge's smoothing); null for none;
     * false when it is not known here (drawn through a transform or a mask, whose entry the quad names). A box's
     * second slot stands for its box.
     * @return array{0: int, 1: int, 2: int, 3: int}|null|false
     */
    private static function box(string $s, int $k, float $scale): array|null|false
    {
        $q = unpack('g16', $s, $k * self::QUAD);
        if ($q[10] < 0) {
            return $k > 0 ? self::box($s, $k - 1, $scale) : null;
        }
        if ($q[10] >= DisplayList::KIND_STRIDE) { return false; }
        $x0 = max($q[1], $q[13]); $y0 = max($q[2], $q[14]);
        $x1 = min($q[1] + $q[3], $q[13] + $q[15]); $y1 = min($q[2] + $q[4], $q[14] + $q[16]);
        if ($x1 <= $x0 || $y1 <= $y0) { return null; }
        return [(int)floor($x0 * $scale) - 2, (int)floor($y0 * $scale) - 2, (int)ceil($x1 * $scale) + 2, (int)ceil($y1 * $scale) + 2];
    }

    /**
     * $r added to rectangles apart from each other (mygo's addRect): it takes in those it overlaps or nearly meets
     * (eight pixels), and past MAX_RECTS the two whose union grows least become one.
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $rects
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function add(array $rects, array $r): array
    {
        for ($i = 0; $i < count($rects);) {
            $o = $rects[$i];
            if ($o[0] - 8 < $r[2] && $r[0] < $o[2] + 8 && $o[1] - 8 < $r[3] && $r[1] < $o[3] + 8) {
                $r = [min($r[0], $o[0]), min($r[1], $o[1]), max($r[2], $o[2]), max($r[3], $o[3])];
                array_splice($rects, $i, 1);
                $i = 0;
                continue;
            }
            $i++;
        }
        $rects[] = $r;
        if (count($rects) <= self::MAX_RECTS) { return $rects; }
        $best = -1; $bi = 0; $bj = 1;
        $area = fn(array $a): int => ($a[2] - $a[0]) * ($a[3] - $a[1]);
        foreach ($rects as $i => $a) {
            for ($j = $i + 1; $j < count($rects); $j++) {
                $b = $rects[$j];
                $grows = $area([min($a[0], $b[0]), min($a[1], $b[1]), max($a[2], $b[2]), max($a[3], $b[3])]) - $area($a) - $area($b);
                if ($best < 0 || $grows < $best) { [$bi, $bj, $best] = [$i, $j, $grows]; }
            }
        }
        [$a, $b] = [$rects[$bi], $rects[$bj]];
        array_splice($rects, $bj, 1); array_splice($rects, $bi, 1);
        return self::add($rects, [min($a[0], $b[0]), min($a[1], $b[1]), max($a[2], $b[2]), max($a[3], $b[3])]);
    }
}
