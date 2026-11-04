<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\{CoreText, ObjC};
use MyPHP\Platform;
use MyPHP\Platform\{AppHandler, Menu, MenuItem, TrayHandler};

final class Backend implements Platform\Backend
{
    private bool $running = false;
    private ?Window $mainWindow = null;
    private ?AppController $app = null;
    private ?Theme $theme = null;
    private ?Screen $screen = null;
    private ?Platform\Fonts $fonts = null;
    private ?Dialogs $dialogs = null;
    private ?Clipboard $clipboard = null;
    private ?Shell $shell = null;
    private ?Power $power = null;
    private ?Network $network = null;

    /** @var list<array{at: float, fn: Closure}> pending timers */
    private array $timers = [];

    public function __construct(
        public readonly AppHandler $h,
    ) {
    }

    public function name(): string
    {
        return 'ios/metal';
    }

    public function init(\MyPHP\ActivationPolicy $policy): void
    {
        try {
            ObjC::boot(['UIKit']);
        } catch (\Throwable $e) {}
        $this->h->ready();
    }

    public function run(): void
    {
        $this->running = true;
        while ($this->running) {
            $this->step();
        }
    }

    public function quit(): void
    {
        $this->running = false;
    }

    public function signal(): void
    {
    }

    public function timer(float $seconds, Closure $fn): void
    {
        $this->timers[] = ['at' => hrtime(true) / 1e9 + $seconds, 'fn' => $fn];
    }

    public function step(): void
    {
        $now = hrtime(true) / 1e9;
        $remaining = [];
        foreach ($this->timers as $timer) {
            if ($now >= $timer['at']) {
                ($timer['fn'])();
            } else {
                $remaining[] = $timer;
            }
        }
        $this->timers = $remaining;

        // Poll native touch events
        $this->mainWindow?->pollEvents();

        // Poll external deep link URLs
        try {
            $f = \MyPHP\Native\iOS::ffi();
            $urlBuf = $f->new('char[512]');
            while ($f->myphp_ios_poll_open_url(\FFI::cast('char*', \FFI::addr($urlBuf)), 512) > 0) {
                $urlStr = \FFI::string(\FFI::cast('char*', \FFI::addr($urlBuf)));
                if ($urlStr !== '') {
                    $this->h->openURLs([$urlStr]);
                }
            }

            // Poll web view IPC messages
            $pageId = $f->new('int');
            $msgBuf = $f->new('char[2048]');
            while ($f->myphp_ios_poll_web_message(\FFI::addr($pageId), \FFI::cast('char*', \FFI::addr($msgBuf)), 2048) > 0) {
                $msgStr = \FFI::string(\FFI::cast('char*', \FFI::addr($msgBuf)));
                Page::onWebMessage((int)$pageId->cdata, $msgStr);
            }
        } catch (\Throwable $e) {}

        // Process frame if requested
        $this->mainWindow?->onFrame();

        // Yield slightly so worker thread doesn't burn CPU
        try {
            if (\MyPHP\Native\iOS::ffi()->myphp_ios_is_app_paused() > 0) {
                usleep(100000); // 100ms deep sleep while suspended in background
                return;
            }
        } catch (\Throwable $e) {}

        usleep(4000); // ~4ms (250Hz max polling) while active
    }

    public function app(): Platform\AppController
    {
        return $this->app ??= new AppController();
    }

    public function setApplicationMenu(?Menu $m): void {}
    public function popupMenu(Menu $m, ?\MyPHP\Window $w, ?array $at): void {}
    public function updateMenuItem(MenuItem $it): void {}
    public function replaceSubmenu(int $id, Menu $m): void {}

    public function newWindow(\MyPHP\Window $w, \MyPHP\WindowOptions $o, bool $panel): Platform\Window
    {
        $win = new Window($this, $w, $o, $panel);
        $this->mainWindow = $win;
        return $win;
    }

    public function newPage(int $width, int $height, array $scripts, Closure $onMessage, bool $devTools = false, $configuration = null, string $userAgent = '', float $zoom = 1.0, string $suffix = '', bool $swipe = false, bool $pinch = false, bool $autoplay = false, bool $throttle = true, bool $pip = false, $dataStore = null): Platform\Page
    {
        return new Page($onMessage);
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
        throw new Platform\Unsupported('Tray is not supported on iOS');
    }

    public function fonts(): Platform\Fonts
    {
        if ($this->fonts === null) {
            $mainScreen = ObjC::cls('UIScreen')?->msg('mainScreen');
            $scale = $mainScreen !== null ? max(1.0, (float)$mainScreen->msg('scale')) : 3.0;
            $this->fonts = new CoreText($scale);
        }
        return $this->fonts;
    }

    public function rescaled(float $scale): void
    {
        if ($this->fonts !== null) {
            $this->fonts->scale = $scale;
        }
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

    public function theme(): Platform\Theme
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
        throw new Platform\Unsupported('NowPlaying not implemented on iOS');
    }

    private ?Media $media = null;

    public function media(): Platform\Media
    {
        return $this->media ??= new Media();
    }

    private ?Symbols $symbols = null;

    public function symbols(): Platform\Symbols
    {
        return $this->symbols ??= new Symbols();
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

    public function registerHotkey(int $id, string $accelerator): void {}
    public function unregisterHotkey(int $id): void {}

    public function notificationsSupported(): bool
    {
        return true;
    }

    public function showNotification(\MyPHP\Notification $n): void
    {
        try {
            iOS::ffi()->myphp_ios_show_notification(
                $n->title,
                $n->body,
                $n->id !== '' ? $n->id : null
            );
        } catch (\Throwable $e) {}
    }

    public function removeNotification(string $id): void
    {
        try {
            iOS::ffi()->myphp_ios_remove_notification($id);
        } catch (\Throwable $e) {}
    }

    public function removeAllNotifications(): void
    {
        try {
            iOS::ffi()->myphp_ios_remove_all_notifications();
        } catch (\Throwable $e) {}
    }

    public function webViewAvailable(): bool
    {
        return true;
    }
}
