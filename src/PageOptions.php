<?php
declare(strict_types=1);

namespace MyPHP;

/** What a window's or a web view's page is made with (mygo's PageOptions). */
final class PageOptions
{
    /** @param list<string> $trustedOrigins */
    public function __construct(
        /** JavaScript run in every page before its own scripts, after window.myphp exists (mygo's PreloadScript). */
        public string $preloadScript = '',
        /**
         * Origins, such as "https://example.com", whose pages may call the
         * app's bound methods too (mygo's TrustedOrigins). By default only
         * the app's own pages can: its html, file: pages, the frontend and
         * the schemes Protocol serves, and loopback dev servers under
         * `myphp dev`. "*" trusts every origin.
         */
        public array $trustedOrigins = [],
        /** The developer tools (mygo's DevTools): a page's web inspector; for a window showing native UI, the inspector of native UI (View → Toggle Developer Tools, F12). */
        public DevTools $devTools = DevTools::Auto,
        /** The page's zoom, 1 by default (mygo's ZoomFactor). */
        public float $zoomFactor = 1.0,
        /** Overrides the user agent (mygo's UserAgent). */
        public string $userAgent = '',
        /**
         * Appends to the page's user agent instead of replacing it, as $userAgent does (mygo's UserAgentSuffix): the
         * app's name and version, such as "MyApp/1.2", for the sites that read them. It appends to $userAgent too,
         * which Page::setUserAgent keeps as well; spaces around it are trimmed. A window.open() window does not inherit
         * it. Some sites serve a bare web view, whose user agent names no browser, older pages: "Version/17.0
         * Safari/605.1.15" makes the page look like Safari.
         */
        public string $userAgentSuffix = '',
        /** Lets a horizontal swipe of a trackpad or a Magic Mouse go back and forward through the page's history, as browsers do (mygo's SwipeNavigation, WKWebView's allowsBackForwardNavigationGestures); off, as an app's pages have their own way back. */
        public bool $swipeNavigation = false,
        /** Lets a pinch magnify the page, and the trackpad's smart zoom, as browsers do (mygo's PinchZoom, WKWebView's allowsMagnification): it scales what the page shows without laying it out again, as $zoomFactor does; off, as in desktop apps. */
        public bool $pinchZoom = false,
    ) {}
}
