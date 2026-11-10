<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use FFI\CData;
use MyPHP\Platform;
use MyPHP\Platform\{AppHandler, Menu, MenuItem, TrayHandler, Unsupported};

/**
 * The Windows backend: Win32 over FFI (mygo's internal/windows). Its event
 * loop is the thread's message queue; a hidden window of the app's
 * (mygo's appHwnd) gets what it posts itself (ready, dispatch, a function
 * to run), the timers, and what Windows tells every top-level window: the
 * settings, the displays, power and the session. Its windows are
 * Windows\Window, its text DirectWrite's (Native\DWrite); native UI's
 * renderer, pages, menus and the rest come stage by stage; what is not here
 * yet throws Unsupported.
 */
final class Backend implements Platform\Backend
{
    private const WM_APP_READY = Win32::WM_APP, WM_APP_DISPATCH = Win32::WM_APP + 1, WM_APP_RUN = Win32::WM_APP + 2, WM_APP_TRAY_ADD = Win32::WM_APP + 4;
    /** @internal the notification area's callback message for the trays */
    public const WM_APP_TRAY = Win32::WM_APP + 3;

    /** The app's hidden window, null before init. */
    private ?CData $hwnd = null;
    private bool $running = false;
    private bool $signaled = false;
    /** @var list<Closure> what runs next from the queue (WM_APP_RUN) */
    private array $posted = [];
    /** @var array<int, Closure> the timers' closures, by their ids */
    private array $timers = [];
    private int $nextTimer = 0;
    private ?App $app = null;
    private ?Screen $screen = null;
    private ?Theme $theme = null;
    private ?Clipboard $clipboard = null;
    private ?Shell $shell = null;
    private ?Power $power = null;
    private ?Media $media = null;
    private ?Menus $menus = null;
    private ?Dialogs $dialogs = null;
    private ?Network $network = null;
    private ?Secrets $secrets = null;
    /** @var array<int, Tray> the trays, by their numbers; the number of the last one counted */
    private array $trays = [];
    private int $nextTray = 0;
    /** @var array<string, Tray> the icons showing the notifications, by their ids */
    private array $notifications = [];
    /** The system's icons, none yet (Headless stands them in through this property). */
    private ?Platform\Symbols $symbols = null;
    private ?NowPlaying $nowPlaying = null;

    public function __construct(
        /** @internal what the backend reports to */
        public readonly AppHandler $h,
    ) {}

    public function name(): string
    {
        return 'windows/webview2';
    }

    public function init(\MyPHP\ActivationPolicy $policy): void
    {
        $this->start();
    }

    /**
     * The thread's COM and DPI awareness, and the app's window, once: at
     * init, or before it as a timer, a signal or a wait asks for them (a
     * test's App::wait without the app running), as GLib's loop runs
     * before GTK's init on Linux. @return CData the app's window
     */
    private function start(): CData
    {
        if ($this->hwnd !== null) {
            return $this->hwnd;
        }
        Win32::ole32()->CoInitializeEx(null, 2); // apartment threaded, as a UI thread's COM
        Win32::user32()->SetProcessDpiAwarenessContext(-4); // per monitor v2: the windows' sizes in pixels, scaled by their display's DPI
        Win32::registerClass('MyPHPApp');
        return $this->hwnd = Win32::createWindow('MyPHPApp', '', 0, 0, fn(int $msg, int $wp, int $lp): ?int => $this->message($msg, $wp, $lp));
    }

