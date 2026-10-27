<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What Context::videoPlayer builds besides the picture and its control
 * bar: the title bar over the picture (for a window of a hidden title
 * bar), the messages at the top left, the sidebar of quick settings, and
 * IINA's default keys (off, the app binds its own).
 */
final class VideoPlayerOptions
{
    public function __construct(
        public readonly bool $titleBar = true,
        public readonly bool $osd = true,
        public readonly bool $sidebar = true,
        public readonly bool $keys = true,
    ) {}
}
