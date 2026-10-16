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
        if ($n->gridCols !== null) { // a grid: the tracks sized in the room there is, each child stretched across its area, or placed down it as its alignSelf says
            [$cols, $rows, $items] = self::gridTracks($n, $w - 2 * $px, $h - 2 * $py);
            $colAt = []; $rowAt = []; // where each track starts
            for ($c = 0, $p = $x + $px; $c < count($cols); $c++) { $colAt[$c] = $p; $p += $cols[$c] + $n->gap; }
            for ($r = 0, $p = $y + $py; $r < count($rows); $r++) { $rowAt[$r] = $p; $p += $rows[$r] + $n->gap; }
            foreach ($items as [$k, $c, $r, $cs, $rs]) {
                $aw = $n->gap * ($cs - 1); for ($j = $c; $j < $c + $cs; $j++) { $aw += $cols[$j]; }
                $ah = $n->gap * ($rs - 1); for ($j = $r; $j < $r + $rs; $j++) { $ah += $rows[$j]; }
                $a = $k->self === Node::AUTO ? Node::STRETCH : $k->self;
                $kh = $a === Node::STRETCH ? $ah : $k->mh;
                self::arrange($k, $colAt[$c], $rowAt[$r] + self::across($a, $ah, $kh), $aw, $kh);
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
        [$items, $nc, $nr] = self::gridPlace($n);
        $cols = self::sizeTracks($n->gridCols, $nc, $availW, $n->gap, array_map(fn($it) => [$it[1], $it[3], $it[0]->mw], $items));
        $rows = self::sizeTracks($n->gridRows, $nr, $availH, $n->gap, array_map(fn($it) => [$it[2], $it[4], $it[0]->mh], $items));
        return [$cols, $rows, $items];
    }

    /**
     * Places the in-flow children of a grid in its cells, mygo's gridPlace
     * as CSS does: those placed in both directions first, then those given
     * a row, then the others in order, a cursor going row by row and
     * skipping cells taken (a span wider than the grid's columns widens it).
     * @return array{0: list<array{0: Node, 1: int, 2: int, 3: int, 4: int}>, 1: int, 2: int} items (node, col, row, colSpan, rowSpan, from 0), columns, rows
     */
    private static function gridPlace(Node $n): array
    {
        $nc = max(count($n->gridCols), 1); $nr = 0;
        $items = [];
        foreach ($n->kids as $k) {
            if ($k->absolute) { continue; }
            $cs = $k->colSpan < 0 ? max(count($n->gridCols) - max($k->cellCol - 1, 0), 1) : max($k->colSpan, 1);
            $rs = $k->rowSpan < 0 ? max(count($n->gridRows) - max($k->cellRow - 1, 0), 1) : max($k->rowSpan, 1);
            $nc = max($nc, max($k->cellCol - 1, 0) + $cs);
            $items[] = [$k, $k->cellCol - 1, $k->cellRow - 1, $cs, $rs];
        }
        $occ = [];
        $fits = function (array $it, int $r, int $c) use (&$occ, $nc): bool {
            for ($rr = $r; $rr < $r + $it[4]; $rr++) { for ($cc = $c; $cc < $c + $it[3]; $cc++) { if (!empty($occ[$rr * $nc + $cc])) { return false; } } }
            return true;
        };
        $mark = function (array &$it, int $r, int $c) use (&$occ, &$nr, $nc): void {
            $it[1] = $c; $it[2] = $r;
            for ($rr = $r; $rr < $r + $it[4]; $rr++) { for ($cc = $c; $cc < $c + $it[3]; $cc++) { $occ[$rr * $nc + $cc] = true; } }
            $nr = max($nr, $r + $it[4]);
        };
        foreach ($items as &$it) { if ($it[2] >= 0 && $it[1] >= 0) { $mark($it, $it[2], $it[1]); } }
        unset($it);
        foreach ($items as &$it) { // in a row given: the first column it fits in
            if ($it[2] < 0 || $it[1] >= 0) { continue; }
            $c = 0; while ($c + $it[3] < $nc && !$fits($it, $it[2], $c)) { $c++; }
            $mark($it, $it[2], $c);
        }
        unset($it);
        $cr = 0; $cc = 0;
        foreach ($items as &$it) {
            if ($it[2] >= 0) { continue; }
            if ($it[1] >= 0) { // a column given: the next row down it fits in
                if ($it[1] < $cc) { $cr++; }
                $cc = $it[1];
                while (!$fits($it, $cr, $cc)) { $cr++; }
                $mark($it, $cr, $cc);
                continue;
            }
            while (true) {
                while ($cc + $it[3] <= $nc && !$fits($it, $cr, $cc)) { $cc++; }
                if ($cc + $it[3] <= $nc) { break; }
                $cr++; $cc = 0;
            }
            $mark($it, $cr, $cc);
            $cc += $it[3];
        }
        unset($it);
        return [$items, $nc, max($nr, count($n->gridRows))];
    }

    /**
     * Sizes $count tracks along an axis, of which $tracks are set, with the
     * room $avail (INF when unknown) and $gap between them, from what the
     * items ask: [start, span, size] each — mygo's sizeTracks, with an
     * item's min and max content the same size. Fit tracks take what the
     * items spanning one alone ask, then items spanning several fit
     * tracks (none fr) spread what they lack over them; fr tracks share
     * what is left, or, with no room known, the largest share any item
     * asks; with no fr tracks the fit ones share the room left (stretch).
     * @param list<Track> $tracks @param list<array{0: int, 1: int, 2: float}> $asks @return list<float>
     */
    private static function sizeTracks(array $tracks, int $count, float $avail, float $gap, array $asks): array
    {
        $kind = fn(int $i) => $i < count($tracks) ? $tracks[$i]->kind : Track::FIT;
        $sizes = []; $frSum = 0.0;
        for ($i = 0; $i < $count; $i++) { $sizes[$i] = $kind($i) === Track::FIXED ? $tracks[$i]->v : 0.0; if ($kind($i) === Track::FR) { $frSum += $tracks[$i]->v; } }
        $spans = function (array $a, int $k) use ($kind): int { $n = 0; for ($i = $a[0]; $i < $a[0] + $a[1]; $i++) { if ($kind($i) === $k) { $n++; } } return $n; };
        foreach ($asks as $a) { if ($a[1] === 1 && $kind($a[0]) === Track::FIT) { $sizes[$a[0]] = max($sizes[$a[0]], $a[2]); } }
        foreach ($asks as $a) {
            if ($a[1] === 1 || $spans($a, Track::FR) > 0) { continue; }
            $fits = $spans($a, Track::FIT);
            if ($fits === 0) { continue; }
            $sum = $gap * ($a[1] - 1); for ($i = $a[0]; $i < $a[0] + $a[1]; $i++) { $sum += $sizes[$i]; }
            if ($a[2] > $sum) { for ($i = $a[0]; $i < $a[0] + $a[1]; $i++) { if ($kind($i) === Track::FIT) { $sizes[$i] += ($a[2] - $sum) / $fits; } } }
        }
        if (!is_finite($avail)) { // as large as the content asks: fr tracks by the largest share any item asks
            $unit = 0.0;
            foreach ($asks as $a) {
                $fr = 0.0; $other = 0.0;
                for ($i = $a[0]; $i < $a[0] + $a[1]; $i++) { if ($kind($i) === Track::FR) { $fr += $tracks[$i]->v; } else { $other += $sizes[$i]; } }
                if ($fr > 0) { $unit = max($unit, ($a[2] - $other - $gap * ($a[1] - 1)) / $fr); }
            }
            for ($i = 0; $i < $count; $i++) { if ($kind($i) === Track::FR) { $sizes[$i] = $unit * $tracks[$i]->v; } }
            return $sizes;
        }
        $free = max(0.0, $avail - $gap * max(0, $count - 1) - array_sum($sizes));
        if ($frSum > 0) {
            for ($i = 0; $i < $count; $i++) { if ($kind($i) === Track::FR) { $sizes[$i] = $free / $frSum * $tracks[$i]->v; } }
        } elseif ($free > 0) {
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
