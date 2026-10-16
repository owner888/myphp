<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/** Flex layout in two passes: measure bottom-up, arrange top-down. */
final class Layout
{
    public static function measure(Node $n): void
    {
        if ($n->kind !== Node::CONTAINER) {
            $n->mw = $n->fw;
            $n->mh = $n->fh;
            return;
        }
        if ($n->gridCols !== null) { // a grid: its tracks at the size their content asks
            foreach ($n->kids as $k) { self::measure($k); }
            [$cols, $rows] = self::gridTracks($n, INF, INF);
            $n->mw = array_sum($cols) + $n->gap * max(0, count($cols) - 1) + 2 * $n->padX();
            $n->mh = array_sum($rows) + $n->gap * max(0, count($rows) - 1) + 2 * $n->padY();
            if ($n->fw > 0) { $n->mw = $n->fw; }
            if ($n->fh > 0) { $n->mh = $n->fh; }
            $n->mw = max($n->mw, $n->minW); $n->mh = max($n->mh, $n->minH);
            return;
        }
        $main = 0.0; $cross = 0.0; $cnt = 0;
        foreach ($n->kids as $k) {
            self::measure($k);
            if ($k->absolute) {
                continue; // out of the flow: it takes no room
            }
            $cnt++;
            if ($n->dir === Node::ROW) {
                $main += $k->mw; if ($k->mh > $cross) $cross = $k->mh;
            } else {
                $main += $k->mh; if ($k->mw > $cross) $cross = $k->mw;
            }
        }
        [$padMain, $padCross] = $n->dir === Node::ROW ? [$n->padX(), $n->padY()] : [$n->padY(), $n->padX()];
        $main += $n->gap * max(0, $cnt - 1) + 2 * $padMain;
        $cross += 2 * $padCross;
        if ($n->wraps && $n->wrapWidth > 0) { // a wrapping row: as wide as its widest line, as high as its lines
            $main = 0.0; $cross = 2 * $padCross;
            foreach (self::lines($n, $n->wrapWidth - 2 * $padMain) as $i => $line) {
                $lw = $n->gap * (count($line) - 1); $lh = 0.0;
                foreach ($line as $k) { $lw += $k->mw; $lh = max($lh, $k->mh); }
                $main = max($main, $lw + 2 * $padMain); $cross += $lh + ($i > 0 ? $n->gap : 0);
            }
        }
        if ($n->scroll !== null) { // a scrolling container takes the room it is given on the axes it scrolls
            if ($n->dir === Node::COLUMN ? $n->scrollY : $n->scrollX) { $main = 2 * $padMain; }
            if ($n->dir === Node::COLUMN ? $n->scrollX : $n->scrollY) { $cross = 2 * $padCross; }
        }
        if ($n->dir === Node::ROW) { $n->mw = $main; $n->mh = $cross; } else { $n->mw = $cross; $n->mh = $main; }
        if ($n->fw > 0) { $n->mw = $n->fw; } // a container given a size (Node::size) takes it over its content's
        if ($n->fh > 0) { $n->mh = $n->fh; }
        $n->mw = max($n->mw, $n->minW); $n->mh = max($n->mh, $n->minH);
    }

