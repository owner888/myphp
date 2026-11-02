<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\Logger;
use MyPHP\Native\Pango;
use MyPHP\Platform;
use MyPHP\Platform\{AppHandler, Menu, MenuItem, TrayHandler};

/**
 * The Linux backend: GTK 3 over FFI (mygo's internal/linux), native UI
 * drawn with OpenGL in a GtkGLArea and text with Pango, web pages with
 * WebKitGTK 4.1 (Linux\WebView). What is not here yet throws
 * Unsupported, or, where every app reaches it (the application menu), is
 * logged.
 */
final class Backend implements Platform\Backend
{
    private ?App $app = null;
    private ?Theme $theme = null;
    private ?Screen $screen = null;
    private ?Clipboard $clipboard = null;
    private ?Menus $menus = null;
    private ?Dialogs $dialogs = null;
    private ?Shell $shell = null;
    private ?Power $power = null;
    private ?Network $network = null;
    private ?Notifications $notifications = null;
    private ?Hotkeys $hotkeys = null;
    private ?NowPlaying $nowPlaying = null;
    private ?Media $media = null;
    private ?Symbols $symbols = null;
    private bool $running = false;
    private bool $signaled = false;

    public function __construct(
        /** @internal what the backend reports to */
        public readonly AppHandler $h,
    ) {}

    public function name(): string
    {
        return 'linux/gtk';
    }

    public function init(\MyPHP\ActivationPolicy $policy): void
    {
        $f = Gtk::ffi();
        // A packaged app's program name is its launcher's, which its desktop entry and icon are named after (myphp build), as mygo's is its executable's.
        $f->g_set_prgname(\MyPHP\Native\Bundle::info('CFBundleExecutable') ?? \MyPHP\App::name());
        $f->g_set_application_name(\MyPHP\App::name());
        if (!$f->gtk_init_check(null, null)) {
            throw new \RuntimeException('myphp: cannot open display (is DISPLAY or WAYLAND_DISPLAY set?)');
        }
    }

    public function run(): void
    {
        $this->running = true;
        Gtk::idle(fn() => $this->h->ready());
        Gtk::ffi()->gtk_main();
        $this->running = false;
    }

    /** Asks the app whether it quits, as AppKit's terminate: does, and ends the main loop if it does. */
    public function quit(): void
    {
        if (!$this->h->quitRequested()) {
            return;
        }
        $this->h->terminating();
        if ($this->running) {
            Gtk::ffi()->gtk_main_quit();
        }
    }

    public function signal(): void
    {
        if (!$this->signaled) {
            $this->signaled = true;
            Gtk::idle(function (): void {
                $this->signaled = false;
                $this->h->dispatch();
            });
        }
    }

    public function timer(float $seconds, \Closure $fn): void
    {
        Gtk::timeout($seconds, $fn);
    }

    public function step(): void
    {
        Gtk::ffi()->g_main_context_iteration(null, 1);
    }

    public function app(): Platform\AppController
    {
        return $this->app ??= new App;
    }

    /** @internal the menus of the windows' menu bars and the context menus */
    public function menus(): Menus
    {
        return $this->menus ??= new Menus($this->h);
    }

    /** Shows $m in the menu bar of every window that shows the application menu, and of those to come (mygo's SetApplicationMenu). */
    public function setApplicationMenu(?Menu $m): void
    {
        $this->menus()->appMenu = $m;
        foreach (Window::all() as $w) {
            if ($w->hasAppMenu()) { $w->installMenu($m); }
        }
    }

    /** Shows a context menu over $w, else the active window, and returns as it closes (mygo's PopupMenu); without a window there is nowhere to show it. */
    public function popupMenu(Menu $m, ?\MyPHP\Window $w, ?array $at): void
    {
        $native = $w?->native();
        if (!$native instanceof Window || !$native->isVisible()) {
            $native = \MyPHP\Platform\Linux\Window::active()?->native();
        }
        if (!$native instanceof Window) {
            Logger::warn('a context menu has no window to show over', 'linux');
            return;
        }
        $this->menus()->popup($m, $native, $at);
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
        return new Window($this, $w, $o, $panel);
    }

