<?php
declare(strict_types=1);

namespace MyPHP\Text;

/**
 * Shaped and wrapped text (mygo's text.Layout): positions in points from
 * the text's top-left corner, offsets in runes.
 */
final class Layout
{
    // The edge a caret takes at a boundary: of the logical text before it (upstream) or after it (mygo's Affinity).
    public const DOWNSTREAM = 0, UPSTREAM = 1;

    /** @var list<Line> */
    public array $lines = [];
    /** The width of the longest line, and the sum of the line heights. */
    public float $width = 0, $height = 0;
    /** That maxLines cut the text. */
    public bool $truncated = false;
    /** @var ?list<int> whole-grapheme offsets, for visual navigation */
    private ?array $boundaries = null;

    /** @param list<int> $runes */
    public function __construct(public readonly Params $params, public readonly array $runes) {}

    /** The index of the line holding rune $index, the start of the next line at a soft line break. */
    public function lineAt(int $index): int
    {
        $n = count($this->lines);
        foreach ($this->lines as $i => $line) {
            if ($index < $line->end || $index === $line->end && ($i === $n - 1 || $this->lines[$i + 1]->start > $index)) {
                return $i;
            }
        }
        return $n - 1;
    }

    /** The caret before rune $index: its x, the top of its line and the line's height. @return array{0: float, 1: float, 2: float} */
    public function caret(int $index): array
    {
        if ($this->lines === []) {
            return [0.0, 0.0, 0.0];
        }
        $index = max(0, min($index, count($this->runes)));
        $line = $this->lines[$this->lineAt($index)];
        $carets = $line->carets();
        $i = max(0, min($index - $line->start, count($carets) - 1));
        return [$carets[$i], $line->y, $line->height];
    }

    /** The rune index of the caret position closest to ($x, $y). */
    public function indexAt(float $x, float $y): int
    {
        if ($this->lines === []) {
            return 0;
        }
        $line = $this->lines[$this->lineAtY($y)];
        $best = 0; $bestDist = -1.0;
        foreach ($line->carets() as $i => $cx) {
            $d = abs($cx - $x);
            if ($bestDist < 0 || $d < $bestDist) {
                $best = $i; $bestDist = $d;
            }
        }
        return $line->start + $best;
    }

    private function lineAtY(float $y): int
    {
        foreach ($this->lines as $i => $l) {
            if ($y < $l->y + $l->height) {
                return $i;
            }
        }
        return count($this->lines) - 1;
    }

    /** The rectangles covering the runes from $start to $end. @return list<array{0: float, 1: float, 2: float, 3: float}> */
    public function selection(int $start, int $end): array
    {
        return $this->appendSelection([], $start, $end, false);
    }

    /**
     * The rectangles, [x, y, w, h], of the runes from $start to $end, after
     * those of $dst, apart where visual runs are apart; $on, for the
     * layout of a paragraph of a longer text whose selection goes on past
     * its newline, shows that newline, as between the lines of a layout
     * (mygo's AppendSelection).
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $dst
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function appendSelection(array $dst, int $start, int $end, bool $on): array
    {
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        if ($on) {
            $end = count($this->runes) + 1;
        }
        $out = $dst; $base = count($dst);
        $nl = count($this->lines);
        foreach ($this->lines as $li => $line) {
            // A range can include the newline at the end, so keep that boundary's line even when none of its glyphs are selected.
            if ($line->end < $start || $line->start >= $end) {
                continue;
            }
            $pieces = [];
            $gs = $line->glyphs; $ng = count($gs);
            for ($i = 0; $i < $ng;) {
                $first = $gs[$i];
                $x0 = $first->x; $x1 = $first->x + $first->advance;
                $i++;
                while ($i < $ng && $gs[$i]->cluster === $first->cluster && $gs[$i]->runes === $first->runes) {
                    $x0 = min($x0, $gs[$i]->x); $x1 = max($x1, $gs[$i]->x + $gs[$i]->advance);
                    $i++;
                }
                $a = max($start, $first->cluster); $z = min($end, $first->cluster + $first->runes);
                if ($a >= $z || $first->runes <= 0) {
                    continue;
                }
                $width = $x1 - $x0;
                $left = ($a - $first->cluster) / $first->runes; $right = ($z - $first->cluster) / $first->runes;
                if ($first->rtl) {
                    [$left, $right] = [1 - $right, 1 - $left];
                }
                $r = [$x0 + $left * $width, $line->y, ($right - $left) * $width, $line->height];
                // Clusters following each other join as they come, as most do.
                $k = count($pieces) - 1;
                if ($k >= 0 && $pieces[$k][0] + $pieces[$k][2] >= $r[0] - 0.01 && $pieces[$k][0] <= $r[0] + 0.01) {
                    $pieces[$k][2] = max($pieces[$k][0] + $pieces[$k][2], $r[0] + $r[2]) - $pieces[$k][0];
                    continue;
                }
                $pieces[] = $r;
            }
            if ($start <= $line->end && $end > $line->end && ($li < $nl - 1 && $this->lines[$li + 1]->start > $line->end || $li === $nl - 1 && $on)) {
                $w = $this->params->style->fontSize() / 3;
                $pieces[] = [$line->rtl ? $line->x - $w : $line->x + $line->width, $line->y, $w, $line->height];
            }
            usort($pieces, fn($p, $q) => $p[0] <=> $q[0]);
            foreach ($pieces as $r) {
                if ($r[2] <= 0) {
                    continue;
                }
                $k = count($out) - 1;
                if ($k >= $base && $out[$k][1] === $r[1] && $out[$k][0] + $out[$k][2] >= $r[0] - 0.01) {
                    $out[$k][2] = max($out[$k][0] + $out[$k][2], $r[0] + $r[2]) - $out[$k][0];
                } else {
                    $out[] = $r;
                }
            }
        }
        return $out;
    }

    // Visual carets (mygo's caret.go): affinity at bidi boundaries and soft line breaks.

    /** @return list<int> */
    private function boundaries(): array
    {
        if ($this->boundaries === null) {
            $b = new Boundaries();
            $b->reset($this->runes, $this->params->text);
            $this->boundaries = $b->graphemeOffsets();
        }
        return $this->boundaries;
    }

