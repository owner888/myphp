<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Where a flyout goes (mygo's platform.Flyout placement): next to its
 * anchor, on a side and lined up with it along that side, in the work area
 * of a display, as GTK places popups on X11 and Wayland compositors do.
 */
final class Flyout
{
    public const BOTTOM = 0, TOP = 1, RIGHT = 2, LEFT = 3;
    public const START = 0, CENTER = 1, END = 2;
    /** The sides and alignments of FlyoutOptions' placements. */
    public const PLACEMENTS = [
        '' => [self::BOTTOM, self::START],
        FlyoutOptions::BOTTOM_START => [self::BOTTOM, self::START], FlyoutOptions::BOTTOM => [self::BOTTOM, self::CENTER], FlyoutOptions::BOTTOM_END => [self::BOTTOM, self::END],
        FlyoutOptions::TOP_START => [self::TOP, self::START], FlyoutOptions::TOP => [self::TOP, self::CENTER], FlyoutOptions::TOP_END => [self::TOP, self::END],
        FlyoutOptions::RIGHT_START => [self::RIGHT, self::START], FlyoutOptions::RIGHT => [self::RIGHT, self::CENTER], FlyoutOptions::RIGHT_END => [self::RIGHT, self::END],
        FlyoutOptions::LEFT_START => [self::LEFT, self::START], FlyoutOptions::LEFT => [self::LEFT, self::CENTER], FlyoutOptions::LEFT_END => [self::LEFT, self::END],
    ];

    /**
     * The point of the anchor the flyout goes to, the point of the flyout
     * that goes there (each -1 for the left or top, 0 the middle, 1 the
     * right or bottom), and the offset between them.
     * @return array{0: array{int, int}, 1: array{int, int}, 2: int, 3: int}
     */
    public static function gravities(int $side, int $align, int $gap): array
    {
        $cross = $align - 1;
        return match ($side) {
            self::TOP => [[$cross, -1], [$cross, 1], 0, -$gap],
            self::RIGHT => [[1, $cross], [-1, $cross], $gap, 0],
            self::LEFT => [[-1, $cross], [1, $cross], -$gap, 0],
            default => [[$cross, 1], [$cross, -1], 0, $gap],
        };
    }

    /**
     * Where a flyout of $width × $height goes, its anchor being at $anchor
     * on the screen, in the work area $work: on each axis it flips to the
     * other side of the anchor, or to its other end, where it would not fit
     * and the flipped one would; then it slides back into the work area;
     * then it shrinks to it. Returns the rect, and whether it flipped across
     * and down.
     * @return array{0: Rectangle, 1: bool, 2: bool}
     */
    public static function place(int $side, int $align, int $gap, Rectangle $anchor, int $width, int $height, Rectangle $work): array
    {
        [$ra, $wa, $dx, $dy] = self::gravities($side, $align, $gap);
        [$x, $fx] = self::flip($work->x, $work->width, $anchor->x, $anchor->width, $width, $ra[0], $wa[0], $dx);
        [$y, $fy] = self::flip($work->y, $work->height, $anchor->y, $anchor->height, $height, $ra[1], $wa[1], $dy);
        [$x, $width] = self::slide($work->x, $work->width, $x, $width);
        [$y, $height] = self::slide($work->y, $work->height, $y, $height);
        return [new Rectangle($x, $y, $width, $height), $fx, $fy];
    }

    /** GDK's maybe_flip_position on one axis. @return array{0: int, 1: bool} */
    private static function flip(int $boundsPos, int $boundsSize, int $rectPos, int $rectSize, int $size, int $rectSign, int $windowSign, int $offset): array
    {
        $fits = fn(int $p) => $p >= $boundsPos && $p + $size <= $boundsPos + $boundsSize;
        $primary = $rectPos + intdiv((1 + $rectSign) * $rectSize, 2) + $offset - intdiv((1 + $windowSign) * $size, 2);
        if ($fits($primary)) {
            return [$primary, false];
        }
        $secondary = $rectPos + intdiv((1 - $rectSign) * $rectSize, 2) - $offset - intdiv((1 - $windowSign) * $size, 2);
        if ($fits($secondary)) {
            return [$secondary, true];
        }
        return [$primary, false];
    }

    /** Moves a span back into bounds, its start first when it is longer, then cuts what still sticks out. @return array{0: int, 1: int} */
    private static function slide(int $boundsPos, int $boundsSize, int $pos, int $size): array
    {
        if ($pos + $size > $boundsPos + $boundsSize) { $pos = $boundsPos + $boundsSize - $size; }
        if ($pos < $boundsPos) { $pos = $boundsPos; }
        if ($pos + $size > $boundsPos + $boundsSize) { $size = $boundsPos + $boundsSize - $pos; }
        return [$pos, max($size, 1)];
    }

    /**
     * The work area of the display a flyout anchored at $r goes to, as GDK
     * picks it: the one whose work area overlaps $r the most, else the one
     * nearest to its center.
     * @param list<array{bounds: Rectangle, workArea: Rectangle, primary: bool}> $displays
     */
    public static function workAreaFor(Rectangle $r, array $displays): Rectangle
    {
        $best = -1; $bestArea = 0;
        foreach ($displays as $i => $d) {
            if (($a = self::overlap($d['workArea'], $r)) > $bestArea) { $best = $i; $bestArea = $a; }
        }
        if ($best < 0) {
            $cx = $r->x + intdiv($r->width, 2); $cy = $r->y + intdiv($r->height, 2); $bestDist = -1;
            foreach ($displays as $i => $d) {
                $b = $d['bounds'];
                $dx = max($b->x - $cx, 0, $cx - ($b->x + $b->width - 1));
                $dy = max($b->y - $cy, 0, $cy - ($b->y + $b->height - 1));
                if (($dist = $dx * $dx + $dy * $dy) < $bestDist || $bestDist < 0) { $best = $i; $bestDist = $dist; }
            }
        }
        return $best < 0 ? new Rectangle(0, 0, 0, 0) : $displays[$best]['workArea'];
    }

    private static function overlap(Rectangle $a, Rectangle $b): int
    {
        $w = min($a->x + $a->width, $b->x + $b->width) - max($a->x, $b->x);
        $h = min($a->y + $a->height, $b->y + $b->height) - max($a->y, $b->y);
        return $w <= 0 || $h <= 0 ? 0 : $w * $h;
    }

    /** Where a flyout goes without an anchor: in the middle of the work area, as large as it fits. */
    public static function centered(int $width, int $height, Rectangle $work): Rectangle
    {
        $w = min($width, $work->width); $h = min($height, $work->height);
        return new Rectangle($work->x + intdiv($work->width - $w, 2), $work->y + intdiv($work->height - $h, 2), $w, $h);
    }

    /** @param list<array{bounds: Rectangle, workArea: Rectangle, primary: bool}> $displays */
    public static function primaryWorkArea(array $displays): Rectangle
    {
        foreach ($displays as $d) {
            if ($d['primary']) { return $d['workArea']; }
        }
        return $displays[0]['workArea'] ?? new Rectangle(0, 0, 0, 0);
    }
}
