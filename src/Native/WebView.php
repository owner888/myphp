<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use RuntimeException;

/**
 * A WKWebView with the MyPHP bridge installed: the bridge script runs at
 * document start, messages the page posts reach $onMessage, and deliver()
 * hands replies and events back to the page. Its page's life — titles,
 * navigations, loads, failures, a crash, window.open(), the JavaScript
 * panels — reaches the hooks Page sets (mygo's darwin window.go).
 */
final class WebView
{
    private const INJECT_AT_DOCUMENT_START = 0;
    private const AUTORESIZE_WIDTH_HEIGHT = 2 | 16;
    private static bool $defined = false;
    /** @var array<int, WebView> by WKWebView address */
    private static array $views = [];

    public $ns;
    /** Asked whether the main frame may go to a URL: (url, userInitiated, a reload, one the app started) → allowed. @var ?Closure(string, bool, bool, bool): bool */
    public ?Closure $onNavigate = null;
    /** Loads the app starts, whose navigations onNavigate is told it started. */
    private int $loads = 0;
    /** Asked whether the page may use the camera or the microphone: (list of "camera", "microphone", the page's origin) → granted. @var ?Closure(list<string>, string): bool */
    public ?Closure $onPermission = null;
    /** Asked where a download goes: (url, suggested file name) → path, or null to cancel; told when it ended: (url, path, ?error). @var ?Closure(string, string): ?string */
    public ?Closure $onDownload = null;
    /** @var ?Closure(string, string, ?string): void */
    public ?Closure $onDownloadDone = null;
    /** Serves a request of a custom scheme (Protocol): (the request's [method, URL, headers, body], its task). @var ?Closure(array, SchemeTask): void */
    public ?Closure $onScheme = null;
    /** Serves a URL of a custom scheme into a download, which WebKit can't make of what a scheme handler serves. @var ?Closure(string): void */
    public ?Closure $onSchemeDownload = null;
    /** The page's <title> changed. @var ?Closure(string): void */
    public ?Closure $onTitle = null;
    /** A navigation committed, at its URL. @var ?Closure(string): void */
    public ?Closure $onCommit = null;
    /** The page finished loading. @var ?Closure(): void */
    public ?Closure $onFinish = null;
    /** A load failed: (URL, code, description). @var ?Closure(string, int, string): void */
    public ?Closure $onFail = null;
    /** The page's web content process went away. @var ?Closure(string): void */
    public ?Closure $onGone = null;
    /** window.open(), or a target=_blank link: (URL, frame name, width, height, the configuration WebKit hands over, whether a user's gesture opened it) → the WKWebView to open it in, or null. @var ?Closure(string, string, int, int, mixed, bool): mixed */
    public ?Closure $onOpen = null;
    /** The page called window.close(). @var ?Closure(): void */
    public ?Closure $onClosedByPage = null;
    /** Shows alert() (kind 'alert', the message), confirm() ('confirm', the message) → bool, prompt() ('prompt', the prompt, the default) → ?string, and a file input ('files', allows several, allows folders) → ?list<string>, as sheets. @var ?Closure(string, mixed...): mixed */
    public ?Closure $onPanel = null;
    /** The user content controller, whose scripts setUserScripts() replaces. */
    private $ucc = null;
    /** What the user agent ends with, after a space (PageOptions::$userAgentSuffix). */
    private string $suffix = '';
    private static $schemeHandler = null;
    private static $navigationDelegate = null, $uiDelegate = null;
    /** @var array<int, array{0: WebView, 1: string, 2: string}> the downloads under way by WKDownload address: their view, URL and path */
    private static array $downloads = [];
    /** @var array<int, \Closure(bool): void> the print operations to PDF under way, by their context */
    private static array $printJobs = [];
    private static int $nextJob = 1;

    private function __construct(private Closure $onMessage) {}

    /** Whether the page has its web inspector (WebView::create's $devTools). */
    private bool $devTools = false;

    /** WebKit's inspector of the page, which WKWebView keeps to itself (mygo's darwin inspector), or null without developer tools. */
    private function inspector()
    {
        if (!$this->devTools || !ObjC::msg('b:p', $this->ns, 'respondsToSelector:', ObjC::sel('_inspector'))) {
            return null;
        }
        return ObjC::msg('p:', $this->ns, '_inspector');
    }

    /** Opens the web inspector, docked in the window (mygo's OpenDevTools). */
    public function openDevTools(): void
    {
        if (($in = $this->inspector()) !== null) { ObjC::msg('v:', $in, 'show'); }
    }

    public function closeDevTools(): void
    {
        if (($in = $this->inspector()) !== null) { ObjC::msg('v:', $in, 'close'); }
    }

    public function isDevToolsOpened(): bool
    {
        $in = $this->inspector();
        return $in !== null && ObjC::msg('b:p', $in, 'respondsToSelector:', ObjC::sel('isVisible')) && ObjC::msg('b:', $in, 'isVisible');
    }

