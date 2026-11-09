<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use FFI\CData;
use MyPHP\Platform\Unsupported;
use MyPHP\Native\Mem;

/**
 * A WebKitWebView with the MyPHP bridge installed (mygo's linux
 * createWebView), the Linux side of what Native\WebView is on macOS: the
 * scripts at document start are WebKitUserScripts of a content manager of
 * its own, the bridge's messages come through its script message handler
 * "myphp" (window.webkit.messageHandlers.myphp, as WebKit's on macOS),
 * and deliver() evaluates the replies and events in the page. The page's
 * life — navigations, loads, failures, its title, window.close(), a
 * crash, window.open() — reaches the hooks the core Page sets. The
 * schemes PHP serves are registered on WebKit's default context.
 *
 * One C function serves each signal for every view, its data the view's
 * id: a closure a view would leave its thunks behind.
 */
final class WebView implements \MyPHP\Platform\Page
{
    /** WebKitUserContentInjectedFrames, WebKitUserScriptInjectionTime. */
    private const ALL_FRAMES = 0, TOP_FRAME = 1, DOCUMENT_START = 0;
    /** WebKitLoadEvent. */
    private const LOAD_STARTED = 0, LOAD_COMMITTED = 2, LOAD_FINISHED = 3;
    /** WebKitPolicyDecisionType's navigation action; WebKitNavigationType's link, form, back/forward and reload. */
    private const POLICY_NAVIGATION = 0;
    private const NAV_LINK = 0, NAV_FORM = 1, NAV_BACK_FORWARD = 2, NAV_RELOAD = 3;
    /** WebKitAutoplayPolicy's allow. */
    private const AUTOPLAY_ALLOW = 0;
    /** The features that slow down a hidden page (mygo's backgroundFeatures). */
    private const BACKGROUND_FEATURES = ['HiddenPageDOMTimerThrottling', 'HiddenPageCSSAnimationSuspension'];
    /** The GtkGestureZoom WebKitGTK pinches with, in its view's data from 2.38 on (mygo's zoomGesture). */
    private const ZOOM_GESTURE = 'wk-view-zoom-gesture';
    /** The capture states' properties by CaptureKind, and the keys macOS's WebView tells onChange of them. */
    private const CAPTURE_PROPERTIES = ['camera-capture-state', 'microphone-capture-state', 'display-capture-state'];
    private const CAPTURE_KEYS = ['cameraCaptureState', 'microphoneCaptureState', '_displayCaptureState'];
    private const CAPTURE_FUNCTIONS = ['camera', 'microphone', 'display'];

    /** @var array<int, self> the open views by id, which their signals' data is */
    private static array $views = [];
    /** @var array<int, int> their ids by WebKitWebView address */
    private static array $byWeb = [];
    private static int $nextId = 0;
    /** @var array<int, array<string, true>> the schemes registered on each web context, by its address */
    private static array $schemes = [];
    /** @var array<string, CData> the holders of the C functions serving the signals, by signal */
    private static array $hooks = [];
    /** @var array<int, Closure(?CData, ?string): void> callAsync's callbacks, by key */
    private static array $pending = [];
    private static int $nextCall = 0;
    /** @var array<int, array{0: int, 1: string, 2: string, 3: ?string}> the downloads under way by WebKitDownload address: their view's id, URL, path and error */
    private static array $downloads = [];
    /** @var array<int, Closure(?string): void> the print operations to PDF under way, by address: called with the error, or null once done */
    private static array $printJobs = [];

    /** The WebKitWebView and its user content manager. */
    public CData $web;
    private ?CData $ucm;
    private int $id;
    /** What the user agent ends with, after a space (PageOptions::$userAgentSuffix). */
    private string $suffix = '';
    /** Whether the app started the navigation to come (mygo's programmatic). */
    private bool $programmatic = false;
    /** Whether a load failed since the last started: WebKit tells it finished after, macOS's does not. */
    private bool $failed = false;
    private bool $autoplay = false;
    /** The URL of the navigation started last, which a failure without one is of. */
    private string $provisionalURL = '';

    /** Asked whether the main frame may go to a URL: (url, userInitiated, a reload, one the app started) → allowed. @var ?Closure(string, bool, bool, bool): bool */
    public ?Closure $onNavigate = null;
    /** A navigation committed, at its URL. @var ?Closure(string): void */
    public ?Closure $onCommit = null;
    /** The page finished loading. @var ?Closure(): void */
    public ?Closure $onFinish = null;
    /** A load failed: (URL, code, description). @var ?Closure(string, int, string): void */
    public ?Closure $onFail = null;
    /** The page's web process went away, and why. @var ?Closure(string): void */
    public ?Closure $onGone = null;
    /** The page's <title> changed. @var ?Closure(string): void */
    public ?Closure $onTitle = null;
    /** The page's sound or capture changed: the key macOS's WebView tells. @var ?Closure(string): void */
    public ?Closure $onChange = null;
    /** window.open(), or a target=_blank link: (URL, '', 0, 0, [the WebKitNavigationAction, the opener's DataStore], a user's gesture), which makes the window opened with it as its configuration (Backend::newPage); what it returns goes unused. @var ?Closure(string, string, int, int, mixed, bool): mixed */
    public ?Closure $onOpen = null;
    /** The page called window.close(). @var ?Closure(): void */
    public ?Closure $onClosedByPage = null;
    /** Serves a request of a custom scheme (Protocol): (the request's [method, URL, headers, body], its task). @var ?Closure(array, \MyPHP\Native\SchemeTask): void */
    public ?Closure $onScheme = null;
    /** Asked where a download goes: (url, suggested file name) → path, or null to cancel; told when it ended: (url, path, ?error). @var ?Closure(string, string): ?string */
    public ?Closure $onDownload = null;
    /** @var ?Closure(string, string, ?string): void */
    public ?Closure $onDownloadDone = null;
    /** Serves a URL of a custom scheme into a download, which WebKit can't make of what a scheme handler serves. @var ?Closure(string): void */
    public ?Closure $onSchemeDownload = null;
    /** An HTTP sign-in challenge (host, port, realm, scheme, a proxy's?) → [user, password], [] to cancel; null: WebKit's handling. @var ?Closure(string, int, string, string, bool): ?array */
    public ?Closure $onAuth = null;
    /** A server certificate that does not verify (URL, host, the error, the DER certificate) → whether to load it; null: the load fails. @var ?Closure(string, string, string, string): bool */
    public ?Closure $onCertificate = null;
    /** Asked whether the page may use devices: (list of "camera", "microphone", "geolocation", "notifications", the page's origin) → granted. @var ?Closure(list<string>, string): bool */
    public ?Closure $onPermission = null;
    /** Shows alert() ('alert', message), confirm() ('confirm', message) → bool, prompt() ('prompt', prompt, default) → ?string and a file input ('files', several, folders) → ?list<string>. @var ?Closure(string, mixed...): mixed */
    public ?Closure $onPanel = null;
    /** WebKit's context menu is about to open: fn(WebKitContextMenu, GdkEvent) calls showContextMenu() when the page's listeners would change it. @var ?Closure(mixed, mixed): void */
    public ?Closure $onContextMenu = null;
    /** The view the wheel over the page goes to instead (a web view of native UI's with WebViewOptions::$scrollToContainer). */
    public mixed $scrollTo = null;
    /** The session's store the view's context is, null for the default one. */
    private ?DataStore $store = null;
    /** Whether the page was made with developer tools (PageOptions::$devTools), which openDevTools() needs. */
    private bool $devTools = false;
    /** The URL a certificate the app allowed failed at, loaded again once WebKit is done failing it (mygo's tlsRetry). */
    private string $tlsRetry = '';
    /** What showContextMenu() decided of the menu WebKit is about to show: true shows none. */
    private bool $noMenu = false;