    /** The app window's messages. */
    private function message(int $msg, int $wp, int $lp): ?int
    {
        if ($this->menus !== null && ($r = MenuDraw::message($this->hwnd, $msg, $wp, $lp)) !== null) {
            return $r; // a tray's menu's items, drawn here
        }
        switch ($msg) {
            case Win32::WM_HOTKEY:
                $this->h->hotkeyPressed($wp);
                return 0;
            case self::WM_APP_TRAY:
                ($this->trays[$wp] ?? null)?->event($lp & 0xFFFF);
                return 0;
            case self::WM_APP_TRAY_ADD:
                foreach ($this->trays as $t) { $t->add(); }
                return 0;
            case Win32::WM_INITMENUPOPUP:
                if ((($lp >> 16) & 0xFFFF) === 0) { $this->menus?->opening($wp); }
                return null;
            case self::WM_APP_READY:
                $this->h->ready();
                return 0;
            case self::WM_APP_DISPATCH:
                $this->signaled = false;
                $this->h->dispatch();
                return 0;
            case self::WM_APP_RUN:
                $posted = $this->posted;
                $this->posted = [];
                foreach ($posted as $fn) { $fn(); }
                return 0;
            case Win32::WM_TIMER:
                $fn = $this->timers[$wp] ?? null;
                Win32::user32()->KillTimer($this->hwnd, $wp);
                unset($this->timers[$wp]);
                $fn?->__invoke();
                return 0;
            case Win32::WM_SETTINGCHANGE:
                $what = Win32::strAt($lp);
                if ($what === 'ImmersiveColorSet') {
                    $this->h->themeChanged();
                    foreach ($this->trays as $t) { $t->appearance(); }
                }
                elseif ($what === 'intl') { $this->h->localeChanged(); }
                return 0;
            case Win32::WM_DISPLAYCHANGE:
                $this->h->displaysChanged();
                return 0;
            case Win32::WM_POWERBROADCAST:
            case Win32::WM_WTSSESSION_CHANGE:
                $this->power?->message($msg, $wp);
                return $msg === Win32::WM_POWERBROADCAST ? 1 : 0;
        }
        return null;
    }

    /** @internal the app's hidden window, which owns the clipboard and gets the power and session events */
    public function hwnd(): ?CData
    {
        return $this->hwnd;
    }

    public function run(): void
    {
        $u = Win32::user32();
        $this->running = true;
        $u->PostMessageW($this->hwnd, self::WM_APP_READY, 0, 0);
        $m = $u->new('MSG');
        while ($u->GetMessageW(\FFI::addr($m), null, 0, 0) > 0) {
            $u->TranslateMessage(\FFI::addr($m));
            $u->DispatchMessageW(\FFI::addr($m));
        }
        $this->running = false;
    }

    /** Asks the app whether it quits, and ends the loop if it does (mygo's Quit; modal loops pass WM_QUIT on). */
    public function quit(): void
    {
        if (!$this->h->quitRequested()) {
            return;
        }
        $this->h->terminating();
        if ($this->running) {
            Win32::user32()->PostQuitMessage(0);
        }
    }

    public function signal(): void
    {
        if ($this->signaled) {
            return;
        }
        $this->signaled = true;
        Win32::user32()->PostMessageW($this->start(), self::WM_APP_DISPATCH, 0, 0);
    }

    /** A timer of the app window's (SetTimer: 10 ms at the least), or, for none, the queue's next turn. */
    public function timer(float $seconds, Closure $fn): void
    {
        $this->start();
        if ($seconds <= 0) {
            $this->posted[] = $fn;
            if (count($this->posted) === 1) { Win32::user32()->PostMessageW($this->hwnd, self::WM_APP_RUN, 0, 0); }
            return;
        }
        $this->timeout($seconds, $fn);
    }

    /** @internal a timer of the app's window (SetTimer, 10 ms at least) running $fn once, cancel() takes back: its id */
    public function timeout(float $seconds, Closure $fn): int
    {
        $this->start();
        $id = ++$this->nextTimer;
        $this->timers[$id] = $fn;
        Win32::user32()->SetTimer($this->hwnd, $id, max(10, (int)round($seconds * 1000)), null);
        return $id;
    }

    /** @internal removes a timer timeout() made, unless it ran */
    public function cancel(int $id): void
    {
        if (isset($this->timers[$id])) {
            unset($this->timers[$id]);
            Win32::user32()->KillTimer($this->hwnd, $id);
        }
    }

    /** Waits 50 ms at most for a message, then handles those there are (mygo's Step); WM_QUIT is posted again for the loop outside. */
    public function step(): void
    {
        $this->start();
        $u = Win32::user32();
        $u->MsgWaitForMultipleObjectsEx(0, null, 50, 0x04FF /* QS_ALLINPUT */, 0x0004 /* MWMO_INPUTAVAILABLE */);
        $m = $u->new('MSG');
        while ($u->PeekMessageW(\FFI::addr($m), null, 0, 0, 1 /* PM_REMOVE */) !== 0) {
            if ($m->message === Win32::WM_QUIT) {
                $u->PostQuitMessage((int)$m->wParam);
                return;
            }
            $u->TranslateMessage(\FFI::addr($m));
            $u->DispatchMessageW(\FFI::addr($m));
        }
    }

    public function app(): Platform\AppController
    {
        return $this->app ??= new App;
    }

