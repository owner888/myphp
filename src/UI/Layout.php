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
        self::$window = [$w, $h];
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
            if ($p->scroll instanceof ListState && !$ch->absolute) { // a list's rows: the place moves by as much, counted from the first row built
                $l = $p->scroll;
                $d = self::nearest(0.0, $e->y - $p->y - $p->padT() - $l->lag, $e->h, $l->viewport);
                if ($d != 0) {
                    $l->offset += $d;
                    $l->aligning = null;
                    if ($l->offset < 0 && $l->first > 0) { $p->context?->runtime?->changed(); } // above the rows built: another pass builds them
                    self::arrange($p, $p->x, $p->y, $p->w, $p->h);
                }
                continue;
            }
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
        if ($n->colFit !== null) {
            $fit = $n->colFit;
            $n->colFit = null;
            $fit();
        }
        self::measureBox($n);
        if ($n->aspect > 0 || $n->maxW < INF || $n->maxH < INF) { // the side its aspect ratio sets, and the largest sizes
            if ($n->aspect > 0 && $n->fw > 0 && $n->fh <= 0) { $n->mh = max($n->fw / $n->aspect, $n->minH); }
            elseif ($n->aspect > 0 && $n->fh > 0 && $n->fw <= 0) { $n->mw = max($n->fh * $n->aspect, $n->minW); }
            $n->mw = max(min($n->mw, $n->maxW), $n->minW); $n->mh = max(min($n->mh, $n->maxH), $n->minH);
            $n->minMw = min($n->minMw, $n->mw);
        }
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
            $n->mw = array_sum($cols) + ($n->gapX ?? $n->gap) * max(0, count($cols) - 1) + $n->padL() + $n->padR();
            $n->mh = array_sum($rows) + ($n->gapY ?? $n->gap) * max(0, count($rows) - 1) + $n->padT() + $n->padB();
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
        $gap = $n->mainGap();
        $n->minMw = ($n->dir === Node::ROW ? $least + $gap * max(0, $cnt - 1) : $least) + $n->padL() + $n->padR();
        [$padMain, $padCross] = $n->dir === Node::ROW ? [$n->padL() + $n->padR(), $n->padT() + $n->padB()] : [$n->padT() + $n->padB(), $n->padL() + $n->padR()];
        $main += $gap * max(0, $cnt - 1) + $padMain;
        $cross += $padCross;
        if ($n->wraps && $n->wrapWidth > 0) { // a wrapping row: as wide as its widest line, as high as its lines
            $main = 0.0; $cross = $padCross;
            foreach (self::lines($n, $n->wrapWidth - $padMain) as $i => $line) {
                $lw = $gap * (count($line) - 1); $lh = 0.0;
                foreach ($line as $k) { $lw += $k->mw; $lh = max($lh, $k->mh); }
                $main = max($main, $lw + $padMain); $cross += $lh + ($i > 0 ? $n->crossGap() : 0);
            }
        }
        if ($n->scroll instanceof ListState && $n->dir === Node::COLUMN && ($n->maxH < INF || $n->sizedByRows)) {
            // A list in a scrolling container, or bounded by a largest height, is as high as its rows, the rest as estimated (mygo's list sized by its rows); elsewhere it takes the room it is given, as columns here shrink nothing.
            $l = $n->scroll;
            $main += max(0, $l->count - $l->first - $cnt) * ($l->estimate + $l->rowGap);
        } elseif ($n->scroll !== null) { // a scrolling container takes the room it is given on the axes it scrolls
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
        $n->laid = true;
        if ($n->kind === Node::RICH && $n->wrapWidth === null && ($n->rtl || ($n->textStyle()->align ?? Node::START) !== Node::START) && $w > $n->textLayout()->width + 0.5) {
            $n->alignWidth = $w; // stretched wider than its lines: laid out across the box, so that they go where the alignment and the direction put them (mygo lays a text out at its width)
            $n->layout = null;
        }
        if ($n->kind === Node::RICH && $n->kids !== []) {
            self::placeInline($n, $n, 0);
            return;
        }
        if ($n->kind !== Node::CONTAINER) return;
        $kids = [];
        foreach ($n->kids as $k) {
            if (!$k->absolute) {
                $kids[] = $k;
            } elseif ($k === $n->pinned) {
                // a list's header built out of the flow: placed with the rows
            } elseif ($k->attach === null && $k->insets === [null, null, null, null] && $k->wPct === null && $k->hPct === null) {
                self::arrange($k, $x + $n->padL() + $k->ax, $y + $n->padT() + $k->ay, $k->mw, $k->mh); // at(): from the content box, at its own size
            } else {
                $absolute[] = $k; // after the flow, which a node it is attached to may be in
            }
        }
        $cnt = count($kids);
        $px = $n->padL(); $py = $n->padT(); $pw = $px + $n->padR(); $ph = $py + $n->padB(); // the content's left and top, and the room the edges take
        $cw = $w - $pw; $ch = $h - $ph;
        if ($cnt === 0) {
            if ($n->scroll instanceof ScrollState) { // nothing to scroll: at the start (mygo's TrackScroll keeps the offset within the content)
                $s = $n->scroll;
                $s->x = $s->y = $s->maxX = $s->maxY = 0.0;
                $s->viewport = $ch; $s->viewportW = $cw;
            }
        } elseif ($n->gridCols !== null) { // a grid: the tracks sized in the room there is, each child stretched over its area or placed in it as justifyItems/justifySelf (across) and alignSelf (down) say
            [$cols, $rows, $items] = self::gridTracks($n, $cw, $ch);
            $gx = $n->gapX ?? $n->gap; $gy = $n->gapY ?? $n->gap;
            $colAt = []; $rowAt = []; // where each track starts
            for ($c = 0, $p = $x + $px; $c < count($cols); $c++) { $colAt[$c] = $p; $p += $cols[$c] + $gx; }
            for ($r = 0, $p = $y + $py; $r < count($rows); $r++) { $rowAt[$r] = $p; $p += $rows[$r] + $gy; }
            foreach ($items as [$k, $c, $r, $cs, $rs]) {
                $aw = $gx * ($cs - 1); for ($j = $c; $j < $c + $cs; $j++) { $aw += $cols[$j]; }
                $ah = $gy * ($rs - 1); for ($j = $r; $j < $r + $rs; $j++) { $ah += $rows[$j]; }
                $a = $k->self !== Node::AUTO ? $k->self : ($n->align !== Node::AUTO ? $n->align : Node::STRETCH); // alignSelf, else the grid's align (mygo's AlignItems), else stretched
                $j = $k->justifySelf !== Node::AUTO ? $k->justifySelf : ($n->justifyItems !== Node::AUTO ? $n->justifyItems : Node::STRETCH); // mygo's gridJustify
                $kh = $k->hPct !== null ? $k->hPct * $ah : ($a === Node::STRETCH && $k->fh <= 0 ? $ah : $k->mh); // a size of its own is kept (mygo: an explicit size is not stretched)
                $kw = $k->wPct !== null ? $k->wPct * $aw : ($j === Node::STRETCH && ($k->fw <= 0 || $k->kind === Node::TEXT) ? $aw : $k->mw); // a text's fw is what it measured, not a size given
                $kw = self::clampW($k, $kw, $cw); $kh = self::clampH($k, $kh, $ch);
                $kx = $colAt[$c] + self::across($j, $aw, $kw);
                if ($n->rtl) { $kx = 2 * ($x + $px) + $cw - $kx - $kw; } // right to left, the columns go from the right
                [$dx, $dy] = self::relative($k, $cw, $ch);
                self::arrange($k, $kx + $dx, $rowAt[$r] + self::across($a, $ah, $kh) + $dy, $kw, $kh);
            }
        } elseif ($n->wraps) { // each line is arranged as a row of its own, the lines stacked as alignContent says, from the bottom in reverse
            $lines = self::lines($n, $cw);
            $heights = [];
            foreach ($lines as $i => $line) { $heights[$i] = 0.0; foreach ($line as $k) { $heights[$i] = max($heights[$i], $k->mh); } }
            $gap = $n->crossGap();
            $free = $ch - array_sum($heights) - $gap * max(0, count($lines) - 1);
            [$start, $between] = [0.0, 0.0];
            if ($n->alignContent === Node::STRETCH && $free > 0) {
                foreach ($heights as $i => $lh) { $heights[$i] = $lh + $free / count($lines); }
            } elseif ($n->alignContent !== Node::AUTO && $n->alignContent !== Node::STRETCH) {
                [$start, $between] = self::justifyOffsets($n->alignContent, max($free, 0.0), count($lines));
            }
            $cy = $y + $py + $start;
            foreach ($lines as $i => $line) {
                $ly = $n->wrapReverse ? 2 * ($y + $py) + $ch - $cy - $heights[$i] : $cy;
                self::arrangeRow($n, $line, $x + $px, $ly, $cw, $heights[$i]);
                $cy += $heights[$i] + $gap + $between;
            }
        } else {
            self::arrangeFlex($n, $kids, $x, $y, $w, $h);
        }
        foreach ($absolute ?? [] as $k) {
            self::arrangeAbsolute($n, $k);
        }
    }

    /** Arranges the children of a row or column that does not wrap, $kids those in the flow: CSS flexbox's (mygo's flexLayout) within myphp's measures. @param list<Node> $kids */
    private static function arrangeFlex(Node $n, array $kids, float $x, float $y, float $w, float $h): void
    {
        $cnt = count($kids);
        $row = $n->dir === Node::ROW;
        $gap = $n->mainGap();
        $px = $n->padL(); $py = $n->padT(); $pw = $px + $n->padR(); $ph = $py + $n->padB();
        $cw = $w - $pw; $ch = $h - $ph;
        $viewW = $cw; // the box a right-to-left node mirrors its children in
        $wide = $n->scroll instanceof ListState && $n->rowMinW > 0;
        if ($wide) { $cw = max($cw, $n->rowMinW); } // rows wider than the list scroll sideways (mygo's RowWidth)
        $avail = ($row ? $cw : $ch) - $gap * ($cnt - 1);
        $align = $n->align === Node::AUTO ? ($row ? Node::CENTER : Node::STRETCH) : $n->align;
        // Each child's size along the axis to start from, its margins included, and the least and largest it may take.
        $base = []; $lo = []; $hi = []; $used = 0.0; $grow = 0.0; $crossMax = 0.0; $cross = [];
        foreach ($kids as $i => $k) {
            [$mx, $my] = self::fixedMargins($k);
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($row) {
                $b = $k->basisPct !== null ? $k->basisPct * $cw : ($k->basis ?? ($k->wPct !== null ? $k->wPct * $cw + $mx : $k->mw));
                $lo[$i] = $k->minWPct !== null ? $k->minWPct * $cw + $mx : $k->minMw;
                $hi[$i] = ($k->maxWPct !== null ? min($k->maxWPct * $cw, $k->maxW) : $k->maxW) + $mx;
            } else {
                // A column's child is as wide as it is across first: its height may follow from its width (aspectRatio).
                $cross[$i] = self::crossWidth($k, $a, $cw);
                $b = $k->basisPct !== null ? $k->basisPct * $ch : ($k->basis ?? ($k->hPct !== null ? $k->hPct * $ch + $my : ($k->aspect > 0 && $k->fh <= 0 ? ($cross[$i] - $mx) / $k->aspect + $my : $k->mh)));
                $lo[$i] = $k->minHPct !== null ? $k->minHPct * $ch + $my : $b; // a column's children keep their heights where it overflows
                $hi[$i] = ($k->maxHPct !== null ? min($k->maxHPct * $ch, $k->maxH) : $k->maxH) + $my;
                $lo[$i] = min($lo[$i], $hi[$i]);
            }
            $base[$i] = $b;
            $used += max($lo[$i], min($b, $hi[$i]));
            $crossMax = max($crossMax, $row ? $k->mh : $k->mw);
            $grow += $k->grow;
        }
        $cx = $x + $px; $cy = $y + $py;
        $scrollRtl = null;
        $flexible = true;
        if ($n->scroll instanceof ScrollState) {
            $s = $n->scroll;
            $contentMain = $used + $gap * ($cnt - 1);
            [$contentW, $contentH] = $row ? [$contentMain, $crossMax] : [$crossMax, $contentMain];
            if ($n->scrollY) {
                $s->maxY = max(0.0, $contentH - $ch);
                $s->viewport = $ch;
                $s->y = max(0.0, min($s->y, $s->maxY));
                $cy -= $s->y;
                if ($row) { $ch = max($ch, $contentH); } // children get their own height
            }
            if ($n->scrollX) {
                $s->maxX = max(0.0, $contentW - $cw);
                $s->viewportW = $cw;
                $s->x = max(0.0, min($s->x, $s->maxX));
                if ($n->rtl) {
                    $scrollRtl = $s->x; // right to left, the content starts at the right, the offset counting from there: placed mirrored, then moved by it
                } else {
                    $cx -= $s->x;
                }
                if (!$row) { $cw = max($cw, $contentW); }
            }
            if (!$n->scrollY) { $s->y = $s->maxY = 0.0; } // the axis it does not scroll stays at its start
            if (!$n->scrollX) { $s->x = $s->maxX = 0.0; }
            if ($row ? $n->scrollX : $n->scrollY) {
                $flexible = false; // nothing grows or shrinks along a scrolled axis
            }
        } elseif ($n->scroll instanceof ListState) {
            $s = $n->scroll;
            $wasViewport = $s->viewport;
            $s->viewport = $ch;
            $s->rowGap = $gap;
            if ($wide) {
                $s->maxX = max(0.0, $cw - $viewW);
                $s->viewportW = $viewW;
                $s->x = min($s->x, $s->maxX);
                if ($n->rtl) { $scrollRtl = $s->x; } else { $cx -= $s->x; }
            } else {
                $s->x = 0.0;
            }
            if ($s->count > 0) { // without rows, what the list shows instead is no row
                foreach ($kids as $i => $k) {
                    $s->measured($s->first + $i, $k->mh);
                }
            }
            if ($s->first !== $s->row) { // the place on a row below the first built (rows built above it, or a move after the build): it counts from the first, measured now
                $k = $s->row - $s->first;
                if ($k > 0 && $k < count($kids)) {
                    for ($i = 0; $i < $k; $i++) { $s->offset += $kids[$i]->mh + $gap; }
                    $s->row = $s->first;
                } else {
                    $n->context?->runtime?->changed(); // moved past the rows built: another pass builds them
                }
            }
            if ($s->aligning !== null && isset($kids[$k = $s->aligning[0] - $s->first])) { // a row to show in the middle or at the bottom, measured now (mygo's ScrollTo)
                $top = 0.0; $all = 0.0;
                foreach ($kids as $i => $kid) { if ($i < $k) { $top += $kid->mh + $gap; } $all += $kid->mh + ($i > 0 ? $gap : 0); }
                if ($s->aligning[1] === Node::START) { // under the header of its section, pinned at the top
                    $hk = $s->headerAbove($s->aligning[0]) - $s->first;
                    $off = $top - ($kids[$hk]->mh ?? $n->pinned?->mh ?? 0.0);
                } else {
                    $off = $top - ($ch - $kids[$k]->mh) * ($s->aligning[1] === Node::CENTER ? 0.5 : 1.0);
                }
                if ($s->first + $cnt >= $s->count) { $off = min($off, $all - $ch); } // no room below the last row
                if ($s->first === 0) { $off = max($off, 0.0); }
                if ($s->glides && isset($kids[$j = $s->shownRow - $s->first])) { // the end moved: the rows stay where they showed, and glide up from there
                    $top2 = 0.0;
                    for ($i = 0; $i < $j; $i++) { $top2 += $kids[$i]->mh + $gap; }
                    $d = $s->shownTop - ($top2 - $off) + ($wasViewport > 0 ? $ch - $wasViewport : 0.0); // the end moving in the list, not the list's bottom moving (mygo's GlideOnShrink, GlideOnWindowResize)
                    if (abs($d) > 0.01 && !$s->went) { $s->lag += $d; $s->grew = true; }
                }
                [$s->offset, $s->row, $s->aligning] = [$off, $s->first, null];
                if ($off < 0) { $n->context?->runtime?->changed(); } // rows above short of the estimates: another pass builds them
            }
            [$s->shownRow, $s->shownTop] = [$s->first, -$s->offset];
            // The view's top lies $offset below the anchor's top; built rows
            // short of the view's bottom leave a gap this frame.
            $cy -= $s->offset - $s->lag; // a glide to the end on its way: below its place
            $s->gap = max(0.0, $ch - ($used + $gap * ($cnt - 1) - $s->offset));
            $s->atEnd = $s->first + $cnt >= $s->count && $used + $gap * ($cnt - 1) - $s->offset <= $ch + 0.5;
            if ($s->gap > 0 && $s->first + $cnt < $s->count) {
                $n->context?->runtime?->changed(); // rows far shorter than estimated fell short of the view: another pass builds more, so the frame shows no gap (mygo's buildLate)
            }
            if ($s->first + $cnt >= $s->count && $s->first === 0) {
                $s->gap = 0.0; // every row is built and they are short of the view: nothing can fill it
                $short = $ch - ($used + $gap * ($cnt - 1));
                if ($short > 0 && ($n->justify === Node::END || $n->justify === Node::CENTER)) {
                    $cy += $n->justify === Node::END ? $short : $short / 2; // rows short of the view at its end, as a chat's (mygo's Justify)
                }
            }
            $flexible = false;
        }
        [$rowX, $rowW] = [$cx, $cw]; // where a list's rows start across, and their width
        $f = $n->followX?->scroll;
        if ($f instanceof ScrollState || $f instanceof ListState) { // the content goes sideways with another's (mygo's ScrollWith)
            if ($n->rtl) { $scrollRtl = ($scrollRtl ?? 0.0) + $f->x; } else { $cx -= $f->x; }
        }
        // The sizes along the axis: grown into the room left, or, in a row, shrunk where they overflow it, none past its least or largest (mygo's resolveFlexible).
        $main = [];
        foreach ($kids as $i => $k) { $main[$i] = max($lo[$i], min($base[$i], $hi[$i])); }
        $growing = $used < $avail;
        if ($flexible && (($growing && $grow > 0) || (!$growing && $row && $n->scroll === null))) {
            $main = self::flexible($kids, $base, $lo, $hi, $avail, $growing);
        }
        $sum = array_sum($main);
        // The automatic margins along the axis take the free space, before justify does.
        $autos = []; $autoCount = 0;
        if ($n->scroll === null) {
            foreach ($kids as $i => $k) {
                $a = $row ? (int)is_infinite($k->margins[3]) + (int)is_infinite($k->margins[1]) : (int)is_infinite($k->margins[0]) + (int)is_infinite($k->margins[2]);
                if ($a > 0) { $autos[$i] = $a; $autoCount += $a; }
            }
        }
        $between = 0.0;
        $free = max(0.0, $avail - $sum);
        if ($autoCount > 0) {
            foreach ($autos as $i => $a) { $autos[$i] = $a * $free / $autoCount; }
        } elseif ($n->scroll === null) { // justify packs the children along the axis
            [$start, $between] = self::justifyOffsets($n->justify, $free, $cnt);
            if ($row) { $cx += $start; } else { $cy += $start; }
        }
        foreach ($kids as $i => $k) {
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($row ? $k->mh <= 0 : $k->mw <= 0) {
                $a = Node::STRETCH; // no size of its own across (a text area given 0, an empty box): it takes the room
            }
            $autoCross = $row ? is_infinite($k->margins[0]) || is_infinite($k->margins[2]) : is_infinite($k->margins[1]) || is_infinite($k->margins[3]); // automatic margins across place it themselves, in all the room
            [$mx, $my] = self::fixedMargins($k);
            [$dx, $dy] = self::relative($k, $cw, $ch);
            if ($row) {
                $kw = $main[$i] + ($autos[$i] ?? 0.0);
                if ($k->hPct !== null) {
                    $kh = $k->hPct * $ch + $my;
                } elseif ($k->aspect > 0 && $k->fh <= 0) {
                    $kh = ($main[$i] - $mx) / $k->aspect + $my;
                } else {
                    $kh = $autoCross || ($a === Node::STRETCH && $k->fh <= 0) ? $ch : $k->mh; // a size of its own is kept (mygo: an explicit size is not stretched)
                }
                $kh = self::clampH($k, $kh, $ch);
                // Reversed, or right to left (not both), the same place counted from the right.
                $at = $n->reverse !== $n->rtl ? 2 * ($x + $px) + $viewW - $cx - $kw + ($scrollRtl ?? 0.0) : $cx;
                self::arrange($k, $at + $dx, $cy + ($autoCross ? 0.0 : self::across($a, $ch, $kh)) + $dy, $kw, $kh);
                $cx += $kw + $gap + $between;
            } else {
                $kh = $main[$i] + ($autos[$i] ?? 0.0);
                $kw = $cross[$i];
                $at = $n->reverse ? 2 * ($y + $py) + $ch - $cy - $kh : $cy; // from the bottom
                $kx = $cx + ($autoCross ? 0.0 : self::across($a, $cw, $kw));
                if ($n->rtl) { $kx = 2 * ($x + $px) + $viewW - $kx - $kw + ($scrollRtl ?? 0.0); } // right to left, across from the right
                self::arrange($k, $kx + $dx, $at + $dy, $kw, $kh);
                $cy += $kh + $gap + $between;
            }
        }
        if ($n->pinned !== null && $n->scroll instanceof ListState) {
            // The header of the section at the top stays there while its rows scroll under it, until the next header pushes it away (mygo's pinHeader).
            $p = $n->pinned;
            [$px0, $pw0, $ph0, $flowY] = $p->absolute ? [$rowX, $rowW, $p->mh, -INF] : [$p->x, $p->w, $p->h, $p->y];
            $stop = $n->pinStop !== null ? $n->pinStop->y - $ph0 : INF;
            self::arrange($p, $px0, max($flowY, min($y + $py, $stop)), $pw0, $ph0);
        }
    }

    /** The width a column's child takes, its margins included: its percentage of the column, stretched across it, or its own, within its least and largest. */
    private static function crossWidth(Node $k, int $a, float $cw): float
    {
        if ($k->mw <= 0) { $a = Node::STRETCH; }
        [$mx] = self::fixedMargins($k);
        $autoCross = is_infinite($k->margins[1]) || is_infinite($k->margins[3]);
        if ($k->wPct !== null) {
            $kw = $k->wPct * $cw + $mx;
        } elseif ($k->aspect > 0 && $k->fw <= 0 && $k->fh > 0) {
            $kw = $k->fh * $k->aspect + $mx;
        } else {
            $kw = $autoCross || ($a === Node::STRETCH && ($k->fw <= 0 || $k->kind === Node::TEXT || $k->kind === Node::RICH)) ? $cw : ($k->single ? min($k->mw, $cw) : $k->mw);
        }
        return self::clampW($k, $kw, $cw);
    }

    /** The fixed margins of $k across and down, automatic ones as none. @return array{0: float, 1: float} */
    private static function fixedMargins(Node $k): array
    {
        [$t, $r, $b, $l] = $k->margins;
        $f = fn(float $m): float => is_finite($m) ? $m : 0.0;
        return [$f($l) + $f($r), $f($t) + $f($b)];
    }

    /** $kw, an outer width (margins included), within $k's least and largest widths against a content box $cw wide. */
    private static function clampW(Node $k, float $kw, float $cw): float
    {
        if ($k->maxW === INF && $k->maxWPct === null && $k->minWPct === null) { return $kw; }
        [$mx] = self::fixedMargins($k);
        $max = min($k->maxW, $k->maxWPct !== null ? $k->maxWPct * $cw : INF);
        $min = max($k->minSet ? $k->minW : 0.0, $k->minWPct !== null ? $k->minWPct * $cw : 0.0);
        return max(min($kw - $mx, $max), $min) + $mx;
    }

    private static function clampH(Node $k, float $kh, float $ch): float
    {
        if ($k->maxH === INF && $k->maxHPct === null && $k->minHPct === null) { return $kh; }
        [, $my] = self::fixedMargins($k);
        $max = min($k->maxH, $k->maxHPct !== null ? $k->maxHPct * $ch : INF);
        $min = max($k->minH, $k->minHPct !== null ? $k->minHPct * $ch : 0.0);
        return max(min($kh - $my, $max), $min) + $my;
    }

    /**
     * Grows the children into $space, or shrinks them where they overflow
     * it, none past its least ($lo) or largest ($hi) size (CSS flexbox
     * §9.7, mygo's resolveFlexible): growing by their grow factors,
     * shrinking by their shrink factors times their sizes; those held at a
     * bound leave the rest to share what is left, going round again.
     * @param list<Node> $kids @param array<int, float> $base @param array<int, float> $lo @param array<int, float> $hi
     * @return array<int, float>
     */
    private static function flexible(array $kids, array $base, array $lo, array $hi, float $space, bool $growing): array
    {
        $main = []; $frozen = [];
        foreach ($kids as $i => $k) {
            $hyp = max($lo[$i], min($base[$i], $hi[$i]));
            $factor = $growing ? $k->grow : $k->shrink * $base[$i];
            $main[$i] = $hyp;
            if ($factor == 0 || ($growing && $base[$i] > $hyp) || (!$growing && $base[$i] < $hyp)) { $frozen[$i] = true; }
        }
        for ($round = 0, $n = count($kids); $round <= $n; $round++) {
            $free = $space; $sum = 0.0;
            foreach ($kids as $i => $k) {
                if (isset($frozen[$i])) { $free -= $main[$i]; } else { $free -= $base[$i]; $sum += $growing ? $k->grow : $k->shrink * $base[$i]; }
            }
            if ($sum == 0) { break; }
            $violation = 0.0;
            foreach ($kids as $i => $k) {
                if (isset($frozen[$i])) { continue; }
                $v = $base[$i] + $free * ($growing ? $k->grow : $k->shrink * $base[$i]) / $sum;
                $clamped = max($lo[$i], min($v, $hi[$i]));
                $violation += $clamped - $v;
                $main[$i] = $clamped;
            }
            if (abs($violation) < 1e-9) { break; }
            foreach ($kids as $i => $k) {
                if (isset($frozen[$i])) { continue; }
                if (($violation > 0 && $main[$i] == $lo[$i]) || ($violation < 0 && $main[$i] == $hi[$i])) { $frozen[$i] = true; }
            }
        }
        return $main;
    }

    /** Where the first of $n children goes, and the room added between them, for $free room along the axis (mygo's justifyOffsets). @return array{0: float, 1: float} */
    private static function justifyOffsets(int $j, float $free, int $n): array
    {
        return match ($j) {
            Node::CENTER => [$free / 2, 0.0],
            Node::END => [$free, 0.0],
            Node::SPACE_BETWEEN => $n > 1 ? [0.0, $free / ($n - 1)] : [0.0, 0.0],
            Node::SPACE_AROUND => $n > 0 ? [$free / $n / 2, $free / $n] : [0.0, 0.0],
            Node::SPACE_EVENLY => [$free / ($n + 1), $free / ($n + 1)],
            default => [0.0, 0.0],
        };
    }

    /** How far the insets of a node in the flow move it from where the layout put it, in a content box $cw × $ch: left over right, unless right to left (mygo's relative). @return array{0: float, 1: float} */
    private static function relative(Node $k, float $cw, float $ch): array
    {
        if ($k->insets === [null, null, null, null]) { return [0.0, 0.0]; }
        [$t, $r, $b, $l] = $k->insets;
        $v = fn(?array $i, float $base): float => $i[1] ? $i[0] * $base : $i[0];
        $dx = $l !== null && !($r !== null && $k->rtl) ? $v($l, $cw) : ($r !== null ? -$v($r, $cw) : 0.0);
        $dy = $t !== null ? $v($t, $ch) : ($b !== null ? -$v($b, $ch) : 0.0);
        return [$dx, $dy];
    }

    /**
     * Places an absolute child $k of $n in $n's padding box (mygo's
     * layoutAbsolute): its insets, both of a pair setting its size between
     * them, else its own size; right to left, one with neither left nor
     * right goes to the right. One attached goes on a point of the box, or
     * of its target's (attach, attachTo).
     */
    private static function arrangeAbsolute(Node $n, Node $k): void
    {
        $bx = $n->x + $n->borders[3]; $by = $n->y + $n->borders[0];
        $pw = $n->w - $n->borders[1] - $n->borders[3]; $ph = $n->h - $n->borders[0] - $n->borders[2];
        [$mx, $my] = self::fixedMargins($k);
        $m = array_map(fn(float $v): float => is_finite($v) ? $v : 0.0, $k->margins);
        [$t, $r, $b, $l] = $k->insets;
        $v = fn(array $i, float $base): float => $i[1] ? $i[0] * $base : $i[0];
        $iw = $k->wPct !== null ? $k->wPct * $pw : ($k->attach === null && $l !== null && $r !== null && $k->fw <= 0 ? $pw - $v($l, $pw) - $v($r, $pw) - $mx : $k->mw - $mx);
        if ($k->aspect > 0 && $k->hPct !== null && $k->wPct === null && $k->fw <= 0) { $iw = $k->hPct * $ph * $k->aspect; }
        $iw = self::clampW($k, $iw + $mx, $pw) - $mx;
        $ih = $k->hPct !== null ? $k->hPct * $ph : ($k->attach === null && $t !== null && $b !== null && $k->fh <= 0 ? $ph - $v($t, $ph) - $v($b, $ph) - $my : ($k->aspect > 0 && $k->fh <= 0 ? $iw / $k->aspect : $k->mh - $my));
        $ih = self::clampH($k, $ih + $my, $ph) - $my;
        if ($k->attach !== null) {
            [$at, $self] = $k->attach;
            [$ax, $ay] = $at->physical($k->rtl)->fractions();
            [$sx, $sy] = $self->physical($k->rtl)->fractions();
            [$dx, $dy] = self::relative($k, $pw, $ph);
            if ($k->attachTarget !== null) { // a popover of another node: on it, in the window
                $tg = $k->attachTarget;
                [$tx, $ty, $tw, $th] = $tg->laid ? self::shownIn($n, $tg) : ($tg->context?->runtime?->rectOf($tg->id) ?? [0.0, 0.0, 0.0, 0.0]); // as it shows, through its transforms, which the popover does not take
                $kx = self::alongTarget($tx, $tw, $ax, $sx, $iw, $m[3] - $m[1], self::$window[0]);
                $ky = self::alongTarget($ty, $th, $ay, $sy, $ih, $m[0] - $m[2], self::$window[1]);
                self::arrange($k, $kx + $dx - $m[3], $ky + $dy - $m[0], $iw + $mx, $ih + $my);
            } else {
                self::arrange($k, $bx + $ax * $pw - $sx * ($iw + $mx) + $dx, $by + $ay * $ph - $sy * ($ih + $my) + $dy, $iw + $mx, $ih + $my);
            }
            return;
        }
        $kx = $l !== null ? $v($l, $pw) : 0.0;
        if ($l === null && ($r !== null || $k->rtl)) {
            $kx = $pw - ($r !== null ? $v($r, $pw) : 0.0) - $iw - $mx;
        } elseif ($l !== null && $r !== null) { // automatic margins share the room left between the insets
            $free = $pw - $v($l, $pw) - $v($r, $pw) - $iw - $mx;
            $autoL = is_infinite($k->margins[3]); $autoR = is_infinite($k->margins[1]);
            if ($autoL && $autoR) { $kx += max($free, 0.0) / 2; } elseif ($autoL) { $kx += max($free, 0.0); } elseif (!$autoR && $k->rtl) { $kx += $free; }
        }
        $ky = $t !== null ? $v($t, $ph) : 0.0;
        if ($t === null && $b !== null) {
            $ky = $ph - $v($b, $ph) - $ih - $my;
        } elseif ($t !== null && $b !== null) {
            $free = $ph - $v($t, $ph) - $v($b, $ph) - $ih - $my;
            $autoT = is_infinite($k->margins[0]); $autoB = is_infinite($k->margins[2]);
            if ($autoT && $autoB) { $ky += max($free, 0.0) / 2; } elseif ($autoT) { $ky += max($free, 0.0); }
        }
        self::arrange($k, $bx + $kx, $by + $ky, $iw + $mx, $ih + $my);
    }

    /** The box target $tg, laid out under $from, shows over in the window, through its transforms and those around it (mygo's visual bounds). @return array{0: float, 1: float, 2: float, 3: float} */
    private static function shownIn(Node $from, Node $tg): array
    {
        $world = self::worldTo($from, $tg, Affine::identity());
        return $world !== null && $world->set ? $world->bounds($tg->x, $tg->y, $tg->w, $tg->h) : [$tg->x, $tg->y, $tg->w, $tg->h];
    }

    /** The map from $tg's layout to the window, found under $n whose ancestors' map is $above, or null when $tg is not under it. */
    private static function worldTo(Node $n, Node $tg, Affine $above): ?Affine
    {
        $w = $above->mul($n->transformAt());
        if ($n === $tg) { return $w; }
        foreach ($n->kids as $k) {
            if (($found = self::worldTo($k, $tg, $w)) !== null) { return $found; }
        }
        return null;
    }

    /** The window's size as the layout goes: what attachTo keeps popovers in. @var array{0: float, 1: float} */
    private static array $window = [INF, INF];

    /** The window's size, the layout's: what attachTo keeps popovers in. */
    public static function window(float $w, float $h): void
    {
        self::$window = [$w, $h];
    }

    /**
     * Where a box $size long goes along an axis of the window, $limit long,
     * its point at fraction $self on the target's (from $t0, $tsize long)
     * at fraction $at, moved by $off: on the other side of the target, or
     * the other way along it, where that overflows the window less, then
     * moved into it, 4 points from its edges (mygo's alongTarget).
     */
    private static function alongTarget(float $t0, float $tsize, float $at, float $self, float $size, float $off, float $limit): float
    {
        $margin = 4.0;
        $pos = fn(float $at, float $self, float $off): float => $t0 + $at * $tsize - $self * $size + $off;
        $over = fn(float $p): float => max(0.0, $margin - $p) + max(0.0, $p + $size - ($limit - $margin));
        $p = $pos($at, $self, $off);
        if ($over($p) > 0) {
            $q = $pos(1 - $at, 1 - $self, -$off);
            if ($over($q) < $over($p)) { $p = $q; }
        }
        $beside = ($at == 1 && $self == 0) || ($at == 0 && $self == 1);
        if (!$beside && $over($p) > 0) { $p = max($margin, min($p, $limit - $margin - $size)); }
        return $p;
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
        $cols = self::sizeTracks($n->gridCols, $nc, $availW, $n->gapX ?? $n->gap, array_map(fn($it) => [$it[1], $it[3], max($it[0]->mw, $it[0]->minW)], $items)); // at least its least width
        $rows = self::sizeTracks($n->gridRows, $nr, $availH, $n->gapY ?? $n->gap, array_map(fn($it) => [$it[2], $it[4], max($it[0]->mh, $it[0]->minH)], $items));
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
        $lines = []; $line = []; $used = 0.0; $gap = $n->mainGap();
        foreach ($n->kids as $k) {
            if ($k->absolute) { continue; }
            if ($line !== [] && $used + $gap + $k->mw > $avail) { $lines[] = $line; $line = []; $used = 0.0; }
            $used += ($line === [] ? 0 : $gap) + $k->mw; $line[] = $k;
        }
        if ($line !== []) { $lines[] = $line; }
        return $lines;
    }

    /** Arranges one line of a wrapping row across ($x, $y, $w, $h): growing children share what is left, the others shrink where it overflows, and justify packs them, as the row aligns them across. */
    private static function arrangeRow(Node $n, array $line, float $x, float $y, float $w, float $h): void
    {
        $gap = $n->mainGap();
        $base = []; $lo = []; $hi = []; $used = 0.0; $grow = 0.0;
        foreach ($line as $i => $k) {
            [$mx] = self::fixedMargins($k);
            $base[$i] = $k->basisPct !== null ? $k->basisPct * $w : ($k->wPct !== null ? $k->wPct * $w + $mx : $k->mw);
            $lo[$i] = $k->minWPct !== null ? $k->minWPct * $w + $mx : $k->minMw;
            $hi[$i] = ($k->maxWPct !== null ? min($k->maxWPct * $w, $k->maxW) : $k->maxW) + $mx;
            $used += max($lo[$i], min($base[$i], $hi[$i])); $grow += $k->grow;
        }
        $avail = $w - $gap * (count($line) - 1);
        $growing = $used < $avail;
        $main = ($growing && $grow > 0) || !$growing ? self::flexible($line, $base, $lo, $hi, $avail, $growing) : array_map(fn($i) => max($lo[$i], min($base[$i], $hi[$i])), array_keys($line));
        [$cx, $between] = self::justifyOffsets($n->justify, max(0.0, $avail - array_sum($main)), count($line));
        $cx += $x;
        $align = $n->align === Node::AUTO ? Node::CENTER : $n->align;
        foreach ($line as $i => $k) {
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($k->mh <= 0) { $a = Node::STRETCH; }
            $kw = $main[$i];
            $kh = self::clampH($k, $k->hPct !== null ? $k->hPct * $h : ($a === Node::STRETCH && $k->fh <= 0 ? $h : $k->mh), $h);
            [$dx, $dy] = self::relative($k, $w, $h);
            self::arrange($k, ($n->rtl ? 2 * $x + $w - $cx - $kw : $cx) + $dx, $y + self::across($a, $h, $kh) + $dy, $kw, $kh); // right to left, from the right
            $cx += $kw + $gap + $between;
        }
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
