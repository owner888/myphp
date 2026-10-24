<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/** Flex layout in two passes: measure bottom-up, arrange top-down. */
final class Layout
{
    /**
     * Gives the nodes inside $e, whose text starts at rune $offset of
     * paragraph $para's, and those inside them, the boxes of their words in
     * the paragraph's layout as their frags, and the box around those as
     * theirs (mygo's placeInline).
     */
    private static function placeInline(Node $para, Node $e, int $offset): void
    {
        $l = $para->textLayout();
        foreach ($e->kids as $k) {
            $a = $offset + $k->runes[0]; $b = $offset + $k->runes[1];
            $k->frags = [];
            $x0 = $y0 = INF; $x1 = $y1 = -INF;
            foreach ($l->appendSelection([], $a, $b, false) as [$rx, $ry, $rw, $rh]) {
                $k->frags[] = [$para->x + $rx, $para->y + $ry, $rw, $rh];
                $x0 = min($x0, $para->x + $rx); $y0 = min($y0, $para->y + $ry);
                $x1 = max($x1, $para->x + $rx + $rw); $y1 = max($y1, $para->y + $ry + $rh);
            }
            if ($k->frags === []) {
                $k->x = $k->y = $k->w = $k->h = 0.0;
            } else {
                $k->x = $x0; $k->y = $y0; $k->w = $x1 - $x0; $k->h = $y1 - $y0;
            }
            self::placeInline($para, $k, $a);
        }
    }

    /**
     * Lays the frame of context $c out in a window of $w × $h points:
     * measures, arranges, and scrolls the nodes asked for into view (mygo's
     * layoutTree).
     */
    public static function layout(Context $c, float $w, float $h): void
    {
        self::measure($c->root);
        self::arrange($c->root, 0, 0, $w, $h);
        $ids = $c->runtime?->takeReveals($c->reveal) ?? $c->reveal;
        if ($ids !== []) {
            self::revealAll($c->root, $ids);
        }
    }

