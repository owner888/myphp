<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\{Frontend, ObjC, WebView as NativeWebView};
use RuntimeException;

/**
 * The web page a window shows (mygo's Page): what it loads, the scripts it
 * runs, its developer tools and its events; Window::page() returns it, null
 * for a window showing native UI.
 *
 *     $win = new Window(new WindowOptions(title: 'Editor', url: '/'));
 *     $win->page()->on('domReady', fn() => Logger::debug('ready'));
 *
 * Its events, by on(): pageTitleUpdated (a TitleEvent, whose
 * preventDefault() keeps the window's title), willNavigate (a
 * NavigateEvent, whose preventDefault() keeps the page: a link, a form,
 * the page's script; not loadURL() and friends), didNavigate (the URL: a
 * navigation committed, a new document started), domReady, didFinishLoad,
 * didFailLoad (a LoadError), renderProcessGone (the reason), willDownload
 * (a DownloadEvent, to choose where a download goes or prevent it),
 * downloadDone (a Download), themeColorChanged (themeColor()'s, null for
 * none), audibleChanged (isAudible()'s) and captureStateChanged (a
 * CaptureKind and its CaptureState).
 */
final class Page
{
    public const EVENTS = ['pageTitleUpdated', 'willNavigate', 'didNavigate', 'domReady', 'didFinishLoad', 'didFailLoad', 'renderProcessGone', 'willDownload', 'downloadDone', 'themeColorChanged', 'audibleChanged', 'captureStateChanged'];
    /** How many events wait for a page that never gets ready, as an image (mygo's maxHeldEvents). */
    private const MAX_HELD = 1024;
    /** The forms Page::eval tries, an expression first: one that does not compile is no expression (mygo's evalExpr, evalBody). */
    private const EVAL_EXPR = "try{return JSON.stringify({ok:true,v:await(\n%s\n)})}catch(e){return JSON.stringify({ok:false,e:String(e&&e.message||e)})}";
    private const EVAL_BODY = "try{return JSON.stringify({ok:true,v:await(async()=>{\n%s\n})()})}catch(e){return JSON.stringify({ok:false,e:String(e&&e.message||e)})}";

    /** @internal the WKWebView with the bridge */
    public readonly NativeWebView $web;
    /** What every message the page's bridge posts carries. */
    private string $secret;
    private bool $domReady = false, $destroyed = false;
    /** @var list<array> the events sent before the page's DOM was ready, which it gets once it is */
    private array $held = [];
    /** The context of the calls of the document shown now, made on its first call. */
    private ?CallContext $callContext = null;
    /** @var array<int, Channel> open channels by the page's id */
    private array $channels = [];
    /** @var array<string, array<int, Closure>> */
    private array $listeners = [];
    private int $next = 0;
    /** @var ?Closure(PermissionRequest): bool */
    private ?Closure $permissionHandler = null;
    /** @var ?Closure(WindowOpenRequest): ?WindowOptions */
    private ?Closure $openHandler = null;
    /** The document loadHTML() loaded, whose reload loads it again: [html, base URL, committed]. @var ?array{0: string, 1: string, 2: bool} */
    private ?array $html = null;
    /** @var list<string> PageOptions::$trustedOrigins */
    private array $trustedOrigins;
    private string $preload;
    private bool $preloadAllFrames = false;
    /**
     * The page's theme color as its bridge told it, [0xRRGGBB, alpha] or
     * null; pending from a navigation's commit until the new page tells
     * it, which it does by the time its DOM is ready: a page without the
     * bridge (an image) never does, and has none once it loaded.
     * @var ?array{0: int, 1: float}
     */
    private ?array $themeColor = null;
    private bool $themeColorPending = false;
    /** What WebKit last told of the page's sound and capture, by CaptureKind, so that listeners hear changes alone. */
    private bool $audible = false;
    /** @var array<int, CaptureState> */
    private array $capture = [];
    private bool $devTools;
    /** The zoom the page was made with, which its first scripts tell the title bar's room in. */
    private float $zoom;

    /** @internal a window's page, or a web view's ($webView); $configuration the one WebKit hands over for window.open() */
    public function __construct(private readonly Window $window, private readonly ?WebView $webView, $frame, PageOptions $o, $configuration = null)
    {
        $this->secret = bin2hex(random_bytes(16));
        $this->trustedOrigins = $o->trustedOrigins;
        $this->preload = $o->preloadScript;
        $this->devTools = $o->devTools->on();
        $this->zoom = $o->zoomFactor > 0 ? $o->zoomFactor : 1.0;
        $this->preloadAllFrames = $o->preloadAllFrames;
        $this->web = NativeWebView::create($frame, $this->scripts(), $this->message(...), $this->devTools, $configuration, $o->userAgent, $o->zoomFactor, trim($o->userAgentSuffix), $o->swipeNavigation, $o->pinchZoom,
            $o->autoplay === Autoplay::Allow, $o->backgroundThrottling === BackgroundThrottling::On);
        if ($o->contentRules !== []) { $this->setContentRules(...$o->contentRules); }
        $this->wire();
    }

