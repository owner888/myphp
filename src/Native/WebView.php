<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use RuntimeException;

/**
 * A WKWebView with the MyPHP bridge installed: the bridge script runs at
 * document start, messages the page posts reach $onMessage, and deliver()
 * hands replies and events back to the page.
 */
final class WebView
{
    private const INJECT_AT_DOCUMENT_START = 0;
    private const AUTORESIZE_WIDTH_HEIGHT = 2 | 16;
    private static bool $defined = false;
    /** @var array<int, WebView> by WKWebView address */
    private static array $views = [];

    public $ns;
    /** Asked whether the page may go to a URL the app did not load: (url, userInitiated) → allowed. @var ?Closure(string, bool): bool */
    public ?Closure $onNavigate = null;
    /** Loads the app starts, whose navigations are not asked about. */
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

    public static function create($frame, array $config, Closure $onMessage, string $preload = '', bool $devTools = false): self
    {
        self::define();
        $self = new self($onMessage);
        $wk = ObjC::msg('p:', ObjC::alloc('WKWebViewConfiguration'), 'init');
        foreach (\MyPHP\Protocol::schemes() as $scheme) { // the schemes PHP serves: handled before the view exists, as WebKit takes them only then
            ObjC::msg('v:pp', $wk, 'setURLSchemeHandler:forURLScheme:', self::$schemeHandler, ObjC::str($scheme));
        }
        $ucc = ObjC::msg('p:', $wk, 'userContentController');
        ObjC::msg('v:pp', $ucc, 'addScriptMessageHandler:name:', ObjC::msg('p:', ObjC::alloc('MyPHPScriptHandler'), 'init'), ObjC::str('myphp'));
        $source = str_replace('__CONFIG__', json_encode($config, JSON_THROW_ON_ERROR), file_get_contents(__DIR__ . '/bridge.js'));
        $script = ObjC::msg('p:plb', ObjC::alloc('WKUserScript'), 'initWithSource:injectionTime:forMainFrameOnly:', ObjC::str($source), self::INJECT_AT_DOCUMENT_START, true);
        ObjC::msg('v:p', $ucc, 'addUserScript:', $script);
        if ($preload !== '') {
            $pre = ObjC::msg('p:plb', ObjC::alloc('WKUserScript'), 'initWithSource:injectionTime:forMainFrameOnly:', ObjC::str($preload), self::INJECT_AT_DOCUMENT_START, true);
            ObjC::msg('v:p', $ucc, 'addUserScript:', $pre);
        }
        // The web inspector (mygo's DevTools): its context menu item, Safari's Develop menu (macOS 13.3), openDevTools.
        ObjC::msg('v:pp', ObjC::msg('p:', $wk, 'preferences'), 'setValue:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', $devTools), ObjC::str('developerExtrasEnabled'));
        $self->ns = ObjC::msg('p:rp', ObjC::alloc('MyPHPWebView'), 'initWithFrame:configuration:', $frame, $wk);
        if (ObjC::msg('b:p', $self->ns, 'respondsToSelector:', ObjC::sel('setInspectable:'))) {
            ObjC::msg('v:b', $self->ns, 'setInspectable:', $devTools);
        }
        $self->devTools = $devTools;
        Drop::register($self->ns);
        ObjC::msg('v:p', $self->ns, 'setNavigationDelegate:', self::$navigationDelegate); // weak: the class keeps it
        ObjC::msg('v:p', $self->ns, 'setUIDelegate:', self::$uiDelegate);
        ObjC::msg('v:L', $self->ns, 'setAutoresizingMask:', self::AUTORESIZE_WIDTH_HEIGHT);
        self::$views[ObjC::addr($self->ns)] = $self;
        return $self;
    }

    private static function define(): void
    {
        if (self::$defined) {
            return;
        }
        self::$defined = true;
        // A WKWebView that hands dropped files to PHP (the page's own drop events do not see paths).
        ObjC::defineClass('MyPHPWebView', 'WKWebView', Drop::methods(), ['NSDraggingDestination']);
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
                    if ($view->loads > 0) {
                        $view->loads--;
                    } elseif ($view->onNavigate !== null) {
                        $url = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', ObjC::msg('p:', $action, 'request'), 'URL'), 'absoluteString'));
                        $type = ObjC::msg('l:', $action, 'navigationType'); // 0 a link, 1 a form
                        $allow = ($view->onNavigate)($url, $type === 0 || $type === 1);
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
            'downloadDidFinish:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $download) {
                self::downloadEnded($download, null);
            }],
            'download:didFailWithError:resumeData:' => ['v@:@@@', 'v(ppppp)', function ($self, $cmd, $download, $error, $resume) {
                self::downloadEnded($download, ObjC::phpStr(ObjC::msg('p:', $error, 'localizedDescription')));
            }],
        ], ['WKNavigationDelegate', 'WKDownloadDelegate']);
        self::$navigationDelegate = ObjC::msg('p:', ObjC::alloc('MyPHPNavigationDelegate'), 'init');
        ObjC::defineClass('MyPHPUIDelegate', 'NSObject', [
            // Camera and microphone requests (macOS 12).
            'webView:requestMediaCapturePermissionForOrigin:initiatedByFrame:type:decisionHandler:' => ['v@:@@@q@?', 'v(ppppplp)', function ($self, $cmd, $wk, $origin, $frame, $type, $handler) {
                $view = self::$views[ObjC::addr($wk)] ?? null;
                $kinds = [0 => ['camera'], 1 => ['microphone'], 2 => ['camera', 'microphone']][$type] ?? [];
                $grant = $view?->onPermission !== null && ($view->onPermission)($kinds, self::origin($origin));
                ObjC::callBlock('v:l', $handler, $grant ? 1 : 2); // WKPermissionDecisionGrant, Deny
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

    public function loadHTML(string $html, ?string $baseURL = null): void
    {
        $base = $baseURL === null ? null : ObjC::msg('p:p', ObjC::cls('NSURL'), 'URLWithString:', ObjC::str($baseURL));
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