    /** @return list<array{0: int, 1: int, 2: float, 3: int}> index, affinity, x, arrival (the visual direction this edge is reached from) */
    private function visualStops(int $li): array
    {
        $line = $this->lines[$li];
        if ($line->stops !== null) {
            return $line->stops;
        }
        $bounds = array_flip($this->boundaries());
        $stops = [];
        $line->upstream = $line->carets();
        $gs = $line->glyphs; $ng = count($gs);
        for ($i = 0; $i < $ng;) {
            $g = $gs[$i];
            $x0 = $g->x; $x1 = $g->x + $g->advance;
            $i++;
            while ($i < $ng && $gs[$i]->cluster === $g->cluster && $gs[$i]->runes === $g->runes) {
                $x0 = min($x0, $gs[$i]->x); $x1 = max($x1, $gs[$i]->x + $gs[$i]->advance);
                $i++;
            }
            if ($g->runes <= 0) {
                continue;
            }
            for ($k = 0; $k <= $g->runes; $k++) {
                $at = $g->cluster + $k;
                if (!isset($bounds[$at])) {
                    continue;
                }
                $x = $g->rtl ? $x1 - ($x1 - $x0) * $k / $g->runes : $x0 + ($x1 - $x0) * $k / $g->runes;
                $affinity = self::DOWNSTREAM; $arrival = $k === 0 ? -1 : 0;
                if ($k === $g->runes) {
                    $affinity = self::UPSTREAM; $arrival = 1;
                }
                if ($g->rtl) {
                    $arrival = -$arrival;
                }
                if ($affinity === self::UPSTREAM && $at >= $line->start && $at <= $line->end) {
                    $line->upstream[$at - $line->start] = $x;
                }
                $stops[] = [$at, $affinity, $x, $arrival];
            }
        }
        // Keep trimmed spaces and empty lines navigable too.
        $carets = $line->carets();
        $have = [];
        foreach ($stops as $s) { $have[$s[0]] = true; }
        foreach ($this->boundaries() as $at) {
            if ($at >= $line->start && $at <= $line->end && !isset($have[$at])) {
                $stops[] = [$at, self::DOWNSTREAM, $carets[$at - $line->start], 0];
            }
        }
        if ($stops === []) {
            $stops[] = [$line->start, self::DOWNSTREAM, $line->x, 0];
        }
        usort($stops, fn($a, $b) => $a[2] <=> $b[2]); // stable
        return $line->stops = $stops;
    }

    private function caretLine(int $index, int $affinity): int
    {
        $li = $this->lineAt($index);
        if ($affinity === self::UPSTREAM && $li > 0 && $this->lines[$li]->start === $index && $this->lines[$li - 1]->end === $index) {
            $li--;
        }
        return $li;
    }

    /** The caret at rune $index on the edge $affinity takes: its x, the top of its line and the line's height. @return array{0: float, 1: float, 2: float} */
    public function caretAt(int $index, int $affinity = self::DOWNSTREAM): array
    {
        if ($this->lines === []) {
            return [0.0, 0.0, 0.0];
        }
        $index = max(0, min($index, count($this->runes)));
        $li = $this->caretLine($index, $affinity);
        $this->visualStops($li);
        $line = $this->lines[$li];
        $carets = $line->carets();
        $i = max(0, min($index - $line->start, count($carets) - 1));
        return [$affinity === self::UPSTREAM ? $line->upstream[$i] : $carets[$i], $line->y, $line->height];
    }

