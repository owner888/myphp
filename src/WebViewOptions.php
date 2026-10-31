<?php
declare(strict_types=1);

namespace MyPHP;

/** What Window::newWebView makes a web view with (mygo's WebViewOptions). */
final class WebViewOptions
{
    public function __construct(
        /** Loaded at once: "/" and other URLs without a scheme are pages of the app's frontend (Page::loadURL). */
        public string $url = '',
        /** The web view's page. */
        public PageOptions $page = new PageOptions(),
        /** Fills the web view until its page paints, as WindowOptions::$backgroundColor: by default the page's, or white. */
        public string $backgroundColor = '',
        /** Shows what the window's view paints under the web view through the transparent parts of its page. */
        public bool $transparent = false,
        /**
         * Gives the wheel and the touchpad over the page to the native UI
         * under the pointer, as over its other elements, for a page as tall
         * as its content in a scroll container (mygo's ScrollToContainer):
         * the page never scrolls with them, and still takes the presses,
         * the keyboard, selection and a touchpad's pinch.
         */
        public bool $scrollToContainer = false,
    ) {}
}
