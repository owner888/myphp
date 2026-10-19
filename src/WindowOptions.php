<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\UI\Content;

/** What a window is created with: mygo's WindowOptions, as far as done (no panels nor flyouts yet; Linux's and Windows' own options have no place here). */
final class WindowOptions
{
    public function __construct(
        /** Defaults to the application name. */
        public ?string $title = null,
        /** Size of the window in points, or of its content with $useContentSize. */
        public int $width = 800,
        public int $height = 600,
        public bool $useContentSize = false,
        /** Top-left corner, from the top-left corner of the primary screen; the window is centered when both are zero. */
        public int $x = 0,
        public int $y = 0,
        /** How small and how large the user can make it; 0 for no limit. */
        public int $minWidth = 0,
        public int $minHeight = 0,
        public int $maxWidth = 0,
        public int $maxHeight = 0,
        /** Creates the window without showing it; call Window::show(), on 'readyToShow' to show no blank window. */
        public bool $hidden = false,
        /** Removes the title bar and the window controls: native UI drags the window by Node::dragWindow, a page by --app-region: drag. */
        public bool $frameless = false,
        public bool $disableResize = false,
        public bool $disableMove = false,
        public bool $disableMinimize = false,
        public bool $disableMaximize = false,
        public bool $disableClose = false,
        public bool $disableFullScreen = false,
        public bool $disableShadow = false,
        public bool $alwaysOnTop = false,
        public bool $fullScreen = false,
        /** Creates the window maximized. */
        public bool $maximized = false,
        /** Makes the window's background transparent: a page without a background, or native UI whose root's background is taken away, shows the desktop through. */
        public bool $transparent = false,
        /** Fills the window until the page paints, around native UI where it draws nothing (Window::setBackgroundColor): a CSS color, "#1e1e1e", "rgb(30 30 30)", or "light-dark(#f5f5f7, #1e1e1e)" for one that follows the appearance; '' for the system's. */
        public string $backgroundColor = '',
        /** A translucent, blurred material behind a page without a background, or native UI where it draws none (Window::setVibrancy): 'sidebar', 'window', 'content', 'header', 'titlebar', 'under-window', 'under-page', 'menu', 'popover', 'hud', 'sheet', 'tooltip', 'selection', 'fullscreen-ui'; '' for none. */
        public string $vibrancy = '',
        /** The window's opacity, from 0 to 1 (1 when not set). */
        public float $opacity = 1.0,
        /** Makes the window a child that stays above $parent; $modal makes it a sheet of it. */
        public ?Window $parent = null,
        public bool $modal = false,
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