    private function __construct(private Closure $onMessage) {}

    /**
     * A web view whose pages get $scripts at document start, the bridge
     * first (mygo's createWebView), with a content manager of its own, so
     * messages and scripts never leak between pages. With $opened, the
     * WebKitNavigationAction of a window.open() (onOpen), it loads that
     * action's URL, as WebKit loads it in macOS's view.
     * @param list<string|array{0: string, 1: bool}> $scripts
     */
    public static function create(array $scripts, Closure $onMessage, bool $devTools, string $userAgent, float $zoom, string $suffix, bool $swipe, bool $pinch, bool $autoplay, bool $throttle, ?CData $opened = null, ?DataStore $store = null): self
    {
        $f = WebKit::ffi();
        self::hooks();
        $ctx = WebsiteData::context($store);
        $k = Mem::addr($ctx);
        foreach (\MyPHP\Protocol::schemes() as $scheme) { // on the view's context, before the view loads anything (mygo's registerScheme)
            if (isset(self::$schemes[$k][$scheme])) { continue; }
            self::$schemes[$k][$scheme] = true;
            $f->webkit_web_context_register_uri_scheme($ctx, $scheme, self::$hooks['scheme']->fn, null, null);
            $sec = $f->webkit_web_context_get_security_manager($ctx);
            $f->webkit_security_manager_register_uri_scheme_as_secure($sec, $scheme);
            $f->webkit_security_manager_register_uri_scheme_as_cors_enabled($sec, $scheme);
        }
        $self = new self($onMessage);
        $self->id = ++self::$nextId;
        $self->suffix = $suffix;
        $self->autoplay = $autoplay;
        $self->devTools = $devTools;
        $self->store = $store;
        $self->ucm = $f->webkit_user_content_manager_new();
        $self->web = $f->g_object_ref($store === null ? $f->webkit_web_view_new_with_user_content_manager($self->ucm) // held past its destroy, until close() lets it go
            : $f->g_object_new($f->webkit_web_view_get_type(), 'web-context', $ctx, 'user-content-manager', $self->ucm, null)); // a session's (mygo's newWebView)
        $data = $f->cast('void*', $self->id);
        $f->webkit_user_content_manager_register_script_message_handler($self->ucm, 'myphp');
        self::on($self->ucm, 'script-message-received::myphp', 'message', $data);
        $self->setUserScripts($scripts);

        $settings = $f->webkit_web_view_get_settings($self->web);
        $f->webkit_settings_set_enable_developer_extras($settings, $devTools ? 1 : 0);
        $f->webkit_settings_set_allow_file_access_from_file_urls($settings, 1);
        $f->webkit_settings_set_javascript_can_access_clipboard($settings, 1);
        if ($userAgent !== '') {
            $self->setUserAgent($userAgent);
        } elseif ($suffix !== '') {
            self::setUserAgentSuffix($settings, $suffix);
        }
        if (!$throttle) {
            self::disableBackgroundThrottling($settings);
        }
        if ($zoom > 0 && $zoom != 1) {
            $self->setZoom($zoom);
        }
        if ($swipe) { // off by default
            $f->g_object_set($settings, 'enable-back-forward-navigation-gestures', 1, null);
        }
        if (!$pinch && ($g = $f->g_object_get_data($self->web, self::ZOOM_GESTURE)) !== null) {
            $f->gtk_event_controller_set_propagation_phase($g, 0); // GTK_PHASE_NONE: pinches no longer magnify (mygo's stopPinchZoom)
        }
        foreach (['load-changed' => 'load', 'load-failed' => 'failed', 'notify::title' => 'title', 'decide-policy' => 'decide', 'create' => 'create',
            'close' => 'close', 'web-process-terminated' => 'gone', 'notify::is-playing-audio' => 'change', 'permission-request' => 'permission',
            'authenticate' => 'auth', 'load-failed-with-tls-errors' => 'tls', 'script-dialog' => 'dialog', 'run-file-chooser' => 'files',
            'context-menu' => 'menu', 'scroll-event' => 'scroll'] as $signal => $hook) {
            self::on($self->web, $signal, $hook, $data);
        }
        foreach (self::CAPTURE_PROPERTIES as $p) {
            self::on($self->web, "notify::$p", 'change', $data);
        }
        self::$views[$self->id] = $self;
        self::$byWeb[Mem::addr($self->web)] = $self->id;
        if ($opened !== null) {
            $self->loadURL((string)$f->webkit_uri_request_get_uri($f->webkit_navigation_action_get_request($opened)));
        }
        return $self;
    }

    /** Connects the C function of $hook to $signal of $obj. */
    private static function on(CData $obj, string $signal, string $hook, CData $data): void
    {
        $f = WebKit::ffi();
        $f->g_signal_connect_data($obj, $signal, $f->cast('void*', self::$hooks[$hook]->fn), $data, null, 0);
    }

    private static function view(?CData $data): ?self
    {
        return self::$views[Mem::addr($data)] ?? null;
    }

