<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use FFI;
use MyPHP\Native\Android;
use MyPHP\Platform;
use MyPHP\Platform\{AppHandler, Menu, MenuItem, TrayHandler};

final class Backend implements Platform\Backend
{
    private ?Platform\Symbols $symbols = null;
    private bool $running = false;
    private ?Window $mainWindow = null;
    private ?AppController $app = null;
    private ?Theme $theme = null;
    private ?Screen $screen = null;
    private ?Fonts $fonts = null;
    private ?Dialogs $dialogs = null;
    private ?Clipboard $clipboard = null;
    private ?Shell $shell = null;
    private ?Power $power = null;
    private ?Network $network = null;
    private mixed $event = null;

    /** @var list<array{at: float, fn: \Closure}> pending timers */
    private array $timers = [];

    public function __construct(
        public readonly AppHandler $h,
    ) {}

    public function name(): string
    {
        return 'android/egl';
    }

    public function init(\MyPHP\ActivationPolicy $policy): void
    {
        $this->h->ready();
    }

    public function run(): void
    {
        \MyPHP\Logger::verbose('Android Backend::run starting loop', 'android');
        $this->running = true;
        while ($this->running) {
            $this->step();
        }
        \MyPHP\Logger::verbose('Android Backend::run loop ended', 'android');
    }

    public function quit(): void
    {
        $this->running = false;
    }

    public function signal(): void
    {
        Android::ffi()->myphp_signal_backend();
    }

    public function timer(float $seconds, \Closure $fn): void
    {
        $this->timers[] = ['at' => hrtime(true) / 1e9 + $seconds, 'fn' => $fn];
    }

    public function step(): void
    {
        // Execute expired timers (preserve any new timers added inside callbacks)
        $now = hrtime(true) / 1e9;
        $timers = $this->timers;
        $this->timers = [];
        $due = [];
        foreach ($timers as $t) {
            if ($now >= $t['at']) {
                $due[] = $t['fn'];
            } else {
                $this->timers[] = $t;
            }
        }
        foreach ($due as $fn) {
            $fn();
        }

        \MyPHP\Native\CurlLoop::pollSockets(); // the transfers' sockets: no loop of the system's watches them for PHP here

        // Process native events
        $f = Android::ffi();
        $ev = $this->event ??= $f->new('MyphpEvent');
        $timeout = 8;
        $needsRender = false;
        while ($f->myphp_read_event(FFI::addr($ev), $timeout) > 0) {
            $timeout = 0; // drain remaining queued events without waiting
            $win = Window::instance();
            try {
                switch ($ev->type) {
                    case Android::EVENT_SURFACE_CREATED:
                        $win?->initEgl();
                        $win?->requestFrame();
                        break;
                    case Android::EVENT_SURFACE_CHANGED:
                        $win?->onSurfaceChanged();
                        break;
                    case Android::EVENT_SURFACE_DESTROYED:
                        $win?->destroySurface();
                        break;
                    case Android::EVENT_TOUCH:
                        $win?->onTouch((int)$ev->action, (float)$ev->x, (float)$ev->y, (int)$ev->time_ms);
                        break;
                    case Android::EVENT_IME_COMMIT:
                        $win?->onImeCommit(\FFI::string($ev->text));
                        break;
                    case Android::EVENT_IME_COMPOSE:
                        $win?->onImeCompose(\FFI::string($ev->text));
                        break;
                    case Android::EVENT_IME_DELETE:
                        $win?->onImeDelete();
                        break;
                    case Android::EVENT_KEY:
                        $win?->onKeyEvent((int)$ev->width, (int)$ev->action, (int)$ev->height);
                        break;
                    case Android::EVENT_CHOREOGRAPHER:
                        $needsRender = true;
                        break;
                    case Android::EVENT_PAUSE:
                        $win?->onPause();
                        break;
                    case Android::EVENT_RESUME:
                        $win?->onResume();
                        break;
                    case Android::EVENT_CONFIGURATION:
                        $this->theme()->setConfiguration((bool)$ev->night_mode, (float)$ev->font_scale, (bool)$ev->reduce_motion);
                        $this->h->themeChanged(); // App::systemChanged: the theme follows night mode, the font size and animations anew, unless the app set its own
                        $win?->setInsets((int)$ev->insets[0], (int)$ev->insets[1], (int)$ev->insets[2], (int)$ev->insets[3], (int)$ev->keyboard);
                        break;
                    case Android::EVENT_DIALOG_RESULT:
                        Dialogs::onDialogResult((int)$ev->width, (int)$ev->action);
                        break;
                    case Android::EVENT_BACK:
                        $win?->onBack();
                        break;
                    case Android::EVENT_ACCESS:
                        $win?->onAccessAction((int)$ev->time_ms, (int)$ev->action, (int)$ev->width);
                        break;
                    case Android::EVENT_FILES_RESULT:
                        Dialogs::onFilesResult((int)$ev->width);
                        break;
                    case Android::EVENT_WEB_MESSAGE:
                        $msg = $f->myphp_take_web_message(); // whole, however long
                        Page::onWebMessage((int)$ev->action, \FFI::string($msg));
                        $f->myphp_free_web_message($msg);
                        break;
                    case Android::EVENT_SIGNAL:
                        $this->h->dispatch();
                        $win?->requestFrame();
                        break;
                }
            } catch (\Throwable $e) {
                \MyPHP\Logger::error("Exception in event dispatch: " . $e->getMessage() . "\n" . $e->getTraceAsString(), "android");
                throw $e;
            }
        }

        if ($f->myphp_take_access_on() > 0) { // a screen reader is on: the frames describe themselves for it from now on
            Window::instance()?->onAccessOn();
        }

        // Render at most one frame per VSync (EVENT_CHOREOGRAPHER), after all input and lifecycle events have been consumed
        if ($needsRender) {
            try {
                $win = Window::instance();
                $win?->onFrame();
            } catch (\Throwable $e) {
                \MyPHP\Logger::error("Exception in onFrame: " . $e->getMessage() . "\n" . $e->getTraceAsString(), "android");
                throw $e;
            }
        }
    }

