<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use FFI\CData;
use MyPHP\Platform\Unsupported;

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
    /** @var array<string, true> the schemes registered on the default context */
    private static array $schemes = [];
    /** @var array<string, CData> the holders of the C functions serving the signals, by signal */
    private static array $hooks = [];
    /** @var array<int, Closure(?CData, ?string): void> callAsync's callbacks, by key */
    private static array $pending = [];
    private static int $nextCall = 0;

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
    /** window.open(), or a target=_blank link: (URL, '', 0, 0, the WebKitNavigationAction, a user's gesture), which makes the window opened with it as its configuration (create()); what it returns goes unused. @var ?Closure(string, string, int, int, mixed, bool): mixed */
    public ?Closure $onOpen = null;
    /** The page called window.close(). @var ?Closure(): void */
    public ?Closure $onClosedByPage = null;
    /** Serves a request of a custom scheme (Protocol): (the request's [method, URL, headers, body], its task). @var ?Closure(array, \MyPHP\Native\SchemeTask): void */
    public ?Closure $onScheme = null;
    /**
     * The core sets these on every page; WebKitGTK's own handling stays
     * until downloads, sign-ins, certificates, permissions, JavaScript
     * panels and context menus come to Linux, so they are never called.
     */
    public ?Closure $onDownload = null, $onDownloadDone = null, $onSchemeDownload = null, $onAuth = null, $onCertificate = null,
        $onPermission = null, $onPanel = null, $onContextMenu = null;
    /** The view the wheel over the page goes to instead (a web view of native UI's), which Linux has no web views of yet. */
    public mixed $scrollTo = null;

    private function __construct(private Closure $onMessage) {}

    /**
     * A web view whose pages get $scripts at document start, the bridge
     * first (mygo's createWebView), with a content manager of its own, so
     * messages and scripts never leak between pages. With $opened, the
     * WebKitNavigationAction of a window.open() (onOpen), it loads that
     * action's URL, as WebKit loads it in macOS's view.
     * @param list<string|array{0: string, 1: bool}> $scripts
     */
    public static function create(array $scripts, Closure $onMessage, bool $devTools, string $userAgent, float $zoom, string $suffix, bool $swipe, bool $pinch, bool $autoplay, bool $throttle, ?CData $opened = null): self
    {
        $f = WebKit::ffi();
        self::hooks();
        $ctx = $f->webkit_web_context_get_default();
        foreach (\MyPHP\Protocol::schemes() as $scheme) { // before the view loads anything (mygo's registerScheme)
            if (isset(self::$schemes[$scheme])) { continue; }
            self::$schemes[$scheme] = true;
            $f->webkit_web_context_register_uri_scheme($ctx, $scheme, self::$hooks['scheme']->fn, null, null);
            $sec = $f->webkit_web_context_get_security_manager($ctx);
            $f->webkit_security_manager_register_uri_scheme_as_secure($sec, $scheme);
            $f->webkit_security_manager_register_uri_scheme_as_cors_enabled($sec, $scheme);
        }
        $self = new self($onMessage);
        $self->id = ++self::$nextId;
        $self->suffix = $suffix;
        $self->autoplay = $autoplay;
        $self->ucm = $f->webkit_user_content_manager_new();
        $self->web = $f->g_object_ref($f->webkit_web_view_new_with_user_content_manager($self->ucm)); // held past its destroy, until close() lets it go
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
            'close' => 'close', 'web-process-terminated' => 'gone', 'notify::is-playing-audio' => 'change'] as $signal => $hook) {
            self::on($self->web, $signal, $hook, $data);
        }
        foreach (self::CAPTURE_PROPERTIES as $p) {
            self::on($self->web, "notify::$p", 'change', $data);
        }
        self::$views[$self->id] = $self;
        self::$byWeb[WebKit::addr($self->web)] = $self->id;
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
        return self::$views[WebKit::addr($data)] ?? null;
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
            return $v === null || $type !== self::POLICY_NAVIGATION ? 0 : $v->decide($decision);
        });
        // WebKitGTK makes a related view share the opener's content manager, which would send the new window's messages to the opener:
        // the window opened gets the navigation action as its configuration, whose URL its own view loads, its page without an opener
        // (mygo's cbCreate). WebKit is told no view.
        self::$hooks['create'] = $h('HCreate', function ($web, $action, $data) {
            $v = self::view($data);
            if ($v?->onOpen === null) { return null; }
            $f = WebKit::ffi();
            $url = (string)$f->webkit_uri_request_get_uri($f->webkit_navigation_action_get_request($action));
            ($v->onOpen)($url, '', 0, 0, $action, (bool)$f->webkit_navigation_action_is_user_gesture($action));
            return null;
        });
        self::$hooks['close'] = $h('HPlain', function ($web, $data): void {
            self::view($data)?->onClosedByPage?->__invoke();
        });
        self::$hooks['gone'] = $h('HGone', function ($web, int $reason, $data): void {
            self::view($data)?->onGone?->__invoke([0 => 'crashed', 1 => 'exceeded memory limit', 2 => 'terminated by API'][$reason] ?? 'crashed');
        });
        self::$hooks['async'] = $h('HNotify', function ($source, $res, $data): void {
            $key = WebKit::addr($data);
            $done = self::$pending[$key] ?? null;
            unset(self::$pending[$key]);
            if ($done === null) { return; }
            $f = WebKit::ffi();
            $err = $f->new('void*[1]');
            $value = $f->webkit_web_view_call_async_javascript_function_finish($source, $res, $err);
            $done($err[0] === null ? $value : null, $err[0] === null ? null : WebKit::errorMessage($err[0]));
        });
        // Requests of the schemes PHP serves (mygo's serveScheme); one of a view gone gets a 503, as macOS's.
        self::$hooks['scheme'] = $h('HScheme', function ($req, $data): void {
            $f = WebKit::ffi();
            $v = self::$views[self::$byWeb[WebKit::addr($f->webkit_uri_scheme_request_get_web_view($req))] ?? 0] ?? null;
            $task = new SchemeTask($req);
            if ($v?->onScheme === null) {
                $task->respond(503, []);
                $task->finish();
                return;
            }
            ($v->onScheme)(SchemeTask::request($req), $task);
        });
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

    public function openDevTools(): void
    {
        throw new Unsupported('myphp: developer tools are not on Linux yet');
    }

    public function closeDevTools(): void
    {
        throw new Unsupported('myphp: developer tools are not on Linux yet');
    }

    public function isDevToolsOpened(): bool
    {
        throw new Unsupported('myphp: developer tools are not on Linux yet');
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

    public function setContentRules(array $lists): void
    {
        throw new Unsupported('myphp: content rules are not on Linux yet');
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

    public function sessionState(): string
    {
        throw new Unsupported('myphp: session state is not on Linux yet');
    }

    public function restoreSessionState(string $state): bool
    {
        throw new Unsupported('myphp: session state is not on Linux yet');
    }

    /** Lets the web view go: its handler and scripts, its page stopped; the view itself once GTK is done with it (mygo's cleanup). */
    public function close(): void
    {
        if ($this->ucm === null) {
            return;
        }
        $f = WebKit::ffi();
        unset(self::$views[$this->id], self::$byWeb[WebKit::addr($this->web)]);
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

    public function capture(Closure $done): void
    {
        throw new Unsupported('myphp: capturing a page is not on Linux yet');
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

    public function print($window): void
    {
        throw new Unsupported('myphp: printing is not on Linux yet');
    }

    public function printToPDF($window, float $width, float $height, bool $landscape, array $margins, bool $background, Closure $done): void
    {
        throw new Unsupported('myphp: printing to PDF is not on Linux yet');
    }

    public function allowsPictureInPicture(): ?bool
    {
        throw new Unsupported('myphp: picture in picture is not on Linux');
    }

    public function togglePictureInPicture(string $script): void
    {
        throw new Unsupported('myphp: picture in picture is not on Linux');
    }

    public function pictureInPictureActive(): bool
    {
        throw new Unsupported('myphp: picture in picture is not on Linux');
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
