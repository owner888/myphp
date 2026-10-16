<?php
declare(strict_types=1);

namespace MyPHP\UI;

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
                foreach ($kids as $i => $k) { if ($k->single) { $shrink[$i] = min($k->mw, $over * $k->mw / $room); } }
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

    /** Where a child of $size sits across $room: at the start, in the middle, or at the end. */
    private static function across(int $align, float $room, float $size): float
    {
        return match ($align) { Node::CENTER => ($room - $size) / 2, Node::END => $room - $size, default => 0.0 };
    }
}
