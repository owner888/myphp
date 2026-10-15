<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\UI\Content;

/** What a window is created with; the subset of mygo's WindowOptions done so far. */
final class WindowOptions
{
    public function __construct(
        /** Defaults to the application name. */
        public ?string $title = null,
        /** Size of the content area in points. */
        public int $width = 800,
        public int $height = 600,
        /** Top-left corner; the window is centered when both are zero. */
        public int $x = 0,
        public int $y = 0,
        /** Creates the window without showing it; call Window::show(). */
        public bool $hidden = false,
        public bool $disableResize = false,
        /** Native UI drawn by MyPHP: make it with Content::view(). */
        public ?Content $content = null,
        /** A web page instead: HTML to show, or a URL to load (http(s) or file:). */
        public ?string $html = null,
        public ?string $url = null,
    ) {}
}
