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
        /** JavaScript run in the page before its own scripts, after window.myphp exists (mygo's PreloadScript). */
        public string $preload = '',
        /** Remembers the window's position and size under this key across runs (WindowState). */
        public string $stateKey = '',
        /** 'default', or 'hidden' / 'hiddenInset' (mygo's TitleBarStyle): the title bar hides but the traffic lights stay, over the view, which draws its own title bar under them (Context::titleBar, Node::dragWindow); hiddenInset lowers the lights as a toolbar would. */
        public string $titleBarStyle = 'default',
        /** Moves the window controls of a window with a hidden title bar (mygo's TrafficLightPosition): [x, y] points from the window's top-left corner to the close button's; the bar leaves as much room below them as above. */
        public ?array $trafficLightPosition = null,
    ) {}
}