    /** Scrolls the nodes of $root with ids $ids into view, in that order (mygo's revealAll). @param list<int> $ids */
    private static function revealAll(Node $root, array $ids): void
    {
        $found = [];
        $walk = function (Node $n, array $chain) use (&$walk, &$found, $ids): void {
            $chain[] = $n;
            if (!isset($found[$n->id]) && in_array($n->id, $ids, true)) {
                $found[$n->id] = $chain;
            }
            foreach ($n->kids as $k) { $walk($k, $chain); }
        };
        $walk($root, []);
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                self::reveal($found[$id]);
            }
        }
    }

    /**
     * Scrolls the containers around the last node of $chain (its ancestors
     * from the root, then it), innermost first, as little as shows its box,
     * arranging each one scrolled again (mygo's reveal). The text inside a
     * paragraph shows by its paragraph.
     * @param non-empty-list<Node> $chain
     */
    private static function reveal(array $chain): void
    {
        while (count($chain) > 1 && $chain[count($chain) - 1]->inline) {
            array_pop($chain);
        }
        $e = $chain[count($chain) - 1];
        for ($i = count($chain) - 2; $i >= 0; $i--) {
            $p = $chain[$i]; $ch = $chain[$i + 1];
            if (!$p->scroll instanceof ScrollState || $ch->absolute) {
                continue;
            }
            $s = $p->scroll;
            $sx = $s->x; $sy = $s->y;
            if ($p->scrollX) { // where the box is in the content, and the offset that shows it
                $sx = max(0.0, min(self::nearest($s->x, $e->x - $p->x - $p->padL() + $s->x, $e->w, $s->viewportW), $s->maxX));
            }
            if ($p->scrollY) {
                $sy = max(0.0, min(self::nearest($s->y, $e->y - $p->y - $p->padT() + $s->y, $e->h, $s->viewport), $s->maxY));
            }
            if ($sx != $s->x || $sy != $s->y) {
                $s->x = $sx;
                $s->scrollTo($sy, false);
                self::arrange($p, $p->x, $p->y, $p->w, $p->h); // what is inside it moves with the offset
            }
        }
    }

    /** The offset that shows what spans [pos, pos + size) of a container's content in its viewport [0, view), scrolled by off, moving as little as it can: what is larger than the viewport shows from its start (mygo's nearest). */
    private static function nearest(float $off, float $pos, float $size, float $view): float
    {
        $start = $pos - $off; $end = $pos + $size - $off;
        if ($start < 0) {
            return $off + $start;
        }
        if ($end > $view) {
            return $off + min($end - $view, $start);
        }
        return $off;
    }

    /** Measures $n and what is inside it, bottom-up: the size it asks, its margins included, which its parent's arrange gives it back less them (margins()). */
    public static function measure(Node $n): void
    {
        self::measureBox($n);
        [$mt, $mr, $mb, $ml] = $n->margins;
        if ($mt != 0 || $mr != 0 || $mb != 0 || $ml != 0) { // an automatic margin asks for none
            $mx = (is_finite($ml) ? $ml : 0) + (is_finite($mr) ? $mr : 0);
            $n->mw += $mx; $n->minMw += $mx;
            $n->mh += (is_finite($mt) ? $mt : 0) + (is_finite($mb) ? $mb : 0);
        }
    }

    private static function measureBox(Node $n): void
    {
        if ($n->kind === Node::RICH) { // laid out at the width it wraps at, unless given a size
            $l = $n->textLayout();
            if ($n->fw <= 0) { $n->fw = $l->width; }
            if ($n->fh <= 0) { $n->fh = $l->height; }
        } elseif ($n->kind === Node::TEXT && $n->metrics !== null && ($n->ts !== null || $n->maxLines > 0 || $n->noWrap || ($selects = ($n->scope !== 0 || $n->selectable) && !$n->unselectable && !$n->focusable && $n->url === null))) {
            if ($n->maxLines > 0 || $n->noWrap || ($selects ?? false) || $n->textStyle()->needsLayout()) { // selected text is laid out to find its runes
                [$ow, $oh] = $n->metrics->measure($n->text ?? '', $n->fontSize, $n->style); // as text() measured it
                $n->toRich(); // laid out by the text system, as rich text is
                $l = $n->textLayout();
                if ($n->fw == $ow || $n->fw <= 0) { $n->fw = $l->width; } // a size given to it stays
                if ($n->fh == $oh || $n->fh <= 0) { $n->fh = $l->height; }
            } else {
                self::restyle($n);
            }
        }
        if ($n->kind !== Node::CONTAINER) {
            $n->mw = $n->fw;
            $n->mh = $n->fh;
            $n->minMw = $n->single ? $n->minW : $n->fw; // a single-line text shrinks to its min width (and shows an ellipsis); other leaves keep their size
            return;
        }
        if ($n->gridCols !== null) { // a grid: its tracks at the size their content asks
            foreach ($n->kids as $k) { self::measure($k); }
            [$cols, $rows] = self::gridTracks($n, INF, INF);
            $n->mw = array_sum($cols) + $n->gap * max(0, count($cols) - 1) + $n->padL() + $n->padR();
            $n->mh = array_sum($rows) + $n->gap * max(0, count($rows) - 1) + $n->padT() + $n->padB();
            if ($n->fw > 0) { $n->mw = $n->fw; }
            if ($n->fh > 0) { $n->mh = $n->fh; }
            $n->mw = max($n->mw, $n->minW); $n->mh = max($n->mh, $n->minH);
            return;
        }
        $main = 0.0; $cross = 0.0; $cnt = 0; $least = 0.0; // least: the min-content width, the automatic minimum a row shrinks this node to
        foreach ($n->kids as $k) {
            self::measure($k);
            if ($k->absolute) {
                continue; // out of the flow: it takes no room
            }
            $cnt++;
            if ($n->dir === Node::ROW) {
                $main += $k->basis ?? $k->mw; if ($k->mh > $cross) $cross = $k->mh;
                $least += $k->minMw;
            } else {
                $main += $k->basis ?? $k->mh; if ($k->mw > $cross) $cross = $k->mw;
                $least = max($least, $k->minMw);
            }
        }
        $n->minMw = ($n->dir === Node::ROW ? $least + $n->gap * max(0, $cnt - 1) : $least) + $n->padL() + $n->padR();
        [$padMain, $padCross] = $n->dir === Node::ROW ? [$n->padL() + $n->padR(), $n->padT() + $n->padB()] : [$n->padT() + $n->padB(), $n->padL() + $n->padR()];
        $main += $n->gap * max(0, $cnt - 1) + $padMain;
        $cross += $padCross;
        if ($n->wraps && $n->wrapWidth > 0) { // a wrapping row: as wide as its widest line, as high as its lines
            $main = 0.0; $cross = $padCross;
            foreach (self::lines($n, $n->wrapWidth - $padMain) as $i => $line) {
                $lw = $n->gap * (count($line) - 1); $lh = 0.0;
                foreach ($line as $k) { $lw += $k->mw; $lh = max($lh, $k->mh); }
                $main = max($main, $lw + $padMain); $cross += $lh + ($i > 0 ? $n->gap : 0);
            }
        }
        if ($n->scroll !== null) { // a scrolling container takes the room it is given on the axes it scrolls
            if ($n->dir === Node::COLUMN ? $n->scrollY : $n->scrollX) { $main = $padMain; }
            if ($n->dir === Node::COLUMN ? $n->scrollX : $n->scrollY) { $cross = $padCross; }
        }
        if ($n->dir === Node::ROW) { $n->mw = $main; $n->mh = $cross; } else { $n->mw = $cross; $n->mh = $main; }
        if ($n->fw > 0) { $n->mw = $n->fw; $n->minMw = min($n->minMw, $n->fw); } // a container given a size (Node::size) takes it over its content's
        if ($n->fh > 0) { $n->mh = $n->fh; }
        if ($n->scrollX || $n->wraps) { $n->minMw = $n->padL() + $n->padR(); } // what scrolls or wraps across has no least width
        $n->mw = max($n->mw, $n->minW); $n->mh = max($n->mh, $n->minH);
        $n->minMw = $n->minSet ? $n->minW : max(min($n->minMw, $n->mw), $n->minW); // a least width set replaces its content's
    }

    /**
     * A text given a text style of its own (Node::fontSize, bold()…) in it:
     * the size, color and Font bits it shows in, and its size measured
     * anew. Its metrics draw a weight of 600 or more bold and a family of
     * "monospace" in the fixed-pitch font, the others in the system's.
     */
    private static function restyle(Node $n): void
    {
        $own = $n->ts;
        [$ow, $oh] = $n->metrics->measure($n->text ?? '', $n->fontSize, $n->style); // as text() measured it
        if ($own->size !== null) { $n->fontSize = $own->size; }
        if ($own->color !== null) { $n->fg = $own->color; }
        $n->style |= Node::fontBits($own);
        if ($own->underline) { $n->underline = true; }
        [$w, $h] = $n->metrics->measure($n->text ?? '', $n->fontSize, $n->style);
        if ($n->fw == $ow) { $n->fw = $w; } // a size given to it stays
        if ($n->fh == $oh) { $n->fh = $h; }
        $n->metrics = null; // once
    }

    /**
     * Places $n in the box ($x, $y, $w, $h) its parent gives it, its margins
     * included: inside them, an automatic margin taking the room the node
     * does not need on its side (mygo's margins); then what is inside it.
     */
    public static function arrange(Node $n, float $x, float $y, float $w, float $h): void
    {
        [$mt, $mr, $mb, $ml] = $n->margins;
        if ($mt != 0 || $mr != 0 || $mb != 0 || $ml != 0) {
            [$x, $w] = self::inMargins($x, $w, $ml, $mr, $n->mw);
            [$y, $h] = self::inMargins($y, $h, $mt, $mb, $n->mh);
        }
        $n->x = $x; $n->y = $y; $n->w = $w; $n->h = $h;
        if ($n->kind === Node::RICH && $n->kids !== []) {
            self::placeInline($n, $n, 0);
            return;
        }
        if ($n->kind !== Node::CONTAINER) return;
        $kids = [];
        foreach ($n->kids as $k) {
            if ($k->absolute) {
                if ($k->pct === [null, null, null, null]) {
                    self::arrange($k, $x + $n->padL() + $k->ax, $y + $n->padT() + $k->ay, $k->mw, $k->mh);
                    continue;
                }
                // Placed in percentages of the content box; a size so given takes its margins on top.
                $cw = $w - $n->padL() - $n->padR(); $ch = $h - $n->padT() - $n->padB();
                [$mt, $mr, $mb, $ml] = array_map(fn(float $m): float => is_finite($m) ? $m : 0.0, $k->margins);
                [$l, $t, $pw, $ph] = $k->pct;
                $kw = $pw !== null ? $pw * $cw + $ml + $mr : $k->mw;
                $kh = $ph !== null ? $ph * $ch + $mt + $mb : $k->mh;
                self::arrange($k, $x + $n->padL() + ($l !== null ? $l * $cw : $k->ax), $y + $n->padT() + ($t !== null ? $t * $ch : $k->ay), $kw, $kh);
            } else {
                $kids[] = $k;
            }
        }
        $cnt = count($kids);
        if ($cnt === 0) return;
        $px = $n->padL(); $py = $n->padT(); $pw = $px + $n->padR(); $ph = $py + $n->padB(); // the content's left and top, and the room the edges take
        if ($n->gridCols !== null) { // a grid: the tracks sized in the room there is, each child stretched over its area or placed in it as justifyItems/justifySelf (across) and alignSelf (down) say
            [$cols, $rows, $items] = self::gridTracks($n, $w - $pw, $h - $ph);
            $colAt = []; $rowAt = []; // where each track starts
            for ($c = 0, $p = $x + $px; $c < count($cols); $c++) { $colAt[$c] = $p; $p += $cols[$c] + $n->gap; }
            for ($r = 0, $p = $y + $py; $r < count($rows); $r++) { $rowAt[$r] = $p; $p += $rows[$r] + $n->gap; }
            foreach ($items as [$k, $c, $r, $cs, $rs]) {
                $aw = $n->gap * ($cs - 1); for ($j = $c; $j < $c + $cs; $j++) { $aw += $cols[$j]; }
                $ah = $n->gap * ($rs - 1); for ($j = $r; $j < $r + $rs; $j++) { $ah += $rows[$j]; }
                $a = $k->self === Node::AUTO ? Node::STRETCH : $k->self;
                $j = $k->justifySelf !== Node::AUTO ? $k->justifySelf : ($n->justifyItems !== Node::AUTO ? $n->justifyItems : Node::STRETCH); // mygo's gridJustify
                $kh = $a === Node::STRETCH && $k->fh <= 0 ? $ah : $k->mh; // a size of its own is kept (mygo: an explicit size is not stretched)
                $kw = $j === Node::STRETCH && ($k->fw <= 0 || $k->kind === Node::TEXT) ? $aw : $k->mw; // a text's fw is what it measured, not a size given
                self::arrange($k, $colAt[$c] + self::across($j, $aw, $kw), $rowAt[$r] + self::across($a, $ah, $kh), $kw, $kh);
            }
            return;
        }
        if ($n->wraps) { // each line is arranged as a row of its own, the lines stacked
            $cy = $y + $py;
            foreach (self::lines($n, $w - $pw) as $line) {
                $lh = 0.0; foreach ($line as $k) { $lh = max($lh, $k->mh); }
                self::arrangeRow($n, $line, $x + $px, $cy, $w - $pw, $lh);
                $cy += $lh + $n->gap;
            }
            return;
        }
        $avail = ($n->dir === Node::ROW ? $w - $pw : $h - $ph) - $n->gap * ($cnt - 1);
        $used = 0.0; $grow = 0.0; $crossMax = 0.0;
        foreach ($kids as $k) { $used += $k->basis ?? ($n->dir === Node::ROW ? $k->mw : $k->mh); $crossMax = max($crossMax, $n->dir === Node::ROW ? $k->mh : $k->mw); $grow += $k->grow; }
        $extra = $grow > 0 ? max(0, $avail - $used) / $grow : 0.0;
        $cx = $x + $px; $cy = $y + $py; $cw = $w - $pw; $ch = $h - $ph;
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
        $autos = []; $autoCount = 0; // the automatic margins along the axis take the free space, before justify does
        if ($grow <= 0 && $n->scroll === null) {
            foreach ($kids as $i => $k) {
                $a = $n->dir === Node::ROW ? (int)is_infinite($k->margins[3]) + (int)is_infinite($k->margins[1]) : (int)is_infinite($k->margins[0]) + (int)is_infinite($k->margins[2]);
                if ($a > 0) { $autos[$i] = $a; $autoCount += $a; }
            }
        }
        if ($autoCount > 0) {
            $per = max(0.0, $avail - $used) / $autoCount;
            foreach ($autos as $i => $a) { $autos[$i] = $a * $per; }
        } elseif ($grow <= 0 && $n->scroll === null) { // nothing grows: justify packs the children along the axis
            $free = max(0.0, $avail - $used);
            if ($n->justify === Node::CENTER) { $cx += $n->dir === Node::ROW ? $free / 2 : 0; $cy += $n->dir === Node::ROW ? 0 : $free / 2; }
            if ($n->justify === Node::END) { $cx += $n->dir === Node::ROW ? $free : 0; $cy += $n->dir === Node::ROW ? 0 : $free; }
        }
        $shrink = $n->dir === Node::ROW && $extra === 0.0 && $n->scroll === null ? self::shrink($kids, $used + $n->gap * ($cnt - 1) - ($w - $pw)) : [];
        foreach ($kids as $i => $k) {
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($n->dir === Node::ROW ? $k->mh <= 0 : $k->mw <= 0) {
                $a = Node::STRETCH; // no size of its own across (a text area given 0, an empty box): it takes the room
            }
            $autoCross = $n->dir === Node::ROW ? is_infinite($k->margins[0]) || is_infinite($k->margins[2]) : is_infinite($k->margins[1]) || is_infinite($k->margins[3]); // automatic margins across place it themselves, in all the room
            if ($n->dir === Node::ROW) {
                $kw = ($k->basis ?? $k->mw) + $extra * $k->grow - ($shrink[$i] ?? 0.0) + ($autos[$i] ?? 0.0);
                $kh = $autoCross || ($a === Node::STRETCH && $k->fh <= 0) ? $ch : $k->mh; // a size of its own is kept (mygo: an explicit size is not stretched)
                $at = $n->reverse ? 2 * ($x + $px) + $cw - $cx - $kw : $cx; // reversed: the same place counted from the right
                self::arrange($k, $at, $cy + ($autoCross ? 0.0 : self::across($a, $ch, $kh)), $kw, $kh);
                $cx += $kw + $n->gap;
            } else {
                $kh = ($k->basis ?? $k->mh) + $extra * $k->grow + ($autos[$i] ?? 0.0);
                $kw = $autoCross || ($a === Node::STRETCH && $k->fw <= 0) ? $cw : ($k->single ? min($k->mw, $cw) : $k->mw);
                $at = $n->reverse ? 2 * ($y + $py) + $ch - $cy - $kh : $cy; // from the bottom
                self::arrange($k, $cx + ($autoCross ? 0.0 : self::across($a, $cw, $kw)), $at, $kw, $kh);
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
        $used = 0.0; $grow = 0.0;
        foreach ($line as $k) { $used += $k->mw; $grow += $k->grow; }
        $extra = $grow > 0 ? max(0, $w - $n->gap * (count($line) - 1) - $used) / $grow : 0.0;
        $shrink = $extra === 0.0 ? self::shrink($line, $used + $n->gap * (count($line) - 1) - $w) : [];
        $align = $n->align === Node::AUTO ? Node::CENTER : $n->align;
        $cx = $x;
        foreach ($line as $i => $k) {
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($k->mh <= 0) { $a = Node::STRETCH; }
            $kw = $k->mw + $extra * $k->grow - ($shrink[$i] ?? 0.0); $kh = $a === Node::STRETCH && $k->fh <= 0 ? $h : $k->mh;
            self::arrange($k, $cx, $y + self::across($a, $h, $kh), $kw, $kh);
            $cx += $kw + $n->gap;
        }
    }

    /**
     * How much each child of a row gives up when they overflow it by $over
     * (CSS flexbox's shrink, as mygo resolves it, every child's shrink
     * factor 1): in proportion to their widths, none below its least width
     * (Node::$minMw), what the frozen ones cannot give going round again.
     * A single-line text shrunk shows an ellipsis; a container shrunk
     * arranges its children in the width left, which shrinks theirs.
     * @param list<Node> $kids
     * @return array<int, float> by index into $kids
     */
    private static function shrink(array $kids, float $over): array
    {
        if ($over <= 0) { return []; }
        $give = []; $frozen = [];
        for ($pass = 0; $pass <= count($kids); $pass++) {
            $sum = 0.0; $taken = 0.0;
            foreach ($kids as $i => $k) { if (isset($frozen[$i])) { $taken += $give[$i]; } else { $sum += $k->mw * $k->shrink; } }
            $left = $over - $taken; // what the children not yet at their least width share
            if ($sum <= 0) { break; }
            $violation = false;
            foreach ($kids as $i => $k) {
                if (isset($frozen[$i])) { continue; }
                $give[$i] = max(0.0, $left) * $k->mw * $k->shrink / $sum;
                if ($give[$i] > $k->mw - $k->minMw) { $give[$i] = max(0.0, $k->mw - $k->minMw); $frozen[$i] = true; $violation = true; } // no further than its least width
            }
            if (!$violation) { break; }
        }
        return $give;
    }

    /**
     * The start and size of a node along an axis, in the room [$at, $at +
     * $room) its parent gives it with its margins: inside fixed margins, and
     * automatic ones (INF) sharing what the node, as large as it measured
     * ($measured, its fixed margins included), leaves (mygo's margins).
     * @return array{0: float, 1: float}
     */
    private static function inMargins(float $at, float $room, float $a, float $b, float $measured): array
    {
        $autoA = is_infinite($a); $autoB = is_infinite($b);
        $fixed = ($autoA ? 0 : $a) + ($autoB ? 0 : $b);
        if (!$autoA && !$autoB) {
            return [$at + $a, max(0.0, $room - $fixed)];
        }
        $size = min(max(0.0, $room - $fixed), max(0.0, $measured - $fixed));
        $free = max(0.0, $room - $fixed - $size);
        $start = $autoA ? ($autoB ? $free / 2 : $free) : $a;
        return [$at + $start, $size];
    }

    /** Where a child of $size sits across $room: at the start, in the middle, or at the end. */
    private static function across(int $align, float $room, float $size): float
    {
        return match ($align) { Node::CENTER => ($room - $size) / 2, Node::END => $room - $size, default => 0.0 };
    }
}
