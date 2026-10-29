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
 * (a DownloadEvent, to choose where a download goes or prevent it) and
 * downloadDone (a Download).
 */
final class Page
{
    public const EVENTS = ['pageTitleUpdated', 'willNavigate', 'didNavigate', 'domReady', 'didFinishLoad', 'didFailLoad', 'renderProcessGone', 'willDownload', 'downloadDone'];
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
        $this->web = NativeWebView::create($frame, $this->scripts(), $this->message(...), $this->devTools, $configuration, $o->userAgent, $o->zoomFactor, trim($o->userAgentSuffix), $o->swipeNavigation, $o->pinchZoom);
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
            $scripts[] = $this->preload;
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
            $this->endDocument();
            $this->emit('didNavigate', $url);
        };
        $w->onFinish = function (): void {
            $this->emit('didFinishLoad');
            $this->window->pageReady($this); // pages without the bridge, as images, never tell their DOM is ready
        };
        $w->onFail = fn(string $url, int $code, string $desc) => $this->emit('didFailLoad', new LoadError($url, $code, $desc));
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
        $w->onOpen = function (string $url, string $frame, int $width, int $height, $configuration) {
            if ($this->openHandler === null) {
                $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
                if ($scheme === 'http' || $scheme === 'https') {
                    App::later(fn() => Shell::openExternal($url));
                }
                return null;
            }
            $opts = ($this->openHandler)(new WindowOpenRequest($url, $frame, $width, $height));
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
