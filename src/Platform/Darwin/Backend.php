<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use MyPHP\Native\{Carbon, CoreText, Metal, ObjC};
use MyPHP\Platform;
use MyPHP\Platform\{Accelerator, AppHandler, Menu, MenuItem, TrayHandler};

/** The macOS backend: Cocoa, WebKit and Metal over the Objective-C runtime (mygo's internal/darwin). */
final class Backend implements Platform\Backend
{
    private static ?Metal $metal = null;
    /** Carbon's macOS virtual key codes of the accelerators' keys (ANSI layout), as mygo's keyCodes. */
    private const KEY_CODES = [
        'a' => 0x00, 's' => 0x01, 'd' => 0x02, 'f' => 0x03, 'h' => 0x04, 'g' => 0x05, 'z' => 0x06, 'x' => 0x07,
        'c' => 0x08, 'v' => 0x09, 'b' => 0x0B, 'q' => 0x0C, 'w' => 0x0D, 'e' => 0x0E, 'r' => 0x0F, 'y' => 0x10,
        't' => 0x11, '1' => 0x12, '2' => 0x13, '3' => 0x14, '4' => 0x15, '6' => 0x16, '5' => 0x17, '=' => 0x18,
        '9' => 0x19, '7' => 0x1A, '-' => 0x1B, '8' => 0x1C, '0' => 0x1D, ']' => 0x1E, 'o' => 0x1F, 'u' => 0x20,
        '[' => 0x21, 'i' => 0x22, 'p' => 0x23, 'l' => 0x25, 'j' => 0x26, "'" => 0x27, 'k' => 0x28, ';' => 0x29,
        '\\' => 0x2A, ',' => 0x2B, '/' => 0x2C, 'n' => 0x2D, 'm' => 0x2E, '.' => 0x2F, '`' => 0x32,
        'Enter' => 0x24, 'Tab' => 0x30, 'Space' => 0x31, 'Backspace' => 0x33, 'Escape' => 0x35,
        'Delete' => 0x75, 'Home' => 0x73, 'End' => 0x77, 'PageUp' => 0x74, 'PageDown' => 0x79, 'Insert' => 0x72,
        'Left' => 0x7B, 'Right' => 0x7C, 'Down' => 0x7D, 'Up' => 0x7E,
        'F1' => 0x7A, 'F2' => 0x78, 'F3' => 0x63, 'F4' => 0x76, 'F5' => 0x60, 'F6' => 0x61, 'F7' => 0x62, 'F8' => 0x64,
        'F9' => 0x65, 'F10' => 0x6D, 'F11' => 0x67, 'F12' => 0x6F, 'F13' => 0x69, 'F14' => 0x6B, 'F15' => 0x71,
        'F16' => 0x6A, 'F17' => 0x40, 'F18' => 0x4F, 'F19' => 0x50, 'F20' => 0x5A,
        'VolumeUp' => 0x48, 'VolumeDown' => 0x49, 'VolumeMute' => 0x4A,
        'Num0' => 0x52, 'Num1' => 0x53, 'Num2' => 0x54, 'Num3' => 0x55, 'Num4' => 0x56, 'Num5' => 0x57,
        'Num6' => 0x58, 'Num7' => 0x59, 'Num8' => 0x5B, 'Num9' => 0x5C, 'NumDec' => 0x41, 'NumMult' => 0x43,
        'NumAdd' => 0x45, 'NumDiv' => 0x4B, 'NumSub' => 0x4E,
    ];

    public function __construct(
        /** @internal what the backend reports to */
        public readonly AppHandler $h,
    ) {}

    private ?App $app = null;
    private ?Menus $menus = null;
    private ?Dialogs $dialogs = null;
    private ?Clipboard $clipboard = null;
    private ?Shell $shell = null;
    private ?Screen $screen = null;
    private ?Theme $theme = null;
    private ?Power $power = null;
    private ?Network $network = null;
    private ?NowPlaying $nowPlaying = null;
    private ?Secrets $secrets = null;
    private ?Notifications $notifications = null;
    /** @var array<int, int> Carbon's hot key ids by the core's */
    private array $hotkeys = [];

    public function name(): string
    {
        return 'darwin/wkwebview';
    }

    public function init(\MyPHP\ActivationPolicy $policy): void
    {
        $this->darwinApp()->init($this->h, $policy);
    }

    public function run(): void
    {
        $this->darwinApp()->run();
    }

    public function quit(): void
    {
        $this->darwinApp()->quit();
    }

    public function signal(): void
    {
        $this->darwinApp()->signal();
    }

    public function timer(float $seconds, \Closure $fn): void
    {
        $this->darwinApp()->timer($seconds, $fn);
    }

    public function step(): void
    {
        $this->darwinApp()->step();
    }

    public function app(): Platform\AppController
    {
        return $this->darwinApp();
    }

    /** @internal NSApplication's side of the backend */
    public function darwinApp(): App
    {
        return $this->app ??= new App($this);
    }

    /** @internal the NSMenus built of snapshots */
    public function menus(): Menus
    {
        return $this->menus ??= new Menus($this);
    }

    public function setApplicationMenu(?Menu $m): void
    {
        $this->menus()->setApplicationMenu($m);
    }

