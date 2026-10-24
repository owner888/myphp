<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Where a scrolling container's thumb goes: along its right edge (its left, right to left), as long as the view's share of the content. */
final class Scrollbar
{
    public const MIN_THUMB = 24.0;
    /** The thumb takes presses this far around it. */
    public const GRAB = 4.0;

    /**
     * The thumb's rectangle for a container at [x, y, w, h] with $extent,
     * or null when the content fits the view. Right to left ($rtl), the
     * vertical thumb is at the left, and the horizontal one starts at the
     * right, where the offset counts from.
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function thumb(array $r, array $extent, float $width, bool $horizontal = false, bool $rtl = false): ?array
    {
        [$offset, $range, $view] = $extent;
        if ($range <= 0 || $view <= 0) {
            return null;
        }
        $inner = ($horizontal ? $r[2] : $r[3]) - 4;
        $thumb = min(max($inner * $view / ($view + $range), self::MIN_THUMB), $inner);
        $travel = $inner - $thumb;
        $at = max(0.0, min($travel, $travel * $offset / $range));
        return $horizontal
            ? [$r[0] + 2 + ($rtl ? $travel - $at : $at), $r[1] + $r[3] - $width - 2, $thumb, $width]
            : [$rtl ? $r[0] + 2 : $r[0] + $r[2] - $width - 2, $r[1] + 2 + $at, $width, $thumb];
    }

    /** How far the content moves when the thumb moves one point. */
    public static function pointsPerThumbPoint(array $r, array $extent, bool $horizontal = false): float
    {
        [, $range, $view] = $extent;
        $inner = ($horizontal ? $r[2] : $r[3]) - 4;
        $thumb = min(max($inner * $view / ($view + $range), self::MIN_THUMB), $inner);
        $travel = $inner - $thumb;
        return $travel > 0 ? $range / $travel : 0.0;
    }
}