    public static function arrange(Node $n, float $x, float $y, float $w, float $h): void
    {
        $n->x = $x; $n->y = $y; $n->w = $w; $n->h = $h;
        if ($n->kind !== Node::CONTAINER) return;
        $kids = [];
        foreach ($n->kids as $k) {
            if ($k->absolute) {
                self::arrange($k, $x + $n->padX() + $k->ax, $y + $n->padY() + $k->ay, $k->mw, $k->mh);
            } else {
                $kids[] = $k;
            }
        }
        $cnt = count($kids);
        if ($cnt === 0) return;
        $px = $n->padX(); $py = $n->padY();
        if ($n->gridCols !== null) { // a grid: the tracks sized in the room there is, each child stretched across its cell, or placed across it as its alignSelf says
            [$cols, $rows] = self::gridTracks($n, $w - 2 * $px, $h - 2 * $py);
            $nc = count($cols); $cx = $x + $px; $cy = $y + $py;
            foreach ($kids as $i => $k) {
                $c = $i % $nc; $r = intdiv($i, $nc);
                if ($c === 0 && $i > 0) { $cx = $x + $px; $cy += $rows[$r - 1] + $n->gap; }
                $a = $k->self === Node::AUTO ? Node::STRETCH : $k->self;
                $kh = $a === Node::STRETCH ? $rows[$r] : $k->mh;
                self::arrange($k, $cx, $cy + self::across($a, $rows[$r], $kh), $cols[$c], $kh);
                $cx += $cols[$c] + $n->gap;
            }
            return;
        }
        if ($n->wraps) { // each line is arranged as a row of its own, the lines stacked
            $cy = $y + $py;
            foreach (self::lines($n, $w - 2 * $px) as $line) {
                $lh = 0.0; foreach ($line as $k) { $lh = max($lh, $k->mh); }
                self::arrangeRow($n, $line, $x + $px, $cy, $w - 2 * $px, $lh);
                $cy += $lh + $n->gap;
            }
            return;
        }
        $avail = ($n->dir === Node::ROW ? $w - 2 * $px : $h - 2 * $py) - $n->gap * ($cnt - 1);
        $used = 0.0; $grow = 0; $crossMax = 0.0;
        foreach ($kids as $k) { $used += $n->dir === Node::ROW ? $k->mw : $k->mh; $crossMax = max($crossMax, $n->dir === Node::ROW ? $k->mh : $k->mw); $grow += $k->grow; }
        $extra = $grow > 0 ? max(0, $avail - $used) / $grow : 0.0;
        $cx = $x + $px; $cy = $y + $py; $cw = $w - 2 * $px; $ch = $h - 2 * $py;
        if ($n->scroll instanceof ScrollState) {
            $s = $n->scroll;
            $contentMain = $used + $n->gap * ($cnt - 1);
            [$contentW, $contentH] = $n->dir === Node::ROW ? [$contentMain, $crossMax] : [$crossMax, $contentMain];
            if ($n->scrollY) {
                $s->maxY = max(0.0, $contentH - $ch);
                $s->viewport = $ch;
                $s->y = min($s->y, $s->maxY);
                $cy -= $s->y;
                if ($n->dir === Node::ROW) { $ch = max($ch, $contentH); } // children get their own height
            }
            if ($n->scrollX) {
                $s->maxX = max(0.0, $contentW - $cw);
                $s->viewportW = $cw;
                $s->x = min($s->x, $s->maxX);
                $cx -= $s->x;
                if ($n->dir === Node::COLUMN) { $cw = max($cw, $contentW); }
            }
            if ($n->dir === Node::COLUMN ? $n->scrollY : $n->scrollX) {
                $extra = 0.0; // nothing grows along a scrolled axis
            }
        } elseif ($n->scroll instanceof ListState) {
            $s = $n->scroll;
            $s->viewport = $ch;
            foreach ($kids as $i => $k) {
                $s->measured($s->first + $i, $k->mh);
            }
            // The view's top lies $offset below the anchor's top; built rows
            // short of the view's bottom leave a gap this frame.
            $cy -= $s->offset;
            $s->gap = max(0.0, $ch - ($used + $n->gap * ($cnt - 1) - $s->offset));
            $s->atEnd = $s->first + $cnt >= $s->count && $used + $n->gap * ($cnt - 1) - $s->offset <= $ch + 0.5;
            if ($s->first + $cnt >= $s->count && $s->first === 0) {
                $s->gap = 0.0; // every row is built and they are short of the view: nothing can fill it
            }
            $extra = 0.0;
        }
        $align = $n->align === Node::AUTO ? ($n->dir === Node::ROW ? Node::CENTER : Node::STRETCH) : $n->align;
        if ($grow === 0 && $n->scroll === null) { // nothing grows: justify packs the children along the axis
            $free = max(0.0, $avail - $used);
            if ($n->justify === Node::CENTER) { $cx += $n->dir === Node::ROW ? $free / 2 : 0; $cy += $n->dir === Node::ROW ? 0 : $free / 2; }
            if ($n->justify === Node::END) { $cx += $n->dir === Node::ROW ? $free : 0; $cy += $n->dir === Node::ROW ? 0 : $free; }
        }
        // Single-line texts shrink, between them, by what the row's children overflow (mygo's flex shrink on SingleLine).
        $shrink = [];
        if ($n->dir === Node::ROW && $extra === 0.0 && $n->scroll === null) {
            $over = $used + $n->gap * ($cnt - 1) - ($w - 2 * $px); $room = 0.0;
            foreach ($kids as $k) { if ($k->single) { $room += $k->mw; } }
            if ($over > 0 && $room > 0) {
                foreach ($kids as $i => $k) { if ($k->single) { $shrink[$i] = min(max(0.0, $k->mw - $k->minW), $over * $k->mw / $room); } } // not below its min width
            }
        }
        foreach ($kids as $i => $k) {
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($n->dir === Node::ROW ? $k->mh <= 0 : $k->mw <= 0) {
                $a = Node::STRETCH; // no size of its own across (a text area given 0, an empty box): it takes the room
            }
            if ($n->dir === Node::ROW) {
                $kw = $k->mw + $extra * $k->grow - ($shrink[$i] ?? 0.0);
                $kh = $a === Node::STRETCH ? $ch : $k->mh;
                self::arrange($k, $cx, $cy + self::across($a, $ch, $kh), $kw, $kh);
                $cx += $kw + $n->gap;
            } else {
                $kh = $k->mh + $extra * $k->grow;
                $kw = $a === Node::STRETCH ? $cw : ($k->single ? min($k->mw, $cw) : $k->mw);
                self::arrange($k, $cx + self::across($a, $cw, $kw), $cy, $kw, $kh);
                $cy += $kh + $n->gap;
            }
        }
    }