    public function menus(): Menus
    {
        if ($this->menus === null) {
            $this->menus = new Menus($this->h);
            $this->menus->trayMenus = function (MenuItem $it): void {
                foreach ($this->trays as $t) { $t->menu()?->apply($it); }
            };
        }
        return $this->menus;
    }

    /** Shows $m in the menu bar of every window that shows the application menu, and of those to come (mygo's SetApplicationMenu). */
    public function setApplicationMenu(?Menu $m): void
    {
        $this->menus()->appMenu = $m;
        foreach (Window::all() as $w) {
            if ($w->hasAppMenu()) { $w->installMenu($m); }
        }
    }

    /** Shows a context menu over $w, else the active window, and returns as it closes (mygo's PopupMenu); without a window there is nowhere to show it, as Linux's. */
    public function popupMenu(Menu $m, ?\MyPHP\Window $w, ?array $at): void
    {
        $native = $w?->native();
        if (!$native instanceof Window || !$native->isVisible()) {
            $native = Window::active();
        }
        if ($native === null) {
            \MyPHP\Logger::warn('a context menu has no window to show over', 'windows');
            return;
        }
        $this->menus()->popup($m, $native, $native === $w?->native() ? $at : null);
    }

    public function updateMenuItem(MenuItem $it): void
    {
        $this->menus()->update($it);
    }

    public function replaceSubmenu(int $id, Menu $m): void
    {
        $this->menus()->replaceSubmenu($id, $m);
    }

    public function newWindow(\MyPHP\Window $w, \MyPHP\WindowOptions $o, bool $panel): Platform\Window
    {
        $this->start();
        return new Window($this, $w, $o, $panel);
    }

    /** A page of WebView2, made as the window showing it does (WebView::attach). */
    public function newPage(int $width, int $height, array $scripts, Closure $onMessage, bool $devTools = false, $configuration = null, string $userAgent = '', float $zoom = 1.0, string $suffix = '', bool $swipe = false, bool $pinch = false, bool $autoplay = false, bool $throttle = true, bool $pip = false, $dataStore = null): Platform\Page
    {
        return new WebView($scripts, $onMessage, $devTools, $userAgent, $zoom, $pinch, $swipe, $dataStore instanceof DataStore ? $dataStore : null);
    }

    public function focusedWindow(): ?\MyPHP\Window
    {
        return Window::active()?->w;
    }

    public function doubleClickInterval(): float
    {
        return Win32::user32()->GetDoubleClickTime() / 1000;
    }

    /**
     * A notification area icon (mygo's NewTray), added once the call that
     * made it gave it its number, image and tooltip: the notification area
     * keeps the user's choices for an icon by its number and names it by its
     * first tooltip, and keeps none for one added blank.
     */
    public function newTray(TrayHandler $h): Platform\Tray
    {
        $this->start();
        $t = new Tray($this, $h, ++$this->nextTray);
        $this->trays[$t->id] = $t;
        Win32::user32()->PostMessageW($this->hwnd, self::WM_APP_TRAY_ADD, 0, 0);
        return $t;
    }

    /** @internal $t numbered $n, unless another icon has it */
    public function renumberTray(Tray $t, int $n): bool
    {
        if (isset($this->trays[$n])) {
            return false;
        }
        unset($this->trays[$t->id]);
        $this->trays[$n] = $t;
        return true;
    }

    /** @internal $t gone */
    public function forgetTray(Tray $t): void
    {
        unset($this->trays[$t->id]);
    }

    /** DirectWrite's fonts at the primary display's scale. */
    public function fonts(): Platform\Fonts
    {
        $primary = array_values(array_filter($this->screen()->displays(), fn(\MyPHP\Display $d) => $d->primary))[0] ?? null;
        return new \MyPHP\Native\DWrite($primary?->scaleFactor ?: 1.0);
    }

    public function rescaled(float $scale): void {}

    public function dialogs(): Platform\Dialogs
    {
        return $this->dialogs ??= new Dialogs($this);
    }

    public function clipboard(): Platform\Clipboard
    {
        return $this->clipboard ??= new Clipboard($this);
    }

    public function shell(): Platform\Shell
    {
        return $this->shell ??= new Shell;
    }

    public function screen(): Platform\Screen
    {
        return $this->screen ??= new Screen;
    }

    public function theme(): Platform\Theme
    {
        return $this->theme ??= new Theme($this);
    }

