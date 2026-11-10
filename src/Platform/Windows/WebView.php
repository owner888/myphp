<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use FFI;
use FFI\CData;
use MyPHP\Logger;
use MyPHP\Native\{Com, ContentRuleList, Mem};
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;

/**
 * A page of WebView2 (mygo's windows webview.go, webview2.go): the
 * runtime's environment made once, without WebView2Loader.dll, through the
 * client library of the Evergreen runtime the registry names (a fixed
 * version one by WEBVIEW2_BROWSER_EXECUTABLE_FOLDER), as the loader does;
 * a controller in its window, made as the window shows it, its calls
 * waiting till then. Its handlers are COM objects of PHP's, one vtable of
 * Invoke(sender or result, args) for them all. The page's bridge posts to
 * window.webkit.messageHandlers.myphp, as WebKit's: a script before it
 * hands its posts to chrome.webview. Content rules are matched in PHP
 * (ContentRuleList): WebView2 has no content blockers, so a page applying
 * them asks about every request.
 */
final class WebView implements Platform\Page
{
    private const IID_CONTROLLER2 = 'c979903e-d4ca-4228-92eb-47ee3fa96eab', IID_SETTINGS2 = 'ee9a0f68-f46c-4e32-ac23-ef8cac224d2a';
    private const IID_VIEW4 = '20d02d59-6df2-42dc-bd06-f98a694b1302', IID_ENV10 = 'ee0eb9df-6f12-46ce-b53f-3f47b9c928e0';
    private const IID_SETTINGS3 = 'fdb5ab74-af33-4854-84f0-0a631deb5eba', IID_SETTINGS5 = '183e7052-1d03-43a0-ab99-98e043b66b39', IID_SETTINGS6 = '11cb3acd-9bc8-43b8-83bf-f40753714f87';
    private const CHANNELS = ['{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}', '{2CD8A007-E189-409D-A2C8-9AF4EF3C72AA}', '{0D50BFEC-CD6A-4F9A-964C-C7416E3ACB10}', '{65C35B14-6C1D-4122-AC46-7148CC9D6497}']; // Stable, Beta, Dev, Canary
    /** What hands the bridge's posts to WebView2's channel, before any script of the page. */
    private const SHIM = 'window.webkit=window.webkit||{};window.webkit.messageHandlers=window.webkit.messageHandlers||{};window.webkit.messageHandlers.myphp={postMessage:function(s){window.chrome.webview.postMessage(s)}};';

    private static ?FFI $f = null;
    /** The environment, once made; false when it could not be; the pages waiting for it. @var list<Closure> */
    private static CData|false|null $env = null;
    private static bool $making = false;
    private static array $waiting = [];
    /** The handlers' shared vtable, and each handler's closure by its number. @var array<int, Closure> */
    private static ?CData $handlerVtbl = null;
    private static array $handlers = [];
    /** @var array<int, list<string>> the interfaces a handler made with $only answers for, by its number */
    private static array $only = [];
    private static int $nextHandler = 0;

    private ?CData $controller = null, $view = null, $settings = null;
    private ?Window $window = null;
    /** The window the controller is in: the page's window, or the child window an embedded web view is (EmbeddedWebView). */
    private ?CData $host = null;
    private bool $ready = false, $closed = false, $loading = false;
    /** @var list<Closure> the calls waiting for the controller */
    private array $queued = [];
    /** @var list<string> the ids of the user scripts added, which setUserScripts removes */
    private array $scriptIds = [];
    private float $zoomFactor = 1.0;
    /** @var list<ContentRuleList> the content rules applied; the script hiding what their css-display-none rules select; whether WebView2 asks about every request */
    private array $rules = [];
    private string $hiding = '';
    private bool $filtersAll = false;
    /** The URL the main frame is going to, which a request of its document is. */
    private string $navigating = '';
    /** @var array<int, self> the pages shown, whose cookie managers WebsiteData takes */
    private static array $shown = [];

    /** Asked whether the main frame may go to a URL: (url, userInitiated, a reload, one the app started) → allowed. @var ?Closure(string, bool, bool, bool): bool */
    public ?Closure $onNavigate = null;
    /** A navigation committed, at its URL. @var ?Closure(string): void */
    public ?Closure $onCommit = null;
    /** The page finished loading. @var ?Closure(): void */
    public ?Closure $onFinish = null;
    /** A load failed: (URL, code, description). @var ?Closure(string, int, string): void */
    public ?Closure $onFail = null;
    /** The page's process went away, and why. @var ?Closure(string): void */
    public ?Closure $onGone = null;
    /** The page's <title> changed. @var ?Closure(string): void */
    public ?Closure $onTitle = null;
    /** The page called window.close(). @var ?Closure(): void */
    public ?Closure $onClosedByPage = null;
    /** The core's hooks the next stage of WebView2 tells (Linux's): kept so that the core may set them. */
    public ?Closure $onChange = null, $onOpen = null, $onScheme = null, $onDownload = null, $onDownloadDone = null, $onSchemeDownload = null, $onAuth = null, $onCertificate = null, $onPermission = null, $onPanel = null, $onContextMenu = null;

    /** @param list<string|array{0: string, 1: bool}> $scripts */
    public function __construct(private array $scripts, private readonly Closure $onMessage, private readonly bool $devTools, private string $userAgent, float $zoom, private readonly bool $pinch, private readonly bool $swipe,
        /** The session's profile, null for the default one. */
        public readonly ?DataStore $store = null)
    {
        $this->zoomFactor = $zoom > 0 ? $zoom : 1.0;
    }

