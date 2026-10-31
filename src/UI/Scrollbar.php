<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The scroll bar of a view that scrolls itself rather than through a
 * scroll container, as a code editor or a terminal does, laid out and
 * painted as a scroll container's are, so that every bar of an app looks
 * alike and follows the theme (Theme::$scrollbar, $scrollbarWidth) (mygo's
 * ui.Scrollbar). The view paints it with Painter::scrollbar() while it
 * shows it, as a container's show while the pointer is over the container
 * or drags a thumb, and maps a drag of the thumb to an offset with thumb()
 * and offsetAt():
 *
 *     $bar = new Scrollbar($r, view: $viewH, content: $contentH, offset: $scrollY);
 *     if ($hovered || $dragging) { $p->scrollbar($bar, new ScrollbarState($dragging)); }
 *
 * Its statics are where a scroll container's thumb goes: along its right
 * edge (its left, right to left), as long as the view's share of the
 * content.
 */
final class Scrollbar
{
    public const MIN_THUMB = 24.0;
    /** The thumb takes presses this far around it. */
    public const GRAB = 4.0;

    /**
     * $box is the view the bar runs along, at its right edge, or its bottom
     * edge for a $horizontal bar, in the coordinates the bar is painted or
     * hit tested in: [x, y, w, h]. $view is the length of the content the
     * view shows, $content the length of all of it, and $offset how far it
     * is scrolled, in any unit, as rows of a terminal's scrollback: the bar
     * shows nothing unless $content is longer than $view.
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     */
    public function __construct(
        public array $box = [0.0, 0.0, 0.0, 0.0],
        public bool $horizontal = false,
        public float $view = 0.0,
        public float $content = 0.0,
        public float $offset = 0.0,
    ) {}

    /** Whether the bar has anywhere to scroll, and so shows. */
    public function scrolls(): bool
    {
        return $this->content > $this->view + 0.5;
    }

    /** Where the track starts along the bar, and its length. @return array{0: float, 1: float} */
    private function along(): array
    {
        return $this->horizontal ? [$this->box[0], max($this->box[2], 0.0)] : [$this->box[1], max($this->box[3], 0.0)];
    }

    /** Where the thumb starts along the bar, as the box's x for a horizontal bar, else its y, and how long it is. @return array{0: float, 1: float} */
    public function thumb(): array
    {
        [$pos, $track] = $this->along();
        $inner = $track - 4;
        $content = max($this->content, $this->view);
        $len = min(max($content > 0 ? $inner * $this->view / $content : $inner, self::MIN_THUMB), $inner);
        $travel = $inner - $len;
        $at = $content > $this->view ? max(0.0, min($travel * $this->offset / ($content - $this->view), $travel)) : 0.0;
        return [$pos + 2 + $at, $len];
    }

    /** The offset that brings the thumb's start to $start, as the thumb is dragged, within the content. */
    public function offsetAt(float $start): float
    {
        [$pos, $track] = $this->along();
        [, $len] = $this->thumb();
        $travel = $track - 4 - $len;
        $reach = $this->content - $this->view;
        if ($travel <= 0 || $reach <= 0) { return 0.0; }
        return max(0.0, min(($start - $pos - 2) / $travel, 1.0)) * $reach;
    }

    /** @internal Painter::scrollbar: the thumb's box, $width points across, three in from the edge. @return array{0: float, 1: float, 2: float, 3: float} */
    public function thumbBox(float $width): array
    {
        [$start, $len] = $this->thumb();
        return $this->horizontal
            ? [$start, $this->box[1] + $this->box[3] - $width - 3, $len, $width]
            : [$this->box[0] + $this->box[2] - $width - 3, $start, $width, $len];
    }

    /** The box a scroll container's bars run along: $r less its bars' insets (Node::scrollbarInsets), top, right, bottom and left. @param array{0: float, 1: float, 2: float, 3: float} $r @param array{0: float, 1: float, 2: float, 3: float} $inset @return array{0: float, 1: float, 2: float, 3: float} */
    public static function track(array $r, array $inset): array
    {
        return [$r[0] + $inset[3], $r[1] + $inset[0], max(0.0, $r[2] - $inset[1] - $inset[3]), max(0.0, $r[3] - $inset[0] - $inset[2])];
    }

    /**
     * @internal Painter, Runtime: the thumb's rectangle for a container at [x, y, w, h] with $extent,
     * or null when the content fits the view. Right to left ($rtl), the
     * vertical thumb is at the left, and the horizontal one starts at the
     * right, where the offset counts from.
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function thumbOf(array $r, array $extent, float $width, bool $horizontal = false, bool $rtl = false): ?array
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
            ? [$r[0] + 2 + ($rtl ? $travel - $at : $at), $r[1] + $r[3] - $width - 3, $thumb, $width] // three points in from the edge (mygo's scrollBars), two from the ends
            : [$rtl ? $r[0] + 3 : $r[0] + $r[2] - $width - 3, $r[1] + 2 + $at, $width, $thumb];
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