    /** The C functions of the signals, made once. */
    private static function hooks(): void
    {
        if (self::$hooks !== []) {
            return;
        }
        $h = static function (string $type, Closure $fn): CData {
            $x = WebKit::ffi()->new($type, false); // kept for good: C keeps its thunk
            $x->fn = $fn;
            return $x;
        };
        self::$hooks['message'] = $h('HNotify', function ($ucm, $result, $data): void {
            $v = self::view($data);
            if ($v === null) { return; }
            $f = WebKit::ffi();
            ($v->onMessage)(WebKit::take($f->jsc_value_to_string($f->webkit_javascript_result_get_js_value($result))));
        });
        self::$hooks['load'] = $h('HLoad', function ($web, int $event, $data): void {
            $v = self::view($data);
            if ($v === null) { return; }
            switch ($event) {
                case self::LOAD_STARTED:
                    $v->failed = false;
                    $v->provisionalURL = $v->url();
                    break;
                case self::LOAD_COMMITTED:
                    $v->onCommit?->__invoke($v->url());
                    break;
                case self::LOAD_FINISHED:
                    if (!$v->failed) { $v->onFinish?->__invoke(); } // a failed load finished too, which macOS's never tells
                    break;
            }
        });
        // Cancelled loads (WEBKIT_NETWORK_ERROR_CANCELLED, 302) and navigations a policy stopped (102) are none (mygo's cbLoadFailed).
        // The others are handled here: WebKit's error page would load after, telling a navigation and a load of its own, as macOS's never does.
        self::$hooks['failed'] = $h('HFailed', function ($web, int $event, $uri, $error, $data): int {
            $v = self::view($data);
            if ($v === null || $error === null) { return 0; }
            $v->failed = true;
            $e = WebKit::ffi()->cast('GError*', $error);
            if ($e->code === 302 || $e->code === 102) { return 0; }
            $url = (string)$uri;
            $v->onFail?->__invoke($url !== '' ? $url : $v->provisionalURL, $e->code, \FFI::string($e->message));
            return 1;
        });
        self::$hooks['title'] = $h('HNotify', function ($web, $pspec, $data): void {
            $v = self::view($data);
            $v?->onTitle?->__invoke((string)WebKit::ffi()->webkit_web_view_get_title($v->web));
        });
        self::$hooks['change'] = $h('HNotify', function ($web, $pspec, $data): void {
            $v = self::view($data);
            if ($v === null) { return; }
            $p = (string)WebKit::ffi()->g_param_spec_get_name($pspec);
            $kind = array_search($p, self::CAPTURE_PROPERTIES, true);
            $v->onChange?->__invoke($kind === false ? '_isPlayingAudio' : self::CAPTURE_KEYS[$kind]);
        });
        self::$hooks['decide'] = $h('HDecide', function ($web, $decision, int $type, $data): int {
            $v = self::view($data);
            return match (true) { $v === null => 0, $type === self::POLICY_NAVIGATION => $v->decide($decision), $type === self::POLICY_RESPONSE => $v->decideResponse($decision), default => 0 };
        });
        // WebKitGTK makes a related view share the opener's content manager, which would send the new window's messages to the opener:
        // the window opened gets the navigation action as its configuration, whose URL its own view loads, its page without an opener
        // (mygo's cbCreate). WebKit is told no view.
        self::$hooks['create'] = $h('HCreate', function ($web, $action, $data) {
            $v = self::view($data);
            if ($v?->onOpen === null) { return null; }
            $f = WebKit::ffi();
            $url = (string)$f->webkit_uri_request_get_uri($f->webkit_navigation_action_get_request($action));
            ($v->onOpen)($url, '', 0, 0, [$action, $v->store], (bool)$f->webkit_navigation_action_is_user_gesture($action)); // in the opener's session (mygo's)
            return null;
        });
        self::$hooks['close'] = $h('HPlain', function ($web, $data): void {
            self::view($data)?->onClosedByPage?->__invoke();
        });
        self::$hooks['gone'] = $h('HGone', function ($web, int $reason, $data): void {
            self::view($data)?->onGone?->__invoke([0 => 'crashed', 1 => 'exceeded memory limit', 2 => 'terminated by API'][$reason] ?? 'crashed');
        });
        self::$hooks['async'] = $h('HNotify', function ($source, $res, $data): void {
            $key = Mem::addr($data);
            $done = self::$pending[$key] ?? null;
            unset(self::$pending[$key]);
            if ($done === null) { return; }
            $f = WebKit::ffi();
            $err = $f->new('void*[1]');
            $value = $f->webkit_web_view_call_async_javascript_function_finish($source, $res, $err);
            $done($err[0] === null ? $value : null, $err[0] === null ? null : WebKit::errorMessage($err[0]));
        });
        self::$hooks['permission'] = $h('HBool', fn($web, $req, $data): int => self::view($data)?->permission($req) ?? 0);
        // Returning false leaves the request to WebKit, which signs in with nothing: the server's refusal (mygo's cbAuthenticate).
        self::$hooks['auth'] = $h('HBool', fn($web, $req, $data): int => self::view($data)?->authenticate($req) ?? 0);
        self::$hooks['tls'] = $h('HTls', fn($web, $uri, $cert, int $flags, $data): int => self::view($data)?->tlsError((string)$uri, $cert, $flags) ?? 0);
        self::$hooks['dialog'] = $h('HBool', fn($web, $dialog, $data): int => self::view($data)?->scriptDialog($dialog) ?? 0);
        self::$hooks['files'] = $h('HBool', fn($web, $req, $data): int => self::view($data)?->chooseFiles($req) ?? 0);
        self::$hooks['menu'] = $h('HMenu', function ($web, $menu, $event, $hit, $data): int {
            $v = self::view($data);
            if ($v?->onContextMenu === null) { return 0; }
            $v->noMenu = false;
            ($v->onContextMenu)($menu, $event);
            return $v->noMenu ? 1 : 0;
        });
        self::$hooks['scroll'] = $h('HBool', fn($web, $event, $data): int => (self::view($data)?->scrollTo)?->__invoke($event) ? 1 : 0);
        // Downloads: the web context tells each, which asks where it goes and tells how it ended, "failed" before "finished" (mygo's download.go).
        self::$hooks['download'] = $h('HNotify', function ($ctx, $d, $data): void {
            $f = WebKit::ffi();
            $id = self::$byWeb[Mem::addr($f->webkit_download_get_web_view($d))] ?? 0;
            if (!isset(self::$views[$id])) { return; }
            self::$downloads[Mem::addr($d)] = [$id, (string)$f->webkit_uri_request_get_uri($f->webkit_download_get_request($d)), '', null];
            $f->g_signal_connect_data($d, 'decide-destination', $f->cast('void*', self::$hooks['destination']->fn), null, null, 0);
            $f->g_signal_connect_data($d, 'failed', $f->cast('void*', self::$hooks['downloadFailed']->fn), null, null, 0);
            $f->g_signal_connect_data($d, 'finished', $f->cast('void*', self::$hooks['downloadFinished']->fn), null, null, 0);
        });
        self::$hooks['destination'] = $h('HDestination', function ($d, $suggested, $data): int {
            $k = Mem::addr($d);
            [$id, $url] = self::$downloads[$k] ?? [0, ''];
            $v = self::$views[$id] ?? null;
            $f = WebKit::ffi();
            $path = $v?->onDownload === null ? null : ($v->onDownload)($url, (string)$suggested);
            if ($path === null) {
                unset(self::$downloads[$k]);
                $f->webkit_download_cancel($d);
                return 1;
            }
            self::$downloads[$k][2] = $path;
            $file = $f->g_file_new_for_path($path);
            $f->webkit_download_set_allow_overwrite($d, 1);
            $f->webkit_download_set_destination($d, WebKit::take($f->g_file_get_uri($file)));
            $f->g_object_unref($file);
            return 1;
        });
        self::$hooks['downloadFailed'] = $h('HNotify', function ($d, $error, $data): void {
            $k = Mem::addr($d);
            if (isset(self::$downloads[$k])) { self::$downloads[$k][3] = \FFI::string(WebKit::ffi()->cast('GError*', $error)->message); }
        });
        self::$hooks['downloadFinished'] = $h('HPlain', function ($d, $data): void {
            $k = Mem::addr($d);
            [$id, $url, $path, $error] = self::$downloads[$k] ?? [0, '', '', null];
            unset(self::$downloads[$k]);
            $v = self::$views[$id] ?? null;
            if ($path !== '' && $v?->onDownloadDone !== null) { ($v->onDownloadDone)($url, $path, $error); }
        });
        self::$hooks['snapshot'] = $h('HNotify', function ($source, $res, $data): void {
            $key = Mem::addr($data);
            $done = self::$pending[$key] ?? null;
            unset(self::$pending[$key]);
            if ($done === null) { return; }
            $f = WebKit::ffi();
            $err = $f->new('void*[1]');
            $surface = $f->webkit_web_view_get_snapshot_finish($source, $res, $err);
            $done($err[0] === null ? $surface : null, $err[0] === null ? null : WebKit::errorMessage($err[0]));
        });
        self::$hooks['printed'] = $h('HPlain', function ($op, $data): void {
            (self::$printJobs[Mem::addr($op)] ?? null)?->__invoke(null);
        });
        self::$hooks['printFailed'] = $h('HNotify', function ($op, $error, $data): void {
            (self::$printJobs[Mem::addr($op)] ?? null)?->__invoke(\FFI::string(WebKit::ffi()->cast('GError*', $error)->message));
        });
        self::contextMade(WebKit::ffi()->webkit_web_context_get_default());
        // Requests of the schemes PHP serves (mygo's serveScheme); one of a view gone gets a 503, as macOS's.
        self::$hooks['scheme'] = $h('HScheme', function ($req, $data): void {
            $f = WebKit::ffi();
            $v = self::$views[self::$byWeb[Mem::addr($f->webkit_uri_scheme_request_get_web_view($req))] ?? 0] ?? null;
            $task = new SchemeTask($req);
            if ($v?->onScheme === null) {
                $task->respond(503, []);
                $task->finish();
                return;
            }
            ($v->onScheme)(SchemeTask::request($req), $task);
        });
    }