    /**
     * A web view of $frame whose pages get $scripts at document start, the
     * bridge first (mygo's createWebView): with the configuration WebKit
     * handed over for window.open(), or a new one, either way with a
     * content controller of its own, so that messages and scripts never
     * leak between pages. $autoplay lets its page play audible media
     * without a gesture (PageOptions::$autoplay), and !$throttle keeps it
     * running while hidden (PageOptions::$backgroundThrottling).
     * @param list<string|array{0: string, 1: bool}> $scripts a script, or one and whether it goes in every frame
     */
    public static function create($frame, array $scripts, Closure $onMessage, bool $devTools = false, $configuration = null, string $userAgent = '', float $zoom = 1.0, string $suffix = '', bool $swipe = false, bool $pinch = false, bool $autoplay = false, bool $throttle = true): self
    {
        self::define();
        $self = new self($onMessage);
        $self->suffix = $suffix;
        if ($configuration !== null) {
            $wk = ObjC::msg('p:', $configuration, 'retain');
        } else {
            $wk = ObjC::msg('p:', ObjC::alloc('WKWebViewConfiguration'), 'init');
            foreach (\MyPHP\Protocol::schemes() as $scheme) { // the schemes PHP serves: handled before the view exists, as WebKit takes them only then
                ObjC::msg('v:pp', $wk, 'setURLSchemeHandler:forURLScheme:', self::$schemeHandler, ObjC::str($scheme));
            }
        }
        self::setAutoplay($wk, $autoplay, $configuration !== null);
        if (!$throttle || $configuration !== null) {
            self::setThrottling($wk, $throttle, $configuration !== null); // a window.open() window's own, not its opener's
        }
        $prefs = ObjC::msg('p:', $wk, 'preferences');
        $yes = fn(bool $b) => ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', $b);
        // The web inspector (mygo's DevTools): its context menu item, Safari's Develop menu (macOS 13.3), openDevTools.
        ObjC::msg('v:pp', $prefs, 'setValue:forKey:', $yes($devTools), ObjC::str('developerExtrasEnabled'));
        ObjC::msg('v:pp', $prefs, 'setValue:forKey:', $yes(true), ObjC::str('allowFileAccessFromFileURLs'));
        if (ObjC::msg('b:p', $prefs, 'respondsToSelector:', ObjC::sel('setElementFullscreenEnabled:'))) {
            ObjC::msg('v:b', $prefs, 'setElementFullscreenEnabled:', true);
        }
        $self->ucc = ObjC::msg('p:', ObjC::alloc('WKUserContentController'), 'init');
        ObjC::msg('v:pp', $self->ucc, 'addScriptMessageHandler:name:', ObjC::msg('p:', ObjC::alloc('MyPHPScriptHandler'), 'init'), ObjC::str('myphp'));
        $self->setUserScripts($scripts);
        ObjC::msg('v:p', $wk, 'setUserContentController:', $self->ucc);
        // WebKit appends the application name to the web view's user agent, and window.open() hands over the
        // configuration of the window that opens it: the new window's options decide, which never inherit a suffix.
        if ($suffix !== '' || $configuration !== null) {
            ObjC::msg('v:p', $wk, 'setApplicationNameForUserAgent:', $suffix === '' ? null : ObjC::str($suffix));
        }
        $self->ns = ObjC::msg('p:rp', ObjC::alloc('MyPHPWebView'), 'initWithFrame:configuration:', $frame, $wk);
        ObjC::msg('v:', $wk, 'release');
        if (ObjC::msg('b:p', $self->ns, 'respondsToSelector:', ObjC::sel('setInspectable:'))) {
            ObjC::msg('v:b', $self->ns, 'setInspectable:', $devTools);
        }
        $self->devTools = $devTools;
        if ($userAgent !== '') {
            $self->setUserAgent($userAgent);
        }
        if ($zoom > 0 && $zoom != 1) {
            $self->setZoom($zoom);
        }
        // Both off in a new WKWebView (PageOptions::$swipeNavigation, $pinchZoom).
        if ($swipe) { ObjC::msg('v:b', $self->ns, 'setAllowsBackForwardNavigationGestures:', true); }
        if ($pinch) { ObjC::msg('v:b', $self->ns, 'setAllowsMagnification:', true); }
        if (!$throttle && self::responds($self->ns, '_setWindowOcclusionDetectionEnabled:')) {
            ObjC::msg('v:b', $self->ns, '_setWindowOcclusionDetectionEnabled:', false); // a window behind others counts as visible
        }
        Drop::register($self->ns);
        ObjC::msg('v:p', $self->ns, 'setNavigationDelegate:', self::$navigationDelegate); // weak: the class keeps it
        ObjC::msg('v:p', $self->ns, 'setUIDelegate:', self::$uiDelegate);
        ObjC::msg('v:L', $self->ns, 'setAutoresizingMask:', self::AUTORESIZE_WIDTH_HEIGHT);
        ObjC::msg('v:ppLp', $self->ns, 'addObserver:forKeyPath:options:context:', self::$navigationDelegate, ObjC::str('title'), 1, null); // NSKeyValueObservingOptionNew
        self::$views[ObjC::addr($self->ns)] = $self;
        return $self;
    }

    /** Replaces the scripts pages get at document start, which a content controller can only remove all of (mygo's SetUserScripts). @param list<string|array{0: string, 1: bool}> $scripts a script of the main frame, or one and whether it goes in every frame */
    public function setUserScripts(array $scripts): void
    {
        ObjC::msg('v:', $this->ucc, 'removeAllUserScripts');
        foreach ($scripts as $s) {
            [$src, $all] = is_array($s) ? $s : [$s, false];
            $script = ObjC::msg('p:plb', ObjC::alloc('WKUserScript'), 'initWithSource:injectionTime:forMainFrameOnly:', ObjC::str($src), self::INJECT_AT_DOCUMENT_START, !$all);
            ObjC::msg('v:p', $this->ucc, 'addUserScript:', $script);
            ObjC::msg('v:', $script, 'release');
        }
    }

    /** Replaces the content controller's rule lists (WKContentRuleLists), which WebKit applies to the loads from then on. @param list<mixed> $lists */
    public function setContentRules(array $lists): void
    {
        if ($this->ucc === null) { return; }
        ObjC::msg('v:', $this->ucc, 'removeAllContentRuleLists');
        foreach ($lists as $l) { ObjC::msg('v:p', $this->ucc, 'addContentRuleList:', $l); }
    }

    private static function responds($obj, string $sel): bool
    {
        return ObjC::msg('b:p', $obj, 'respondsToSelector:', ObjC::sel($sel));
    }

