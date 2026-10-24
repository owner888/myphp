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
    ) {}
}
