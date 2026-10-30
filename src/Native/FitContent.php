<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * What a window that takes the size of its page's content keeps
 * (FlyoutOptions::$fitContent, WindowOptions::$fitContent; mygo's
 * fitContent): the limits of that size in points, 0 for none, the size it
 * last took, and the content's size its page last told, which another zoom
 * makes another size.
 *
 * The content's size is the bridge's, in CSS pixels: the root element's
 * w and h, and the room of the scrollbars the page shows, sw and sh. A
 * vertical scrollbar shows for a moment whenever the content grows before
 * the window does: its room counts only where the window cannot take the
 * content's height. A horizontal one the bridge tells only while the
 * content is wider than the root, as at the maximum width.
 *
 * @internal Window, Page
 */
final class FitContent
{
    /** The largest size a page may tell, in points, so that none overflows an int: no display is that large. */
    public const MAX_POINTS = 1 << 16;

    /** @var array{0: int, 1: int} the size it last took, [0, 0] before any */
    public array $size = [0, 0];
    /** @var ?array{w: float, h: float, sw: float, sh: float} the content its page last told */
    public ?array $content = null;

    public function __construct(
        public readonly int $minWidth = 0,
        public readonly int $minHeight = 0,
        public readonly int $maxWidth = 0,
        public readonly int $maxHeight = 0,
    ) {}

    /** The limits in the CSS pixels of a page at $zoom, for the bridge (its configuration's fit, the myphp:fit-content event). @return array{minWidth: float, minHeight: float, maxWidth: float, maxHeight: float} */
    public function limits(float $zoom): array
    {
        $zoom = $zoom > 0 ? $zoom : 1.0;
        return ['minWidth' => $this->minWidth / $zoom, 'minHeight' => $this->minHeight / $zoom, 'maxWidth' => $this->maxWidth / $zoom, 'maxHeight' => $this->maxHeight / $zoom];
    }

    /**
     * The size in points of content $c at $zoom, within the limits and
     * below $maxHeight points if it is not 0, or null for a size no layout
     * gives (negative, NaN). Fractions of a pixel take one, so that the
     * content fits, but for the error of rounding a layout's fractional
     * sizes. A content taller than the height it gets takes the room of its
     * vertical scrollbar.
     * @param array{w: float, h: float, sw: float, sh: float} $c
     * @return ?array{0: int, 1: int}
     */
    public function fit(array $c, float $zoom, int $maxHeight = 0): ?array
    {
        if (!($c['w'] >= 0 && $c['h'] >= 0)) {
            return null;
        }
        $zoom = $zoom > 0 ? $zoom : 1.0;
        $points = fn(float $css): int => max((int)ceil(min($css * $zoom, self::MAX_POINTS) - 1 / 128), 1);
        $within = fn(int $v, int $lo, int $hi): int => $hi > 0 ? min(max($v, $lo), $hi) : max($v, $lo);
        $maxH = $maxHeight > 0 && ($this->maxHeight === 0 || $maxHeight < $this->maxHeight) ? $maxHeight : $this->maxHeight;
        $room = fn(float $v): float => $v > 0 ? $v : 0.0; // NaN and below 0 are none
        [$width, $height] = [$c['w'], $c['h'] + $room($c['sh'])];
        if ($points($height) > $within($points($height), $this->minHeight, $maxH)) {
            $width += $room($c['sw']);
        }
        return [$within($points($width), $this->minWidth, $this->maxWidth), $within($points($height), $this->minHeight, $maxH)];
    }

    /** A message of the bridge, {"t":"content-size",…}, as a content's size; null for one that is none. @return ?array{w: float, h: float, sw: float, sh: float} */
    public static function told(array $m): ?array
    {
        $num = fn(mixed $v): float => is_int($v) || is_float($v) ? (float)$v : NAN;
        $c = ['w' => $num($m['w'] ?? null), 'h' => $num($m['h'] ?? null), 'sw' => $num($m['sw'] ?? 0), 'sh' => $num($m['sh'] ?? 0)];
        return is_nan($c['w']) || is_nan($c['h']) ? null : $c;
    }
}