    /**
     * Lets the page of $cfg play audible media without a gesture: no media
     * type requires the user's action (WKAudiovisualMediaTypeNone). A
     * configuration window.open() handed over is a copy of the opener's,
     * which a window that does not ask gets WebKit's default back on
     * (mygo's setAutoplay).
     */
    private static function setAutoplay($cfg, bool $allow, bool $opened): void
    {
        if (!self::responds($cfg, 'setMediaTypesRequiringUserActionForPlayback:')) { return; }
        if ($allow) {
            ObjC::msg('v:L', $cfg, 'setMediaTypesRequiringUserActionForPlayback:', 0);
        } elseif ($opened) {
            $defaults = ObjC::msg('p:', ObjC::alloc('WKWebViewConfiguration'), 'init');
            ObjC::msg('v:L', $cfg, 'setMediaTypesRequiringUserActionForPlayback:', ObjC::msg('L:', $defaults, 'mediaTypesRequiringUserActionForPlayback'));
            ObjC::msg('v:', $defaults, 'release');
        }
    }

    /**
     * The preferences with which WebKit slows down a page it cannot see,
     * and the value of each that keeps it running (mygo's throttlingPrefs):
     * the public inactiveSchedulingPolicy, whose none keeps the web
     * content process of a window behind others from being suspended
     * (macOS 14), and private ones, looked up with respondsToSelector:,
     * throttling a hidden page's timers, suspending its CSS animations and
     * letting App Nap slow its process. [getter, setter, off, a bool].
     */
    private const THROTTLING = [
        ['inactiveSchedulingPolicy', 'setInactiveSchedulingPolicy:', 2, false],
        ['_hiddenPageDOMTimerThrottlingEnabled', '_setHiddenPageDOMTimerThrottlingEnabled:', 0, true],
        ['_hiddenPageCSSAnimationSuspensionEnabled', '_setHiddenPageCSSAnimationSuspensionEnabled:', 0, true],
        ['_pageVisibilityBasedProcessSuppressionEnabled', '_setPageVisibilityBasedProcessSuppressionEnabled:', 0, true],
    ];

    /**
     * Sets THROTTLING in $cfg's preferences: WebKit's defaults when
     * $throttle, else the values keeping a hidden page running. The
     * preferences of a configuration window.open() hands over ($shared)
     * are the opener's: a window that wants them otherwise gets a copy
     * (mygo's setBackgroundThrottling).
     */
    private static function setThrottling($cfg, bool $throttle, bool $shared): void
    {
        $prefs = ObjC::msg('p:', $cfg, 'preferences');
        $defaults = $throttle ? ObjC::msg('p:', ObjC::alloc('WKPreferences'), 'init') : null;
        $get = fn($o, array $p): int => $p[3] ? (ObjC::msg('b:', $o, $p[0]) ? 1 : 0) : ObjC::msg('l:', $o, $p[0]);
        foreach (self::THROTTLING as $p) {
            if (!self::responds($prefs, $p[0]) || !self::responds($prefs, $p[1])) { continue; }
            $v = $defaults !== null ? $get($defaults, $p) : $p[2];
            if ($get($prefs, $p) === $v) { continue; }
            if ($shared && self::responds($prefs, 'copyWithZone:')) {
                $shared = false;
                $prefs = ObjC::msg('p:', $prefs, 'copy');
                ObjC::msg('v:p', $cfg, 'setPreferences:', $prefs);
                ObjC::msg('v:', $prefs, 'release'); // the configuration keeps it
            }
            $p[3] ? ObjC::msg('v:b', $prefs, $p[1], $v === 1) : ObjC::msg('v:l', $prefs, $p[1], $v);
        }
        if ($defaults !== null) { ObjC::msg('v:', $defaults, 'release'); }
    }

    /** The page's web content process, from WebKit's private _webProcessIdentifier, 0 when it does not answer it or runs none yet (mygo's ProcessID). */
    public function processID(): int
    {
        return self::responds($this->ns, '_webProcessIdentifier') ? ObjC::msg('i:', $this->ns, '_webProcessIdentifier') : 0;
    }

    /** WKWebView's interactionState, WebKit's own encoding of the back/forward list, '' when it gives none (mygo's SessionState). */
    public function sessionState(): string
    {
        $s = ObjC::msg('p:', $this->ns, 'interactionState');
        if ($s === null || !ObjC::msg('b:p', $s, 'isKindOfClass:', ObjC::cls('NSData'))) { return ''; }
        $n = ObjC::msg('L:', $s, 'length');
        return $n === 0 ? '' : \FFI::string(ObjC::$c->cast('char*', ObjC::msg('p:', $s, 'bytes')), $n);
    }