    public function newPage(int $width, int $height, array $scripts, \Closure $onMessage, bool $devTools = false, $configuration = null, string $userAgent = '', float $zoom = 1.0, string $suffix = '', bool $swipe = false, bool $pinch = false, bool $autoplay = false, bool $throttle = true, bool $pip = false, $dataStore = null): Platform\Page
    {
        if ($pip) {
            Logger::warn('picture in picture is not on Linux: the page goes without it', 'linux');
        }
        [$opened, $openerStore] = $configuration ?? [null, null]; // a window.open()'s navigation action and its opener's store (WebView::$onOpen)
        return WebView::create($scripts, $onMessage, $devTools, $userAgent, $zoom, $suffix, $swipe, $pinch, $autoplay, $throttle, $opened, $dataStore ?? $openerStore);
    }

    public function focusedWindow(): ?\MyPHP\Window
    {
        return Window::active();
    }

    public function doubleClickInterval(): float
    {
        $ms = Gtk::ffi()->new('int[1]');
        Gtk::ffi()->g_object_get(Gtk::ffi()->gtk_settings_get_default(), 'gtk-double-click-time', \FFI::addr($ms[0]), null);
        return $ms[0] / 1000;
    }

    public function newTray(TrayHandler $h): Platform\Tray
    {
        return new Tray($this->menus(), $h);
    }

    /** Pango's fonts at the primary monitor's scale. */
    public function fonts(): Platform\Fonts
    {
        $f = Gtk::ffi();
        $display = $f->gdk_display_get_default();
        $m = $display === null ? null : ($f->gdk_display_get_primary_monitor($display) ?? $f->gdk_display_get_monitor($display, 0));
        return new Pango($m === null ? 1.0 : (float)max(1, $f->gdk_monitor_get_scale_factor($m)));
    }

    public function rescaled(float $scale): void
    {
        Window::rescaled($scale);
    }

    public function dialogs(): Platform\Dialogs
    {
        return $this->dialogs ??= new Dialogs;
    }

    public function clipboard(): Platform\Clipboard
    {
        return $this->clipboard ??= new Clipboard;
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
        return $this->theme ??= new Theme;
    }

    public function power(): Platform\Power
    {
        return $this->power ??= new Power($this->h);
    }

    public function network(): Platform\Network
    {
        return $this->network ??= new Network($this->h);
    }

    public function nowPlaying(): Platform\NowPlaying
    {
        return $this->nowPlaying ??= new NowPlaying($this->h);
    }

    public function media(): Platform\Media
    {
        return $this->media ??= new Media($this);
    }

    public function symbols(): Platform\Symbols
    {
        return $this->symbols ??= new Symbols;
    }

    public function secrets(): Platform\Secrets
    {
        return new Secrets;
    }

    public function applications(): array
    {
        return Shell::installedApps();
    }

    public function registerHotkey(int $id, string $accelerator): void
    {
        $this->hotkeys()->register($id, $accelerator);
    }

    public function unregisterHotkey(int $id): void
    {
        $this->hotkeys()->unregister($id);
    }

    private function hotkeys(): Hotkeys
    {
        return $this->hotkeys ??= new Hotkeys($this->h);
    }

    public function notificationsSupported(): bool
    {
        return $this->notifications()->supported();
    }

    public function showNotification(\MyPHP\Notification $n): void
    {
        $this->notifications()->show($n);
    }

    public function removeNotification(string $id): void
    {
        $this->notifications()->remove($id);
    }

    public function removeAllNotifications(): void
    {
        $this->notifications()->removeAll();
    }

    private function notifications(): Notifications
    {
        return $this->notifications ??= new Notifications($this->h);
    }

    /** Whether WebKitGTK 4.1 loads (mygo's WebViewAvailable). */
    public function webViewAvailable(): bool
    {
        return WebKit::available();
    }
}