    /** The window that shows the page: for a web view's, the window it is in (mygo's Window). */
    public function window(): Window
    {
        return $this->window;
    }

    /** The web view whose page this is, null for a window's (mygo's WebView). */
    public function webView(): ?WebView
    {
        return $this->webView;
    }

    /**
     * Calls $fn on $event, one of EVENTS (see the class). Returns a
     * function that removes it.
     */
    public function on(string $event, Closure $fn): Closure
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new RuntimeException("no page event \"$event\"");
        }
        $k = $this->next++;
        $this->listeners[$event][$k] = $fn;
        return function () use ($event, $k): void { unset($this->listeners[$event][$k]); };
    }

    private function emit(string $event, mixed ...$args): void
    {
        foreach ($this->listeners[$event] ?? [] as $fn) {
            $fn(...$args);
        }
    }

    // Loading.

    /**
     * Navigates the page to $url (mygo's LoadURL). A URL without a scheme,
     * "/" or "/settings?tab=1", is a page of the app's frontend
     * (App::setFrontend): the dev server at devUrl during `myphp dev`, the
     * frontend's files otherwise. Besides http(s) and file: URLs, the
     * schemes Protocol serves load.
     */
    /**
     * The page's back/forward list, with the scroll positions and form
     * fields WebKit saved, which PageOptions::$sessionState brings back in
     * a new window or web view: at the next launch, as browsers bring back
     * their windows, or after the app closed a page it was not showing
     * (mygo's SessionState). WebKit saves them as it goes: the scroll
     * position of the page it shows a moment after the user stops
     * scrolling, its form fields as it goes out of view, the other
     * entries' as the page leaves them. Bytes for the app to keep: the
     * page's URL and WebKit's interactionState, which may hold what the
     * user typed into forms, so keep it as the user's data; HTML that
     * loadHTML() loaded is not in it.
     */
    public function sessionState(): string
    {
        if ($this->destroyed) { throw new RuntimeException('myphp: session state: the page is closed'); }
        return self::encodeSessionState('darwin', $this->web->url(), $this->web->sessionState());
    }

    /**
     * The page's theme color, [0xRRGGBB, alpha], null when it has none
     * (mygo's ThemeColor): the content of the first <meta
     * name="theme-color"> in its head whose media is absent or matches and
     * whose content is a CSS color, as browsers pick it, so that a page
     * with one for light and one for dark appearance has the app's
     * appearance's. MyPHP's runtime in the page tells it, in pages of any
     * origin; a page it does not run in, as an image or a PDF, has none.
     * A page that just started loading has the color of the one before
     * until its DOM is ready, so that going between pages of one color
     * changes nothing. themeColorChanged tells its changes.
     * @return ?array{0: int, 1: float}
     */
    public function themeColor(): ?array
    {
        return $this->themeColor;
    }

    /** "#rrggbbaa", as the bridge tells a theme color, as [0xRRGGBB, alpha]; null for "" or what is no such color. @internal @return ?array{0: int, 1: float} */
    public static function parseThemeColor(string $v): ?array
    {
        if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})$/iD', $v, $m) !== 1) { return null; }
        return [(int)hexdec($m[1]), hexdec($m[2]) / 255.0];
    }

    /** The page's theme color, as its bridge told it, its listeners told when it changed. */
    private function setThemeColor(?array $c): void
    {
        $this->themeColorPending = false;
        if ($c === $this->themeColor) { return; }
        $this->themeColor = $c;
        $this->emit('themeColorChanged', $c);
    }

    /**
     * Whether the page makes sound: a video or audio element playing, Web
     * Audio, from any of its frames, as a browser tab's speaker shows; it
     * can stay true while the page is muted (mygo's IsAudible). WebKit's
     * private _isPlayingAudio, false where it does not answer it, and once
     * the page closed. audibleChanged tells its changes.
     */
    public function isAudible(): bool
    {
        return !$this->destroyed && $this->web->isAudible();
    }

    /**
     * Mutes all the page's sound, or lets it play again, as a browser mutes
     * a tab: its own media elements, Web Audio and its frames of other
     * sites, without the page seeing its elements muted; it lasts as the
     * page navigates (mygo's SetAudioMuted). WebKit's private
     * _setPageMuted:, nothing where it does not answer it. WebKit's mute of
     * the page's sound mutes the camera and the microphone together, so
     * while only one of them is muted (setCaptureState), it mutes the
     * other for a moment.
     */
    public function setAudioMuted(bool $muted): void
    {
        if (!$this->destroyed) { $this->web->setAudioMuted($muted); }
    }

    /** Whether the page is muted (setAudioMuted): false where WebKit cannot mute, and once the page closed (mygo's IsAudioMuted). */
    public function isAudioMuted(): bool
    {
        return !$this->destroyed && $this->web->isAudioMuted();
    }

    /**
     * Whether the page captures the camera, the microphone or the display,
     * from any of its frames, and whether it is muted, as Safari's
     * recording indicators show (mygo's CaptureState). The page asks for
     * the devices first, which setPermissionHandler decides. WKWebView's
     * cameraCaptureState and microphoneCaptureState, and WebKit's private
     * _displayCaptureState (macOS 13); None where WebKit does not answer
     * them, and once the page closed. captureStateChanged tells changes.
     */
    public function captureState(CaptureKind $kind): CaptureState
    {
        return $this->destroyed ? CaptureState::None : CaptureState::from($this->web->captureState($kind->value));
    }

    /**
     * Mutes the page's capture of a kind of device (Muted), unmutes it
     * (Active) or stops it (None), as the mute button of a call app,
     * without the page's help: a muted capture keeps the page's tracks,
     * which get silence or black frames, and a stopped one ends them. The
     * page cannot unmute it itself: asking for the device again goes to
     * setPermissionHandler. Nothing while the page captures no such
     * device. The change shows in captureState() a moment later, once the
     * page took it (mygo's SetCaptureState).
     */
    public function setCaptureState(CaptureKind $kind, CaptureState $state): void
    {
        if (!$this->destroyed) { $this->web->setCaptureState($kind->value, $state->value); }
    }

    /**
     * Answers the HTTP sign-in challenges (Basic, Digest, NTLM, Negotiate)
     * of the page and of what it loads, the server's or a proxy's (mygo's
     * SetAuthHandler): $fn returns the credentials, or an empty
     * AuthResponse to cancel, on the main thread while the load waits. A
     * server that refuses them asks again; WebKit keeps those it accepts
     * for the rest of the session, for that server and realm, so $fn is
     * not asked for every request. myphp never logs them.
     *
     *     $page->setAuthHandler(fn(AuthRequest $r) => $r->host === 'router.lan' && !$r->isProxy
     *         ? new AuthResponse('admin', $password) : new AuthResponse());
     *
     * To ask the user, $fn can cancel and show the app's own prompt, then
     * reload() once it has the credentials. Null restores WebKit's
     * handling, which shows no prompt: the page gets the server's refusal.
     * @param ?Closure(AuthRequest): AuthResponse $fn
     */
    public function setAuthHandler(?Closure $fn): void
    {
        $this->web->onAuth = $fn === null ? null : static function (string $host, int $port, string $realm, string $scheme, bool $proxy) use ($fn): array {
            $r = $fn(new AuthRequest($host, $port, $realm, $scheme, $proxy));
            return [$r->username, $r->password];
        };
    }

    /**
     * Decides about the server certificates that do not verify, for the
     * page and what it loads (mygo's SetCertificateErrorHandler): $fn
     * returns true to load them anyway, as for a dev server with a
     * self-signed certificate, on the main thread while the load waits. By
     * default, and whenever $fn returns false, the load fails as in a
     * browser (didFailLoad). It is asked of each connection to the server,
     * and a yes lasts for that connection; myphp checks the certificate
     * itself (SecTrustEvaluateAsyncWithError) only while a handler is set.
     * Allow only the certificate expected, never a host whatever its
     * certificate, which anyone between the app and the server can present:
     *
     *     $page->setCertificateErrorHandler(fn(CertificateError $e) => $e->host === 'dev.example'
     *         && hash('sha256', $e->certificate) === DEV_CERTIFICATE_SHA256);
     *
     * Null restores the default.
     * @param ?Closure(CertificateError): bool $fn
     */
    public function setCertificateErrorHandler(?Closure $fn): void
    {
        $this->web->onCertificate = $fn === null ? null : static fn(string $url, string $host, string $error, string $der): bool => $fn(new CertificateError($url, $host, $error, $der)) === true;
    }

    /** The ID of the page's web content process (WebKit's com.apple.WebKit.WebContent), 0 when WebKit does not tell it or runs none yet, or the page closed (mygo's ProcessID). WebKit may give a page another as it goes to another site or after its process crashed: ask again rather than keep it. */
    public function processID(): int
    {
        return $this->destroyed ? 0 : $this->web->processID();
    }

    /** Loads the first page of a new window or web view: the one of $state (PageOptions::$sessionState), else $url (mygo's loadPage). @internal */
    public function loadFirst(?string $state, string $url): void
    {
        if ($state !== null && ($s = self::decodeSessionState($state)) !== null) {
            [$os, $u, $native] = $s;
            if ($os === 'darwin' && $native !== '' && $this->web->restoreSessionState($native)) { return; }
            if ($u !== '') { $url = $u; }
        }
        if ($url !== '') { $this->loadURL($url); }
    }

    /** Session states, as mygo's version 1: the magic, the version byte, the system that saved it and the URL each ended by a NUL, then WebKit's own. */
    private const SESSION_MAGIC = 'mygo-session', SESSION_VERSION = 1;

    /** @internal */
    public static function encodeSessionState(string $os, string $url, string $native): string
    {
        return self::SESSION_MAGIC . chr(self::SESSION_VERSION) . "$os\0$url\0" . $native;
    }

    /** [system, URL, WebKit's] of a state encodeSessionState made, null for anything else, a later version's included. @internal @return ?array{0: string, 1: string, 2: string} */
    public static function decodeSessionState(string $b): ?array
    {
        $head = self::SESSION_MAGIC . chr(self::SESSION_VERSION);
        if (!str_starts_with($b, $head)) { return null; }
        $parts = explode("\0", substr($b, strlen($head)), 3);
        return count($parts) === 3 ? [$parts[0], $parts[1], $parts[2]] : null;
    }

    public function loadURL(string $url): void
    {
        $this->html = null;
        $this->web->loadURL(Frontend::resolve($url));
    }

    /**
     * Loads a local HTML file (mygo's LoadFile): a relative path is
     * resolved against the working directory, then the directory of the
     * script the app runs, then the bundle's Resources. Throws when none
     * has it.
     */
    public function loadFile(string $path): void
    {
        $abs = self::resolveFile($path);
        $this->html = null;
        $this->web->loadFile($abs, dirname($abs));
    }

    /** Loads $html, whose relative URLs resolve against $baseURL; the page is at $baseURL, or about:blank. Reloading it, by reload() or from the page, loads $html again (mygo's LoadHTML). */
    public function loadHTML(string $html, string $baseURL = ''): void
    {
        $this->html = [$html, $baseURL, false];
        $this->web->loadHTML($html, $baseURL);
    }

    public function reload(): void { $this->web->reload(false); }
    /** Reloads the page, the cache bypassed (mygo's ReloadIgnoringCache). */
    public function reloadIgnoringCache(): void { $this->web->reload(true); }
    /** Stops loading (mygo's Stop). */
    public function stop(): void { $this->web->stop(); }
    public function goBack(): void { $this->web->goBack(); }
    public function goForward(): void { $this->web->goForward(); }
    public function canGoBack(): bool { return $this->web->canGoBack(); }
    public function canGoForward(): bool { return $this->web->canGoForward(); }
    /** The URL of the page shown, '' before any; about:blank for HTML loaded without a base URL. */
    public function url(): string { return $this->web->url(); }
    public function isLoading(): bool { return $this->web->isLoading(); }

    /** Zooms the page; 1 is its own size (mygo's SetZoomFactor). The title bar's room follows, in the page's pixels. */
    public function setZoomFactor(float $f): void
    {
        if ($f <= 0) {
            throw new \InvalidArgumentException('myphp: the zoom factor must be positive');
        }
        $this->web->setZoom($f);
        $this->refreshScripts();
        $this->sendTitleBar();
    }

    public function zoomFactor(): float { return $this->web->zoom(); }
    /** Overrides the user agent; '' restores WebKit's (mygo's SetUserAgent). */
    public function setUserAgent(string $ua): void { $this->web->setUserAgent($ua); }
    public function userAgent(): string { return $this->web->userAgent(); }

    /**
     * Replaces the content rules the page applies, which
     * PageOptions::$contentRules set, with $rules; none removes them
     * (mygo's SetContentRules). Loads from then on go through them: a page
     * loaded already keeps what it loaded and hid until it reloads. Nulls
     * are ignored.
     */
    public function setContentRules(?ContentRules ...$rules): void
    {
        $this->web->setContentRules(array_map(fn(ContentRules $r) => $r->list, array_values(array_filter($rules))));
    }

    // Developer tools.

    /** Opens the web inspector, docked in the window (mygo's OpenDevTools); nothing unless PageOptions::$devTools turns it on. */
    public function openDevTools(): void { $this->web->openDevTools(); }
    public function closeDevTools(): void { $this->web->closeDevTools(); }
    public function isDevToolsOpened(): bool { return $this->web->isDevToolsOpened(); }

    public function toggleDevTools(): void
    {
        $this->isDevToolsOpened() ? $this->closeDevTools() : $this->openDevTools();
    }

    // Scripts.

    /**
     * Evaluates JavaScript in the page and returns its result, decoded from
     * JSON (mygo's Eval): an expression's value, promises awaited; code
     * that is no expression runs as the body of an async function, which
     * returns its value with return:
     *
     *     $title = $win->page()->eval('document.title');
     *     $n = $win->page()->eval("const items = document.querySelectorAll('li'); return items.length");
     *
     * The page's Content Security Policy does not apply. It throws an
     * EvalError when the script throws, and returns once the page
     * answered, the run loop turning meanwhile.
     */
    public function eval(string $code): mixed
    {
        $raw = $this->evalRaw($code);
        return $raw === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** The JSON of the value of $code, '' for undefined. */
    private function evalRaw(string $code): string
    {
        $result = null;
        $this->web->callAsync(sprintf(self::EVAL_EXPR, rtrim($code, " \t\r\n;")), function (?string $r, ?string $e) use (&$result, $code): void {
            if ($e === null || !str_starts_with($e, 'SyntaxError')) {
                $result = [$r, $e]; // one that ran, and threw afterwards or went with the page, is not run twice
                return;
            }
            if ($this->destroyed) {
                $result = [null, 'myphp: the page is gone'];
                return;
            }
            $this->web->callAsync(sprintf(self::EVAL_BODY, $code), function (?string $r, ?string $e) use (&$result): void { $result = [$r, $e]; });
        });
        App::wait(function () use (&$result): bool { return $result !== null || $this->destroyed; });
        if ($result === null) {
            throw new RuntimeException('myphp: the page is gone');
        }
        [$r, $e] = $result;
        if ($e !== null) {
            throw new RuntimeException($e);
        }
        $out = json_decode((string)$r, true);
        if (!is_array($out) || !array_key_exists('ok', $out)) {
            throw new RuntimeException("myphp: unexpected eval result \"$r\"");
        }
        if (!$out['ok']) {
            throw new EvalError((string)($out['e'] ?? ''));
        }
        return array_key_exists('v', $out) ? json_encode($out['v'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : '';
    }

    /**
     * Decides what window.open() and links with target="_blank" open
     * (mygo's SetWindowOpenHandler): $fn gets a WindowOpenRequest and
     * returns the options of the window to open the page in, or null to
     * deny it. Without a handler, http(s) URLs open in the browser and
     * the rest is denied. The new page keeps its opener (window.opener).
     */
    public function setWindowOpenHandler(?Closure $fn): void
    {
        $this->openHandler = $fn;
    }

    /**
     * Decides what the page may use, as the camera and the microphone
     * (mygo's SetPermissionHandler): $fn returns whether to grant a
     * PermissionRequest. Null restores the default: trusted pages
     * (isTrusted) get what they ask for, others do not. macOS may still
     * ask the user once, which needs NSCameraUsageDescription and
     * NSMicrophoneUsageDescription in the bundle's Info.plist, without
     * which it ends the app.
     */
    public function setPermissionHandler(?Closure $fn): void
    {
        $this->permissionHandler = $fn;
    }

    /**
     * Highlights the occurrences of $text in the page, scrolls to the
     * active one and says how many there are, as a browser's find bar
     * (mygo's FindInPage): call it as the user types, and with findNext to
     * go to the next match. Text split by markup, as "my<b>go</b>", is not
     * found.
     */
    public function findInPage(string $text, FindOptions $opts = new FindOptions): FindResult
    {
        $r = $this->eval('window.__myphp.find(' . json_encode($text, JSON_THROW_ON_ERROR) . ', ' . json_encode(['matchCase' => $opts->matchCase, 'backward' => $opts->backward, 'next' => $opts->findNext]) . ')');
        return new FindResult((int)($r['matches'] ?? 0), (int)($r['active'] ?? 0));
    }

    /** Removes the highlights of findInPage() (mygo's StopFindInPage). */
    public function stopFindInPage(): void
    {
        $this->web->evaluate('window.__myphp && window.__myphp.stopFind()');
    }

    /** Shows the print panel for the page, as a sheet of its window (mygo's Print). */
    public function print(): void
    {
        $this->web->print($this->window->nativeHandle());
    }

    /**
     * The page as a PDF document, laid out for printing (its print style
     * sheets apply) on pages of the size $opts gives (mygo's PrintToPDF):
     * the PDF's bytes.
     *
     *     $pdf = $win->page()->printToPDF(new PDFOptions(PageSize::a4(), background: true));
     */
    public function printToPDF(PDFOptions $opts = new PDFOptions): string
    {
        $size = $opts->pageSize ?? PageSize::letter();
        $m = $opts->margins ?? new Margins(0.4, 0.4, 0.4, 0.4);
        $result = null;
        $this->web->printToPDF($this->window->nativeHandle(), $size->width, $size->height, $opts->landscape, [$m->top, $m->right, $m->bottom, $m->left], $opts->background,
            function (?string $pdf, ?string $error) use (&$result): void { $result = [$pdf, $error]; });
        App::wait(function () use (&$result): bool { return $result !== null || $this->destroyed; });
        if ($result === null) {
            throw new RuntimeException('myphp: the page went away while it printed');
        }
        return $result[0] ?? throw new RuntimeException($result[1]);
    }

    /** A PNG of the page as it shows (mygo's CapturePage of a page). */
    public function capture(): string
    {
        $result = null;
        $this->web->capture(function (?string $png, ?string $error) use (&$result): void { $result = [$png, $error]; });
        App::wait(function () use (&$result): bool { return $result !== null || $this->destroyed; });
        if ($result === null) {
            throw new RuntimeException('myphp: the page went away');
        }
        return $result[0] ?? throw new RuntimeException($result[1]);
    }

    // Trust.

    /**
     * Whether the page may call the app's bound methods (mygo's trusted
     * pages): the app's own (WindowOptions' html, file:, the frontend and
     * the schemes Protocol serves), the dev server under `myphp dev`, and
     * PageOptions::$trustedOrigins. Documents tell these pages their
     * paths; permissions are theirs by default.
     */
    public function isTrusted(): bool
    {
        return Frontend::isTrusted($this->web->url(), $this->trustedOrigins);
    }

    /** @internal whether pages of $origin ("scheme://host") are trusted here, as for CORS */
    public function trusts(string $origin): bool
    {
        return Frontend::isTrusted($origin, $this->trustedOrigins);
    }

    // The bridge.

    /** @internal sends an event to the page, held until its DOM is ready (mygo's enqueue) */
    public function event(string $name, mixed $payload): void
    {
        $msg = ['t' => 'event', 'n' => $name, 'p' => $payload];
        if (!$this->domReady) {
            if (count($this->held) === self::MAX_HELD) {
                array_shift($this->held);
            }
            $this->held[] = $msg;
            return;
        }
        $this->deliver($msg);
    }

    /** @internal hands a bridge message (a reply, an event, a channel's value) to the page */
    public function deliver(array $message): void
    {
        if (!$this->destroyed) {
            $this->web->deliver($message);
        }
    }

    /** @internal the context of the calls of the document shown now (Ipc) */
    public function callContext(): CallContext
    {
        return $this->callContext ??= new CallContext($this->window, $this);
    }

    /** @internal a channel the page passed to a call, by its id */
    public function openChannel(int $id, string $secret): Channel
    {
        return $this->channels[$id] = new Channel(fn(array $m) => $this->deliver($m), function () use ($id): void { unset($this->channels[$id]); }, $id, $secret);
    }

    /** @internal */
    public function channel(int $id): ?Channel
    {
        return $this->channels[$id] ?? null;
    }

    /** @internal the page goes with its window or web view */
    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }
        $this->endDocument();
        $this->destroyed = true;
        $this->web->close();
    }

    /** @internal whether the page went with its window or web view */
    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    /** @internal the desktop's preferences or the language the app shows changed: pages loaded later get them at document start, this one by its event */
    public function settingsChanged(bool $locale): void
    {
        $this->refreshScripts();
        if ($locale) {
            $this->event('myphp:locale', self::locale());
        } else {
            $this->event('myphp:preferences', Theme::preferences());
        }
    }

    /** @internal the room of the window controls changed (mygo's sendTitleBar) */
    public function sendTitleBar(): void
    {
        if ($this->webView === null && $this->window->hasHiddenTitleBar()) {
            $this->event('myphp:title-bar', $this->titleBar());
        }
    }

    /** @internal files dropped on the page, where (mygo's filesDropped): its trusted page hears of them too @param list<string> $paths */
    public function filesDropped(array $paths, float $x, float $y): void
    {
        if ($this->isTrusted()) { // local paths are none of other pages' business
            $this->event('myphp:file-drop', ['paths' => $paths, 'x' => (int)$x, 'y' => (int)$y]);
        }
    }

    /** The scripts pages get at document start: the bridge, the preferences, the room of the title bar, the preload script (mygo's pageScripts). @return list<string> */
    private function scripts(): array
    {
        $config = ['platform' => 'darwin', 'windowId' => $this->window->id, 'version' => App::VERSION, 'secret' => $this->secret] + self::locale();
        $tell = fn(string $name, mixed $p) => 'window.myphp&&window.myphp.__deliver(' . json_encode(['t' => 'event', 'n' => $name, 'p' => $p], JSON_THROW_ON_ERROR) . ');';
        $scripts = [str_replace('__CONFIG__', json_encode($config, JSON_THROW_ON_ERROR), (string)file_get_contents(__DIR__ . '/Native/bridge.js'))];
        $scripts[] = $tell('myphp:preferences', Theme::preferences());
        if ($this->webView === null && $this->window->hasHiddenTitleBar()) {
            $scripts[] = $tell('myphp:title-bar', $this->titleBar());
        }
        if ($this->preload !== '') {
            $scripts[] = [$this->preload, $this->preloadAllFrames];
        }
        return $scripts;
    }

    private function refreshScripts(): void
    {
        if (!$this->destroyed) {
            $this->web->setUserScripts($this->scripts());
        }
    }

    /** The room of the window controls in the page's CSS pixels (mygo's NewTitleBar). @return array{height: float, left: float, right: float} */
    private function titleBar(): array
    {
        $zoom = isset($this->web) ? $this->web->zoom() : $this->zoom;
        $zoom = $zoom > 0 ? $zoom : 1.0;
        return array_map(fn(float $v) => $v / $zoom, $this->window->titleBar());
    }

    /** @return array{locale: string, dir: string} */
    private static function locale(): array
    {
        $l = App::locale();
        return ['locale' => $l, 'dir' => I18n::direction($l)->value];
    }

    /** A message the page's bridge posted. */
    private function message(string $raw): void
    {
        switch ($raw) {
            case '{"t":"theme-color","v":""}':
                $this->setThemeColor(null);
                return;
            case '{"t":"dom-ready"}':
                $this->domReady = true;
                $held = $this->held;
                $this->held = [];
                foreach ($held as $m) {
                    $this->deliver($m);
                }
                $this->sendTitleBar(); // the script at document start may have told a later page the room before a change
                $this->deliver(['t' => 'event', 'n' => 'myphp:locale', 'p' => self::locale()]); // one that started loading as the language changed had the one before
                $this->emit('domReady');
                $this->window->pageReady($this);
                return;
            case '{"t":"escape"}': // an Escape the page left (no handler called preventDefault)
                $this->window->pageEscape($this);
                return;
            case '{"t":"drag"}':
                $this->window->pageDrag(1);
                return;
            case '{"t":"dblclick"}':
                $this->window->pageDrag(2);
                return;
        }
        if (str_starts_with($raw, '{"t":"theme-color","v":"#')) {
            $this->setThemeColor(self::parseThemeColor((string)(json_decode($raw, true)['v'] ?? '')));
            return;
        }
        $reply = Ipc::handle($raw, $this->secret, $this);
        if ($reply !== null) {
            $this->deliver($reply);
        }
    }

    /** The document shown goes: its calls' context ends, its channels close. */
    private function endDocument(): void
    {
        $this->callContext?->cancel();
        $this->callContext = null;
        foreach ($this->channels as $ch) {
            $ch->closedByPage();
        }
        $this->channels = [];
    }

    /** The native web view's hooks. */
    private function wire(): void
    {
        $w = $this->web;
        $w->onNavigate = function (string $url, bool $user, bool $reload, bool $unasked): bool {
            $doc = $this->html;
            if ($reload && $doc !== null && $doc[2]) { // the reload of HTML loadHTML loaded loads it again
                App::later(function () use ($doc): void {
                    if ($this->html === $doc && !$this->destroyed) { $this->loadHTML($doc[0], $doc[1]); }
                });
                return false;
            }
            if ($unasked) {
                return true;
            }
            $e = new NavigateEvent($url, $user);
            $this->emit('willNavigate', $e);
            return !$e->prevented;
        };
        $w->onCommit = function (string $url): void {
            if ($this->html !== null) {
                $base = $this->html[1] === '' ? 'about:blank' : $this->html[1];
                $trim = fn(string $u) => rtrim(explode('#', $u, 2)[0], '/');
                if ($this->html[2]) {
                    $this->html = null; // another document replaced it
                } elseif ($trim($url) === $trim($base)) {
                    $this->html[2] = true;
                }
            }
            $this->domReady = false;
            $this->themeColorPending = true;
            $this->endDocument();
            $this->emit('didNavigate', $url);
        };
        $w->onChange = function (string $key): void {
            if ($key === '_isPlayingAudio') {
                if (($a = $this->web->isAudible()) !== $this->audible) {
                    $this->audible = $a;
                    $this->emit('audibleChanged', $a);
                }
                return;
            }
            foreach (CaptureKind::cases() as $kind) {
                $s = CaptureState::from($this->web->captureState($kind->value));
                if ($s !== ($this->capture[$kind->value] ?? CaptureState::None)) {
                    $this->capture[$kind->value] = $s;
                    $this->emit('captureStateChanged', $kind, $s);
                }
            }
        };
        $w->onFinish = function (): void {
            if ($this->themeColorPending) { $this->setThemeColor(null); } // no bridge told one
            $this->emit('didFinishLoad');
            $this->window->pageReady($this); // pages without the bridge, as images, never tell their DOM is ready
        };
        $w->onFail = function (string $url, int $code, string $desc): void {
            if ($this->themeColorPending) { $this->setThemeColor(null); }
            $this->emit('didFailLoad', new LoadError($url, $code, $desc));
        };
        $w->onGone = fn(string $reason) => $this->emit('renderProcessGone', $reason);
        $w->onTitle = function (string $title): void {
            if ($title === '') {
                return;
            }
            $e = new TitleEvent($title);
            $this->emit('pageTitleUpdated', $e);
            if (!$e->prevented && $this->webView === null) {
                $this->window->pageTitled($title);
            }
        };
        $w->onClosedByPage = function (): void {
            if ($this->webView === null) { // a web view's page does not close its window
                App::later($this->window->close(...));
            }
        };
        $w->onOpen = function (string $url, string $frame, int $width, int $height, $configuration, bool $user) {
            if ($this->openHandler === null) {
                $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
                if ($scheme === 'http' || $scheme === 'https') {
                    App::later(fn() => Shell::openExternal($url));
                }
                return null;
            }
            $opts = ($this->openHandler)(new WindowOpenRequest($url, $frame, $width, $height, $user));
            if ($opts === null) {
                return null;
            }
            $child = Window::opened($opts, $width, $height, $configuration);
            return $child->page()->web->ns;
        };
        $w->onPanel = $this->panel(...);
        $w->onPermission = function (array $kinds, string $origin): bool {
            return $this->permissionHandler !== null ? (bool)($this->permissionHandler)(new PermissionRequest($kinds, $origin)) : $origin === '://' || $this->trusts($origin); // '://': loadHTML's, of no origin
        };
        $w->onDownload = function (string $url, string $suggested): ?string {
            $e = new DownloadEvent($url, Download::fileName($suggested), '');
            $dir = getenv('HOME') . '/Downloads';
            if (is_dir($dir) || @mkdir($dir, 0755, true)) {
                $e->path = Download::uniquePath("$dir/{$e->suggestedName}");
            }
            $this->emit('willDownload', $e);
            return $e->prevented || $e->path === '' ? null : $e->path;
        };
        $w->onDownloadDone = fn(string $url, string $path, ?string $error) => $this->emit('downloadDone', new Download($url, $path, $error));
        $w->onScheme = function (array $r, Native\SchemeTask $task): void {
            [$method, $url, $headers, $body] = $r;
            Native\Scheme::serve(new ProtocolRequest($method, $url, $headers, $body, $this->window), $task, $this->trusts(...));
        };
        $w->onSchemeDownload = function (string $url) use ($w): void {
            App::later(fn() => Native\Scheme::download($url, new ProtocolRequest('GET', $url, [], '', $this->window), $w->onDownload, $w->onDownloadDone));
        };
    }

    /** alert(), confirm(), prompt() and file inputs, as sheets of the window (mygo's jsAlert, jsConfirm, jsPrompt, jsFileInput). */
    private function panel(string $kind, mixed ...$args): mixed
    {
        $t = L10n::in(App::locale());
        switch ($kind) {
            case 'alert':
                Dialog::message(new MessageOptions($this->window, message: $args[0], buttons: [$t->get('ok')]));
                return null;
            case 'confirm':
                return Dialog::message(new MessageOptions($this->window, message: $args[0], buttons: [$t->get('ok'), $t->get('cancel')], cancelButton: 1))->button === 0;
            case 'prompt':
                return Dialog::prompt($this->window, $args[0], $args[1]);
            default: // 'files'
                return Dialog::chooseForPage($this->window, $args[0], $args[1]);
        }
    }

    /** $path, absolute, or found relative to the working directory, the script's directory or the bundle's Resources (mygo's resolveFile). */
    private static function resolveFile(string $path): string
    {
        if (str_starts_with($path, '/')) {
            if (!file_exists($path)) {
                throw new RuntimeException("myphp: file \"$path\" not found");
            }
            return $path;
        }
        $dirs = [(string)getcwd(), dirname((string)realpath($_SERVER['argv'][0] ?? '.'))];
        if (($app = Native\Bundle::path()) !== null) {
            $dirs[] = "$app/Contents/Resources";
        }
        foreach ($dirs as $d) {
            if (file_exists("$d/$path")) {
                return "$d/$path";
            }
        }
        throw new RuntimeException("myphp: file \"$path\" not found");
    }
}