    /** Sets interactionState, which restores the back/forward list and goes to its current item, and reports whether there is one: WebKit ignores what it cannot decode (mygo's RestoreSessionState). */
    public function restoreSessionState(string $state): bool
    {
        $this->loads++;
        ObjC::msg('v:p', $this->ns, 'setInteractionState:', ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $state, strlen($state)));
        if (ObjC::msg('p:', ObjC::msg('p:', $this->ns, 'backForwardList'), 'currentItem') !== null) { return true; }
        $this->loads--;
        return false;
    }

    /**
     * Whether a user's gesture started $action: WebKit's private
     * _isUserInitiated knows, a click whose handler navigates or calls
     * window.open() included; without it, a link or a form (mygo's
     * userInitiated).
     */
    private static function userInitiated($action): bool
    {
        if (self::responds($action, '_isUserInitiated')) { return ObjC::msg('b:', $action, '_isUserInitiated'); }
        $type = ObjC::msg('l:', $action, 'navigationType');
        return $type === 0 || $type === 1; // WKNavigationTypeLinkActivated, FormSubmitted
    }

    /** Lets the web view go: its observer, scripts and delegates, its page stopped (mygo's cleanup). */
    public function close(): void
    {
        if ($this->ucc === null) {
            return;
        }
        ObjC::msg('v:pp', $this->ns, 'removeObserver:forKeyPath:', self::$navigationDelegate, ObjC::str('title'));
        ObjC::msg('v:p', $this->ucc, 'removeScriptMessageHandlerForName:', ObjC::str('myphp'));
        ObjC::msg('v:', $this->ucc, 'removeAllUserScripts');
        ObjC::msg('v:', $this->ns, 'stopLoading');
        ObjC::msg('v:p', $this->ns, 'setNavigationDelegate:', null);
        ObjC::msg('v:p', $this->ns, 'setUIDelegate:', null);
        ObjC::msg('v:', $this->ucc, 'release');
        $this->ucc = null;
        unset(self::$views[ObjC::addr($this->ns)]);
    }

    public function reload(bool $ignoreCache): void { $this->navigate($ignoreCache ? 'reloadFromOrigin' : 'reload'); }
    public function stop(): void { ObjC::msg('v:', $this->ns, 'stopLoading'); }
    public function goBack(): void { $this->navigate('goBack'); }
    public function goForward(): void { $this->navigate('goForward'); }

    /** A navigation the app starts, which the 'willNavigate' listeners are not asked about: none when WebKit starts none (nothing to go back to). */
    private function navigate(string $sel): void
    {
        $this->loads++;
        if (ObjC::msg('p:', $this->ns, $sel) === null) {
            $this->loads--;
        }
    }
    public function canGoBack(): bool { return ObjC::msg('b:', $this->ns, 'canGoBack'); }
    public function canGoForward(): bool { return ObjC::msg('b:', $this->ns, 'canGoForward'); }
    public function isLoading(): bool { return ObjC::msg('b:', $this->ns, 'isLoading'); }

    /** The page's zoom (pageZoom, macOS 11). */
    public function setZoom(float $f): void { ObjC::msg('v:d', $this->ns, 'setPageZoom:', $f); }
    public function zoom(): float { return ObjC::msg('d:', $this->ns, 'pageZoom'); }

    /** Overrides the user agent, the suffix still after it; '' restores WebKit's, which the configuration still appends the suffix to. */
    public function setUserAgent(string $ua): void
    {
        if ($ua !== '' && $this->suffix !== '') { $ua .= ' ' . $this->suffix; }
        ObjC::msg('v:p', $this->ns, 'setCustomUserAgent:', $ua === '' ? null : ObjC::str($ua));
    }

    /** The user agent the page is loaded with: the one set, else WebKit's. */
    public function userAgent(): string
    {
        $s = ObjC::msg('p:', $this->ns, 'customUserAgent');
        if (($s === null || ObjC::phpStr($s) === '') && ObjC::msg('b:p', $this->ns, 'respondsToSelector:', ObjC::sel('_userAgent'))) {
            $s = ObjC::msg('p:', $this->ns, '_userAgent');
        }
        return $s === null ? '' : ObjC::phpStr($s);
    }

    /** Loads a local file, with read access to $readAccessDir (mygo's LoadFile). */
    public function loadFile(string $path, string $readAccessDir): void
    {
        $url = fn(string $p) => ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($p));
        $this->loads++;
        ObjC::msg('p:pp', $this->ns, 'loadFileURL:allowingReadAccessToURL:', $url($path), $url($readAccessDir));
    }

    /**
     * Runs $body as the body of an async function in the page's world
     * (callAsyncJavaScript, macOS 11), not subject to the page's Content
     * Security Policy; $done gets the string it returns, or the error: the
     * script's exception message ("SyntaxError: …") when it threw.
     * @param Closure(?string, ?string): void $done
     */
    public function callAsync(string $body, Closure $done): void
    {
        if (!ObjC::msg('b:p', $this->ns, 'respondsToSelector:', ObjC::sel('callAsyncJavaScript:arguments:inFrame:inContentWorld:completionHandler:'))) {
            $done(null, 'myphp: eval needs macOS 11 or later');
            return;
        }
        ObjC::msg('v:ppppp', $this->ns, 'callAsyncJavaScript:arguments:inFrame:inContentWorld:completionHandler:', ObjC::str($body), null, null,
            ObjC::msg('p:', ObjC::cls('WKContentWorld'), 'pageWorld'), ObjC::block('pp', function ($result, $error) use ($done): void {
                if ($error !== null) {
                    $done(null, self::errorText($error));
                    return;
                }
                $done($result === null ? '' : ObjC::phpStr($result), null);
            }));
    }

    /** A PNG of the page as it shows (takeSnapshot); $done gets it or the error. @param Closure(?string, ?string): void $done */
    public function capture(Closure $done): void
    {
        ObjC::msg('v:pp', $this->ns, 'takeSnapshotWithConfiguration:completionHandler:', null, ObjC::block('pp', function ($img, $error) use ($done): void {
            if ($error !== null || $img === null) {
                $done(null, $error === null ? 'myphp: no snapshot' : self::errorText($error));
                return;
            }
            $rep = ObjC::msg('p:p', ObjC::cls('NSBitmapImageRep'), 'imageRepWithData:', ObjC::msg('p:', $img, 'TIFFRepresentation'));
            $png = $rep === null ? null : ObjC::msg('p:Lp', $rep, 'representationUsingType:properties:', 4, ObjC::msg('p:', ObjC::cls('NSDictionary'), 'dictionary'));
            $png === null ? $done(null, 'myphp: the snapshot is no image') : $done(Drag::bytes($png), null);
        }));
    }

    /** An NSError's text: a script's exception message, else its description (mygo's nsError). */
    private static function errorText($error): string
    {
        $msg = ObjC::msg('p:p', ObjC::msg('p:', $error, 'userInfo'), 'objectForKey:', ObjC::str('WKJavaScriptExceptionMessage'));
        return ObjC::phpStr($msg ?? ObjC::msg('p:', $error, 'localizedDescription'));
    }

    private static function define(): void
    {
        if (self::$defined) {
            return;
        }
        self::$defined = true;
        // A WKWebView that hands dropped files to PHP (the page's own drop events do not see paths).
        ObjC::defineClass('MyPHPWebView', 'WKWebView', Drop::methods() + [
            // The press a drag region of the page moves the window with (mygo's lastMouseDown): the page tells of it after the event.
            'mouseDown:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) {
                if (self::$lastDown !== null) { ObjC::msg('v:', self::$lastDown, 'release'); }
                self::$lastDown = ObjC::msg('p:', $event, 'retain');
                if (($win = ObjC::msg('p:', $self, 'window')) !== null) { WindowDrag::pressed($win, $event); }
                ObjC::msgSuper('v:p', $self, 'WKWebView', 'mouseDown:', $event);
            }],
        ], ['NSDraggingDestination']);
        ObjC::defineClass('MyPHPScriptHandler', 'NSObject', [
            'userContentController:didReceiveScriptMessage:' => ['v@:@@', 'v(pppp)', function ($self, $cmd, $ucc, $message) {
                $view = self::$views[ObjC::addr(ObjC::msg('p:', $message, 'webView'))] ?? null;
                if ($view === null) {
                    return;
                }
                $body = ObjC::phpStr(ObjC::msg('p:', $message, 'body'));
                ($view->onMessage)($body);
            }],
        ]);
        // Requests of the schemes PHP serves (mygo's darwin scheme.go).
        ObjC::defineClass('MyPHPSchemeHandler', 'NSObject', [
            'webView:startURLSchemeTask:' => ['v@:@@', 'v(pppp)', function ($self, $cmd, $wk, $task) {
                $view = self::$views[ObjC::addr($wk)] ?? null;
                $t = new WebKitTask($task);
                if ($view?->onScheme === null) {
                    $t->respond(503, []);
                    $t->finish();
                    return;
                }
                ($view->onScheme)(WebKitTask::request($task), $t);
            }],
            'webView:stopURLSchemeTask:' => ['v@:@@', 'v(pppp)', function ($self, $cmd, $wk, $task) {
                (WebKitTask::$live[ObjC::addr($task)] ?? null)?->stop();
            }],
        ], ['WKURLSchemeHandler']);
        self::$schemeHandler = ObjC::msg('p:', ObjC::alloc('MyPHPSchemeHandler'), 'init');
        // Navigations of the main frame the app did not start go to the window's 'willNavigate' listeners.
        ObjC::defineClass('MyPHPNavigationDelegate', 'NSObject', [
            'webView:decidePolicyForNavigationAction:decisionHandler:' => ['v@:@@@?', 'v(ppppp)', function ($self, $cmd, $wk, $action, $handler) {
                $view = self::$views[ObjC::addr($wk)] ?? null;
                // A link with the download attribute (macOS 11.3); one of a scheme PHP serves is served into the download.
                if (ObjC::msg('b:p', $action, 'respondsToSelector:', ObjC::sel('shouldPerformDownload')) && ObjC::msg('b:', $action, 'shouldPerformDownload')) {
                    $url = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', ObjC::msg('p:', $action, 'request'), 'URL'), 'absoluteString'));
                    if (\MyPHP\Protocol::handlerFor((string)parse_url($url, PHP_URL_SCHEME)) !== null && $view?->onSchemeDownload !== null) {
                        ObjC::callBlock('v:l', $handler, 0); // WKNavigationActionPolicyCancel
                        ($view->onSchemeDownload)($url);
                        return;
                    }
                    ObjC::callBlock('v:l', $handler, 2); // WKNavigationActionPolicyDownload
                    return;
                }
                $frame = ObjC::msg('p:', $action, 'targetFrame');
                $allow = true;
                if ($view !== null && $frame !== null && ObjC::msg('b:', $frame, 'isMainFrame')) {
                    $unasked = $view->loads > 0; // one the app started
                    if ($unasked) { $view->loads--; }
                    if ($view->onNavigate !== null) {
                        $url = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', ObjC::msg('p:', $action, 'request'), 'URL'), 'absoluteString'));
                        $type = ObjC::msg('l:', $action, 'navigationType'); // 0 a link, 1 a form, 3 a reload
                        $allow = ($view->onNavigate)($url, self::userInitiated($action), $type === 3, $unasked);
                    }
                }
                ObjC::callBlock('v:l', $handler, $allow ? 1 : 0); // WKNavigationActionPolicyAllow, Cancel
            }],
            // Responses the page cannot show, or sent as attachments, are downloads (macOS 11.3).
            'webView:decidePolicyForNavigationResponse:decisionHandler:' => ['v@:@@@?', 'v(ppppp)', function ($self, $cmd, $wk, $nav, $handler) {
                $resp = ObjC::msg('p:', $nav, 'response');
                $url = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', $resp, 'URL'), 'absoluteString'));
                $custom = array_key_exists($url, Scheme::$dispositions); // served by PHP, whose headers WebKit doesn't keep
                $disposition = $custom ? Scheme::$dispositions[$url] : '';
                if (!$custom && ObjC::msg('b:p', $resp, 'respondsToSelector:', ObjC::sel('allHeaderFields'))) {
                    $d = ObjC::msg('p:p', ObjC::msg('p:', $resp, 'allHeaderFields'), 'objectForKey:', ObjC::str('Content-Disposition'));
                    $disposition = $d === null ? '' : ObjC::phpStr($d);
                }
                $attachment = str_starts_with(strtolower(trim($disposition)), 'attachment');
                if (!$attachment && ObjC::msg('b:', $nav, 'canShowMIMEType')) {
                    ObjC::callBlock('v:l', $handler, 1); // WKNavigationResponsePolicyAllow
                    return;
                }
                $view = self::$views[ObjC::addr($wk)] ?? null;
                if ($custom && $view?->onSchemeDownload !== null) {
                    ObjC::callBlock('v:l', $handler, 0); // Cancel: WebKit can't make a download of what a scheme handler serves, so it is served again into one
                    ($view->onSchemeDownload)($url);
                    return;
                }
                ObjC::callBlock('v:l', $handler, 2); // WKNavigationResponsePolicyDownload
            }],
            'webView:navigationAction:didBecomeDownload:' => ['v@:@@@', 'v(ppppp)', function ($self, $cmd, $wk, $action, $download) {
                self::$downloads[ObjC::addr($download)] = [self::$views[ObjC::addr($wk)] ?? null, '', ''];
                ObjC::msg('v:p', $download, 'setDelegate:', $self);
            }],
            'webView:navigationResponse:didBecomeDownload:' => ['v@:@@@', 'v(ppppp)', function ($self, $cmd, $wk, $nav, $download) {
                self::$downloads[ObjC::addr($download)] = [self::$views[ObjC::addr($wk)] ?? null, '', ''];
                ObjC::msg('v:p', $download, 'setDelegate:', $self);
            }],
            // WKDownloadDelegate.
            'download:decideDestinationUsingResponse:suggestedFilename:completionHandler:' => ['v@:@@@@?', 'v(pppppp)', function ($self, $cmd, $download, $resp, $suggested, $handler) {
                $k = ObjC::addr($download);
                $view = self::$downloads[$k][0] ?? null;
                if ($view === null || $view->onDownload === null) {
                    unset(self::$downloads[$k]);
                    ObjC::callBlock('v:p', $handler, null); // cancels the download
                    return;
                }
                $url = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', ObjC::msg('p:', $download, 'originalRequest'), 'URL'), 'absoluteString'));
                $path = ($view->onDownload)($url, ObjC::phpStr($suggested));
                if ($path === null) {
                    unset(self::$downloads[$k]);
                    ObjC::callBlock('v:p', $handler, null);
                    return;
                }
                @unlink($path); // WebKit refuses to replace a file
                self::$downloads[$k] = [$view, $url, $path];
                ObjC::callBlock('v:p', $handler, ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($path)));
            }],
            'observeValueForKeyPath:ofObject:change:context:' => ['v@:@@@^v', 'v(pppppp)', function ($self, $cmd, $keyPath, $object, $change, $context) {
                $view = self::$views[ObjC::addr($object)] ?? null;
                if ($view?->onTitle !== null && ObjC::phpStr($keyPath) === 'title') {
                    $t = ObjC::msg('p:', $object, 'title');
                    ($view->onTitle)($t === null ? '' : ObjC::phpStr($t));
                }
            }],
            'webView:didCommitNavigation:' => ['v@:@@', 'v(pppp)', function ($self, $cmd, $wk, $nav) {
                $view = self::$views[ObjC::addr($wk)] ?? null;
                if ($view?->onCommit !== null) { ($view->onCommit)($view->url()); }
            }],
            'webView:didFinishNavigation:' => ['v@:@@', 'v(pppp)', function ($self, $cmd, $wk, $nav) {
                (self::$views[ObjC::addr($wk)] ?? null)?->onFinish?->__invoke();
            }],
            'webView:didFailProvisionalNavigation:withError:' => ['v@:@@@', 'v(ppppp)', function ($self, $cmd, $wk, $nav, $error) {
                (self::$views[ObjC::addr($wk)] ?? null)?->loadFailed($error);
            }],
            'webView:didFailNavigation:withError:' => ['v@:@@@', 'v(ppppp)', function ($self, $cmd, $wk, $nav, $error) {
                (self::$views[ObjC::addr($wk)] ?? null)?->loadFailed($error);
            }],
            'webViewWebContentProcessDidTerminate:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $wk) {
                (self::$views[ObjC::addr($wk)] ?? null)?->onGone?->__invoke('terminated');
            }],
            'downloadDidFinish:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $download) {
                self::downloadEnded($download, null);
            }],
            'download:didFailWithError:resumeData:' => ['v@:@@@', 'v(ppppp)', function ($self, $cmd, $download, $error, $resume) {
                self::downloadEnded($download, ObjC::phpStr(ObjC::msg('p:', $error, 'localizedDescription')));
            }],
        ], ['WKNavigationDelegate']); // WebKit asks the download delegate's methods by respondsToSelector:; its protocol is not always registered at run time (objc_getProtocol finds none), so it is not declared
        self::$navigationDelegate = ObjC::msg('p:', ObjC::alloc('MyPHPNavigationDelegate'), 'init');
        ObjC::defineClass('MyPHPUIDelegate', 'NSObject', [
            // Camera and microphone requests (macOS 12).
            'webView:requestMediaCapturePermissionForOrigin:initiatedByFrame:type:decisionHandler:' => ['v@:@@@q@?', 'v(ppppplp)', function ($self, $cmd, $wk, $origin, $frame, $type, $handler) {
                $view = self::$views[ObjC::addr($wk)] ?? null;
                $kinds = [0 => ['camera'], 1 => ['microphone'], 2 => ['camera', 'microphone']][$type] ?? [];
                $grant = $view?->onPermission !== null && ($view->onPermission)($kinds, self::origin($origin));
                ObjC::callBlock('v:l', $handler, $grant ? 1 : 2); // WKPermissionDecisionGrant, Deny
            }],
            'webView:createWebViewWithConfiguration:forNavigationAction:windowFeatures:' => ['@@:@@@@', 'p(pppppp)', function ($self, $cmd, $wk, $cfg, $action, $features) {
                $view = self::$views[ObjC::addr($wk)] ?? null;
                if ($view?->onOpen === null) {
                    return null;
                }
                $num = function (string $key) use ($features): int {
                    $n = ObjC::msg('p:', $features, $key);
                    return $n === null ? 0 : ObjC::msg('l:', $n, 'integerValue');
                };
                $url = ObjC::msg('p:', ObjC::msg('p:', ObjC::msg('p:', $action, 'request'), 'URL'), 'absoluteString');
                return ($view->onOpen)($url === null ? '' : ObjC::phpStr($url), '', $num('width'), $num('height'), $cfg, self::userInitiated($action));
            }],
            'webViewDidClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $wk) {
                (self::$views[ObjC::addr($wk)] ?? null)?->onClosedByPage?->__invoke();
            }],
            'webView:runJavaScriptAlertPanelWithMessage:initiatedByFrame:completionHandler:' => ['v@:@@@@?', 'v(pppppp)', function ($self, $cmd, $wk, $message, $frame, $handler) {
                $view = self::$views[ObjC::addr($wk)] ?? null;
                if ($view?->onPanel !== null) { ($view->onPanel)('alert', ObjC::phpStr($message)); }
                ObjC::callBlock('v:', $handler);
            }],
            'webView:runJavaScriptConfirmPanelWithMessage:initiatedByFrame:completionHandler:' => ['v@:@@@@?', 'v(pppppp)', function ($self, $cmd, $wk, $message, $frame, $handler) {
                $view = self::$views[ObjC::addr($wk)] ?? null;
                ObjC::callBlock('v:b', $handler, $view?->onPanel !== null && ($view->onPanel)('confirm', ObjC::phpStr($message)));
            }],
            'webView:runJavaScriptTextInputPanelWithPrompt:defaultText:initiatedByFrame:completionHandler:' => ['v@:@@@@@?', 'v(ppppppp)', function ($self, $cmd, $wk, $prompt, $default, $frame, $handler) {
                $view = self::$views[ObjC::addr($wk)] ?? null;
                $text = $view?->onPanel === null ? null : ($view->onPanel)('prompt', ObjC::phpStr($prompt), $default === null ? '' : ObjC::phpStr($default));
                ObjC::callBlock('v:p', $handler, $text === null ? null : ObjC::str($text));
            }],
            'webView:runOpenPanelWithParameters:initiatedByFrame:completionHandler:' => ['v@:@@@@?', 'v(pppppp)', function ($self, $cmd, $wk, $params, $frame, $handler) {
                $view = self::$views[ObjC::addr($wk)] ?? null;
                $dirs = ObjC::msg('b:p', $params, 'respondsToSelector:', ObjC::sel('allowsDirectories')) && ObjC::msg('b:', $params, 'allowsDirectories');
                $paths = $view?->onPanel === null ? null : ($view->onPanel)('files', ObjC::msg('b:', $params, 'allowsMultipleSelection'), $dirs);
                if ($paths === null) {
                    ObjC::callBlock('v:p', $handler, null);
                    return;
                }
                $urls = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
                foreach ($paths as $path) {
                    ObjC::msg('v:p', $urls, 'addObject:', ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($path)));
                }
                ObjC::callBlock('v:p', $handler, $urls);
            }],
        ], ['WKUIDelegate']);
        self::$uiDelegate = ObjC::msg('p:', ObjC::alloc('MyPHPUIDelegate'), 'init');
        // The end of a print operation to PDF, whose window may be gone.
        ObjC::defineClass('MyPHPPrintDelegate', 'NSObject', [
            'printOperationDidRun:success:contextInfo:' => ['v@:@B^v', 'v(pppbp)', function ($self, $cmd, $op, $success, $context) {
                $job = ObjC::addr($context);
                $done = self::$printJobs[$job] ?? null;
                unset(self::$printJobs[$job]);
                if ($done !== null) {
                    $done((bool)$success);
                }
            }],
        ]);
    }

    public function loadHTML(string $html, string $baseURL = ''): void
    {
        $base = $baseURL === '' ? null : ObjC::msg('p:p', ObjC::cls('NSURL'), 'URLWithString:', ObjC::str($baseURL));
        $this->loads++;
        ObjC::msg('p:pp', $this->ns, 'loadHTMLString:baseURL:', ObjC::str($html), $base);
    }

    /** The URL of the page shown, '' before any; about:blank for HTML loaded without a base URL. */
    public function url(): string
    {
        $u = ObjC::msg('p:', $this->ns, 'URL');
        return $u === null ? '' : ObjC::phpStr(ObjC::msg('p:', $u, 'absoluteString'));
    }

    /** Loads an http(s) URL, or a file: URL with read access to its directory. */
    public function loadURL(string $url): void
    {
        $nsurl = ObjC::msg('p:p', ObjC::cls('NSURL'), 'URLWithString:', ObjC::str($url))
            ?? throw new RuntimeException("bad URL $url");
        $this->loads++;
        if (str_starts_with($url, 'file:')) {
            $dir = ObjC::msg('p:', $nsurl, 'URLByDeletingLastPathComponent');
            ObjC::msg('p:pp', $this->ns, 'loadFileURL:allowingReadAccessToURL:', $nsurl, $dir);
            return;
        }
        ObjC::msg('p:p', $this->ns, 'loadRequest:', ObjC::msg('p:p', ObjC::cls('NSURLRequest'), 'requestWithURL:', $nsurl));
    }

    /** A load failed: cancellations (-999) and interrupted frame loads (102), which a navigation replaced or denied, are none (mygo's loadFailed). */
    private function loadFailed($error): void
    {
        $code = ObjC::msg('l:', $error, 'code');
        if ($code === -999 || $code === 102 || $this->onFail === null) {
            return;
        }
        $url = ObjC::msg('p:p', ObjC::msg('p:', $error, 'userInfo'), 'objectForKey:', ObjC::str('NSErrorFailingURLStringKey'));
        ($this->onFail)($url === null ? '' : ObjC::phpStr($url), $code, ObjC::phpStr(ObjC::msg('p:', $error, 'localizedDescription')));
    }

    /** A download ended, with $error or done. */
    private static function downloadEnded($download, ?string $error): void
    {
        $k = ObjC::addr($download);
        [$view, $url, $path] = self::$downloads[$k] ?? [null, '', ''];
        unset(self::$downloads[$k]);
        if ($view !== null && $path !== '' && $view->onDownloadDone !== null) {
            ($view->onDownloadDone)($url, $path, $error);
        }
    }

    /** A WKSecurityOrigin as "https://example.com:8443" (mygo's securityOrigin). */
    private static function origin($o): string
    {
        $s = ObjC::phpStr(ObjC::msg('p:', $o, 'protocol')) . '://' . ObjC::phpStr(ObjC::msg('p:', $o, 'host'));
        $port = ObjC::msg('l:', $o, 'port');
        return $port !== 0 ? "$s:$port" : $s;
    }

    /** Shows the print panel for the page (mygo's Page.Print), as a sheet of $window. */
    public function print($window): void
    {
        $info = ObjC::msg('p:', ObjC::cls('NSPrintInfo'), 'sharedPrintInfo');
        $op = ObjC::msg('p:p', $this->ns, 'printOperationWithPrintInfo:', $info);
        ObjC::msg('v:r', ObjC::msg('p:', $op, 'view'), 'setFrame:', ObjC::msg('r:', $this->ns, 'bounds'));
        ObjC::msg('v:pppp', $op, 'runOperationModalForWindow:delegate:didRunSelector:contextInfo:', $window, null, null, null);
    }

    /**
     * Prints the page into a PDF file, without panels, as a sheet of
     * $window (mygo's darwin PrintToPDF): paper of $width × $height inches,
     * margins in inches, backgrounds or not; $done gets the PDF's bytes or
     * the error.
     * @param \Closure(?string, ?string): void $done
     */
    public function printToPDF($window, float $width, float $height, bool $landscape, array $margins, bool $background, \Closure $done): void
    {
        $path = tempnam(sys_get_temp_dir(), 'myphp-') . '.pdf';
        $info = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSPrintInfo'), 'sharedPrintInfo'), 'copy');
        ObjC::msg('v:p', $info, 'setJobDisposition:', ObjC::str('NSPrintSaveJob'));
        ObjC::msg('v:pp', ObjC::msg('p:', $info, 'dictionary'), 'setObject:forKey:', ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($path)), ObjC::str('NSJobSavingURL'));
        $orientation = 0; // NSPaperOrientationPortrait
        if ($landscape) {
            [$width, $height, $orientation] = [$height, $width, 1];
        }
        $paper = ObjC::$c->new('CGSize');
        [$paper->w, $paper->h] = [$width * 72, $height * 72];
        ObjC::msg('v:z', $info, 'setPaperSize:', $paper);
        ObjC::msg('v:l', $info, 'setOrientation:', $orientation);
        [$top, $right, $bottom, $left] = $margins;
        ObjC::msg('v:d', $info, 'setTopMargin:', $top * 72);
        ObjC::msg('v:d', $info, 'setRightMargin:', $right * 72);
        ObjC::msg('v:d', $info, 'setBottomMargin:', $bottom * 72);
        ObjC::msg('v:d', $info, 'setLeftMargin:', $left * 72);
        ObjC::msg('v:l', $info, 'setHorizontalPagination:', 1); // fit the width, as browsers
        ObjC::msg('v:l', $info, 'setVerticalPagination:', 0); // as many pages as it takes
        ObjC::msg('v:b', $info, 'setHorizontallyCentered:', false);
        ObjC::msg('v:b', $info, 'setVerticallyCentered:', false);
        $prefs = ObjC::msg('p:', ObjC::msg('p:', $this->ns, 'configuration'), 'preferences');
        $backgrounds = ObjC::msg('b:p', $prefs, 'respondsToSelector:', ObjC::sel('setShouldPrintBackgrounds:')); // macOS 13.3
        $printed = $backgrounds && ObjC::msg('b:', $prefs, 'shouldPrintBackgrounds');
        if ($backgrounds) {
            ObjC::msg('v:b', $prefs, 'setShouldPrintBackgrounds:', $background);
        }
        $op = ObjC::msg('p:', ObjC::msg('p:p', $this->ns, 'printOperationWithPrintInfo:', $info), 'retain'); // kept until it ran
        ObjC::msg('v:', $info, 'release');
        ObjC::msg('v:b', $op, 'setShowsPrintPanel:', false);
        ObjC::msg('v:b', $op, 'setShowsProgressPanel:', false);
        ObjC::msg('v:r', ObjC::msg('p:', $op, 'view'), 'setFrame:', ObjC::msg('r:', $this->ns, 'bounds'));
        $job = self::$nextJob++;
        self::$printJobs[$job] = function (bool $success) use ($op, $prefs, $backgrounds, $printed, $path, $done): void {
            ObjC::msg('v:', $op, 'release');
            if ($backgrounds) {
                ObjC::msg('v:b', $prefs, 'setShouldPrintBackgrounds:', $printed);
            }
            $pdf = @file_get_contents($path);
            @unlink($path);
            @unlink(substr($path, 0, -4));
            $done($success && $pdf !== false && $pdf !== '' ? $pdf : null, $success && $pdf !== false && $pdf !== '' ? null : 'myphp: printing to PDF failed');
        };
        self::$printDelegate ??= ObjC::msg('p:', ObjC::alloc('MyPHPPrintDelegate'), 'init');
        ObjC::msg('v:pppL', $op, 'runOperationModalForWindow:delegate:didRunSelector:contextInfo:', $window, self::$printDelegate, ObjC::sel('printOperationDidRun:success:contextInfo:'), $job);
    }

    private static $printDelegate = null;
    /** The last press on a web view, retained. */
    private static $lastDown = null;

    /** The last press on a web view, which a drag region moves the window with; null before one. */
    public static function lastDown()
    {
        return self::$lastDown;
    }

    /** Runs JavaScript; $completion is an ObjC block (id result, NSError* error) or null. */
    public function evaluate(string $js, $completion = null): void
    {
        ObjC::msg('v:pp', $this->ns, 'evaluateJavaScript:completionHandler:', ObjC::str($js), $completion);
    }

    /** Hands a bridge message (reply or event) to the page. */
    public function deliver(array $message): void
    {
        $this->evaluate('window.myphp.__deliver(' . json_encode($message, JSON_THROW_ON_ERROR) . ')');
    }
}
