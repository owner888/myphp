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
        [$px, $py] = $para->textOrigin();
        foreach ($e->kids as $k) {
            $a = $offset + $k->runes[0]; $b = $offset + $k->runes[1];
            $k->frags = [];
            $x0 = $y0 = INF; $x1 = $y1 = -INF;
            foreach ($l->appendSelection([], $a, $b, false) as [$rx, $ry, $rw, $rh]) {
                $k->frags[] = [$px + $rx, $py + $ry, $rw, $rh];
                $x0 = min($x0, $px + $rx); $y0 = min($y0, $py + $ry);
                $x1 = max($x1, $px + $rx + $rw); $y1 = max($y1, $py + $ry + $rh);
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
        self::$metrics = $c->metrics();
        self::$aligned = [];
        self::measure($c->root);
        self::arrange($c->root, 0, 0, $w, $h);
        if (self::wrapTexts($c->root)) { // texts arranged narrower than their lines wrap at the width they got, as high as their lines then (mygo's text wrapping at its width)
            // A list's row aligned again, from where it showed, as high as its lines wrap: a list following its end keeps it in view as a reply grows past the list.
            foreach (self::$aligned as [$s, $aligning, $row, $top, $lag, $grew]) {
                [$s->aligning, $s->shownRow, $s->shownTop, $s->lag, $s->grew] = [$aligning, $row, $top, $lag, $grew];
            }
            self::measure($c->root);
            self::arrange($c->root, 0, 0, $w, $h);
        }
        $ids = $c->runtime?->takeReveals($c->reveal) ?? $c->reveal;
        if ($ids !== []) {
            self::revealAll($c->root, $ids);
        }
    }

    /** Has the texts under $n arranged narrower than their lines wrap at the width they got, laid out by the text system; whether one did. */
    private static function wrapTexts(Node $n): bool
    {
        if ($n->memo === 2) { return false; }
        if ($n->wrapsAtWidth() && $n->w > 0 && $n->w + 0.5 < $n->mw) {
            if ($n->kind === Node::TEXT) {
                [$ow, $oh] = $n->metrics->measure($n->text ?? '', $n->fontSize, $n->style); // as text() measured it: a size given to it stays
                $n->toRich();
                if ($n->fw == $ow) { $n->fw = 0.0; }
                if ($n->fh == $oh) { $n->fh = 0.0; }
            } else {
                $l = $n->textLayout();
                if ($n->fw == $l->width) { $n->fw = 0.0; }
                if ($n->fh == $l->height) { $n->fh = 0.0; }
            }
            $n->wrapWidth = $n->w - $n->padL() - $n->padR(); $n->autoWrap = true; $n->layout = null; // inside its padding
            return true;
        }
        $wrapped = false;
        foreach ($n->kids as $k) { $wrapped = self::wrapTexts($k) || $wrapped; }
        return $wrapped;
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
     * paragraph shows by its paragraph; a node with an app's text input
     * client (a code editor), by its caret's box, as the client gives it:
     * the text it edits may be taller than the view, as an editor as high
     * as its lines in a scroll view (mygo's clientCaret). Fields reveal
     * their whole box, and scroll their caret into view themselves.
     * @param non-empty-list<Node> $chain
     */
    private static function reveal(array $chain): void
    {
        while (count($chain) > 1 && $chain[count($chain) - 1]->inline) {
            array_pop($chain);
        }
        $e = $chain[count($chain) - 1];
        [$rx, $ry, $rw, $rh] = self::clientCaret($e) ?? [0.0, 0.0, $e->w, $e->h]; // what shows, from the node's corner
        for ($i = count($chain) - 2; $i >= 0; $i--) {
            $p = $chain[$i]; $ch = $chain[$i + 1];
            if ($p->scroll instanceof ListState && !$ch->absolute) { // a list's rows: the place moves by as much, counted from the first row built
                $l = $p->scroll;
                $d = self::nearest(0.0, $e->y + $ry - $p->y - $p->padT() - $l->lag, $rh, $l->viewport);
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
                $sx = max(0.0, min(self::nearest($s->x, $e->x + $rx - $p->x - $p->padL() + $s->x, $rw, $s->viewportW), $s->maxX));
            }
            if ($p->scrollY) {
                $sy = max(0.0, min(self::nearest($s->y, $e->y + $ry - $p->y - $p->padT() + $s->y, $rh, $s->viewport), $s->maxY));
            }
            if ($sx != $s->x || $sy != $s->y) {
                $s->x = $sx;
                $s->scrollTo($sy, false);
                self::arrange($p, $p->x, $p->y, $p->w, $p->h); // what is inside it moves with the offset
            }
        }
    }

    /** The box of the caret of a node with an app's text input client, [x, y, w, h] from its corner, as the client places it, or null. @return ?array{0: float, 1: float, 2: float, 3: float} */
    private static function clientCaret(Node $e): ?array
    {
        if ($e->textClient === null) {
            return null;
        }
        $at = $e->textClient->selection()->caret();
        $b = $e->textClient->boundsForRange(new TextInputRange($at, $at));
        if ($b === null || $b[0][3] <= 0) {
            return null;
        }
        [$x, $y, $w, $h] = $b[0];
        return [$x, $y, max($w, 1.0), $h];
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
        if ($n->memo === 2) { return; } // a memo placed again: measured as it was (Context::memo)
        if ($n->colFit !== null) {
            $fit = $n->colFit;
            $n->colFit = null;
            $fit();
        }
        [$pw, $ph] = $n->padSums(); $n->padW = $pw; $n->padH = $ph;
        self::measureBox($n);
        if ($n->mw < $pw) { $n->mw = $pw; } // no smaller than its padding and border, whatever its size says, as in CSS
        if ($n->mh < $ph) { $n->mh = $ph; }
        if ($n->natW < $pw) { $n->natW = $pw; }
        if ($n->natH < $ph) { $n->natH = $ph; }
        if ($n->aspect > 0 || $n->maxW < INF || $n->maxH < INF) { // the side its aspect ratio sets, and the largest sizes
            if ($n->aspect > 0 && $n->fw > 0 && $n->fh <= 0) { $n->mh = max($n->fw / $n->aspect, $n->minH); }
            elseif ($n->aspect > 0 && $n->fh > 0 && $n->fw <= 0) { $n->mw = max($n->fh * $n->aspect, $n->minW); }
            $n->mw = max(min($n->mw, $n->maxW), $n->minW, $pw); $n->mh = max(min($n->mh, $n->maxH), $n->minH, $ph);
            $n->minMw = min($n->minMw, $n->mw);
        }
        [$mt, $mr, $mb, $ml] = $n->margins;
        if ($mt != 0 || $mr != 0 || $mb != 0 || $ml != 0) { // an automatic margin asks for none
            $mx = $n->marW = (is_finite($ml) ? $ml : 0.0) + (is_finite($mr) ? $mr : 0.0);
            $n->mw += $mx; $n->minMw += $mx;
            $n->mh += $n->marH = (is_finite($mt) ? $mt : 0.0) + (is_finite($mb) ? $mb : 0.0);
        } else {
            $n->marW = $n->marH = 0.0;
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
        } elseif ($n->kind === Node::BUTTON && $n->metrics !== null) {
            if ($n->madePad !== null && $n->pads !== null) { self::repad($n); }
            if ($n->ts !== null) { self::restyle($n); } // a label in a style of its own, as mygo's B, I, U and S buttons
            $n->metrics = null;
        } elseif (($n->kind === Node::INPUT || $n->kind === Node::TEXTAREA) && $n->metrics !== null) {
            if ($n->ts !== null) { self::restyleField($n); } // a field in a style of its own: a launcher's query in 22 points (mygo's FontSize on a TextInput)
            if ($n->kind === Node::INPUT && $n->pads !== null && $n->bordered) { // its own padding (mygo's on a TextInput): as high as its line and that
                $n->fh = $n->metrics->measure('X', $n->fontSize, $n->edit?->style ?? $n->style)[1] + $n->padT() + $n->padB();
            }
            $n->metrics = null;
        }
        if ($n->kind !== Node::CONTAINER) {
            $n->mw = $n->fw;
            $n->mh = $n->fh;
            $n->minMw = $n->single ? $n->minW : ($n->wrapsAtWidth() ? min($n->fw, $n->minContentWidth()) : $n->fw); // a single-line text shrinks to its min width (and shows an ellipsis), one that wraps to its widest word; other leaves keep their size
            if (($n->kind === Node::TEXT || $n->kind === Node::RICH) && !$n->inline) { // a text's padding and border around its lines (mygo's)
                $px = $n->padW; $py = $n->padH;
                $n->mw += $px; $n->mh += $py; $n->minMw += $px;
            }
            $n->natW = $n->mw; $n->natH = $n->mh;
            $n->mw = max($n->mw, $n->minW); $n->mh = max($n->mh, $n->minH); // a leaf's least size too, as a slider's (mygo's MinWidth)
            if ($n->minSet) { $n->minMw = max($n->minMw, $n->minW); }
            return;
        }
        if ($n->gridCols !== null) { // a grid: its tracks at the size their content asks
            foreach ($n->kids as $k) { self::measure($k); }
            [$cols, $rows] = self::gridTracks($n, INF, INF);
            $n->mw = array_sum($cols) + ($n->gapX ?? $n->gap) * max(0, count($cols) - 1) + $n->padW;
            $n->mh = array_sum($rows) + ($n->gapY ?? $n->gap) * max(0, count($rows) - 1) + $n->padH;
            if ($n->fw > 0) { $n->mw = $n->fw; }
            if ($n->fh > 0) { $n->mh = $n->fh; }
            $n->natW = $n->mw; $n->natH = $n->mh;
            $n->mw = max($n->mw, $n->minW); $n->mh = max($n->mh, $n->minH);
            return;
        }
        $main = 0.0; $cross = 0.0; $cnt = 0; $least = 0.0; // least: the min-content width, the automatic minimum a row shrinks this node to
        foreach ($n->kids as $k) {
            self::measure($k);
            if ($k->absolute || $k->hidden) {
                continue; // out of the flow, or hidden: it takes no room
            }
            $cnt++;
            if ($n->dir === Node::ROW) {
                $kw = $k->mw;
                if ($k->basis !== null) { // an item that cannot grow takes no more than its flex base size, one that cannot shrink no less, neither below its least (CSS flexbox §9.9.1, mygo's 269e2f9)
                    $kmx = $k->marW;
                    $b = max(min($k->basis, $k->maxW), $k->minSet ? $k->minW : 0.0, $k->padW) + $kmx; // the basis, as arrangeFlex's, is the box's, without its margins
                    if ($k->grow == 0) { $kw = max(min($kw, $b), $k->minMw); }
                    if ($k->shrink == 0) { $kw = max($kw, $b); }
                }
                $main += $kw; if ($k->mh > $cross) $cross = $k->mh;
                $least += $k->minMw;
            } else {
                $main += $k->basis ?? $k->mh; if ($k->mw > $cross) $cross = $k->mw;
                $least = max($least, $k->minMw);
            }
        }
        if ($n->dir === Node::ROW && !$n->wraps) { // children lined up by their baselines: as high as the highest ascent and the deepest descent
            $cross = max($cross, self::baselineCross($n, $n->kids));
        }
        $gap = $n->mainGap();
        $n->minMw = ($n->dir === Node::ROW ? $least + $gap * max(0, $cnt - 1) : $least) + $n->padW;
        [$padMain, $padCross] = $n->dir === Node::ROW ? [$n->padW, $n->padH] : [$n->padH, $n->padW];
        $main += $gap * max(0, $cnt - 1) + $padMain;
        $cross += $padCross;
        if ($n->wraps && $n->wrapWidth > 0) { // a wrapping row: as wide as its widest line, as high as its lines
            $main = 0.0; $cross = $padCross;
            foreach (self::lines($n, $n->wrapWidth - $padMain) as $i => $line) {
                $lw = $gap * (count($line) - 1); $lh = 0.0;
                foreach (self::lineMain($n, $line, $n->wrapWidth - $padMain) as $j => $kw) { $lw += $kw; $lh = max($lh, self::lineHeight($line[$j], $kw)); } // within the largest each takes, its height following its width
                $lh = max($lh, self::baselineCross($n, $line));
                $main = max($main, $lw + $padMain); $cross += $lh + ($i > 0 ? $n->crossGap() : 0);
            }
        }
        if ($n->scroll instanceof ListState && $n->dir === Node::COLUMN && ($n->maxH < INF || $n->sizedByRows)) {
            // A list in a scrolling container, or bounded by a largest height, is as high as its rows, the rest as estimated (mygo's list sized by its rows); elsewhere it takes the room it is given, as columns here shrink nothing.
            $l = $n->scroll;
            $main += max(0, $l->count - $l->first - $cnt) * ($l->estimate + $l->rowGap);
        } elseif ($n->scroll !== null && !($n->absolute && $n->attachTarget !== null)) { // a scrolling container takes the room it is given on the axes it scrolls; a popover's is as large as its content, then cut to the window's room (roomAlong)
            if ($n->dir === Node::COLUMN ? $n->scrollY : $n->scrollX) { $main = $padMain; }
            if ($n->dir === Node::COLUMN ? $n->scrollX : $n->scrollY) { $cross = $padCross; }
        }
        if ($n->dir === Node::ROW) { $n->mw = $main; $n->mh = $cross; } else { $n->mw = $cross; $n->mh = $main; }
        if ($n->fw > 0) { $n->mw = $n->fw; $n->minMw = min($n->minMw, $n->fw); } // a container given a size (Node::size) takes it over its content's
        if ($n->fh > 0) { $n->mh = $n->fh; }
        if ($n->scrollX || $n->wraps) { $n->minMw = $n->padW; } // what scrolls or wraps across has no least width
        $n->natW = $n->mw; $n->natH = $n->mh;
        $n->mw = max($n->mw, $n->minW); $n->mh = max($n->mh, $n->minH);
        $n->minMw = $n->minSet ? $n->minW : max(min($n->minMw, $n->mw), $n->minW); // a least width set replaces its content's
    }

    /** A button given a padding of its own (padding()): as much bigger or smaller as it is than the one it was made with, on each side not given a size, as mygo's Padding on a button. */
    private static function repad(Node $n): void
    {
        [[$t, $r, $b, $l], [$w, $h]] = $n->madePad;
        $p = $n->pads;
        if (!$n->sizedW && $n->fw == $w) { $n->fw += $p[1] + $p[3] - $r - $l; }
        if ($n->fh == $h) { $n->fh += $p[0] + $p[2] - $t - $b; }
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
        if ($own->strike) { $n->strike = true; }
        [$w, $h] = $n->metrics->measure($n->text ?? '', $n->fontSize, $n->style);
        if ($n->kind === Node::BUTTON) { // its padding around the label as it measures now
            if (!$n->sizedW) { $n->fw += $w - $ow; }
            if ($n->fh > 0) { $n->fh += $h - $oh; }
        } else {
            if ($n->fw == $ow) { $n->fw = $w; } // a size given to it stays
            if ($n->fh == $oh) { $n->fh = $h; }
        }
        $n->metrics = null; // once
    }

    /** A text field given a text style of its own: the size, color and Font bits its text shows in, a single line's field as much higher as its line. */
    private static function restyleField(Node $n): void
    {
        $own = $n->ts;
        [, $oh] = $n->metrics->measure('X', $n->fontSize, $n->style); // as the field measured its line
        if ($own->size !== null) { $n->fontSize = $own->size; }
        if ($own->color !== null) { $n->fg = $own->color; }
        $n->style |= Node::fontBits($own);
        [, $h] = $n->metrics->measure('X', $n->fontSize, $n->style);
        if ($n->kind === Node::INPUT && $n->fh > 0) { $n->fh += $h - $oh; }
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
        if ($n->memo === 2) {
            if ($w == $n->w && $h == $n->h) { // a memo placed again at its size: as it was laid out, moved
                if ($x != $n->x || $y != $n->y) { self::move($n, $x - $n->x, $y - $n->y); }
                return;
            }
            $n->context?->runtime?->memoForget($n->id); // at another size: built again, in another pass
            $n->context?->runtime?->changed();
        }
        $n->x = $x; $n->y = $y; $n->w = $w; $n->h = $h;
        $n->laid = true;
        if ($n->kind === Node::RICH && $n->wrapWidth === null && ($n->rtl || ($n->textStyle()->align ?? Node::START) !== Node::START) && $w - $n->padL() - $n->padR() > $n->textLayout()->width + 0.5) {
            $n->alignWidth = $w - $n->padL() - $n->padR(); // stretched wider than its lines: laid out across the box, so that they go where the alignment and the direction put them (mygo lays a text out at its width)
            $n->layout = null;
        }
        if ($n->kind === Node::RICH && $n->kids !== []) {
            self::placeInline($n, $n, 0);
            return;
        }
        if ($n->kind !== Node::CONTAINER) return;
        $kids = [];
        foreach ($n->kids as $k) {
            if ($k->hidden) {
                self::gone($k, $x, $y); // no room, no gap
            } elseif (!$k->absolute) {
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
            $size = function (array $it) use ($n, $cols, $rows, $gx, $gy): array { // an item's area, and its size there: its percentages of the area, as in CSS (mygo's 813ee46)
                [$k, $c, $r, $cs, $rs] = $it;
                $aw = $gx * ($cs - 1); for ($j = $c; $j < $c + $cs; $j++) { $aw += $cols[$j]; }
                $ah = $gy * ($rs - 1); for ($j = $r; $j < $r + $rs; $j++) { $ah += $rows[$j]; }
                $a = $k->self !== Node::AUTO ? $k->self : ($n->align !== Node::AUTO ? $n->align : Node::STRETCH); // alignSelf, else the grid's align (mygo's AlignItems), else stretched
                $j = $k->justifySelf !== Node::AUTO ? $k->justifySelf : ($n->justifyItems !== Node::AUTO ? $n->justifyItems : Node::STRETCH); // mygo's gridJustify
                // Automatic margins take the room the child leaves in its area, which it is not stretched over, before alignment does (mygo's gridPlaceIn); right to left, the columns mirror the area, so its sides swap.
                [$ml, $mr] = $n->rtl ? [$k->margins[1], $k->margins[3]] : [$k->margins[3], $k->margins[1]];
                if (is_infinite($ml) || is_infinite($mr)) { $j = self::autoPlace($ml, $mr); }
                if (is_infinite($k->margins[0]) || is_infinite($k->margins[2])) { $a = self::autoPlace($k->margins[0], $k->margins[2]); }
                $kh = $k->hPct !== null ? $k->hPct * $ah : ($a === Node::STRETCH && $k->fh <= 0 ? $ah : $k->mh); // a size of its own is kept (mygo: an explicit size is not stretched)
                $kw = $k->wPct !== null ? $k->wPct * $aw : ($j === Node::STRETCH && ($k->fw <= 0 || $k->kind === Node::TEXT) ? $aw : $k->mw); // a text's fw is what it measured, not a size given
                return [$aw, $ah, $a, $j, self::clampW($k, $kw, $aw), self::clampH($k, $kh, $ah)];
            };
            // The items of a row lined up by their baselines, laid out first to find them: how far below the row's top each goes.
            $align = $n->align !== Node::AUTO ? $n->align : Node::STRETCH;
            $base = []; $rowBase = [];
            foreach (self::asksBaseline($align, $n->kids) ? $items : [] as $i => $it) {
                if ($it[4] !== 1 || !self::byBaseline($it[0], $align, $n)) { continue; }
                [, , , , $kw, $kh] = $size($it);
                self::arrange($it[0], 0.0, 0.0, $kw, $kh);
                $base[$i] = $it[0]->y + (self::boxBaseline($it[0]) ?? $it[0]->h);
                $rowBase[$it[2]] = max($rowBase[$it[2]] ?? -INF, $base[$i]);
            }
            foreach ($items as $i => $it) {
                [$k, $c, $r] = $it;
                [$aw, $ah, $a, $j, $kw, $kh] = $size($it);
                $kx = $colAt[$c] + self::across($j, $aw, $kw);
                if ($n->rtl) { $kx = 2 * ($x + $px) + $cw - $kx - $kw; } // right to left, the columns go from the right
                [$dx, $dy] = self::relative($k, $aw, $ah);
                if (isset($base[$i])) {
                    self::move($k, $kx + $dx, $rowAt[$r] + $rowBase[$r] - $base[$i] + $dy);
                } else {
                    self::arrange($k, $kx + $dx, $rowAt[$r] + self::across($a, $ah, $kh) + $dy, $kw, $kh);
                }
            }
        } elseif ($n->wraps) { // each line is arranged as a row of its own, the lines stacked as alignContent says, from the bottom in reverse
            $lines = self::lines($n, $cw);
            $heights = [];
            foreach ($lines as $i => $line) { $heights[$i] = 0.0; foreach (self::lineMain($n, $line, $cw) as $j => $kw) { $heights[$i] = max($heights[$i], self::lineHeight($line[$j], $kw)); } }
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

    /** Gives $n, hidden, and what is inside it no room, at ($x, $y). */
    private static function gone(Node $n, float $x, float $y): void
    {
        $n->x = $x; $n->y = $y; $n->w = $n->h = 0.0;
        $n->gone = true; $n->laid = true;
        foreach ($n->kids as $k) { self::gone($k, $x, $y); }
    }

    /** Moves $n and what is inside it, laid out, by ($dx, $dy). */
    private static function move(Node $n, float $dx, float $dy): void
    {
        $n->x += $dx; $n->y += $dy;
        foreach ($n->frags as $i => $f) { $n->frags[$i][0] += $dx; $n->frags[$i][1] += $dy; }
        foreach ($n->kids as $k) { self::move($k, $dx, $dy); }
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
        $base = []; $lo = []; $hi = []; $pads = []; $used = 0.0; $grow = 0.0; $crossMax = 0.0; $cross = []; $colShrinks = false;
        foreach ($kids as $i => $k) {
            [$mx, $my] = [$k->marW, $k->marH];
            $a = $k->self === Node::AUTO ? $align : $k->self;
            // The flex base size, before the least and largest, which only its hypothetical size is clamped by (CSS flexbox §9.2, mygo's 269e2f9); its margins included.
            if ($row) {
                $b = ($k->basisPct !== null ? $k->basisPct * $cw : ($k->basis ?? ($k->wPct !== null ? $k->wPct * $cw : $k->natW))) + $mx;
                $lo[$i] = $k->minWPct !== null ? $k->minWPct * $cw + $mx : $k->minMw;
                $hi[$i] = ($k->maxWPct !== null ? min($k->maxWPct * $cw, $k->maxW) : $k->maxW) + $mx;
                // The content's least width was measured where a maximum that is a percentage of this row does not resolve: it binds here, as CSS clamps the content-based minimum by the max size; a least width the node asked for itself still wins (mygo's bbcc7b7).
                if ($k->minWPct === null && !$k->minSet) { $lo[$i] = min($lo[$i], $hi[$i]); }
                $pads[$i] = $k->padW + $mx;
            } else {
                // A column's child is as wide as it is across first: its height may follow from its width (aspectRatio).
                $cross[$i] = self::crossWidth($k, $a, $cw);
                $b = ($k->basisPct !== null ? $k->basisPct * $ch : ($k->basis ?? ($k->hPct !== null ? $k->hPct * $ch : ($k->aspect > 0 && $k->fh <= 0 ? ($cross[$i] - $mx) / $k->aspect : $k->natH)))) + $my;
                $hi[$i] = ($k->maxHPct !== null ? min($k->maxHPct * $ch, $k->maxH) : $k->maxH) + $my;
                // A column's children keep their heights where it overflows, within their largest, unless they ask for a least height, down to which they shrink (a split's pane); a least height wins over the largest, as in CSS.
                $least = $k->minHPct !== null ? $k->minHPct * $ch : ($k->minH > 0 ? $k->minH : null);
                $lo[$i] = $least !== null ? $least + $my : min($b, $hi[$i]);
                if ($least !== null && $lo[$i] < $b) { $colShrinks = true; }
                $pads[$i] = $k->padH + $my;
            }
            // No size along it goes below the padding and border, as in CSS.
            $p = $pads[$i];
            if ($p > 0) { // most nodes have none: max() three times a child was a hot spot
                if ($b < $p) { $b = $p; }
                if ($lo[$i] < $p) { $lo[$i] = $p; }
                if ($hi[$i] < $p) { $hi[$i] = $p; }
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
                self::$aligned[] = [$s, $s->aligning, $s->shownRow, $s->shownTop, $s->lag, $s->grew];
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
            $top = -$s->offset; [$vf, $vl] = [0, -1]; // the rows some of which show (mygo's Visible)
            foreach ($s->count > 0 ? $kids : [] as $i => $k) {
                if ($top + $k->mh > 0 && $top < $ch) { if ($vl < $vf) { $vf = $s->first + $i; } $vl = $s->first + $i; }
                $top += $k->mh + $gap;
            }
            if ($s->showed($vf, $vl)) { $n->context?->runtime?->changed(); } // what read them builds again with these
            if ($s->gap > 0 && $s->first + $cnt < $s->count) {
                $n->context?->runtime?->changed(); // rows far shorter than estimated fell short of the view: another pass builds more, so the frame shows no gap (mygo's buildLate)
            }
            if ($s->first + $cnt >= $s->count && $s->first === 0) {
                $s->gap = 0.0; // every row is built and they are short of the view: nothing can fill it
                $short = $ch - ($used + $gap * ($cnt - 1));
                if ($short > 0 && $s->count > 0 && ($n->justify === Node::END || $n->justify === Node::CENTER)) { // what shows without rows stays at the top, as mygo's
                    $cy += $n->justify === Node::END ? $short : $short / 2; // rows short of the view at its end, as a chat's (mygo's Justify)
                }
            }
            $flexible = false;
        }
        [$rowX, $rowW] = [$cx, $cw]; // where a list's rows start across, and their width
        $f = $n->followX !== null ? $n->followX->scroll : null; // not `?->`: the tracing JIT once read `scroll` of a null `followX` here (docs/architecture.md, v1.56)
        if ($f instanceof ScrollState || $f instanceof ListState) { // the content goes sideways with another's (mygo's ScrollWith)
            if ($n->rtl) { $scrollRtl = ($scrollRtl ?? 0.0) + $f->x; } else { $cx -= $f->x; }
        }
        // The sizes along the axis: grown into the room left, or, in a row, shrunk where they overflow it, none past its least or largest (mygo's resolveFlexible).
        $main = [];
        foreach ($kids as $i => $k) { $main[$i] = max($lo[$i], min($base[$i], $hi[$i])); }
        $growing = $used < $avail;
        if ($flexible && (($growing && $grow > 0) || (!$growing && ($row || $colShrinks) && $n->scroll === null))) {
            $main = self::flexible($kids, $base, $lo, $hi, $pads, $avail, $growing);
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
        // The children lined up by their baselines, laid out first to find them (mygo's 4dc371f): how far below the line's top each goes; a row's child's size, along it as resolved, across as its alignment and its own say.
        $lift = $row && self::asksBaseline($align, $kids) ? self::lineUp($n, $kids, $align, function (int $i, Node $k) use ($main, $autos, $align, $ch): array {
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($k->mh <= 0) { $a = Node::STRETCH; }
            $autoCross = is_infinite($k->margins[0]) || is_infinite($k->margins[2]);
            [$mx, $my] = [$k->marW, $k->marH];
            $kw = $main[$i] + ($autos[$i] ?? 0.0);
            if ($k->hPct !== null) {
                $kh = $k->hPct * $ch + $my;
            } elseif ($k->aspect > 0 && $k->fh <= 0) {
                $kh = ($main[$i] - $mx) / $k->aspect + $my;
            } else {
                $kh = $autoCross || ($a === Node::STRETCH && $k->fh <= 0) ? $ch : $k->mh; // a size of its own is kept (mygo: an explicit size is not stretched)
            }
            return [$kw, self::clampH($k, $kh, $ch)];
        }) : [];
        // Across a column the children lined up by their baselines line up their left edges, as Chrome: right to left, as far from the right as the widest there reaches.
        $leftEdge = null;
        if (!$row && $n->rtl && self::asksBaseline($align, $kids)) {
            foreach ($kids as $i => $k) {
                if (($k->self === Node::AUTO ? $align : $k->self) === Node::BASELINE && is_finite($k->margins[1]) && is_finite($k->margins[3])) {
                    $leftEdge = max($leftEdge ?? -INF, $k->margins[1] + $cross[$i] - $k->marW);
                }
            }
        }
        foreach ($kids as $i => $k) {
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($row ? $k->mh <= 0 : $k->mw <= 0) {
                $a = Node::STRETCH; // no size of its own across (a text area given 0, an empty box): it takes the room
            }
            $autoCross = $row ? is_infinite($k->margins[0]) || is_infinite($k->margins[2]) : is_infinite($k->margins[1]) || is_infinite($k->margins[3]); // automatic margins across place it themselves, in all the room
            [$dx, $dy] = self::relative($k, $cw, $ch);
            if ($row) {
                [$mx, $my] = [$k->marW, $k->marH];
                $kw = $main[$i] + ($autos[$i] ?? 0.0);
                if ($k->hPct !== null) {
                    $kh = $k->hPct * $ch + $my;
                } elseif ($k->aspect > 0 && $k->fh <= 0) {
                    $kh = ($main[$i] - $mx) / $k->aspect + $my;
                } else {
                    $kh = $autoCross || ($a === Node::STRETCH && $k->fh <= 0) ? $ch : $k->mh; // a size of its own is kept (mygo: an explicit size is not stretched)
                }
                $kh = self::clampH($k, $kh, $ch); // as $rowSize, inline: a closure call a child was a hot spot
                // Reversed, or right to left (not both), the same place counted from the right.
                $at = $n->reverse !== $n->rtl ? 2 * ($x + $px) + $viewW - $cx - $kw + ($scrollRtl ?? 0.0) : $cx;
                if (isset($lift[$i])) {
                    self::move($k, $at + $dx, $cy + $lift[$i] + $dy); // laid out at the origin already, at this size
                } else {
                    self::arrange($k, $at + $dx, $cy + ($autoCross ? 0.0 : self::across($a, $ch, $kh)) + $dy, $kw, $kh);
                }
                $cx += $kw + $gap + $between;
            } else {
                $kh = $main[$i] + ($autos[$i] ?? 0.0);
                $kw = $cross[$i];
                $at = $n->reverse ? 2 * ($y + $py) + $ch - $cy - $kh : $cy; // from the bottom
                $kx = $cx + ($autoCross ? 0.0 : self::across($a, $cw, $kw));
                if ($n->rtl && $leftEdge !== null && $a === Node::BASELINE && !$autoCross) {
                    $kx = $x + $px + $viewW - $leftEdge - $k->margins[3] + ($scrollRtl ?? 0.0); // its left edge where the others' are
                } elseif ($n->rtl) { $kx = 2 * ($x + $px) + $viewW - $kx - $kw + ($scrollRtl ?? 0.0); } // right to left, across from the right
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
        $mx = $k->marW;
        $autoCross = is_infinite($k->margins[1]) || is_infinite($k->margins[3]);
        if ($k->wPct !== null) {
            $kw = $k->wPct * $cw + $mx;
        } elseif ($k->aspect > 0 && $k->fw <= 0 && $k->fh > 0) {
            $kw = $k->fh * $k->aspect + $mx;
        } else {
            $kw = $autoCross || ($a === Node::STRETCH && ($k->fw <= 0 || $k->kind === Node::TEXT || $k->kind === Node::RICH || ($k->kind === Node::BUTTON && !$k->sizedW))) ? $cw : ($k->single || $k->wrapsAtWidth() ? min($k->mw, $cw) : ($k->kind === Node::CONTAINER && $k->fw <= 0 ? min($k->mw, max($k->minMw, $cw)) : $k->mw)); // a text that wraps no wider than the room; a container as wide as its content, within the room down to its least (CSS's fit-content)
        }
        return self::clampW($k, $kw, $cw);
    }

    /** $kw, an outer width (margins included), within $k's least and largest widths against a content box $cw wide. */
    private static function clampW(Node $k, float $kw, float $cw): float
    {
        $pad = $k->padW; // no narrower than its padding and border, as in CSS
        if ($k->maxW === INF && $k->maxWPct === null && $k->minWPct === null) { return $pad == 0.0 ? $kw : max($kw, $pad + $k->marW); }
        $mx = $k->marW;
        $max = min($k->maxW, $k->maxWPct !== null ? $k->maxWPct * $cw : INF);
        $min = max($k->minSet ? $k->minW : 0.0, $k->minWPct !== null ? $k->minWPct * $cw : 0.0, $pad);
        return max(min($kw - $mx, $max), $min) + $mx;
    }

    private static function clampH(Node $k, float $kh, float $ch): float
    {
        $pad = $k->padH;
        if ($k->maxH === INF && $k->maxHPct === null && $k->minHPct === null) { return $pad == 0.0 ? $kh : max($kh, $pad + $k->marH); }
        $my = $k->marH;
        $max = min($k->maxH, $k->maxHPct !== null ? $k->maxHPct * $ch : INF);
        $min = max($k->minH, $k->minHPct !== null ? $k->minHPct * $ch : 0.0, $pad);
        return max(min($kh - $my, $max), $min) + $my;
    }

    /**
     * Grows the children into $space, or shrinks them where they overflow
     * it, none past its least ($lo) or largest ($hi) size (CSS flexbox
     * §9.7, mygo's resolveFlexible): growing by their grow factors,
     * shrinking by their shrink factors times their sizes less their
     * padding, border and margins ($pads); those clamped the way the total
     * moved are frozen (by their least or by their largest, the least
     * winning where it is the larger), leaving the rest to share what is
     * left, going round again.
     * @param list<Node> $kids @param array<int, float> $base @param array<int, float> $lo @param array<int, float> $hi @param array<int, float> $pads
     * @return array<int, float>
     */
    private static function flexible(array $kids, array $base, array $lo, array $hi, array $pads, float $space, bool $growing): array
    {
        $main = []; $frozen = []; $inner = [];
        foreach ($kids as $i => $k) {
            $inner[$i] = max($base[$i] - $pads[$i], 0.0);
            $hyp = max($lo[$i], min($base[$i], $hi[$i]));
            $factor = $growing ? $k->grow : $k->shrink * $inner[$i];
            $main[$i] = $hyp;
            if ($factor == 0 || ($growing && $base[$i] > $hyp) || (!$growing && $base[$i] < $hyp)) { $frozen[$i] = true; }
        }
        for ($round = 0, $n = count($kids); $round <= $n; $round++) {
            $free = $space; $sum = 0.0;
            foreach ($kids as $i => $k) {
                if (isset($frozen[$i])) { $free -= $main[$i]; } else { $free -= $base[$i]; $sum += $growing ? $k->grow : $k->shrink * $inner[$i]; }
            }
            if ($sum == 0) { break; }
            $violation = 0.0; $moved = [];
            foreach ($kids as $i => $k) {
                if (isset($frozen[$i])) { continue; }
                $v = $base[$i] + $free * ($growing ? $k->grow : $k->shrink * $inner[$i]) / $sum;
                $clamped = max($lo[$i], min($v, $hi[$i]));
                $moved[$i] = $clamped - $v;
                $violation += $moved[$i];
                $main[$i] = $clamped;
            }
            if (abs($violation) < 1e-9) { break; }
            foreach ($moved as $i => $d) {
                if (($violation > 0 && $d > 0) || ($violation < 0 && $d < 0)) { $frozen[$i] = true; }
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
        if ($k->attachTarget?->gone) { // attached to what is hidden: hidden too
            self::gone($k, $n->x, $n->y);
            $k->invisible = true;
            return;
        }
        $bx = $n->x + $n->borders[3]; $by = $n->y + $n->borders[0];
        $pw = $n->w - $n->borders[1] - $n->borders[3]; $ph = $n->h - $n->borders[0] - $n->borders[2];
        if (($on = $k->placeOn) !== null) { [$bx, $by, $pw, $ph] = [$on->x, $on->y, $on->w, $on->h]; } // on a sibling, where this frame lays it out
        [$mx, $my] = [$k->marW, $k->marH];
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
                if ($k->scroll !== null && $k->scrollY) { $ih = min($ih, self::roomAlong($ty, $th, ($ay == 1 && $sy == 0) || ($ay == 0 && $sy == 1), $m[0] + $m[2], self::$window[1])); } // one that scrolls, as a select's long list, no longer than the room
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
    /** @var list<array{0: ListState, 1: array, 2: int, 3: float, 4: float, 5: bool}> the lists whose row this layout aligned (ListState::$aligning), as they were before: how, where they showed and their glide's lag; the arrange after texts wrap aligns them again from there */
    private static array $aligned = [];
    /** The text metrics of the frame laid out, for the baselines of texts (boxBaseline). */
    private static ?TextMetrics $metrics = null;

    /** The window's size, the layout's: what attachTo keeps popovers in. */
    public static function window(float $w, float $h): void
    {
        self::$window = [$w, $h];
    }

    /**
     * The most a box attached to a target (from $t0, $tsize long) can be
     * along an axis of the window, $limit long, and stay in it, 4 points
     * from its edges: the target's larger side for one placed $beside it,
     * else the window (mygo's roomAlong).
     */
    private static function roomAlong(float $t0, float $tsize, bool $beside, float $margins, float $limit): float
    {
        $room = $beside ? max($t0, $limit - ($t0 + $tsize)) - 4.0 - $margins : $limit - 8.0 - $margins;
        return max($room, 0.0);
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
        // An item lined up by its baseline asks for the room above it that lines it up with its row's (mygo's 4dc371f).
        $align = $n->align !== Node::AUTO ? $n->align : Node::STRETCH;
        $lifts = []; $rowBase = [];
        foreach (self::asksBaseline($align, $n->kids) ? $items : [] as $j => [$k, , $r, , $rs]) {
            if ($rs !== 1 || !self::byBaseline($k, $align, $n)) { continue; }
            $lifts[$j] = $k->margins[0] + (self::measuredBaseline($k) ?? $k->mh - $k->marH);
            $rowBase[$r] = max($rowBase[$r] ?? 0.0, $lifts[$j]);
        }
        $rows = self::sizeTracks($n->gridRows, $nr, $availH, $n->gapY ?? $n->gap, array_map(fn($j) => [$items[$j][2], $items[$j][4], max($items[$j][0]->mh, $items[$j][0]->minH) + (isset($lifts[$j]) ? $rowBase[$items[$j][2]] - $lifts[$j] : 0.0)], array_keys($items)));
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
            if ($k->absolute || $k->hidden) { continue; }
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
            if ($k->absolute || $k->hidden) { continue; }
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
        $main = self::lineMain($n, $line, $w);
        $avail = $w - $gap * (count($line) - 1);
        [$cx, $between] = self::justifyOffsets($n->justify, max(0.0, $avail - array_sum($main)), count($line));
        $cx += $x;
        $align = $n->align === Node::AUTO ? Node::CENTER : $n->align;
        $size = function (int $i, Node $k) use ($main, $align, $h): array {
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($k->mh <= 0) { $a = Node::STRETCH; }
            return [$main[$i], self::clampH($k, $k->hPct !== null ? $k->hPct * $h : ($a === Node::STRETCH && $k->fh <= 0 && $k->aspect <= 0 ? $h : self::lineHeight($k, $main[$i])), $h)];
        };
        $lift = self::asksBaseline($align, $line) ? self::lineUp($n, $line, $align, $size) : [];
        foreach ($line as $i => $k) {
            $a = $k->self === Node::AUTO ? $align : $k->self;
            if ($k->mh <= 0) { $a = Node::STRETCH; }
            [$kw, $kh] = $size($i, $k);
            [$dx, $dy] = self::relative($k, $w, $h);
            $kx = ($n->rtl !== $n->reverse ? 2 * $x + $w - $cx - $kw : $cx) + $dx; // right to left, or reversed, from the right (mygo's flipMain)
            if (isset($lift[$i])) { self::move($k, $kx, $y + $lift[$i] + $dy); } else { self::arrange($k, $kx, $y + self::across($a, $h, $kh) + $dy, $kw, $kh); }
            $cx += $kw + $gap + $between;
        }
    }

    /** The widths the children of one line of a wrapping row take in $w: growing ones share what is left, the others shrink where it overflows, each within its least and largest. @param list<Node> $line @return list<float> */
    private static function lineMain(Node $n, array $line, float $w): array
    {
        $gap = $n->mainGap();
        $base = []; $lo = []; $hi = []; $pads = []; $used = 0.0; $grow = 0.0;
        foreach ($line as $i => $k) {
            $mx = $k->marW;
            $base[$i] = $k->basisPct !== null ? $k->basisPct * $w : ($k->wPct !== null ? $k->wPct * $w + $mx : $k->mw);
            $lo[$i] = $k->minWPct !== null ? $k->minWPct * $w + $mx : $k->minMw;
            $hi[$i] = ($k->maxWPct !== null ? min($k->maxWPct * $w, $k->maxW) : $k->maxW) + $mx;
            if ($k->minWPct === null && !$k->minSet) { $lo[$i] = min($lo[$i], $hi[$i]); } // the content's least width bound by the largest, as arrangeFlex's (mygo's bbcc7b7)
            $pads[$i] = $k->padW + $mx;
            $used += max($lo[$i], min($base[$i], $hi[$i])); $grow += $k->grow;
        }
        $avail = $w - $gap * (count($line) - 1);
        $growing = $used < $avail;
        return ($growing && $grow > 0) || !$growing ? self::flexible($line, $base, $lo, $hi, $pads, $avail, $growing) : array_map(fn($i) => max($lo[$i], min($base[$i], $hi[$i])), array_keys($line));
    }

    /** How high a child of a wrapping row's line is at width $main: as its aspect ratio makes it, when its height is not its own, else as it measured. */
    private static function lineHeight(Node $k, float $main): float
    {
        return $k->aspect > 0 && $k->fh <= 0 && $k->hPct === null ? ($main - $k->marW) / $k->aspect + $k->marH : $k->mh;
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
    /** Whether a container aligning by $align, or one of $kids, asks for baselines: those that do not skip the work. @param array<int, Node> $kids */
    private static function asksBaseline(int $align, array $kids): bool
    {
        if ($align === Node::BASELINE) { return true; }
        foreach ($kids as $k) { if ($k->self === Node::BASELINE) { return true; } }
        return false;
    }

    /**
     * Whether $k, a child of the row or grid $n, lines its baseline up with its siblings' as aligning by $align asks:
     * not where automatic margins place it across, nor in a row wrapping in reverse, where BASELINE is START (mygo's
     * byBaseline).
     */
    private static function byBaseline(Node $k, int $align, Node $n): bool
    {
        return ($k->self === Node::AUTO ? $align : $k->self) === Node::BASELINE && !is_infinite($k->margins[0]) && !is_infinite($k->margins[2]) && !$n->wrapReverse;
    }

    /**
     * Lays out the children of a line of $n lined up by their baselines at the origin, at their sizes ($size gives them),
     * and says how far below the line's top each goes so that their baselines meet (mygo's flexLayout): a child without
     * one puts its bottom there. @param array<int, Node> $kids @param Closure(int, Node): array{0: float, 1: float} $size
     * @return array<int, float>
     */
    private static function lineUp(Node $n, array $kids, int $align, Closure $size): array
    {
        $base = [];
        foreach ($kids as $i => $k) {
            if (!self::byBaseline($k, $align, $n)) { continue; }
            [$kw, $kh] = $size($i, $k);
            self::arrange($k, 0.0, 0.0, $kw, $kh);
            $base[$i] = $k->y + (self::boxBaseline($k) ?? $k->h); // its top margin and its baseline
        }
        if ($base === []) { return []; }
        $ascent = max($base);
        return array_map(fn(float $b): float => $ascent - $b, $base);
    }

    /** How high a line of $kids of the row $n is to line up those aligned by their baselines, as measured: the highest ascent and the deepest descent, 0 for none. @param array<int, Node> $kids */
    private static function baselineCross(Node $n, array $kids): float
    {
        $align = $n->align === Node::AUTO ? Node::CENTER : $n->align;
        if (!self::asksBaseline($align, $kids)) { return 0.0; }
        $ascent = $descent = -INF;
        foreach ($kids as $k) {
            if ($k->absolute || $k->hidden || !self::byBaseline($k, $align, $n)) { continue; }
            $b = $k->margins[0] + (self::measuredBaseline($k) ?? $k->mh - $k->marH);
            $ascent = max($ascent, $b); $descent = max($descent, $k->mh - $b);
        }
        return is_finite($ascent) ? $ascent + $descent : 0.0;
    }

    /**
     * How far below the top of $n, laid out, its first baseline is, as CSS's (mygo's boxBaseline): its first line of
     * text's, or its first child's (in a row, its first child's aligned by its baseline), which a child without one takes
     * from its bottom; null for a node with neither text nor children.
     */
    private static function boxBaseline(Node $n): ?float
    {
        if ($n->kind !== Node::CONTAINER) { return self::textBaseline($n, $n->h); }
        $first = null;
        $row = $n->dir === Node::ROW && $n->gridCols === null;
        $align = $n->align === Node::AUTO ? Node::STRETCH : $n->align;
        foreach ($n->kids as $k) {
            if ($k->absolute || $k->invisible || $k->hidden) { continue; }
            $first ??= $k;
            if (!$row) { break; }
            if (self::byBaseline($k, $align, $n)) { $first = $k; break; }
        }
        return $first === null ? null : $first->y - $n->y + (self::boxBaseline($first) ?? $first->h);
    }

    /** boxBaseline() as measured, before the node is laid out: its children taken at their measured sizes, at the top across. */
    private static function measuredBaseline(Node $n): ?float
    {
        if ($n->kind !== Node::CONTAINER) { return self::textBaseline($n, $n->mh - $n->marH); }
        $row = $n->dir === Node::ROW && $n->gridCols === null;
        if ($row && ($asc = self::baselineCross($n, $n->kids)) > 0) {
            $align = $n->align === Node::AUTO ? Node::CENTER : $n->align;
            $best = -INF;
            foreach ($n->kids as $k) {
                if (!$k->absolute && !$k->hidden && self::byBaseline($k, $align, $n)) { $best = max($best, $k->margins[0] + (self::measuredBaseline($k) ?? $k->mh - $k->marH)); }
            }
            return $n->padT() + $best;
        }
        foreach ($n->kids as $k) {
            if ($k->absolute || $k->invisible || $k->hidden) { continue; }
            return $n->padT() + (is_finite($k->margins[0]) ? $k->margins[0] : 0.0) + (self::measuredBaseline($k) ?? $k->mh - $k->marH);
        }
        return null;
    }

    /** The baseline of the first line of a leaf $h high, below its top: a text's, a button's label's, a field's; null for one without text. */
    private static function textBaseline(Node $n, float $h): ?float
    {
        if ($n->kind === Node::RICH) {
            $l = $n->textLayout();
            return $l->lines === [] ? null : ($n->inline ? 0.0 : $n->padT()) + $l->lines[0]->baseline;
        }
        $m = self::$metrics;
        if ($m === null) { return null; }
        if ($n->kind === Node::TEXT && $n->text !== null) {
            return ($n->inline ? 0.0 : $n->padT()) + $m->ascent($n->fontSize, $n->style);
        }
        if (($n->kind === Node::BUTTON && $n->text !== null) || $n->kind === Node::INPUT) { // a label, a field's line, centered in it (Painter)
            $style = $n->kind === Node::INPUT && $n->edit !== null ? $n->edit->style : $n->style;
            [, $th] = $m->measure($n->kind === Node::BUTTON ? $n->text : ($n->edit?->shown() ?: 'X'), $n->fontSize, $style);
            return ($h - $th) / 2 + $m->ascent($n->fontSize, $style);
        }
        return null;
    }

    /** How automatic margins on the start and the end of an axis place a child in its area: in the middle, at the end, or at the start. */
    private static function autoPlace(float $start, float $end): int
    {
        return is_infinite($start) ? (is_infinite($end) ? Node::CENTER : Node::END) : Node::START;
    }

    private static function across(int $align, float $room, float $size): float
    {
        return match ($align) { Node::CENTER => ($room - $size) / 2, Node::END => $room - $size, default => 0.0 };
    }
}