    /** The caret position at ($x, $y), hit-testing whole graphemes, with the visual edge chosen. @return array{0: int, 1: int} index and affinity */
    public function positionAt(float $x, float $y): array
    {
        if ($this->lines === []) {
            return [0, self::DOWNSTREAM];
        }
        $stops = $this->visualStops($this->lineAtY($y));
        $best = $stops[0]; $distance = -1.0;
        foreach ($stops as $s) {
            $d = abs($s[2] - $x);
            // Affinity breaks ties at one offset, not across offsets sharing an edge, as before and after a trimmed space.
            if ($distance < 0 || $d < $distance || $d == $distance && $s[0] === $best[0] && $s[1] === self::DOWNSTREAM) {
                $best = $s; $distance = $d;
            }
        }
        return [$best[0], $best[1]];
    }

    /** @param list<array{0: int, 1: int, 2: float, 3: int}> $stops @return array{0: int, 1: int} */
    private static function arriving(array $stops, float $x, int $direction): array
    {
        $p = [0, self::DOWNSTREAM];
        foreach ($stops as $s) {
            // Native runs can disagree by a few rounding bits at their shared edge.
            if (abs($s[2] - $x) > 0.01) {
                continue;
            }
            $p = [$s[0], $s[1]];
            if ($s[3] === $direction) {
                return $p;
            }
        }
        return $p;
    }

    /** The caret one visual grapheme from [$index, $affinity] to the right ($direction > 0) or left, keeping the edge arrived at. @return array{0: int, 1: int} */
    public function moveCaret(int $index, int $affinity, int $direction): array
    {
        if ($this->lines === [] || $direction === 0) {
            return [$index, $affinity];
        }
        $direction = $direction < 0 ? -1 : 1;
        $li = $this->caretLine($index, $affinity);
        [$x] = $this->caretAt($index, $affinity);
        $stops = $this->visualStops($li);
        if ($direction > 0) {
            foreach ($stops as $s) {
                if ($s[2] > $x + 0.001) {
                    return self::arriving($stops, $s[2], $direction);
                }
            }
        } else {
            for ($i = count($stops) - 1; $i >= 0; $i--) {
                if ($stops[$i][2] < $x - 0.001) {
                    return self::arriving($stops, $stops[$i][2], $direction);
                }
            }
        }
        $next = $this->lines[$li]->rtl ? $li - $direction : $li + $direction;
        if ($next < 0 || $next >= count($this->lines)) {
            return [$index, $affinity];
        }
        $stops = $this->visualStops($next);
        $x = $direction < 0 ? $stops[count($stops) - 1][2] : $stops[0][2];
        return self::arriving($stops, $x, $direction);
    }

    /**
     * The logical ranges a visual gesture from caret $a to $b covers,
     * [start, end] each: one through mixed-direction text can cover
     * several (mygo's SelectionRanges).
     *
     * @param array{0: int, 1: int} $a
     * @param array{0: int, 1: int} $b
     * @return list<array{0: int, 1: int}>
     */
    public function selectionRanges(array $a, array $b): array
    {
        if ($this->lines === []) {
            return [];
        }
        $ai = $this->caretLine($a[0], $a[1]); $bi = $this->caretLine($b[0], $b[1]);
        [$ax] = $this->caretAt($a[0], $a[1]);
        [$bx] = $this->caretAt($b[0], $b[1]);
        if ($ai > $bi) {
            [$ai, $bi, $ax, $bx] = [$bi, $ai, $bx, $ax];
        }
        $ranges = [];
        $bounds = $this->boundaries(); $nb = count($bounds);
        for ($li = $ai; $li <= $bi; $li++) {
            $line = $this->lines[$li];
            $lo = $line->x; $hi = $line->x + $line->width;
            if ($ai === $bi) {
                $lo = min($ax, $bx); $hi = max($ax, $bx);
            } else {
                if ($li === $ai) {
                    if ($line->rtl) { $hi = $ax; } else { $lo = $ax; }
                }
                if ($li === $bi) {
                    if ($line->rtl) { $lo = $bx; } else { $hi = $bx; }
                }
            }
            $this->visualStops($li);
            for ($k = 0; $k + 1 < $nb; $k++) {
                $start = $bounds[$k]; $end = $bounds[$k + 1];
                if ($start < $line->start) {
                    continue;
                }
                if ($start >= $line->end) {
                    break;
                }
                if ($end > $line->end) {
                    continue;
                }
                [$x0] = $this->caretAt($start, self::DOWNSTREAM);
                [$x1] = $this->caretAt($end, self::UPSTREAM);
                $left = min($x0, $x1); $right = max($x0, $x1);
                if ($right > $left && $left >= $lo - 0.01 && $right <= $hi + 0.01) {
                    $ranges[] = [$start, $end];
                }
            }
            if ($li < $bi && $li + 1 < count($this->lines) && $this->lines[$li + 1]->start > $line->end) {
                $ranges[] = [$line->end, $this->lines[$li + 1]->start];
            }
        }
        usort($ranges, fn($p, $q) => $p[0] <=> $q[0]);
        $out = [];
        foreach ($ranges as $r) {
            $n = count($out) - 1;
            if ($n >= 0 && $out[$n][1] >= $r[0]) {
                $out[$n][1] = max($out[$n][1], $r[1]);
            } else {
                $out[] = $r;
            }
        }
        return $out;
    }
}
