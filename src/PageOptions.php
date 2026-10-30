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
        /** Runs $preloadScript in the page's iframes too, of every origin, as they start, where window.myphp is not (mygo's PreloadAllFrames): it sees what a player embedded from another site loads. */
        public bool $preloadAllFrames = false,
        /**
         * Origins, such as "https://example.com", whose pages may call the
         * app's bound methods too (mygo's TrustedOrigins). By default only
         * the app's own pages can: its html, file: pages, the frontend and
         * the schemes Protocol serves, and loopback dev servers under
         * `myphp dev`. "*" trusts every origin.
         */
        public array $trustedOrigins = [],
        /**
         * Makes the page someone else's, whatever its URL (mygo's
         * Untrusted): a plugin's UI or a preview of a user's HTML served
         * from a scheme Protocol serves. $trustedOrigins no longer count,
         * and the page calls no PHP method, bound or MyPHP's own
         * (myphp:window), and its window's drag regions do nothing; MyPHP
         * drops whatever else it posts above 4 KiB without decoding it. It
         * hears no Event::broadcast, nor the Event::emit of the window of
         * native UI its web view is in: send to it with Event::emitPage. It
         * gets no permission, opens no window nor the browser, downloads
         * nothing and navigates to no other origin than its page's, unless
         * setPermissionHandler, setWindowOpenHandler, 'willDownload' or
         * 'willNavigate' listeners decide. Its requests to the schemes
         * Protocol serves reach their handlers, which tell it by
         * ProtocolRequest::$page, but it cannot read the responses of other
         * origins than its own.
         */
        public bool $untrusted = false,
        /**
         * Lets the page's videos go into picture in picture, a small window
         * floating over every app's (mygo's PictureInPicture): the button
         * of a video's controls, its context menu's item and
         * requestPictureInPicture(), with WebKit's private
         * _allowsPictureInPictureMediaPlayback, which WKWebView keeps to
         * Safari otherwise. Page::togglePictureInPicture moves one out.
         */
        public bool $pictureInPicture = false,
        /** The session whose website data the page uses: cookies, storage, caches (mygo's Session); null for the default one. A window window.open opens keeps its opener's. */
        public ?Session $session = null,
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
        /** @var list<ContentRules> rules that block what the page loads and hide parts of it, as Page::setContentRules does, from its first load (mygo's ContentRules) */
        public array $contentRules = [],
        /** Whether the page may play audible media before the user clicked or typed in it (mygo's Autoplay). */
        public Autoplay $autoplay = Autoplay::UserGesture,
        /** Whether the page slows down while its window is hidden, minimized or covered (mygo's BackgroundThrottling). */
        public BackgroundThrottling $backgroundThrottling = BackgroundThrottling::On,
        /**
         * Brings back a page as Page::sessionState() saved it, in place of
         * the window's or web view's URL: its back/forward list, with the
         * scroll positions and form fields saved, at the entry it was at
         * (mygo's SessionState). A state WebKit cannot read loads the URL it
         * was at alone; one that is no state loads the URL.
         */
        public ?string $sessionState = null,
    ) {}
}