    /** WebKitPolicyDecisionType's response. */
    private const POLICY_RESPONSE = 2;

    /**
     * Makes attachments, and what WebKit cannot show, downloads; one of a
     * scheme PHP serves is served again into a download, as WebKit makes
     * none of what a scheme handler serves (mygo's decideResponse).
     */
    private function decideResponse(CData $decision): int
    {
        $f = WebKit::ffi();
        $resp = $f->webkit_response_policy_decision_get_response($decision);
        $url = (string)$f->webkit_uri_response_get_uri($resp);
        $custom = array_key_exists($url, \MyPHP\Native\Scheme::$dispositions); // served by PHP
        $disposition = $custom ? \MyPHP\Native\Scheme::$dispositions[$url] : '';
        if (!$custom && ($h = $f->webkit_uri_response_get_http_headers($resp)) !== null) {
            $disposition = (string)$f->soup_message_headers_get_one($h, 'Content-Disposition');
        }
        $attachment = str_starts_with(strtolower(trim($disposition)), 'attachment');
        if (!$attachment && $f->webkit_response_policy_decision_is_mime_type_supported($decision)) {
            return 0;
        }
        if ($custom && $this->onSchemeDownload !== null) {
            $f->webkit_policy_decision_ignore($decision);
            ($this->onSchemeDownload)($url);
            return 1;
        }
        $f->webkit_policy_decision_download($decision);
        return 1;
    }