    /**
     * The sizes of a grid's columns and rows, mygo's sizeTracks for children
     * placed row by row, each in one cell: fixed tracks their points, fit
     * tracks the largest content in them, fr tracks sharing what is left of
     * $availW (or $availH) — or, with no room known (INF), the largest share
     * any child asks. The rows beyond those set fit their content.
     * @return array{0: list<float>, 1: list<float>}
     */
    private static function gridTracks(Node $n, float $availW, float $availH): array
    {
        $nc = max(1, count($n->gridCols));
        $kids = array_values(array_filter($n->kids, fn(Node $k) => !$k->absolute));
        $nr = max(count($n->gridRows), intdiv(count($kids) + $nc - 1, $nc));
        $cols = self::sizeTracks($n->gridCols, $nc, $availW, $n->gap, $kids, fn(int $i) => $i % $nc, fn(Node $k) => $k->mw);
        $rows = self::sizeTracks($n->gridRows, $nr, $availH, $n->gap, $kids, fn(int $i) => intdiv($i, $nc), fn(Node $k) => $k->mh);
        return [$cols, $rows];
    }

    /** @param list<Track> $tracks @return list<float> */
    private static function sizeTracks(array $tracks, int $count, float $avail, float $gap, array $kids, Closure $trackOf, Closure $sizeOf): array
    {
        $kind = fn(int $i) => $i < count($tracks) ? $tracks[$i]->kind : Track::FIT;
        $sizes = []; $frSum = 0.0;
        for ($i = 0; $i < $count; $i++) { $sizes[$i] = $kind($i) === Track::FIXED ? $tracks[$i]->v : 0.0; if ($kind($i) === Track::FR) { $frSum += $tracks[$i]->v; } }
        $unit = 0.0; // the largest share an fr track's content asks, for a grid with no room set
        foreach ($kids as $i => $k) {
            $t = $trackOf($i);
            if ($kind($t) === Track::FIT) { $sizes[$t] = max($sizes[$t], $sizeOf($k)); }
            elseif ($kind($t) === Track::FR && $tracks[$t]->v > 0) { $unit = max($unit, $sizeOf($k) / $tracks[$t]->v); }
        }
        if (!is_finite($avail)) {
            for ($i = 0; $i < $count; $i++) { if ($kind($i) === Track::FR) { $sizes[$i] = $unit * $tracks[$i]->v; } }
            return $sizes;
        }
        $free = max(0.0, $avail - $gap * max(0, $count - 1) - array_sum($sizes));
        if ($frSum > 0) {
            for ($i = 0; $i < $count; $i++) { if ($kind($i) === Track::FR) { $sizes[$i] = $free / $frSum * $tracks[$i]->v; } }
        } elseif ($free > 0) { // no fr tracks: the fit tracks share the room left (mygo's stretch)
            $fits = 0; for ($i = 0; $i < $count; $i++) { if ($kind($i) === Track::FIT) { $fits++; } }
            for ($i = 0; $i < $count; $i++) { if ($fits > 0 && $kind($i) === Track::FIT) { $sizes[$i] += $free / $fits; } }
        }
        return $sizes;
    }

    /** The children of a wrapping row by line: a child overflowing $avail starts the next (mygo's flex wrap). */
    private static function lines(Node $n, float $avail): array
    {
        $lines = []; $line = []; $used = 0.0;
        foreach ($n->kids as $k) {
            if ($k->absolute) { continue; }
            if ($line !== [] && $used + $n->gap + $k->mw > $avail) { $lines[] = $line; $line = []; $used = 0.0; }
            $used += ($line === [] ? 0 : $n->gap) + $k->mw; $line[] = $k;
        }
        if ($line !== []) { $lines[] = $line; }
        return $lines;
    }

    /** Arranges one line of a wrapping row across ($x, $y, $w, $h): growing children share what is left, the rest sit as the row aligns them. */
    private static function arrangeRow(Node $n, array $line, float $x, float $y, float $w, float $h): void
    {
        $used = 0.0; $grow = 0;
        foreach ($line as $k) { $used += $k->mw; $grow += $k->grow; }
        $extra = $grow > 0 ? max(0, $w - $n->gap * (count($line) - 1) - $used) / $grow : 0.0;
        $align = $n->align === Node::AUTO ? Node::CENTER : $n->align;
        $cx = $x;
        foreach ($line as $k) {
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($k->mh <= 0) { $a = Node::STRETCH; }
            $kw = $k->mw + $extra * $k->grow; $kh = $a === Node::STRETCH ? $h : $k->mh;
            self::arrange($k, $cx, $y + self::across($a, $h, $kh), $kw, $kh);
            $cx += $kw + $n->gap;
        }
    }

    /** Where a child of $size sits across $room: at the start, in the middle, or at the end. */
    private static function across(int $align, float $room, float $size): float
    {
        return match ($align) { Node::CENTER => ($room - $size) / 2, Node::END => $room - $size, default => 0.0 };
    }
}
