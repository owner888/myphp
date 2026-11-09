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
    private const WM_APP_READY = Win32::WM_APP, WM_APP_DISPATCH = Win32::WM_APP + 1, WM_APP_RUN = Win32::WM_APP + 2;

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
    /** The system's icons, none yet (Headless stands them in through this property). */
    private ?Platform\Symbols $symbols = null;

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
        switch ($msg) {
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
                if ($what === 'ImmersiveColorSet') { $this->h->themeChanged(); }
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
        $id = ++$this->nextTimer;
        $this->timers[$id] = $fn;
        Win32::user32()->SetTimer($this->hwnd, $id, max(10, (int)round($seconds * 1000)), null);
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

    public function setApplicationMenu(?Menu $m): void
    {
        \MyPHP\Logger::debug('the application menu comes with the windows of Windows, at a later stage', 'windows');
    }

    public function popupMenu(Menu $m, ?\MyPHP\Window $w, ?array $at): void
    {
        throw new Unsupported('myphp: context menus are not on Windows yet');
    }

    public function updateMenuItem(MenuItem $it): void {}

    public function replaceSubmenu(int $id, Menu $m): void {}

    public function newWindow(\MyPHP\Window $w, \MyPHP\WindowOptions $o, bool $panel): Platform\Window
    {
        $this->start();
        return new Window($this, $w, $o, $panel);
    }

    public function newPage(int $width, int $height, array $scripts, Closure $onMessage, bool $devTools = false, $configuration = null, string $userAgent = '', float $zoom = 1.0, string $suffix = '', bool $swipe = false, bool $pinch = false, bool $autoplay = false, bool $throttle = true, bool $pip = false, $dataStore = null): Platform\Page
    {
        throw new Unsupported('myphp: web pages are not on Windows yet');
    }

    public function focusedWindow(): ?\MyPHP\Window
    {
        return Window::active()?->w;
    }

    public function doubleClickInterval(): float
    {
        return Win32::user32()->GetDoubleClickTime() / 1000;
    }

    public function newTray(TrayHandler $h): Platform\Tray
    {
        throw new Unsupported('myphp: the tray is not on Windows yet');
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
        throw new Unsupported('myphp: dialogs are not on Windows yet');
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
        throw new Unsupported('myphp: the network status is not on Windows yet');
    }

    public function nowPlaying(): Platform\NowPlaying
    {
        throw new Unsupported('myphp: Now Playing is not on Windows yet');
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
        throw new Unsupported('myphp: secrets are not on Windows yet');
    }

    public function applications(): array
    {
        throw new Unsupported('myphp: the installed applications are not on Windows yet');
    }

    public function registerHotkey(int $id, string $accelerator): void
    {
        throw new Unsupported('myphp: global shortcuts are not on Windows yet');
    }

    public function unregisterHotkey(int $id): void {}

    public function notificationsSupported(): bool
    {
        return false;
    }

    public function showNotification(\MyPHP\Notification $n): void
    {
        throw new Unsupported('myphp: notifications are not on Windows yet');
    }

    public function removeNotification(string $id): void {}

    public function removeAllNotifications(): void {}

    public function webViewAvailable(): bool
    {
        return false;
    }
}