    public function popupMenu(Menu $m, ?\MyPHP\Window $w, ?array $at): void
    {
        $this->menus()->popup($m, $w?->nativeHandle(), $at);
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
        return \MyPHP\Native\WebView::create(ObjC::rect(0, 0, $width, $height), $scripts, $onMessage, $devTools, $configuration, $userAgent, $zoom, $suffix, $swipe, $pinch, $autoplay, $throttle, $pip, $dataStore?->ns); // a session's DataStore: its WKWebsiteDataStore
    }

    public function focusedWindow(): ?\MyPHP\Window
    {
        return Window::keyWindow();
    }

    public function doubleClickInterval(): float
    {
        return ObjC::msg('d:', ObjC::cls('NSEvent'), 'doubleClickInterval');
    }

    public function fonts(): Platform\Fonts
    {
        ObjC::boot(['AppKit']);
        return new CoreText(ObjC::msg('d:', ObjC::msg('p:', ObjC::cls('NSScreen'), 'mainScreen'), 'backingScaleFactor'));
    }

    public function rescaled(float $scale): void
    {
        self::$metal?->setAtlasScale($scale);
    }

    /** The one Metal device every window draws with, its atlases App::text()'s. */
    public static function metal(): Metal
    {
        if (self::$metal === null) {
            $text = \MyPHP\App::text();
            self::$metal = new Metal();
            self::$metal->attachAtlas($text->atlas, $text->scale());
            self::$metal->attachImages($text->images);
        }
        return self::$metal;
    }

    public function newTray(TrayHandler $h): Platform\Tray
    {
        return new Tray($this->menus(), $h);
    }

    /**
     * @internal the NSImage of a menu item's icon, made once and released
     * with it (mygo's iconImage): AppKit draws it at 16 points, keeping its
     * aspect, from the representation fit for the display, and tints a
     * template image as the menu's text; null for what AppKit cannot read.
     */
    public function iconImage(\MyPHP\MenuIcon $ic): mixed
    {
        return $ic->native(static function () use ($ic) {
            $data = ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $ic->png, strlen($ic->png));
            $img = ObjC::msg('p:p', ObjC::alloc('NSImage'), 'initWithData:', $data);
            if ($img === null) {
                return null;
            }
            $size = ObjC::msg('z:', $img, 'size');
            if ($size->w > 0 && $size->h > 0) {
                $k = \MyPHP\MenuIcon::SIZE / max($size->w, $size->h);
                $s = ObjC::$c->new('CGSize');
                [$s->w, $s->h] = [$size->w * $k, $size->h * $k];
                ObjC::msg('v:z', $img, 'setSize:', $s);
            }
            ObjC::msg('v:b', $img, 'setTemplate:', $ic->template);
            return $img;
        }, static fn($img) => ObjC::msg('v:', $img, 'release'));
    }

    public function dialogs(): Platform\Dialogs { return $this->dialogs ??= new Dialogs; }

    public function clipboard(): Platform\Clipboard { return $this->clipboard ??= new Clipboard; }

    public function shell(): Platform\Shell { return $this->shell ??= new Shell; }

    public function screen(): Platform\Screen { return $this->screen ??= new Screen; }

    public function theme(): Platform\Theme { return $this->theme ??= new Theme; }

    public function power(): Platform\Power { return $this->power ??= new Power($this); }

    public function network(): Platform\Network { return $this->network ??= new Network($this); }

    public function nowPlaying(): Platform\NowPlaying { return $this->nowPlaying ??= new NowPlaying($this); }

    public function secrets(): Platform\Secrets { return $this->secrets ??= new Secrets; }

    public function applications(): array
    {
        return Shell::installedApps();
    }

    public function registerHotkey(int $id, string $accelerator): void
    {
        $a = Accelerator::parse($accelerator, 'Darwin');
        [$key, $mods] = [$a->key, 0];
        if ($key === '+') {
            [$key, $mods] = ['=', Carbon::SHIFT];
        }
        $code = self::KEY_CODES[$key] ?? throw new \RuntimeException("myphp: key \"{$a->key}\" cannot be used in a global shortcut");
        foreach ([Accelerator::SUPER => Carbon::CMD, Accelerator::CTRL => Carbon::CONTROL, Accelerator::ALT => Carbon::OPTION, Accelerator::SHIFT => Carbon::SHIFT] as $m => $carbon) {
            if ($a->has($m)) { $mods |= $carbon; }
        }
        $h = $this->h;
        try {
            $this->hotkeys[$id] = Carbon::register($code, $mods, static fn() => $h->hotkeyPressed($id));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("myphp: cannot register shortcut $accelerator, it may be used by another application", 0, $e);
        }
    }

    public function unregisterHotkey(int $id): void
    {
        if (isset($this->hotkeys[$id])) {
            Carbon::unregister($this->hotkeys[$id]);
            unset($this->hotkeys[$id]);
        }
    }

    public function notificationsSupported(): bool
    {
        return Notifications::supported();
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

    /** @internal App: the delegate that shows banners while the app is in front and reports clicks, attached at launch */
    public function notifications(): Notifications
    {
        return $this->notifications ??= new Notifications($this);
    }

    public function webViewAvailable(): bool
    {
        ObjC::boot(['Foundation', 'WebKit']);
        return ObjC::$c->objc_getClass('WKWebView') !== null;
    }
}