    /** Camera, microphone, location and notifications go to onPermission; other requests get WebKit's answer (mygo's cbPermission). */
    private function permission(CData $req): int
    {
        $f = WebKit::ffi();
        $kinds = [];
        if ($f->g_type_check_instance_is_a($req, $f->webkit_user_media_permission_request_get_type())) {
            if ($f->webkit_user_media_permission_is_for_video_device($req)) { $kinds[] = 'camera'; }
            if ($f->webkit_user_media_permission_is_for_audio_device($req)) { $kinds[] = 'microphone'; }
        } elseif ($f->g_type_check_instance_is_a($req, $f->webkit_geolocation_permission_request_get_type())) {
            $kinds = ['geolocation'];
        } elseif ($f->g_type_check_instance_is_a($req, $f->webkit_notification_permission_request_get_type())) {
            $kinds = ['notifications'];
        }
        if ($kinds === [] || $this->onPermission === null) {
            return 0;
        }
        $u = parse_url($this->url());
        $origin = ($u['scheme'] ?? '') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ":{$u['port']}" : '');
        ($this->onPermission)($kinds, $origin) ? $f->webkit_permission_request_allow($req) : $f->webkit_permission_request_deny($req);
        return 1;
    }

    /** WebKitAuthenticationScheme's HTTP schemes by their names; others, client certificates among them, get WebKit's handling (mygo's authScheme). */
    private const AUTH_SCHEMES = [1 => 'basic', 2 => 'basic', 3 => 'digest', 5 => 'ntlm', 6 => 'negotiate'];

    /** An HTTP sign-in (mygo's cbAuthenticate): onAuth's credentials, for the session, or none, the server's refusal. */
    private function authenticate(CData $req): int
    {
        $f = WebKit::ffi();
        $scheme = self::AUTH_SCHEMES[$f->webkit_authentication_request_get_scheme($req)] ?? null;
        if ($scheme === null || $this->onAuth === null) {
            return 0;
        }
        $r = ($this->onAuth)((string)$f->webkit_authentication_request_get_host($req), $f->webkit_authentication_request_get_port($req),
            (string)$f->webkit_authentication_request_get_realm($req), $scheme, (bool)$f->webkit_authentication_request_is_for_proxy($req));
        if ($r === null) {
            return 0;
        }
        if ($r === [] || $r === ['', '']) {
            $f->webkit_authentication_request_authenticate($req, null); // without credentials: the server's refusal
            return 1;
        }
        $c = $f->webkit_credential_new($r[0], $r[1], 1); // WEBKIT_CREDENTIAL_PERSISTENCE_FOR_SESSION
        $f->webkit_authentication_request_authenticate($req, $c);
        $f->webkit_credential_free($c);
        return 1;
    }

    /** GTlsCertificateFlags' errors, worded, by bit (mygo's tlsErrors). */
    private const TLS_ERRORS = ["the certificate's issuer is not known", 'the certificate does not match the host', 'the certificate is not valid yet',
        'the certificate expired', 'the certificate was revoked', "the certificate's algorithm is insecure", 'the certificate is invalid'];

    /**
     * A server certificate that does not verify (mygo's cbTLSErrors): one
     * onCertificate allows is allowed for its host, and the page loaded
     * again once WebKit finished failing it; otherwise the load fails.
     */
    private function tlsError(string $url, ?CData $cert, int $flags): int
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($cert === null || $this->onCertificate === null || $host === '') {
            return 0;
        }
        $f = WebKit::ffi();
        $error = 'the certificate does not verify';
        foreach (self::TLS_ERRORS as $bit => $text) {
            if ($flags & (1 << $bit)) { $error = $text; break; }
        }
        $der = '';
        $slot = $f->new('GByteArray*[1]');
        $f->g_object_get($cert, 'certificate', \FFI::addr($slot[0]), null); // variadic: a pointer, not the array
        if ($slot[0] !== null) {
            $der = \FFI::string($slot[0]->data, $slot[0]->len);
            $f->g_byte_array_unref($slot[0]);
        }
        if (!($this->onCertificate)($url, trim($host, '[]'), $error, $der)) {
            return 0;
        }
        $f->webkit_web_context_allow_tls_certificate_for_host($f->webkit_web_view_get_context($this->web), $cert, trim($host, '[]'));
        $this->failed = true; // WebKit finishes the failed load after the signal, which is no page's load
        $this->tlsRetry = $url;
        Gtk::idle(function (): void {
            if ($this->tlsRetry !== '' && $this->ucm !== null) {
                $url = $this->tlsRetry;
                $this->tlsRetry = '';
                $this->loadURL($url);
            }
        });
        return 1;
    }

    /** WebKitScriptDialogType's. */
    private const DIALOG_ALERT = 0, DIALOG_CONFIRM = 1, DIALOG_PROMPT = 2;

    /** alert(), confirm() and prompt() as the app's dialogs, through onPanel (macOS's panels); WebKit's own for before-unload. */
    private function scriptDialog(CData $dialog): int
    {
        $f = WebKit::ffi();
        $type = $f->webkit_script_dialog_get_dialog_type($dialog);
        if ($this->onPanel === null || $type > self::DIALOG_PROMPT) {
            return 0;
        }
        $message = (string)$f->webkit_script_dialog_get_message($dialog);
        match ($type) {
            self::DIALOG_ALERT => ($this->onPanel)('alert', $message),
            self::DIALOG_CONFIRM => $f->webkit_script_dialog_confirm_set_confirmed($dialog, ($this->onPanel)('confirm', $message) ? 1 : 0),
            self::DIALOG_PROMPT => (function () use ($f, $dialog, $message): void {
                $text = ($this->onPanel)('prompt', $message, (string)$f->webkit_script_dialog_prompt_get_default_text($dialog));
                if ($text !== null) { $f->webkit_script_dialog_prompt_set_text($dialog, $text); } // none set: prompt() returns null
            })(),
        };
        return 1;
    }

    /** A file input's chooser, through onPanel; WebKitGTK tells no folder input. */
    private function chooseFiles(CData $req): int
    {
        if ($this->onPanel === null) {
            return 0;
        }
        $f = WebKit::ffi();
        $paths = ($this->onPanel)('files', (bool)$f->webkit_file_chooser_request_get_select_multiple($req), false);
        if ($paths === null || $paths === []) {
            $f->webkit_file_chooser_request_cancel($req);
            return 1;
        }
        $list = $f->new('const char*[' . (count($paths) + 1) . ']');
        $keep = [];
        foreach (array_values($paths) as $i => $p) {
            $keep[$i] = $f->new('char[' . (strlen($p) + 1) . ']', false);
            \FFI::memcpy($keep[$i], $p . "\0", strlen($p) + 1);
            $list[$i] = $f->cast('const char*', $keep[$i]);
        }
        $list[count($paths)] = null;
        $f->webkit_file_chooser_request_select_files($req, $list);
        foreach ($keep as $k) { \FFI::free($k); }
        return 1;
    }

    /** @internal a web context was made, the default or a session's (WebsiteData::newStore): its downloads are the pages' */
    public static function contextMade(CData $ctx): void
    {
        self::hooks();
        WebKit::ffi()->g_signal_connect_data($ctx, 'download-started', WebKit::ffi()->cast('void*', self::$hooks['download']->fn), null, null, 0);
    }

    /** Decides a navigation of the page (mygo's cbDecidePolicy): the main frame's go to onNavigate, the app's own and back/forward and reloads told so. */
    private function decide(CData $decision): int
    {
        $f = WebKit::ffi();
        if ($f->webkit_navigation_policy_decision_get_frame_name($decision) !== null) {
            return 0; // a frame's: WebKit's default
        }
        $action = $f->webkit_navigation_policy_decision_get_navigation_action($decision);
        $type = $f->webkit_navigation_action_get_navigation_type($action);
        $url = (string)$f->webkit_uri_request_get_uri($f->webkit_navigation_action_get_request($action));
        $user = $f->webkit_navigation_action_is_user_gesture($action) || $type === self::NAV_LINK || $type === self::NAV_FORM;
        $unasked = $type === self::NAV_BACK_FORWARD || $type === self::NAV_RELOAD || $this->programmatic;
        $this->programmatic = false;
        $allow = $this->onNavigate === null || ($this->onNavigate)($url, $user, $type === self::NAV_RELOAD, $unasked);
        if (!$allow) {
            $f->webkit_policy_decision_ignore($decision);
        } elseif ($this->autoplay) { // audible media without a gesture (mygo's useWithAutoplay)
            $policies = $f->webkit_website_policies_new_with_policies('autoplay', self::AUTOPLAY_ALLOW, null);
            $f->webkit_policy_decision_use_with_policies($decision, $policies);
            $f->g_object_unref($policies);
        } else {
            $f->webkit_policy_decision_use($decision);
        }
        return 1;
    }

    public function handle(): mixed
    {
        return $this->web;
    }

    /** Shows WebKit's inspector, in a window of its own, for a page made with developer tools (mygo's OpenDevTools). */
    public function openDevTools(): void
    {
        if ($this->devTools) { WebKit::ffi()->webkit_web_inspector_show(WebKit::ffi()->webkit_web_view_get_inspector($this->web)); }
    }

    public function closeDevTools(): void
    {
        WebKit::ffi()->webkit_web_inspector_close(WebKit::ffi()->webkit_web_view_get_inspector($this->web));
    }

    /** Whether the inspector has its web view, which it has while it shows (mygo's IsDevToolsOpened). */
    public function isDevToolsOpened(): bool
    {
        return WebKit::ffi()->webkit_web_inspector_get_web_view(WebKit::ffi()->webkit_web_view_get_inspector($this->web)) !== null;
    }

    /** Replaces the scripts pages get at document start, all of them, as the content manager removes them so (mygo's SetUserScripts). */
    public function setUserScripts(array $scripts): void
    {
        if ($this->ucm === null) {
            return;
        }
        $f = WebKit::ffi();
        $f->webkit_user_content_manager_remove_all_scripts($this->ucm);
        foreach ($scripts as $s) {
            [$src, $all] = is_array($s) ? $s : [$s, false];
            $script = $f->webkit_user_script_new($src, $all ? self::ALL_FRAMES : self::TOP_FRAME, self::DOCUMENT_START, null, null);
            $f->webkit_user_content_manager_add_script($this->ucm, $script);
            $f->webkit_user_script_unref($script);
        }
    }

    /** Replaces the content manager's filters (WebKitUserContentFilters of WebsiteData::compileContentRules), which apply to the loads from then on (mygo's setContentRules). */
    public function setContentRules(array $lists): void
    {
        if ($this->ucm === null) {
            return;
        }
        $f = WebKit::ffi();
        $f->webkit_user_content_manager_remove_all_filters($this->ucm);
        foreach ($lists as $l) { $f->webkit_user_content_manager_add_filter($this->ucm, $l); }
    }

    public function isAudible(): bool
    {
        return (bool)WebKit::ffi()->webkit_web_view_is_playing_audio($this->web);
    }

    public function setAudioMuted(bool $muted): void
    {
        WebKit::ffi()->webkit_web_view_set_is_muted($this->web, $muted ? 1 : 0);
    }

    public function isAudioMuted(): bool
    {
        return (bool)WebKit::ffi()->webkit_web_view_get_is_muted($this->web);
    }

    /** WebKitMediaCaptureState (none, active, muted) is CaptureState's. */
    public function captureState(int $kind): int
    {
        return WebKit::ffi()->{'webkit_web_view_get_' . self::CAPTURE_FUNCTIONS[$kind] . '_capture_state'}($this->web);
    }

    public function setCaptureState(int $kind, int $state): void
    {
        if ($this->captureState($kind) === 0) { return; } // WebKit would otherwise keep a mute set before
        WebKit::ffi()->{'webkit_web_view_set_' . self::CAPTURE_FUNCTIONS[$kind] . '_capture_state'}($this->web, $state);
    }

    /** 0: WebKitGTK tells no web process of a view (mygo's ProcessID). */
    public function processID(): int
    {
        return 0;
    }

    /** WebKitGTK's serialized session state: the back/forward list, in its own versioned encoding (mygo's SessionState). */
    public function sessionState(): string
    {
        $f = WebKit::ffi();
        $state = $f->webkit_web_view_get_session_state($this->web);
        $bytes = $f->webkit_web_view_session_state_serialize($state);
        $f->webkit_web_view_session_state_unref($state);
        $n = $f->new('size_t[1]');
        $p = $f->g_bytes_get_data($bytes, $n);
        $out = $n[0] === 0 ? '' : \FFI::string($p, $n[0]);
        $f->g_bytes_unref($bytes);
        return $out;
    }

    /**
     * Puts back the back/forward list, which WebKitGTK does without loading
     * anything, then goes to its current item, as Epiphany does; a state it
     * can't read is none (mygo's RestoreSessionState).
     */
    public function restoreSessionState(string $state): bool
    {
        if ($state === '') {
            return false;
        }
        $f = WebKit::ffi();
        $bytes = $f->g_bytes_new($state, strlen($state));
        $s = $f->webkit_web_view_session_state_new($bytes);
        $f->g_bytes_unref($bytes);
        if ($s === null) {
            return false;
        }
        $f->webkit_web_view_restore_session_state($this->web, $s);
        $f->webkit_web_view_session_state_unref($s);
        $item = $f->webkit_back_forward_list_get_current_item($f->webkit_web_view_get_back_forward_list($this->web));
        if ($item === null) {
            return false;
        }
        $f->webkit_web_view_go_to_back_forward_list_item($this->web, $item);
        return true;
    }

    /** Lets the web view go: its handler and scripts, its page stopped; the view itself once GTK is done with it (mygo's cleanup). */
    public function close(): void
    {
        if ($this->ucm === null) {
            return;
        }
        $f = WebKit::ffi();
        unset(self::$views[$this->id], self::$byWeb[Mem::addr($this->web)]);
        $f->webkit_user_content_manager_unregister_script_message_handler($this->ucm, 'myphp');
        $f->webkit_user_content_manager_remove_all_scripts($this->ucm);
        $f->webkit_web_view_stop_loading($this->web);
        $f->g_object_unref($this->ucm);
        $this->ucm = null;
        $web = $this->web;
        Gtk::idle(fn() => WebKit::ffi()->g_object_unref($web)); // once its window, destroyed now, let it go
    }

    public function reload(bool $ignoreCache): void
    {
        $ignoreCache ? WebKit::ffi()->webkit_web_view_reload_bypass_cache($this->web) : WebKit::ffi()->webkit_web_view_reload($this->web);
    }

    public function stop(): void { WebKit::ffi()->webkit_web_view_stop_loading($this->web); }
    public function goBack(): void { WebKit::ffi()->webkit_web_view_go_back($this->web); }
    public function goForward(): void { WebKit::ffi()->webkit_web_view_go_forward($this->web); }
    public function canGoBack(): bool { return (bool)WebKit::ffi()->webkit_web_view_can_go_back($this->web); }
    public function canGoForward(): bool { return (bool)WebKit::ffi()->webkit_web_view_can_go_forward($this->web); }
    public function isLoading(): bool { return (bool)WebKit::ffi()->webkit_web_view_is_loading($this->web); }
    public function setZoom(float $f): void { WebKit::ffi()->webkit_web_view_set_zoom_level($this->web, $f); }
    public function zoom(): float { return WebKit::ffi()->webkit_web_view_get_zoom_level($this->web); }

    /** Overrides the user agent, the suffix after it; '' restores WebKit's, the suffix appended to it (mygo's SetUserAgent). */
    public function setUserAgent(string $ua): void
    {
        $settings = WebKit::ffi()->webkit_web_view_get_settings($this->web);
        if ($ua === '') {
            $this->suffix !== '' ? self::setUserAgentSuffix($settings, $this->suffix) : WebKit::ffi()->webkit_settings_set_user_agent($settings, null);
            return;
        }
        WebKit::ffi()->webkit_settings_set_user_agent($settings, $this->suffix === '' ? $ua : "$ua {$this->suffix}");
    }

    public function userAgent(): string
    {
        return (string)WebKit::ffi()->webkit_settings_get_user_agent(WebKit::ffi()->webkit_web_view_get_settings($this->web));
    }

    /** Appends $suffix to WebKit's own user agent, after a space, as macOS's does: WebKitGTK's application details would join it with "/" (mygo's webkitSettingsSetUserAgentSuffix). */
    private static function setUserAgentSuffix(CData $settings, string $suffix): void
    {
        $f = WebKit::ffi();
        $f->webkit_settings_set_user_agent($settings, null);
        $f->webkit_settings_set_user_agent($settings, $f->webkit_settings_get_user_agent($settings) . " $suffix");
    }

    /** Turns off the features that slow down a hidden page (mygo's disableBackgroundThrottling). */
    private static function disableBackgroundThrottling(CData $settings): void
    {
        $f = WebKit::ffi();
        $list = $f->webkit_settings_get_all_features();
        for ($i = 0, $n = $f->webkit_feature_list_get_length($list); $i < $n; $i++) {
            $feature = $f->webkit_feature_list_get($list, $i);
            if (in_array($f->webkit_feature_get_identifier($feature), self::BACKGROUND_FEATURES, true)) {
                $f->webkit_settings_set_feature_enabled($settings, $feature, 0);
            }
        }
        $f->webkit_feature_list_unref($list);
    }

    /** Loads a local file, as its file: URL: WebKitGTK reads the files of any folder (mygo's LoadFile). */
    public function loadFile(string $path, string $readAccessDir): void
    {
        $f = WebKit::ffi();
        $file = $f->g_file_new_for_path($path);
        $uri = WebKit::take($f->g_file_get_uri($file));
        $f->g_object_unref($file);
        $this->loadURL($uri);
    }

    /**
     * Runs $body as the body of an async function in the page's world
     * (webkit_web_view_call_async_javascript_function, WebKitGTK 2.40);
     * $done gets the string it returns ('' for undefined or null, as
     * macOS's), or the error: the script's exception message.
     * @param Closure(?string, ?string): void $done
     */
    public function callAsync(string $body, Closure $done): void
    {
        $f = WebKit::ffi();
        $key = ++self::$nextCall;
        self::$pending[$key] = function (?CData $value, ?string $error) use ($done): void {
            if ($value === null) {
                $done(null, $error);
                return;
            }
            $f = WebKit::ffi();
            $s = $f->jsc_value_is_undefined($value) || $f->jsc_value_is_null($value) ? '' : WebKit::take($f->jsc_value_to_string($value));
            $f->g_object_unref($value);
            $done($s, null);
        };
        $f->webkit_web_view_call_async_javascript_function($this->web, $body, strlen($body), null, null, null, null, self::$hooks['async']->fn, $f->cast('void*', $key));
    }

    /** A PNG of the visible part of the page (webkit_web_view_get_snapshot, mygo's CapturePage); $done gets it or the error. @param Closure(?string, ?string): void $done */
    public function capture(Closure $done): void
    {
        $f = WebKit::ffi();
        $key = ++self::$nextCall;
        self::$pending[$key] = function (?CData $surface, ?string $error) use ($done): void {
            if ($surface === null) {
                $done(null, $error);
                return;
            }
            $f = WebKit::ffi();
            $path = tempnam(sys_get_temp_dir(), 'myphp-capture');
            $ok = $f->cairo_surface_write_to_png($surface, $path) === 0; // CAIRO_STATUS_SUCCESS
            $f->cairo_surface_destroy($surface);
            $png = $ok ? (string)file_get_contents($path) : '';
            unlink($path);
            $png === '' ? $done(null, 'myphp: the snapshot is no image') : $done($png, null);
        };
        $f->webkit_web_view_get_snapshot($this->web, 0, 0, null, self::$hooks['snapshot']->fn, $f->cast('void*', $key)); // WEBKIT_SNAPSHOT_REGION_VISIBLE, no options
    }

    public function loadHTML(string $html, string $baseURL = ''): void
    {
        $this->programmatic = true;
        WebKit::ffi()->webkit_web_view_load_html($this->web, $html, $baseURL === '' ? null : $baseURL);
    }

    /** The URL of the page shown, '' before any. */
    public function url(): string
    {
        return (string)WebKit::ffi()->webkit_web_view_get_uri($this->web);
    }

    public function loadURL(string $url): void
    {
        $this->programmatic = true;
        WebKit::ffi()->webkit_web_view_load_uri($this->web, $url);
    }

    /** Shows GTK's print dialog for the page over $window, the GtkWindow (mygo's Print). */
    public function print($window): void
    {
        $f = WebKit::ffi();
        $op = $f->webkit_print_operation_new($this->web);
        $f->webkit_print_operation_run_dialog($op, $window);
        $f->g_object_unref($op);
    }

    /**
     * Prints the page into a PDF through GTK's "Print to File" printer,
     * which writes it to a temporary file (mygo's PrintToPDF): paper of
     * $width × $height inches, margins in inches; $done gets the bytes or
     * the error.
     * @param Closure(?string, ?string): void $done
     */
    public function printToPDF($window, float $width, float $height, bool $landscape, array $margins, bool $background, Closure $done): void
    {
        if ($this->url() === '') { // WebKitGTK crashes printing a view that never loaded anything
            $done(null, 'myphp: printing to PDF: the page has loaded nothing');
            return;
        }
        $f = WebKit::ffi();
        $path = tempnam(sys_get_temp_dir(), 'myphp-') . '.pdf';
        $file = $f->g_file_new_for_path($path);
        $uri = WebKit::take($f->g_file_get_uri($file));
        $f->g_object_unref($file);
        $settings = $f->gtk_print_settings_new();
        foreach (['printer' => 'Print to File', 'output-file-format' => 'pdf', 'output-uri' => $uri] as $k => $v) {
            $f->gtk_print_settings_set($settings, $k, $v);
        }
        $inch = 2; // GTK_UNIT_INCH
        $setup = $f->gtk_page_setup_new();
        $paper = $f->gtk_paper_size_new_custom('myphp', 'MyPHP', $width, $height, $inch);
        $f->gtk_page_setup_set_paper_size($setup, $paper);
        $f->gtk_paper_size_free($paper);
        if ($landscape) { $f->gtk_page_setup_set_orientation($setup, 1); } // GTK_PAGE_ORIENTATION_LANDSCAPE
        [$top, $right, $bottom, $left] = $margins;
        $f->gtk_page_setup_set_top_margin($setup, $top, $inch);
        $f->gtk_page_setup_set_right_margin($setup, $right, $inch);
        $f->gtk_page_setup_set_bottom_margin($setup, $bottom, $inch);
        $f->gtk_page_setup_set_left_margin($setup, $left, $inch);
        $web = $f->webkit_web_view_get_settings($this->web);
        $printed = $f->webkit_settings_get_print_backgrounds($web);
        $f->webkit_settings_set_print_backgrounds($web, $background ? 1 : 0);
        $op = $f->webkit_print_operation_new($this->web);
        $f->webkit_print_operation_set_print_settings($op, $settings);
        $f->webkit_print_operation_set_page_setup($op, $setup);
        $f->g_object_unref($settings);
        $f->g_object_unref($setup);
        $k = Mem::addr($op);
        $failed = null;
        self::$printJobs[$k] = function (?string $error) use ($k, $op, $web, $printed, $path, $done, &$failed): void {
            if ($error !== null) { // "failed" comes before "finished"
                $failed = $error;
                return;
            }
            unset(self::$printJobs[$k]);
            $f = WebKit::ffi();
            $f->webkit_settings_set_print_backgrounds($web, $printed);
            $f->g_object_unref($op);
            $pdf = is_file($path) ? (string)file_get_contents($path) : '';
            if (is_file($path)) { unlink($path); }
            if (is_file(substr($path, 0, -4))) { unlink(substr($path, 0, -4)); }
            if ($failed !== null || $pdf === '') {
                $done(null, $failed !== null ? "myphp: printing to PDF: $failed" : 'myphp: printing to PDF produced no file');
                return;
            }
            $done($pdf, null);
        };
        $f->g_signal_connect_data($op, 'finished', $f->cast('void*', self::$hooks['printed']->fn), null, null, 0);
        $f->g_signal_connect_data($op, 'failed', $f->cast('void*', self::$hooks['printFailed']->fn), null, null, 0);
        $f->webkit_print_operation_print($op);
    }

    public function allowsPictureInPicture(): ?bool
    {
        throw new Unsupported('myphp: picture in picture is not on Linux: WebKitGTK has none');
    }

    public function togglePictureInPicture(string $script): void
    {
        throw new Unsupported('myphp: picture in picture is not on Linux: WebKitGTK has none');
    }

    public function pictureInPictureActive(): bool
    {
        throw new Unsupported('myphp: picture in picture is not on Linux: WebKitGTK has none');
    }

    /**
     * Asks $listen about WebKit's context menu $menu, opened by $event,
     * and changes it as the page's listeners answer (mygo's linux
     * contextMenu): see ContextMenus.
     * @param \Closure(\MyPHP\Menu, ?array{0: float, 1: float}): \MyPHP\ContextMenuEvent $listen
     */
    public function showContextMenu($menu, $event, Closure $listen): void
    {
        $this->noMenu = ContextMenus::show($this, $menu, $event, $listen);
    }

    /** Nothing: WebKitGTK has no picture in picture whose page its back/forward cache would keep. */
    public function leavingPage(bool $pip): void {}

    /** Runs JavaScript in the page; $completion is macOS's (an ObjC block), which nothing passes here. */
    public function evaluate(string $js, $completion = null): void
    {
        if ($completion !== null) {
            throw new Unsupported('myphp: evaluate() takes no completion on Linux: use callAsync()');
        }
        WebKit::ffi()->webkit_web_view_evaluate_javascript($this->web, $js, strlen($js), null, null, null, null, null);
    }

    /** Hands a bridge message (reply or event) to the page. */
    public function deliver(array $message): void
    {
        $this->evaluate('window.myphp.__deliver(' . json_encode($message, JSON_THROW_ON_ERROR) . ')');
    }
}