    public function app(): Platform\AppController
    {
        return $this->app ??= new AppController();
    }

    public function setApplicationMenu(?Menu $m): void
    {
        // No top menu bar on Android
    }

    public function popupMenu(Menu $m, ?\MyPHP\Window $w, ?array $at): void
    {
        throw new Platform\Unsupported('Context menus not implemented on Android yet');
    }

    public function updateMenuItem(MenuItem $it): void {}
    public function replaceSubmenu(int $id, Menu $m): void {}

    public function newWindow(\MyPHP\Window $w, \MyPHP\WindowOptions $o, bool $panel): Platform\Window
    {
        return $this->mainWindow ??= new Window($this, $w, $o, $panel);
    }

    public function newPage(int $width, int $height, array $scripts, \Closure $onMessage, bool $devTools = false, $configuration = null, string $userAgent = '', float $zoom = 1.0, string $suffix = '', bool $swipe = false, bool $pinch = false, bool $autoplay = false, bool $throttle = true, bool $pip = false, $dataStore = null): Platform\Page
    {
        return new Page($onMessage, $scripts);
    }

    public function focusedWindow(): ?\MyPHP\Window
    {
        return $this->mainWindow?->w;
    }

    public function doubleClickInterval(): float
    {
        return 0.3;
    }

    public function newTray(TrayHandler $h): Platform\Tray
    {
        throw new Platform\Unsupported('System tray not supported on Android');
    }

    public function fonts(): Platform\Fonts
    {
        $f = Android::ffi();
        $scale = max(1.0, $f->myphp_get_density());
        return $this->fonts ??= new Fonts($scale);
    }

    public function rescaled(float $scale): void
    {
        $this->fonts()?->setScale($scale);
    }

    public function dialogs(): Platform\Dialogs
    {
        return $this->dialogs ??= new Dialogs($this);
    }

    public function clipboard(): Platform\Clipboard
    {
        return $this->clipboard ??= new Clipboard();
    }

    public function shell(): Platform\Shell
    {
        return $this->shell ??= new Shell();
    }

    public function screen(): Platform\Screen
    {
        return $this->screen ??= new Screen();
    }

    public function theme(): Theme
    {
        return $this->theme ??= new Theme();
    }

    public function power(): Platform\Power
    {
        return $this->power ??= new Power();
    }

    public function network(): Platform\Network
    {
        return $this->network ??= new Network();
    }

    public function nowPlaying(): Platform\NowPlaying
    {
        throw new Platform\Unsupported('NowPlaying not implemented on Android yet');
    }

    public function media(): Platform\Media
    {
        throw new Platform\Unsupported('Media not implemented on Android yet');
    }

    public function symbols(): Platform\Symbols
    {
        return $this->symbols ??= new Symbols; // Material Symbols for SF Symbol names
    }

    private ?Secrets $secrets = null;

    public function secrets(): Platform\Secrets
    {
        return $this->secrets ??= new Secrets();
    }

    public function applications(): array
    {
        return [];
    }

    public function registerHotkey(int $id, string $accelerator): void
    {
        throw new Platform\Unsupported('Global hotkeys not supported on Android');
    }

    public function unregisterHotkey(int $id): void {}

    public function notificationsSupported(): bool
    {
        return true;
    }

    public function showNotification(\MyPHP\Notification $n): void
    {
        $id = crc32($n->id !== '' ? $n->id : $n->title);
        Android::ffi()->myphp_show_notification($n->title, $n->body, $id);
    }

    public function removeNotification(string $id): void {}
    public function removeAllNotifications(): void {}

    public function webViewAvailable(): bool
    {
        return true;
    }

    public function destroySession(string $id): void {}
    public function defaultDataStore(): ?Platform\DataStore
    {
        return null;
    }
}