    public function power(): Platform\Power
    {
        return $this->power ??= new Power($this);
    }

    public function network(): Platform\Network
    {
        return $this->network ??= new Network($this, $this->h);
    }

    public function nowPlaying(): Platform\NowPlaying
    {
        return $this->nowPlaying ??= new NowPlaying($this, $this->h);
    }

    public function media(): Platform\Media
    {
        return $this->media ??= new Media($this);
    }

    public function symbols(): Platform\Symbols
    {
        return $this->symbols ?? throw new Unsupported('myphp: the system\'s symbols are not on Windows yet');
    }

    public function secrets(): Platform\Secrets
    {
        return $this->secrets ??= new Secrets();
    }

    public function applications(): array
    {
        throw new Unsupported('myphp: the installed applications are not on Windows yet');
    }

    /** A global shortcut (mygo's RegisterHotkey): RegisterHotKey on the app's window, its WM_HOTKEY told; its key held does not repeat it. */
    public function registerHotkey(int $id, string $accelerator): void
    {
        $a = Platform\Accelerator::parse($accelerator, 'Windows');
        $vk = Menus::virtualKey($a->key) ?? throw new \RuntimeException("myphp: unsupported key in \"$accelerator\"");
        $mods = 0x4000; // MOD_NOREPEAT
        foreach ([Platform\Accelerator::ALT => 0x1, Platform\Accelerator::CTRL => 0x2, Platform\Accelerator::SHIFT => 0x4, Platform\Accelerator::SUPER => 0x8] as $m => $w) {
            if ($a->has($m)) { $mods |= $w; }
        }
        if (Win32::user32()->RegisterHotKey($this->start(), $id, $mods, $vk) === 0) {
            throw new \RuntimeException("myphp: cannot register \"$accelerator\" (error " . Win32::kernel32()->GetLastError() . ')');
        }
    }

    public function unregisterHotkey(int $id): void
    {
        if ($this->hwnd !== null) { Win32::user32()->UnregisterHotKey($this->hwnd, $id); }
    }

    /** Notifications are the notification area's balloons (mygo's), which Windows 10 and later show as toasts. */
    public function notificationsSupported(): bool
    {
        return true;
    }

    /**
     * Shows $n as the balloon of the first tray icon showing none, else of
     * a temporary icon of the app's icon, which goes with it (mygo's
     * ShowNotification): its subtitle over its body.
     */
    public function showNotification(\MyPHP\Notification $n): void
    {
        $this->start();
        $t = null;
        foreach ($this->trays as $x) {
            if (!$x->temporary() && $x->notification === '' && $x->added()) { $t = $x; break; }
        }
        if ($t === null) {
            $t = new Tray($this, null, ++$this->nextTray);
            $this->trays[$t->id] = $t;
            $t->add();
        }
        $this->removeNotification($n->id); // one of the same id replaced
        $t->notification = $n->id;
        $this->notifications[$n->id] = $t;
        $o = $n->options;
        if (!$t->balloon($o->title, $o->subtitle === '' ? $o->body : "{$o->subtitle}\n{$o->body}", $o->silent)) {
            $this->finishNotification($t);
            throw new \RuntimeException('myphp: cannot show the notification');
        }
    }

    public function removeNotification(string $id): void
    {
        if (isset($this->notifications[$id])) { $this->finishNotification($this->notifications[$id]); }
    }

    public function removeAllNotifications(): void
    {
        foreach ($this->notifications as $t) { $this->finishNotification($t); }
    }

    /** @internal $t's balloon was clicked ($clicked) or timed out: the app told of a click, the notification finished */
    public function notificationEnded(Tray $t, bool $clicked): void
    {
        $id = $t->notification;
        if ($id === '') {
            return;
        }
        $this->finishNotification($t);
        if ($clicked) { $this->h->notificationClicked($id); }
    }

    /** $t's notification gone: a temporary icon with it, another's balloon hidden (an empty one hides it; mygo's finishNotification). */
    private function finishNotification(Tray $t): void
    {
        unset($this->notifications[$t->notification]);
        $t->notification = '';
        if ($t->temporary()) {
            $t->destroy();
            return;
        }
        $t->balloon('', '', true);
    }

    /** Whether pages can be shown: the WebView2 Runtime is there (mygo's WebViewAvailable). */
    public function webViewAvailable(): bool
    {
        return WebView::runtime() !== null;
    }
}
