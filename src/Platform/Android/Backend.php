<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use FFI;
use MyPHP\Native\Android;
use MyPHP\Platform;
use MyPHP\Platform\{AppHandler, Menu, MenuItem, TrayHandler};

final class Backend implements Platform\Backend
{
    private bool $running = false;
    private ?Window $mainWindow = null;
    private ?AppController $app = null;
    private ?Theme $theme = null;
    private ?Screen $screen = null;
    private ?Fonts $fonts = null;
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
        \MyPHP\Logger::debug('Android Backend::run starting loop', 'android');
        $this->running = true;
        while ($this->running) {
            $this->step();
        }
        \MyPHP\Logger::debug('Android Backend::run loop ended', 'android');
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
        // Execute expired timers
        $now = hrtime(true) / 1e9;
        $keep = [];
        foreach ($this->timers as $t) {
            if ($now >= $t['at']) {
                ($t['fn'])();
            } else {
                $keep[] = $t;
            }
        }
        $this->timers = $keep;

        // Process native events
        $f = Android::ffi();
        $ev = $this->event ??= $f->new('MyphpEvent');
        $timeout = 16;
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
                        \MyPHP\App::setTheme($ev->night_mode ? \MyPHP\UI\Theme::dark() : \MyPHP\UI\Theme::light());
                        $win?->setInsets((int)$ev->insets[0], (int)$ev->insets[1], (int)$ev->insets[2], (int)$ev->insets[3]);
                        break;
                    case Android::EVENT_SIGNAL:
                        $this->h->dispatch();
                        break;
                }
            } catch (\Throwable $e) {
                \MyPHP\Logger::error("Exception in event dispatch: " . $e->getMessage() . "\n" . $e->getTraceAsString(), "android");
            }
        }

        // Render at most one frame per step, after all input and lifecycle events have been consumed
        if ($needsRender) {
            try {
                $win = Window::instance();
                $win?->onFrame();
            } catch (\Throwable $e) {
                \MyPHP\Logger::error("Exception in onFrame: " . $e->getMessage() . "\n" . $e->getTraceAsString(), "android");
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
        throw new Platform\Unsupported('Page not implemented on Android yet');
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
        throw new Platform\Unsupported('Dialogs not implemented on Android yet');
    }

    public function clipboard(): Platform\Clipboard
    {
        throw new Platform\Unsupported('Clipboard not implemented on Android yet');
    }

    public function shell(): Platform\Shell
    {
        throw new Platform\Unsupported('Shell not implemented on Android yet');
    }

    public function screen(): Platform\Screen
    {
        return $this->screen ??= new Screen();
    }

    public function theme(): Platform\Theme
    {
        return $this->theme ??= new Theme();
    }

    public function power(): Platform\Power
    {
        throw new Platform\Unsupported('Power not implemented on Android yet');
    }

    public function network(): Platform\Network
    {
        throw new Platform\Unsupported('Network not implemented on Android yet');
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
        throw new Platform\Unsupported('Symbols not implemented on Android yet');
    }

    public function secrets(): Platform\Secrets
    {
        throw new Platform\Unsupported('Secrets not implemented on Android yet');
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
        return false;
    }

    public function showNotification(\MyPHP\Notification $n): void
    {
        throw new Platform\Unsupported('Notifications not implemented on Android yet');
    }

    public function removeNotification(string $id): void {}
    public function removeAllNotifications(): void {}

    public function webViewAvailable(): bool
    {
        return false;
    }

    public function destroySession(string $id): void {}
    public function defaultDataStore(): ?Platform\DataStore
    {
        return null;
    }
}
