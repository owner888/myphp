<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * How a Scrollbar paints (mygo's ui.ScrollbarState): $dragging while the
 * pointer drags its thumb, which widens it, as a scroll container's;
 * $color, if not null, colors it for a view whose colors are not the
 * theme's, as a terminal's, in place of Theme::$scrollbar, as faint as the
 * theme's.
 */
final class ScrollbarState
{
    public function __construct(
        public bool $dragging = false,
        public ?int $color = null,
    ) {}
}