    private static function f(): FFI
    {
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct { int32_t left, top, right, bottom; } RECT;
            typedef struct { uint8_t A, R, G, B; } COREWEBVIEW2_COLOR;
            typedef struct { int64_t value; } Token;
            typedef struct HandlerVtbl HandlerVtbl;
            typedef struct { HandlerVtbl *v; int64_t id; } Handler;
            struct HandlerVtbl {
                int32_t (*QueryInterface)(void *self, const void *iid, void **out);
                uint32_t (*AddRef)(void *self);
                uint32_t (*Release)(void *self);
                int32_t (*Invoke)(void *self, uintptr_t a, void *b);
            };
            typedef struct { void *r0[3]; int32_t (*CreateCoreWebView2Controller)(void *self, void *hwnd, void *handler); int32_t (*CreateWebResourceResponse)(void *self, void *content, int32_t status, const void *reason, const void *headers, void **response); } EnvVtbl;
            typedef struct { EnvVtbl *v; } Env;
            typedef struct { void *r0[20]; int32_t (*CreateCoreWebView2ControllerOptions)(void *self, void **options); int32_t (*CreateCoreWebView2ControllerWithOptions)(void *self, void *hwnd, void *options, void *handler); } Env10Vtbl;
            typedef struct { Env10Vtbl *v; } Env10;
            typedef struct { void *r0[4]; int32_t (*put_ProfileName)(void *self, const void *name); void *r5; int32_t (*put_IsInPrivateModeEnabled)(void *self, int32_t on); } ControllerOptionsVtbl;
            typedef struct { ControllerOptionsVtbl *v; } ControllerOptions;
            typedef struct {
                void *r0[4];
                int32_t (*put_IsVisible)(void *self, int32_t visible);
                void *r5;
                int32_t (*put_Bounds)(void *self, RECT bounds);
                int32_t (*get_ZoomFactor)(void *self, double *zoom);
                int32_t (*put_ZoomFactor)(void *self, double zoom);
                void *r9[3];
                int32_t (*MoveFocus)(void *self, int32_t reason);
                void *r13[10];
                int32_t (*NotifyParentWindowPositionChanged)(void *self);
                int32_t (*Close)(void *self);
                int32_t (*get_CoreWebView2)(void *self, void **view);
                void *r26;
                int32_t (*put_DefaultBackgroundColor)(void *self, COREWEBVIEW2_COLOR color);
            } ControllerVtbl;
            typedef struct { ControllerVtbl *v; } Controller;
            typedef struct {
                void *r0[3];
                int32_t (*get_Settings)(void *self, void **settings);
                int32_t (*get_Source)(void *self, uint16_t **uri);
                int32_t (*Navigate)(void *self, const void *uri);
                int32_t (*NavigateToString)(void *self, const void *html);
                int32_t (*add_NavigationStarting)(void *self, void *handler, Token *token);
                void *r8;
                int32_t (*add_ContentLoading)(void *self, void *handler, Token *token);
                void *r10[5];
                int32_t (*add_NavigationCompleted)(void *self, void *handler, Token *token);
                void *r16[7];
                int32_t (*add_PermissionRequested)(void *self, void *handler, Token *token);
                void *r24;
                int32_t (*add_ProcessFailed)(void *self, void *handler, Token *token);
                void *r26;
                int32_t (*AddScriptToExecuteOnDocumentCreated)(void *self, const void *script, void *handler);
                int32_t (*RemoveScriptToExecuteOnDocumentCreated)(void *self, const void *id);
                int32_t (*ExecuteScript)(void *self, const void *script, void *handler);
                void *r30;
                int32_t (*Reload)(void *self);
                void *r32[2];
                int32_t (*add_WebMessageReceived)(void *self, void *handler, Token *token);
                void *r35;
                int32_t (*CallDevToolsProtocolMethod)(void *self, const void *method, const void *params, void *handler);
                int32_t (*get_BrowserProcessId)(void *self, uint32_t *pid);
                int32_t (*get_CanGoBack)(void *self, int32_t *can);
                int32_t (*get_CanGoForward)(void *self, int32_t *can);
                int32_t (*GoBack)(void *self);
                int32_t (*GoForward)(void *self);
                void *r42;
                int32_t (*Stop)(void *self);
                int32_t (*add_NewWindowRequested)(void *self, void *handler, Token *token);
                void *r45;
                int32_t (*add_DocumentTitleChanged)(void *self, void *handler, Token *token);
                void *r47;
                int32_t (*get_DocumentTitle)(void *self, uint16_t **title);
                void *r49[2];
                int32_t (*OpenDevToolsWindow)(void *self);
                void *r52[3];
                int32_t (*add_WebResourceRequested)(void *self, void *handler, Token *token);
                void *r56;
                int32_t (*AddWebResourceRequestedFilter)(void *self, const void *uri, int32_t context);
                void *r58;
                int32_t (*add_WindowCloseRequested)(void *self, void *handler, Token *token);
            } ViewVtbl;
            typedef struct { void *r0[75]; int32_t (*add_DownloadStarting)(void *self, void *handler, Token *token); } View4Vtbl;
            typedef struct { View4Vtbl *v; } View4;
            typedef struct { void *r0[3]; int32_t (*get_Request)(void *self, void **request); void *r4; int32_t (*put_Response)(void *self, void *response); int32_t (*GetDeferral)(void *self, void **deferral); int32_t (*get_ResourceContext)(void *self, int32_t *context); } ResourceRequestedVtbl;
            typedef struct { ResourceRequestedVtbl *v; } ResourceRequested;
            typedef struct { void *r0[3]; int32_t (*get_Uri)(void *self, uint16_t **uri); void *r4; int32_t (*get_Method)(void *self, uint16_t **method); void *r6; int32_t (*get_Content)(void *self, void **stream); void *r8; int32_t (*get_Headers)(void *self, void **headers); } RequestVtbl;
            typedef struct { RequestVtbl *v; } Request;
            typedef struct { void *r0[7]; int32_t (*RemoveHeader)(void *self, const void *name); int32_t (*GetIterator)(void *self, void **iterator); } HeadersVtbl;
            typedef struct { HeadersVtbl *v; } Headers;
            typedef struct { void *r0[3]; int32_t (*GetCurrentHeader)(void *self, uint16_t **name, uint16_t **value); int32_t (*get_HasCurrentHeader)(void *self, int32_t *has); int32_t (*MoveNext)(void *self, int32_t *more); } HeaderIteratorVtbl;
            typedef struct { HeaderIteratorVtbl *v; } HeaderIterator;
            typedef struct { void *r0[3]; int32_t (*Read)(void *self, void *buf, uint32_t n, uint32_t *read); } StreamVtbl;
            typedef struct { StreamVtbl *v; } Stream;
            typedef struct { void *r0[3]; int32_t (*Complete)(void *self); } DeferralVtbl;
            typedef struct { DeferralVtbl *v; } Deferral;
            typedef struct { void *r0[3]; int32_t (*get_Uri)(void *self, uint16_t **uri); void *r4; void *r5; int32_t (*put_Handled)(void *self, int32_t handled); void *r7; int32_t (*get_IsUserInitiated)(void *self, int32_t *on); } NewWindowVtbl;
            typedef struct { NewWindowVtbl *v; } NewWindow;
            typedef struct { void *r0[3]; int32_t (*get_Uri)(void *self, uint16_t **uri); int32_t (*get_PermissionKind)(void *self, int32_t *kind); void *r5[2]; int32_t (*put_State)(void *self, int32_t state); } PermissionVtbl;
            typedef struct { PermissionVtbl *v; } Permission;
            typedef struct { void *r0[3]; int32_t (*get_DownloadOperation)(void *self, void **op); void *r4; int32_t (*put_Cancel)(void *self, int32_t cancel); int32_t (*get_ResultFilePath)(void *self, uint16_t **path); int32_t (*put_ResultFilePath)(void *self, const void *path); void *r8; int32_t (*put_Handled)(void *self, int32_t handled); } DownloadStartingVtbl;
            typedef struct { DownloadStartingVtbl *v; } DownloadStarting;
            typedef struct { void *r0[7]; int32_t (*add_StateChanged)(void *self, void *handler, Token *token); void *r8; int32_t (*get_Uri)(void *self, uint16_t **uri); void *r10[6]; int32_t (*get_State)(void *self, int32_t *state); int32_t (*get_InterruptReason)(void *self, int32_t *reason); } DownloadVtbl;
            typedef struct { DownloadVtbl *v; } Download;
            typedef struct { ViewVtbl *v; } View;
            typedef struct {
                void *r0[4];
                int32_t (*put_IsScriptEnabled)(void *self, int32_t on);
                void *r5;
                int32_t (*put_IsWebMessageEnabled)(void *self, int32_t on);
                void *r7;
                int32_t (*put_AreDefaultScriptDialogsEnabled)(void *self, int32_t on);
                void *r9;
                int32_t (*put_IsStatusBarEnabled)(void *self, int32_t on);
                void *r11;
                int32_t (*put_AreDevToolsEnabled)(void *self, int32_t on);
                void *r13;
                int32_t (*put_AreDefaultContextMenusEnabled)(void *self, int32_t on);
                void *r15[3];
                int32_t (*put_IsZoomControlEnabled)(void *self, int32_t on);
                void *r19[2];
                int32_t (*get_UserAgent)(void *self, uint16_t **ua);
                int32_t (*put_UserAgent)(void *self, const void *ua);
                int32_t (*get_AreBrowserAcceleratorKeysEnabled)(void *self, int32_t *on);
                int32_t (*put_AreBrowserAcceleratorKeysEnabled)(void *self, int32_t on);
                void *r25[5];
                int32_t (*put_IsPinchZoomEnabled)(void *self, int32_t on);
                void *r31;
                int32_t (*put_IsSwipeNavigationEnabled)(void *self, int32_t on);
            } SettingsVtbl;
            typedef struct { SettingsVtbl *v; } Settings;
            typedef struct { void *r0[3]; int32_t (*get_Uri)(void *self, uint16_t **uri); int32_t (*get_IsUserInitiated)(void *self, int32_t *on); int32_t (*get_IsRedirected)(void *self, int32_t *on); void *r6[2]; int32_t (*put_Cancel)(void *self, int32_t cancel); } NavStartingVtbl;
            typedef struct { NavStartingVtbl *v; } NavStarting;
            typedef struct { void *r0[3]; int32_t (*get_IsSuccess)(void *self, int32_t *ok); int32_t (*get_WebErrorStatus)(void *self, int32_t *status); } NavCompletedVtbl;
            typedef struct { NavCompletedVtbl *v; } NavCompleted;
            typedef struct { void *r0[5]; int32_t (*TryGetWebMessageAsString)(void *self, uint16_t **message); } MessageVtbl;
            typedef struct { MessageVtbl *v; } Message;
            typedef struct { void *r0[3]; int32_t (*get_ProcessFailedKind)(void *self, int32_t *kind); } ProcessFailedVtbl;
            typedef struct { ProcessFailedVtbl *v; } ProcessFailed;
            typedef struct { int32_t (*fn)(int32_t check, int32_t runtimeType, const void *userData, void *options, void *handler); } CreateEnv;
            void CoTaskMemFree(void *p);
            C, 'ole32.dll');
    }

    // The handlers: COM objects of PHP's.

    /**
     * @internal WebsiteData, NowPlaying: a handler whose Invoke calls $fn(a,
     * b): a completed one's (HRESULT, result), an event's (sender, args); it
     * lives as long as the process (WebView2 may call it till it goes).
     * With $only (IIDs), it is IUnknown and those interfaces alone, as a
     * Windows Runtime event's source asks of a delegate (mygo's
     * newSMTCHandler): one claiming IWeakReferenceSource would be called
     * beyond its vtable.
     * @param ?list<string> $only
     */
    public static function handler(Closure $fn, ?array $only = null): CData
    {
        $f = self::f();
        if (self::$handlerVtbl === null) {
            $vt = self::$handlerVtbl = $f->new('HandlerVtbl', false);
            // IMarshal, IAgileObject and INoMarshal refused (mygo's handlerVtbl): a handler claiming them would be called
            // beyond its vtable; IUnknown and the handler's own interface are what remains.
            $refused = array_map(self::iidBytes(...), ['00000003-0000-0000-c000-000000000046', '94ea2b94-e9cc-49e0-c0ff-ee64ca8f5b90', 'ecc8691b-c1db-4dc0-855e-65f6c551af49']);
            $unknown = self::iidBytes('00000000-0000-0000-c000-000000000046');
            $vt->QueryInterface = function ($self, $iid, $out) use ($refused, $unknown, $f): int {
                $asked = Mem::read(Mem::addr($iid), 16);
                $only = self::$only[$f->cast('Handler *', $self)->id] ?? null;
                if (in_array($asked, $refused, true) || $only !== null && $asked !== $unknown && !in_array($asked, $only, true)) {
                    $out[0] = null;
                    return Com::E_NOINTERFACE;
                }
                $out[0] = $self;
                return 0;
            };
            $vt->AddRef = fn($self) => 1;
            $vt->Release = fn($self) => 1;
            $vt->Invoke = function ($self, int $a, $b) use ($f): int {
                $h = $f->cast('Handler *', $self);
                $fn = self::$handlers[$h->id] ?? null;
                try {
                    $fn?->__invoke($a, $b);
                } catch (\Throwable $e) {
                    Logger::error('WebView2: a handler threw: ' . $e->getMessage(), 'windows');
                }
                return 0;
            };
        }
        $h = $f->new('Handler', false);
        $h->v = FFI::addr(self::$handlerVtbl);
        $h->id = ++self::$nextHandler;
        self::$handlers[$h->id] = $fn;
        if ($only !== null) {
            self::$only[$h->id] = array_map(self::iidBytes(...), $only);
        }
        return FFI::addr($h);
    }

    /** The 16 bytes of the GUID of text $g, as they are in memory. */
    private static function iidBytes(string $g): string
    {
        return pack('Vvv', hexdec(substr($g, 0, 8)), hexdec(substr($g, 9, 4)), hexdec(substr($g, 14, 4))) . hex2bin(substr($g, 19, 4) . substr($g, 24));
    }

    /** @internal An HRESULT passed in a register of 64 bits, its upper half undefined, as a signed 32-bit one. */
    public static function hr(int $a): int
    {
        $a &= 0xFFFFFFFF;
        return $a >= 0x80000000 ? $a - 0x100000000 : $a;
    }

    /** @internal A string a WebView2 method gave, freed. */
    public static function take(?CData $p): string
    {
        if ($p === null || FFI::isNull($p)) {
            return '';
        }
        $s = Win32::str($p);
        self::f()->CoTaskMemFree($p);
        return $s;
    }

    // The environment.

    /**
     * The client library of the WebView2 runtime, as the loader finds it
     * (mygo's webview2.Find): a fixed version one by
     * WEBVIEW2_BROWSER_EXECUTABLE_FOLDER, else the newest Evergreen one of
     * the first channel the registry names (its 32-bit view, per machine
     * then per user). @return ?array{0: string, 1: bool} the DLL and whether it is a fixed version's
     */
    public static function runtime(): ?array
    {
        $client = fn(string $dir): ?string => is_file("$dir\\msedgewebview2.exe") && is_file($dll = "$dir\\EBWebView\\x64\\EmbeddedBrowserWebView.dll") ? $dll : null;
        if (($fixed = (string)getenv('WEBVIEW2_BROWSER_EXECUTABLE_FOLDER')) !== '') {
            return ($dll = $client($fixed)) === null ? null : [$dll, true];
        }
        $a = Win32::advapi32();
        foreach (self::CHANNELS as $channel) {
            $best = null; $version = '';
            foreach ([0x80000002, 0x80000001] as $root) { // HKEY_LOCAL_MACHINE, HKEY_CURRENT_USER
                $size = $a->new('uint32_t');
                $size->cdata = 1024;
                $buf = $a->new('uint16_t[512]');
                if ($a->RegGetValueW($root, Win32::wide("SOFTWARE\\Microsoft\\EdgeUpdate\\ClientState\\$channel"), Win32::wide('EBWebView'), 0x2 | 0x20000, null, $buf, FFI::addr($size)) !== 0) { // RRF_RT_REG_SZ, RRF_SUBKEY_WOW6432KEY
                    continue;
                }
                $dir = Win32::str($a->cast('uint16_t *', FFI::addr($buf[0])));
                if ($client($dir) !== null && ($best === null || version_compare(basename($dir), $version, '>'))) {
                    [$best, $version] = [$dir, basename($dir)];
                }
            }
            if ($best !== null) {
                return [$client($best), false];
            }
        }
        return null;
    }

    /**
     * Runs $then once the environment is made (mygo's createEnvironment): by
     * the runtime's CreateWebViewEnvironmentWithOptionsInternal, as the
     * loader calls it, its user data in the local AppData of the app.
     */
    private static function environment(Closure $then): void
    {
        if (self::$env !== null) {
            $then();
            return;
        }
        self::$waiting[] = $then;
        if (self::$making) {
            return;
        }
        self::$making = true;
        $done = function (): void {
            self::$making = false;
            $waiting = self::$waiting;
            self::$waiting = [];
            foreach ($waiting as $fn) { $fn(); }
        };
        $rt = self::runtime();
        if ($rt === null) {
            self::$env = false;
            Logger::warn('the Microsoft Edge WebView2 Runtime is not installed; get it from https://go.microsoft.com/fwlink/p/?LinkId=2124703', 'windows');
            $done();
            return;
        }
        [$dll, $fixed] = $rt;
        $k = FFI::cdef('void *LoadLibraryW(const void *name); void *GetProcAddress(void *module, const char *name);', 'kernel32.dll');
        $proc = ($lib = $k->LoadLibraryW(Win32::wide($dll))) === null ? null : $k->GetProcAddress($lib, 'CreateWebViewEnvironmentWithOptionsInternal');
        if ($proc === null) {
            self::$env = false;
            Logger::warn("an unsupported WebView2 Runtime at $dll", 'windows');
            $done();
            return;
        }
        $f = self::f();
        static $create = null, $createFns = null; // a function pointer read through a pointer to its struct, as Com's vtables (docs/architecture.md)
        $createFns = $f->new('CreateEnv', false);
        FFI::memcpy($createFns, pack('P', Mem::addr($proc)), 8);
        $create = Com::typed($f, FFI::addr($createFns), 'CreateEnv *');
        $base = getenv('LOCALAPPDATA') ?: sys_get_temp_dir();
        $userData = (string)(getenv('WEBVIEW2_USER_DATA_FOLDER') ?: $base . '\\' . preg_replace('/[<>:"\/\\\\|?*]/', '_', \MyPHP\App::name()) . '\\WebView2');
        @mkdir($userData, 0o755, true);
        $handler = self::handler(function (int $a, $env) use ($done): void {
            if (self::hr($a) < 0 || $env === null) {
                self::$env = false;
                Logger::warn(sprintf('creating the WebView2 environment failed (0x%08X)', $a & 0xFFFFFFFF), 'windows');
            } else {
                $e = Com::typed(self::f(), $env, 'Env *');
                Com::addRef($e);
                self::$env = $e;
                Logger::debug('the WebView2 environment made', 'windows');
            }
            $done();
        });
        Logger::debug("making the WebView2 environment of $dll", 'windows');
        $hr = ($create->fn)(1, $fixed ? 1 : 0, Win32::wide($userData), null, $handler);
        Logger::debug(sprintf('CreateWebViewEnvironmentWithOptionsInternal: 0x%08X, its data in %s', $hr & 0xFFFFFFFF, $userData), 'windows');
        if ($hr < 0) {
            self::$env = false;
            Logger::warn(sprintf('creating the WebView2 environment failed (0x%08X)', $hr & 0xFFFFFFFF), 'windows');
            $done();
        }
    }

    /** @internal WebsiteData: the ICoreWebView2 of a page shown of the profile $store (null: the default one), the first one, null for none */
    public static function shownView(?DataStore $store): ?CData
    {
        foreach (self::$shown as $page) {
            if ($page->store === $store) {
                return $page->view;
            }
        }
        return null;
    }

    /**
     * Asks the environment for a controller in $hwnd of the profile $store,
     * null for the default one, through $handler (mygo's createController):
     * a profile's name, InPrivate for an ephemeral one, in the options of
     * ICoreWebView2Environment10 (a runtime of 1.0.1185 or newer). An
     * HRESULT.
     */
    private static function createController(?DataStore $store, CData $hwnd, CData $handler): int
    {
        $env = self::$env;
        if ($store === null) {
            return ($env->v->CreateCoreWebView2Controller)($env, $hwnd, $handler);
        }
        $f = self::f();
        $e10 = Com::query($env, self::IID_ENV10);
        if ($e10 === null) {
            Logger::warn('sessions need a newer WebView2 Runtime (profiles, 1.0.1185 or newer)', 'windows');
            return Com::E_NOINTERFACE;
        }
        $e = Com::typed($f, $e10, 'Env10 *');
        $out = $f->new('void *');
        try {
            if (($hr = ($e->v->CreateCoreWebView2ControllerOptions)($e, FFI::addr($out))) < 0 || FFI::isNull($out)) {
                return $hr < 0 ? $hr : Com::E_NOINTERFACE;
            }
            $o = Com::typed($f, $out, 'ControllerOptions *');
            if (($hr = ($o->v->put_ProfileName)($o, Win32::wide($store->profile))) >= 0 && $store->private) {
                $hr = ($o->v->put_IsInPrivateModeEnabled)($o, 1);
            }
            return $hr < 0 ? $hr : ($e->v->CreateCoreWebView2ControllerWithOptions)($e, $hwnd, $o, $handler);
        } finally {
            if (!FFI::isNull($out)) { Com::release($out); }
            Com::release($e10);
        }
    }

    /**
     * @internal WebsiteData: makes a controller in $hwnd of the profile
     * $store, not visible, for what only a web view has (a cookie manager);
     * then $done(controller, view), or $done(null, null, why).
     */
    public static function hiddenView(CData $hwnd, ?DataStore $store, Closure $done): void
    {
        self::environment(function () use ($hwnd, $store, $done): void {
            if (self::$env === false) {
                $done(null, null, 'no WebView2 Runtime');
                return;
            }
            $hr = self::createController($store, $hwnd, self::handler(function (int $a, $controller) use ($done): void {
                if (self::hr($a) < 0 || $controller === null) {
                    $done(null, null, sprintf('creating a WebView2 controller failed (0x%08X)', $a & 0xFFFFFFFF));
                    return;
                }
                $f = self::f();
                $c = Com::typed($f, $controller, 'Controller *');
                Com::addRef($c);
                ($c->v->put_IsVisible)($c, 0);
                $out = $f->new('void *');
                ($c->v->get_CoreWebView2)($c, FFI::addr($out));
                $done($c, Com::typed($f, $out, 'View *'));
            }));
            if ($hr < 0) {
                $done(null, null, sprintf('creating a WebView2 controller failed (0x%08X)', $hr & 0xFFFFFFFF));
            }
        });
    }

    /** @internal WebsiteData: closes a controller hiddenView made, and releases it and its view */
    public static function closeHidden(CData $controller, CData $view): void
    {
        ($controller->v->Close)($controller);
        Com::release($view);
        Com::release($controller);
    }

    // The controller, in its window.

    /** @internal shows the page in $w, made as soon as the environment is (mygo's createWebView); the calls made meanwhile wait */
    public function attach(Window $w, ?CData $host = null): void
    {
        $this->window = $w;
        $this->host = $host ?? $w->hwnd;
        self::environment(function (): void {
            if ($this->closed || $this->window === null || $this->window->isDead()) {
                return;
            }
            if (self::$env === false) {
                Logger::warn('the page shows nothing: no WebView2', 'windows');
                return;
            }
            $hr = self::createController($this->store, $this->host, self::handler(function (int $a, $controller): void {
                if ($this->closed || $this->window === null || $this->window->isDead()) {
                    return;
                }
                if (self::hr($a) < 0 || $controller === null) {
                    Logger::warn(sprintf('creating the WebView2 controller failed (0x%08X)', $a & 0xFFFFFFFF), 'windows');
                    return;
                }
                $c = Com::typed(self::f(), $controller, 'Controller *');
                Com::addRef($c);
                $this->setUp($c);
            }));
            if ($hr < 0) {
                Logger::warn(sprintf('creating the WebView2 controller failed (0x%08X)', $hr & 0xFFFFFFFF), 'windows');
            }
        });
    }

    /** The controller made: its view's settings, scripts and events, its bounds, then the calls that waited (mygo's setUp). */
    private function setUp(CData $c): void
    {
        Logger::debug('a WebView2 controller made', 'windows');
        $f = self::f();
        $this->controller = $c;
        $out = $f->new('void *');
        ($c->v->get_CoreWebView2)($c, FFI::addr($out));
        $this->view = $v = Com::typed($f, $out, 'View *');
        $out = $f->new('void *');
        ($v->v->get_Settings)($v, FFI::addr($out));
        $this->settings = $s = Com::typed($f, $out, 'Settings *');
        ($s->v->put_IsScriptEnabled)($s, 1);
        ($s->v->put_IsWebMessageEnabled)($s, 1);
        ($s->v->put_AreDefaultScriptDialogsEnabled)($s, 1);
        ($s->v->put_IsStatusBarEnabled)($s, 0);
        ($s->v->put_AreDevToolsEnabled)($s, $this->devTools ? 1 : 0);
        ($s->v->put_AreDefaultContextMenusEnabled)($s, 1);
        ($s->v->put_IsZoomControlEnabled)($s, 1);
        // The browser's own keys (F5 reloads, Ctrl+P prints, Ctrl+F finds) come with the inspector, as WKWebView and WebKitGTK have none; a touchscreen's pinch and swipe as the page asks (mygo's).
        if (($s3 = Com::query($s, self::IID_SETTINGS3)) !== null) {
            $x = Com::typed($f, $s3, 'Settings *');
            ($x->v->put_AreBrowserAcceleratorKeysEnabled)($x, $this->devTools ? 1 : 0);
            Com::release($s3);
        }
        if (($s5 = Com::query($s, self::IID_SETTINGS5)) !== null) {
            $x = Com::typed($f, $s5, 'Settings *');
            ($x->v->put_IsPinchZoomEnabled)($x, $this->pinch ? 1 : 0);
            Com::release($s5);
        }
        if (($s6 = Com::query($s, self::IID_SETTINGS6)) !== null) {
            $x = Com::typed($f, $s6, 'Settings *');
            ($x->v->put_IsSwipeNavigationEnabled)($x, $this->swipe ? 1 : 0);
            Com::release($s6);
        }
        if ($this->userAgent !== '') {
            $this->applyUserAgent();
        }
        $this->addScripts();
        $tok = $f->new('Token');
        ($v->v->add_NavigationStarting)($v, self::handler(function (int $sender, $args) use ($f): void {
            $a = Com::typed($f, $args, 'NavStarting *');
            $p = $f->new('uint16_t *');
            ($a->v->get_Uri)($a, FFI::addr($p));
            $uri = self::appURL(self::take($p));
            $user = $f->new('int32_t');
            ($a->v->get_IsUserInitiated)($a, FFI::addr($user));
            $this->loading = true;
            $this->navigating = $uri;
            if ($this->onNavigate !== null && !($this->onNavigate)($uri, $user->cdata !== 0, false, false)) {
                ($a->v->put_Cancel)($a, 1);
                $this->loading = false;
            }
        }), FFI::addr($tok));
        ($v->v->add_ContentLoading)($v, self::handler(function (int $sender, $args): void {
            ($this->onCommit)?->__invoke($this->url());
        }), FFI::addr($tok));
        ($v->v->add_NavigationCompleted)($v, self::handler(function (int $sender, $args) use ($f): void {
            $this->loading = false;
            $a = Com::typed($f, $args, 'NavCompleted *');
            $ok = $f->new('int32_t'); $status = $f->new('int32_t');
            ($a->v->get_IsSuccess)($a, FFI::addr($ok));
            if ($ok->cdata !== 0) {
                ($this->onFinish)?->__invoke();
                return;
            }
            ($a->v->get_WebErrorStatus)($a, FFI::addr($status));
            ($this->onFail)?->__invoke($this->url(), $status->cdata, "WebView2's web error status {$status->cdata}");
        }), FFI::addr($tok));
        ($v->v->add_DocumentTitleChanged)($v, self::handler(function (int $sender, $args) use ($f): void {
            $p = $f->new('uint16_t *');
            ($this->view->v->get_DocumentTitle)($this->view, FFI::addr($p));
            ($this->onTitle)?->__invoke(self::take($p));
        }), FFI::addr($tok));
        ($v->v->add_WebMessageReceived)($v, self::handler(function (int $sender, $args) use ($f): void {
            $a = Com::typed($f, $args, 'Message *');
            $p = $f->new('uint16_t *');
            if (($a->v->TryGetWebMessageAsString)($a, FFI::addr($p)) >= 0) { // not a string otherwise
                ($this->onMessage)(self::take($p));
            }
        }), FFI::addr($tok));
        ($v->v->add_WindowCloseRequested)($v, self::handler(fn(int $sender, $args) => ($this->onClosedByPage)?->__invoke()), FFI::addr($tok));
        ($v->v->add_ProcessFailed)($v, self::handler(function (int $sender, $args) use ($f): void {
            $a = Com::typed($f, $args, 'ProcessFailed *');
            $kind = $f->new('int32_t');
            ($a->v->get_ProcessFailedKind)($a, FFI::addr($kind));
            $why = match ($kind->cdata) { 0 => 'browser-exited', 1, 3 => 'crashed', 2 => 'unresponsive', default => null };
            if ($why !== null) { ($this->onGone)?->__invoke($why); }
        }), FFI::addr($tok));
        $this->addStageTwoHandlers($v);
        $this->filterAllRequests();
        ($c->v->put_IsVisible)($c, 1);
        $this->fit();
        if ($this->zoomFactor != 1.0) {
            ($c->v->put_ZoomFactor)($c, $this->zoomFactor);
        }
        $this->ready = true;
        self::$shown[spl_object_id($this)] = $this;
        $queued = $this->queued;
        $this->queued = [];
        foreach ($queued as $fn) { $fn(); }
    }

    /** Runs $fn now when the view is made, or once it is. */
    private function run(Closure $fn): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->ready) {
            $fn();
        } else {
            $this->queued[] = $fn;
        }
    }

    /** @internal the view fit to its window's client area, as it is resized */
    public function fit(): void
    {
        if ($this->controller === null || $this->window === null || $this->window->isDead()) {
            return;
        }
        $u = Win32::user32();
        $r = $u->new('RECT');
        $u->GetClientRect($this->host, FFI::addr($r));
        $b = self::f()->new('RECT');
        [$b->left, $b->top, $b->right, $b->bottom] = [$r->left, $r->top, $r->right, $r->bottom];
        ($this->controller->v->put_Bounds)($this->controller, $b);
    }

    /** @internal the window moved: WebView2's popups (its menus, a select's list) follow */
    public function moved(): void
    {
        if ($this->controller !== null) { ($this->controller->v->NotifyParentWindowPositionChanged)($this->controller); }
    }

    /** @internal the window took the keyboard: the page has it; $reason a Tab's, next (1) or previous (2), which puts it on the page's first or last element */
    public function focus(int $reason = 0): void
    {
        $this->run(fn() => ($this->controller->v->MoveFocus)($this->controller, $reason)); // COREWEBVIEW2_MOVE_FOCUS_REASON_PROGRAMMATIC, NEXT, PREVIOUS
    }

    /** @internal the color painted until the page paints (ICoreWebView2Controller2's DefaultBackgroundColor) @param array{0: int, 1: int, 2: int, 3: int} $rgba */
    public function background(array $rgba): void
    {
        $this->run(function () use ($rgba): void {
            if (($c2 = Com::query($this->controller, self::IID_CONTROLLER2)) === null) {
                return;
            }
            $f = self::f();
            $x = Com::typed($f, $c2, 'Controller *');
            $c = $f->new('COREWEBVIEW2_COLOR');
            [$c->R, $c->G, $c->B, $c->A] = [$rgba[0], $rgba[1], $rgba[2], $rgba[3] > 0 ? 255 : 0]; // WebView2 takes opaque or transparent alone
            ($x->v->put_DefaultBackgroundColor)($x, $c);
            Com::release($c2);
        });
    }

    /** The bridge's shim, then the page's scripts, before any of its own (AddScriptToExecuteOnDocumentCreated), their ids kept to remove them. */
    private function addScripts(): void
    {
        $v = $this->view;
        foreach ($this->scriptIds as $id) {
            ($v->v->RemoveScriptToExecuteOnDocumentCreated)($v, Win32::wide($id));
        }
        $this->scriptIds = [];
        foreach ([self::SHIM, ...$this->scripts, ...($this->hiding === '' ? [] : [$this->hiding])] as $s) {
            $src = is_array($s) ? $s[0] : $s;
            ($v->v->AddScriptToExecuteOnDocumentCreated)($v, Win32::wide($src), self::handler(function (int $a, $id): void {
                if (self::hr($a) >= 0 && $id !== null) {
                    $this->scriptIds[] = Win32::str(self::f()->cast('uint16_t *', $id));
                }
            }));
        }
    }

    private function applyUserAgent(): void
    {
        if (($s2 = Com::query($this->settings, self::IID_SETTINGS2)) !== null) {
            $x = Com::typed(self::f(), $s2, 'Settings *');
            ($x->v->put_UserAgent)($x, Win32::wide($this->userAgent));
            Com::release($s2);
        }
    }

    // Stage two: the app's schemes, new windows, permissions, downloads.

    /**
     * The app's schemes (Protocol) are served from http://<scheme>.localhost/,
     * their other hosts from http://<host>.<scheme>.localhost/, which WebView2
     * lets the app answer (WebResourceRequested): Chromium takes *.localhost
     * for a secure origin, and http blocks no mixed content, so pages reach
     * ws:// and http:// as on macOS and Linux; the core sees <scheme>://<host>/
     * URLs (mygo's schemehost). The rest of the URLs are as they are.
     */
    public static function webURL(string $url): string
    {
        $schemes = \MyPHP\Protocol::schemes();
        if (preg_match('~^([a-z][a-z0-9+.-]*)://([^/?#]*)(.*)$~i', $url, $m) !== 1 || !in_array(strtolower($m[1]), $schemes, true)) {
            return $url;
        }
        [$scheme, $host] = [strtolower($m[1]), strtolower($m[2])];
        if ($host !== 'localhost' && !self::validHost($host)) {
            return $url; // a port, a user, no host, or one that is no DNS name
        }
        $web = ($host === 'localhost' ? '' : "$host.") . "$scheme.localhost";
        return self::appHost($web) === [$scheme, $host] ? "http://$web" . $m[3] : $url; // one that would not come back stays
    }

    /** A URL WebView2 loaded, back to its scheme's, as webURL made it (mygo's AppURL). */
    public static function appURL(string $url): string
    {
        if (preg_match('~^http://([^/?#:@]+)(.*)$~i', $url, $m) !== 1 || ($sh = self::appHost($m[1])) === null) {
            return $url;
        }
        return "{$sh[0]}://{$sh[1]}" . $m[2];
    }

    /** @return ?array{0: string, 1: string} the scheme and host an http host serves (mygo's AppHost): <scheme>.localhost is <scheme>://localhost, <host>.<scheme>.localhost <scheme>://<host>; the longest scheme wins */
    private static function appHost(string $web): ?array
    {
        $web = strtolower($web);
        if (!str_ends_with($web, '.localhost') || ($rest = substr($web, 0, -10)) === '') {
            return null;
        }
        $schemes = \MyPHP\Protocol::schemes();
        for ($i = 0; ; ) {
            $scheme = substr($rest, $i);
            if (in_array($scheme, $schemes, true)) {
                if ($i === 0) { return [$scheme, 'localhost']; }
                $host = substr($rest, 0, $i - 1);
                return self::validHost($host) ? [$scheme, $host] : null;
            }
            $j = strpos($scheme, '.');
            if ($j === false) {
                return null;
            }
            $i += $j + 1;
        }
    }

    private static function validHost(string $host): bool
    {
        return $host !== '' && strlen($host) <= 253 && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) === 1;
    }

    /** The handlers of the app's schemes, window.open(), devices and downloads (mygo's setUp's). */
    private function addStageTwoHandlers(CData $v): void
    {
        $f = self::f();
        $tok = $f->new('Token');
        ($v->v->add_WebResourceRequested)($v, self::handler(fn(int $sender, $args) => $this->resourceRequested($args)), FFI::addr($tok));
        foreach (\MyPHP\Protocol::schemes() as $scheme) {
            ($v->v->AddWebResourceRequestedFilter)($v, Win32::wide("http://$scheme.localhost/*"), 0); // COREWEBVIEW2_WEB_RESOURCE_CONTEXT_ALL
            ($v->v->AddWebResourceRequestedFilter)($v, Win32::wide("http://*.$scheme.localhost/*"), 0); // its other hosts
        }
        ($v->v->add_NewWindowRequested)($v, self::handler(function (int $sender, $args) use ($f): void {
            $a = Com::typed($f, $args, 'NewWindow *');
            $p = $f->new('uint16_t *');
            ($a->v->get_Uri)($a, FFI::addr($p));
            $url = self::appURL(self::take($p));
            $user = $f->new('int32_t');
            ($a->v->get_IsUserInitiated)($a, FFI::addr($user));
            ($a->v->put_Handled)($a, 1); // the core opens what it opens: a window of its own, or the browser
            ($this->onOpen)?->__invoke($url, '', 0, 0, null, $user->cdata !== 0);
        }), FFI::addr($tok));
        ($v->v->add_PermissionRequested)($v, self::handler(function (int $sender, $args) use ($f): void {
            $a = Com::typed($f, $args, 'Permission *');
            $kind = $f->new('int32_t');
            ($a->v->get_PermissionKind)($a, FFI::addr($kind));
            $name = [1 => 'microphone', 2 => 'camera', 3 => 'geolocation', 4 => 'notifications'][$kind->cdata] ?? null;
            if ($name === null || $this->onPermission === null) {
                return; // WebView2's own answer
            }
            $p = $f->new('uint16_t *');
            ($a->v->get_Uri)($a, FFI::addr($p));
            $origin = self::appURL(self::take($p));
            if (preg_match('~^[a-z][a-z0-9+.-]*://[^/?#]*~i', $origin, $m) === 1) { $origin = $m[0]; }
            ($a->v->put_State)($a, ($this->onPermission)([$name], $origin) ? 1 : 2); // ALLOW, DENY
        }), FFI::addr($tok));
        if (($v4 = Com::query($v, self::IID_VIEW4)) !== null) {
            $x = Com::typed($f, $v4, 'View4 *');
            ($x->v->add_DownloadStarting)($x, self::handler(fn(int $sender, $args) => $this->downloadStarting($args)), FFI::addr($tok));
            Com::release($v4);
        }
    }

    /**
     * A request of the app's schemes, to the core's onScheme with a task its
     * answer goes to (mygo's resourceRequested): the request's method, URL
     * (its scheme's), headers and body; WebView2 waits for it (a deferral).
     */
    private function resourceRequested(CData $args): void
    {
        $f = self::f();
        $a = Com::typed($f, $args, 'ResourceRequested *');
        $out = $f->new('void *');
        if (($a->v->get_Request)($a, FFI::addr($out)) < 0 || FFI::isNull($out)) {
            return;
        }
        $req = Com::typed($f, $out, 'Request *');
        $p = $f->new('uint16_t *');
        ($req->v->get_Uri)($req, FFI::addr($p));
        $uri = self::take($p);
        if ($this->rules !== [] && $this->filterContent($a, $req, $uri)) {
            Com::release($out);
            return;
        }
        $url = self::appURL($uri);
        if ($url === $uri) {
            Com::release($out);
            return; // not the app's
        }
        ($req->v->get_Method)($req, FFI::addr($p));
        $method = self::take($p);
        $headers = [];
        $h = $f->new('void *');
        if (($req->v->get_Headers)($req, FFI::addr($h)) >= 0 && !FFI::isNull($h)) {
            $hs = Com::typed($f, $h, 'Headers *');
            $it = $f->new('void *');
            if (($hs->v->GetIterator)($hs, FFI::addr($it)) >= 0 && !FFI::isNull($it)) {
                $i = Com::typed($f, $it, 'HeaderIterator *');
                $has = $f->new('int32_t');
                for (($i->v->get_HasCurrentHeader)($i, FFI::addr($has)); $has->cdata !== 0; ($i->v->MoveNext)($i, FFI::addr($has))) {
                    $n = $f->new('uint16_t *'); $val = $f->new('uint16_t *');
                    ($i->v->GetCurrentHeader)($i, FFI::addr($n), FFI::addr($val));
                    $headers[self::take($n)] = self::take($val);
                }
                Com::release($it);
            }
            Com::release($h);
        }
        $body = '';
        $c = $f->new('void *');
        if (($req->v->get_Content)($req, FFI::addr($c)) >= 0 && !FFI::isNull($c)) {
            $st = Com::typed($f, $c, 'Stream *');
            $buf = $f->new('uint8_t[65536]');
            $n = $f->new('uint32_t');
            while (($st->v->Read)($st, $buf, 65536, FFI::addr($n)) >= 0 && $n->cdata > 0) {
                $body .= FFI::string($buf, $n->cdata);
            }
            Com::release($c);
        }
        Com::release($out);
        $d = $f->new('void *');
        if (($a->v->GetDeferral)($a, FFI::addr($d)) < 0 || FFI::isNull($d)) {
            return;
        }
        Com::addRef($a);
        $task = new SchemeTask(function (int $status, array $headers, string $body) use ($a, $d): void {
            $this->respond($a, Com::typed(self::f(), $d, 'Deferral *'), $status, $headers, $body);
        });
        if ($this->onScheme === null) {
            $task->respond(503, []);
            $task->finish();
            return;
        }
        ($this->onScheme)([$method, $url, $headers, $body], $task);
    }

    /** Answers a request: its response of an IStream of the body (SHCreateMemStream); a deferral holding it completed, null for one answered in its handler. @param array<string, string> $headers */
    private function respond(CData $args, ?CData $deferral, int $status, array $headers, string $body): void
    {
        $f = self::f();
        $raw = '';
        foreach ($headers as $k => $v) { $raw .= "$k: $v\r\n"; }
        static $shlwapi = null;
        $shlwapi ??= FFI::cdef('void *SHCreateMemStream(const void *init, uint32_t size);', 'shlwapi.dll');
        $buf = $f->new('uint8_t[' . max(1, strlen($body)) . ']');
        if ($body !== '') { FFI::memcpy($buf, $body, strlen($body)); }
        $stream = $shlwapi->SHCreateMemStream($buf, strlen($body));
        $resp = $f->new('void *');
        $env = self::$env;
        if ($env !== false && $env !== null && ($env->v->CreateWebResourceResponse)($env, $stream, $status, Win32::wide(self::reason($status)), Win32::wide($raw), FFI::addr($resp)) >= 0) {
            ($args->v->put_Response)($args, $resp);
            Com::release($resp);
        }
        if ($stream !== null) { Com::release($stream); }
        if ($deferral !== null) {
            ($deferral->v->Complete)($deferral);
            Com::release($deferral);
            Com::release($args);
        }
    }

    private static function reason(int $status): string
    {
        return [200 => 'OK', 204 => 'No Content', 206 => 'Partial Content', 301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified', 307 => 'Temporary Redirect', 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 500 => 'Internal Server Error', 503 => 'Service Unavailable'][$status] ?? 'Status';
    }

    /** A download: where it goes the core's onDownload says (null cancels it), and it is told as it ends (mygo's downloadStarting). */
    private function downloadStarting(CData $args): void
    {
        $f = self::f();
        $a = Com::typed($f, $args, 'DownloadStarting *');
        $op = $f->new('void *');
        if (($a->v->get_DownloadOperation)($a, FFI::addr($op)) < 0 || FFI::isNull($op)) {
            return;
        }
        $d = Com::typed($f, $op, 'Download *');
        $p = $f->new('uint16_t *');
        ($d->v->get_Uri)($d, FFI::addr($p));
        $url = self::appURL(self::take($p));
        ($a->v->get_ResultFilePath)($a, FFI::addr($p));
        $suggested = basename(str_replace('\\', '/', self::take($p)));
        if ($this->onDownload === null) {
            return; // WebView2's own
        }
        $path = ($this->onDownload)($url, $suggested);
        if ($path === null) {
            ($a->v->put_Cancel)($a, 1);
            return;
        }
        ($a->v->put_ResultFilePath)($a, Win32::wide(str_replace('/', '\\', $path)));
        ($a->v->put_Handled)($a, 1); // no download bubble
        Com::addRef($d);
        $tok = $f->new('Token');
        ($d->v->add_StateChanged)($d, self::handler(function (int $sender, $x) use ($d, $f, $url, $path): void {
            $state = $f->new('int32_t');
            ($d->v->get_State)($d, FFI::addr($state));
            if ($state->cdata === 0) {
                return; // in progress
            }
            $error = null;
            if ($state->cdata === 1) { // interrupted
                $reason = $f->new('int32_t');
                ($d->v->get_InterruptReason)($d, FFI::addr($reason));
                $error = "the download was interrupted (reason {$reason->cdata})";
            }
            ($this->onDownloadDone)?->__invoke($url, $path, $error);
            Com::release($d);
        }), FFI::addr($tok));
    }

    // Platform\Page.

    public function handle(): mixed
    {
        return $this->controller;
    }

    public function openDevTools(): void
    {
        $this->run(fn() => ($this->view->v->OpenDevToolsWindow)($this->view));
    }

    /** WebView2 has no call that closes its DevTools window. */
    public function closeDevTools(): void {}

    public function isDevToolsOpened(): bool
    {
        return false;
    }

    public function setUserScripts(array $scripts): void
    {
        $this->scripts = $scripts;
        if ($this->ready) { $this->addScripts(); }
    }

    /** Applies the lists (ContentRuleList, App::compileContentRules's) to the loads from now on, and their hiding to the documents (mygo's setContentRules). */
    public function setContentRules(array $lists): void
    {
        $this->rules = array_values(array_filter($lists, fn($l) => $l instanceof ContentRuleList));
        $this->hiding = ContentRuleList::hidingScript($this->rules);
        $this->run(function (): void {
            $this->filterAllRequests();
            $this->addScripts();
        });
    }

    /** Makes WebView2 ask about every request, once the page applies content rules. */
    private function filterAllRequests(): void
    {
        if ($this->rules !== [] && !$this->filtersAll) {
            $this->filtersAll = true;
            ($this->view->v->AddWebResourceRequestedFilter)($this->view, Win32::wide('*'), 0); // COREWEBVIEW2_WEB_RESOURCE_CONTEXT_ALL
        }
    }

    /** The resource-type of content rules of a COREWEBVIEW2_WEB_RESOURCE_CONTEXT (mygo's resourceTypes). */
    private static function resourceType(int $context): int
    {
        return match ($context) {
            1 => ContentRuleList::DOCUMENT, 2 => ContentRuleList::STYLE_SHEET, 3 => ContentRuleList::IMAGE, 4 => ContentRuleList::MEDIA,
            5 => ContentRuleList::FONT, 6 => ContentRuleList::SCRIPT, 7 => ContentRuleList::RAW, 8 => ContentRuleList::RAW | ContentRuleList::FETCH,
            11 => ContentRuleList::RAW | ContentRuleList::WEBSOCKET, 14 => ContentRuleList::PING,
            default => ContentRuleList::RAW | ContentRuleList::OTHER,
        };
    }

    /**
     * Applies the content rules to a request, and tells whether it answered
     * it (mygo's filterContent): one they block fails, one they make secure
     * goes to https, one they block cookies of goes without its Cookie
     * header. WebView2 cannot fail a request, and a fetch takes any
     * response: a blocked one is redirected to a data: URL, which Chromium
     * does not follow from a load of a page, as WebKit's blocked load is a
     * network error; a page itself gets an empty 403.
     */
    private function filterContent(CData $a, CData $req, string $uri): bool
    {
        $f = self::f();
        $ctx = $f->new('int32_t');
        ($a->v->get_ResourceContext)($a, FFI::addr($ctx));
        $target = self::appURL($uri);
        $top = $this->url();
        if ($ctx->cdata === 1 && $target === $this->navigating || $top === '') {
            $top = ''; // the page itself
        }
        $r = ContentRuleList::match($this->rules, $target, $top, self::resourceType($ctx->cdata));
        if ($r['block']) {
            $ctx->cdata === 1 ? $this->respond($a, null, 403, [], '') : $this->respond($a, null, 307, ['Location' => 'data:,'], '');
            return true;
        }
        if ($r['makeHTTPS'] && $target === $uri) {
            $this->respond($a, null, 307, ['Location' => str_starts_with(strtolower($uri), 'ws:') ? 'wss:' . substr($uri, 3) : 'https:' . substr($uri, 5)], '');
            return true;
        }
        if ($r['blockCookies']) {
            $h = $f->new('void *');
            if (($req->v->get_Headers)($req, FFI::addr($h)) >= 0 && !FFI::isNull($h)) {
                $hs = Com::typed($f, $h, 'Headers *');
                ($hs->v->RemoveHeader)($hs, Win32::wide('Cookie'));
                Com::release($h);
            }
        }
        return false;
    }

    public function isAudible(): bool
    {
        return false;
    }

    public function setAudioMuted(bool $muted): void {}

    public function isAudioMuted(): bool
    {
        return false;
    }

    public function captureState(int $kind): int
    {
        return 0;
    }

    public function setCaptureState(int $kind, int $state): void {}

    public function processID(): int
    {
        if ($this->view === null) {
            return 0;
        }
        $pid = self::f()->new('uint32_t');
        ($this->view->v->get_BrowserProcessId)($this->view, FFI::addr($pid));
        return $pid->cdata;
    }

    public function sessionState(): string
    {
        return '';
    }

    public function restoreSessionState(string $state): bool
    {
        return false;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->queued = [];
        unset(self::$shown[spl_object_id($this)]);
        if ($this->controller !== null) {
            ($this->controller->v->Close)($this->controller);
            Com::release($this->settings);
            Com::release($this->view);
            Com::release($this->controller);
        }
        [$this->controller, $this->view, $this->settings, $this->window] = [null, null, null, null];
    }

    public function reload(bool $ignoreCache): void
    {
        $this->run(fn() => $ignoreCache
            ? $this->devToolsCall('Page.reload', '{"ignoreCache":true}', fn() => null)
            : ($this->view->v->Reload)($this->view));
    }

    public function stop(): void
    {
        $this->run(fn() => ($this->view->v->Stop)($this->view));
    }

    public function goBack(): void
    {
        $this->run(fn() => ($this->view->v->GoBack)($this->view));
    }

    public function goForward(): void
    {
        $this->run(fn() => ($this->view->v->GoForward)($this->view));
    }

    private function can(string $getter): bool
    {
        if ($this->view === null) {
            return false;
        }
        $on = self::f()->new('int32_t');
        ($this->view->v->$getter)($this->view, FFI::addr($on));
        return $on->cdata !== 0;
    }

    public function canGoBack(): bool
    {
        return $this->can('get_CanGoBack');
    }

    public function canGoForward(): bool
    {
        return $this->can('get_CanGoForward');
    }

    public function isLoading(): bool
    {
        return $this->loading;
    }

    public function setZoom(float $f): void
    {
        $this->zoomFactor = $f;
        $this->run(fn() => ($this->controller->v->put_ZoomFactor)($this->controller, $f));
    }

    /** The zoom set (mygo's Zoom), not the controller's. */
    public function zoom(): float
    {
        return $this->zoomFactor;
    }

    public function setUserAgent(string $ua): void
    {
        $this->userAgent = $ua;
        $this->run(fn() => $this->applyUserAgent());
    }

    public function userAgent(): string
    {
        if ($this->settings === null || ($s2 = Com::query($this->settings, self::IID_SETTINGS2)) === null) {
            return $this->userAgent;
        }
        $f = self::f();
        $x = Com::typed($f, $s2, 'Settings *');
        $p = $f->new('uint16_t *');
        ($x->v->get_UserAgent)($x, FFI::addr($p));
        Com::release($s2);
        return self::take($p);
    }

    /** The file as a file: URL, Windows's path's backslashes slashes. */
    public function loadFile(string $path, string $readAccessDir): void
    {
        $p = str_replace('\\', '/', $path);
        $this->loadURL('file:///' . implode('/', array_map('rawurlencode', explode('/', ltrim($p, '/')))));
    }

    /**
     * Runs $body as the body of an async function in the page, through the
     * DevTools protocol (Runtime.evaluate, its promise awaited): $done gets
     * the string it returns ('' for undefined or null, as macOS's), or the
     * error, its exception's message.
     * @param Closure(?string, ?string): void $done
     */
    public function callAsync(string $body, Closure $done): void
    {
        $expr = '(async () => {' . $body . "\n})().then(v => v == null ? '' : String(v))";
        $params = json_encode(['expression' => $expr, 'awaitPromise' => true, 'returnByValue' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->run(fn() => $this->devToolsCall('Runtime.evaluate', $params, function (?string $json, ?string $error) use ($done): void {
            if ($json === null) {
                $done(null, $error);
                return;
            }
            $r = json_decode($json, true);
            if (isset($r['exceptionDetails'])) {
                $done(null, (string)($r['exceptionDetails']['exception']['description'] ?? $r['exceptionDetails']['text'] ?? 'an exception'));
                return;
            }
            $done((string)($r['result']['value'] ?? ''), null);
        }));
    }

    /** Calls a method of the DevTools protocol, $done getting its result's JSON or an error. @param Closure(?string, ?string): void $done */
    private function devToolsCall(string $method, string $params, Closure $done): void
    {
        ($this->view->v->CallDevToolsProtocolMethod)($this->view, Win32::wide($method), Win32::wide($params), self::handler(function (int $a, $json) use ($done, $method): void {
            if (self::hr($a) < 0 || $json === null) {
                $done(null, sprintf('%s failed (0x%08X)', $method, $a & 0xFFFFFFFF));
                return;
            }
            $done(Win32::str(self::f()->cast('uint16_t *', $json)), null);
        }));
    }

    public function capture(Closure $done): void
    {
        throw new Unsupported('myphp: a page\'s capture comes with the next stage of WebView2');
    }

    /** The markup as the page (NavigateToString), which knows no base URL. */
    public function loadHTML(string $html, string $baseURL = ''): void
    {
        $this->run(fn() => ($this->view->v->NavigateToString)($this->view, Win32::wide($html)));
    }

    public function url(): string
    {
        if ($this->view === null) {
            return '';
        }
        $p = self::f()->new('uint16_t *');
        ($this->view->v->get_Source)($this->view, FFI::addr($p));
        return self::appURL(self::take($p)); // about:blank for HTML the app loaded, an origin the app's own, as WebKit's (mygo's URL)
    }

    public function loadURL(string $url): void
    {
        $this->run(fn() => ($this->view->v->Navigate)($this->view, Win32::wide(self::webURL($url))));
    }

    public function print($window): void
    {
        $this->evaluate('window.print()');
    }

    public function printToPDF($window, float $width, float $height, bool $landscape, array $margins, bool $background, Closure $done): void
    {
        throw new Unsupported('myphp: printing a page to PDF comes with the next stage of WebView2');
    }

    public function allowsPictureInPicture(): ?bool
    {
        return null;
    }

    public function togglePictureInPicture(string $script): void {}

    public function pictureInPictureActive(): bool
    {
        return false;
    }

    public function leavingPage(bool $pip): void {}

    /** Runs $js in the page (ExecuteScript); a completion gets its result's JSON. */
    public function evaluate(string $js, $completion = null): void
    {
        $this->run(fn() => ($this->view->v->ExecuteScript)($this->view, Win32::wide($js), self::handler(function (int $a, $json) use ($completion): void {
            if ($completion !== null) { $completion(self::hr($a) >= 0 && $json !== null ? Win32::str(self::f()->cast('uint16_t *', $json)) : null); }
        })));
    }

    /** Hands a bridge message (reply or event) to the page. */
    public function deliver(array $message): void
    {
        $this->evaluate('window.myphp.__deliver(' . json_encode($message, JSON_THROW_ON_ERROR) . ')');
    }

    public function showContextMenu($menu, $event, Closure $listen): void {}
}
