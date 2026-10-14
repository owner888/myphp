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
            $cnt++;
            if ($n->dir === Node::ROW) {
                $main += $k->mw; if ($k->mh > $cross) $cross = $k->mh;
            } else {
                $main += $k->mh; if ($k->mw > $cross) $cross = $k->mw;
            }
        }
        $main += $n->gap * max(0, $cnt - 1) + 2 * $n->pad;
        $cross += 2 * $n->pad;
        if ($n->scroll !== null) {
            $main = 2 * $n->pad; // a scrolling container takes the room it is given, not its content's
        }
        if ($n->dir === Node::ROW) { $n->mw = $main; $n->mh = $cross; } else { $n->mw = $cross; $n->mh = $main; }
    }

    public static function arrange(Node $n, float $x, float $y, float $w, float $h): void
    {
        $n->x = $x; $n->y = $y; $n->w = $w; $n->h = $h;
        if ($n->kind !== Node::CONTAINER) return;
        $kids = $n->kids; $cnt = count($kids);
        if ($cnt === 0) return;
        $avail = ($n->dir === Node::ROW ? $w : $h) - 2 * $n->pad - $n->gap * ($cnt - 1);
        $used = 0.0; $grow = 0;
        foreach ($kids as $k) { $used += $n->dir === Node::ROW ? $k->mw : $k->mh; $grow += $k->grow; }
        $extra = $grow > 0 ? max(0, $avail - $used) / $grow : 0.0;
        $cx = $x + $n->pad; $cy = $y + $n->pad; $cw = $w - 2 * $n->pad; $ch = $h - 2 * $n->pad;
        if ($n->scroll !== null) {
            $content = $used + $n->gap * ($cnt - 1);
            $n->scroll->maxY = max(0.0, $content - $ch);
            $n->scroll->y = min($n->scroll->y, $n->scroll->maxY);
            $cy -= $n->scroll->y;
            $extra = 0.0;
        }
        if ($n->dir === Node::ROW) {
            foreach ($kids as $k) { $kw = $k->mw + $extra * $k->grow; self::arrange($k, $cx, $cy, $kw, $ch); $cx += $kw + $n->gap; }
        } else {
            foreach ($kids as $k) { $kh = $k->mh + $extra * $k->grow; self::arrange($k, $cx, $cy, $cw, $kh); $cy += $kh + $n->gap; }
        }
    }
}
