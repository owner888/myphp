<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\{Appearance, CoreText, Metal, ObjC};
use MyPHP\UI\{Atlas, Text, Theme, ImageAtlas};
use RuntimeException;

/**
 * The application: one per process, as mygo's App. run() starts the native
 * event loop on the main thread and blocks; windows are created from
 * whenReady() callbacks, which run once the app has finished launching.
 */
final class App
{
    public const VERSION = '0.4.0-dev';
    /** @var list<Closure> */
    private static array $whenReady = [];
    private static bool $ready = false;
    private static bool $running = false;
    private static ?string $name = null;
    private static ?Menu $menu = null;
    private static ?Theme $theme = null;
    private static bool $themeFixed = false;
    private static $nsApp = null;
    private static ?Metal $metal = null;
    private static ?Text $text = null;
    private static float $scale = 1.0;
    /** Quit once the last window closes, as a document-less app does. */
    public static bool $quitOnLastWindowClosed = true;

    public static function whenReady(Closure $fn): void
    {
        if (self::$ready) {
            $fn();
            return;
        }
        self::$whenReady[] = $fn;
    }

    public static function isReady(): bool
    {
        return self::$ready;
    }

    /**
     * Starts the event loop and blocks until the app quits. With
     * MYPHP_GENERATE set to a path, writes the TypeScript client for the
     * bound services there instead and returns, as mygo generate does.
     */
    public static function run(): void
    {
        if (self::$running) {
            throw new RuntimeException('App::run called twice');
        }
        self::$running = true;
        if (($out = getenv('MYPHP_GENERATE')) !== false && $out !== '') {
            file_put_contents($out, TypeScript::generate());
            return;
        }
        ObjC::boot(['Foundation', 'AppKit', 'QuartzCore', 'Metal', 'MetalKit', 'CoreText', 'WebKit', 'UniformTypeIdentifiers', 'UserNotifications']);
        self::$nsApp = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
        ObjC::msg('b:l', self::$nsApp, 'setActivationPolicy:', 0); // regular app, with a Dock icon
        ObjC::defineClass('MyPHPAppDelegate', 'NSObject', [
            'observeValueForKeyPath:ofObject:change:context:' => ['v@:@@@^v', 'v(pppppp)', function ($self, $cmd, $keyPath, $object, $change, $context) {
                if (ObjC::phpStr($keyPath) === 'effectiveAppearance') {
                    self::themeChanged();
                }
            }],
            'applicationWillTerminate:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                WindowState::save();
            }],
            'applicationDidFinishLaunching:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                self::$ready = true;
                Notification::attachDelegate(); // before the click that launched the app is delivered
                self::installMenu(self::$menu ?? Menu::default());
                ObjC::msg('v:b', self::$nsApp, 'activateIgnoringOtherApps:', true);
                $callbacks = self::$whenReady;
                self::$whenReady = [];
                foreach ($callbacks as $fn) {
                    $fn();
                }
            }],
        ]);
        $delegate = ObjC::msg('p:', ObjC::alloc('MyPHPAppDelegate'), 'init');
        ObjC::msg('v:p', self::$nsApp, 'setDelegate:', $delegate);
        ObjC::msg('v:ppLp', self::$nsApp, 'addObserver:forKeyPath:options:context:', $delegate, ObjC::str('effectiveAppearance'), 0, null);
        ObjC::msg('v:', self::$nsApp, 'run');
    }

    /**
     * The theme windows draw with: light or dark after the system's
     * appearance, with its accent color, unless setTheme() fixed one.
     */
    public static function theme(): Theme
    {
        if (self::$theme === null) {
            self::$theme = self::$nsApp === null ? Theme::light() : self::systemTheme();
        }
        return self::$theme;
    }

    /** Fixes the theme; null returns to following the system. */
    public static function setTheme(?Theme $theme): void
    {
        self::$theme = $theme;
        self::$themeFixed = $theme !== null;
        self::themeChanged();
    }

    private static function systemTheme(): Theme
    {
        $dark = Appearance::isDark(self::$nsApp);
        return ($dark ? Theme::dark() : Theme::light())->withAccent(Appearance::accent(self::$nsApp));
    }

    private static function themeChanged(): void
    {
        if (!self::$themeFixed) {
            self::$theme = self::$nsApp === null ? null : self::systemTheme();
        }
        foreach (Window::all() as $w) {
            $w->themeChanged();
        }
    }

    /** The menu bar; Menu::default() unless set. Can be set before or after run(). */
    public static function setMenu(Menu $menu): void
    {
        self::$menu = $menu;
        if (self::$ready) {
            self::installMenu($menu);
        }
    }

    public static function menu(): ?Menu
    {
        return self::$menu;
    }

    private static function installMenu(Menu $menu): void
    {
        ObjC::msg('v:p', self::$nsApp, 'setMainMenu:', $menu->build());
    }

    /**
     * Runs the event loop until $until() is true, for a call that must
     * return a result an asynchronous API delivers later (a sheet's
     * completion handler). Events keep flowing meanwhile.
     */
    public static function wait(Closure $until): void
    {
        // NSRunLoop alone does not dispatch AppKit events (NSApp's run does),
        // so pull and send them here, as a modal session would.
        $mode = ObjC::str('kCFRunLoopDefaultMode');
        while (!$until()) {
            $date = ObjC::msg('p:d', ObjC::cls('NSDate'), 'dateWithTimeIntervalSinceNow:', 0.05);
            $event = ObjC::msg('p:Lppb', self::$nsApp, 'nextEventMatchingMask:untilDate:inMode:dequeue:', PHP_INT_MAX, $date, $mode, true);
            if ($event !== null) {
                ObjC::msg('v:p', self::$nsApp, 'sendEvent:', $event);
            }
            ObjC::msg('v:', self::$nsApp, 'updateWindows');
        }
    }

    /** Calls $fn with the notification's id when the user clicks one, also one shown by an earlier run. */
    public static function onNotificationClick(Closure $fn): void
    {
        self::$onNotificationClick[] = $fn;
    }

    private static array $onNotificationClick = [];

    public static function notificationClicked(string $id): void
    {
        foreach (self::$onNotificationClick as $fn) {
            $fn($id);
        }
    }

    /** Where the app keeps its data: ~/Library/Application Support/<name>, made on first use. */
    public static function userDataPath(): string
    {
        $dir = getenv('HOME') . '/Library/Application Support/' . self::name();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException("cannot create $dir");
        }
        return $dir;
    }

    /** @var list<Closure> */
    private static array $later = [];
    private static $laterTarget = null;

    /** Runs $fn on the next turn of the run loop, after the current event; events in between are handled first. */
    public static function later(Closure $fn): void
    {
        if (self::$laterTarget === null) {
            ObjC::defineClass('MyPHPLater', 'NSObject', [
                'tick:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) {
                    $queue = self::$later;
                    self::$later = [];
                    foreach ($queue as $fn) {
                        $fn();
                    }
                }],
                'fire:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $number) {
                    $id = ObjC::msg('l:', $number, 'longValue');
                    $fn = self::$timers[$id] ?? null;
                    unset(self::$timers[$id]);
                    $fn?->__invoke();
                }],
            ]);
            self::$laterTarget = ObjC::msg('p:', ObjC::alloc('MyPHPLater'), 'init');
        }
        self::$later[] = $fn;
        if (count(self::$later) === 1) {
            ObjC::msg('v:ppd', self::$laterTarget, 'performSelector:withObject:afterDelay:', ObjC::sel('tick:'), null, 0.0);
        }
    }

    /** @var array<int, Closure> pending timers by id */
    private static array $timers = [];
    private static int $nextTimer = 0;

    /** Runs $fn once, $seconds from now, on the main thread (mygo's After). */
    public static function after(float $seconds, Closure $fn): void
    {
        self::later(fn() => null); // defines MyPHPLater
        $id = ++self::$nextTimer;
        self::$timers[$id] = $fn;
        ObjC::msg('v:ppd', self::$laterTarget, 'performSelector:withObject:afterDelay:', ObjC::sel('fire:'), ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', $id), $seconds);
    }

    /** Opens a URL (http, mailto, a file…) with the system's handler, as mygo's OpenURL. */
    public static function openURL(string $url): void
    {
        $nsurl = ObjC::msg('p:p', ObjC::cls('NSURL'), 'URLWithString:', ObjC::str($url));
        if ($nsurl === null) {
            throw new RuntimeException("not a URL: $url");
        }
        ObjC::msg('b:p', ObjC::msg('p:', ObjC::cls('NSWorkspace'), 'sharedWorkspace'), 'openURL:', $nsurl);
    }

    /** Brings the app to the front, as a click on its Dock icon does. */
    public static function activate(): void
    {
        ObjC::msg('v:b', self::$nsApp, 'activateIgnoringOtherApps:', true);
    }

    public static function quit(): void
    {
        ObjC::msg('v:p', self::$nsApp, 'terminate:', null);
    }

    public static function name(): string
    {
        return self::$name ?? pathinfo($_SERVER['argv'][0] ?? 'MyPHP', PATHINFO_FILENAME);
    }

    /** Overrides the application name; call it before run(). */
    public static function setName(string $name): void
    {
        self::$name = $name;
    }

    /** The display's backing scale text is rasterized at. */
    public static function scale(): float
    {
        self::metal();
        return self::$scale;
    }

    /**
     * A window's display changed: glyphs are rasterized at the largest
     * backing scale among the open windows (a window on a lesser display
     * samples them down), so a change there remakes the atlas.
     */
    public static function rescale(): void
    {
        $scale = 1.0;
        foreach (Window::all() as $w) {
            $scale = max($scale, ObjC::msg('d:', $w->native(), 'backingScaleFactor'));
        }
        if ($scale === self::$scale) {
            return;
        }
        self::$scale = $scale;
        self::$text->rescale($scale);
        self::$metal->setAtlasScale($scale);
        foreach (Window::all() as $w) {
            $w->update();
        }
    }

    /** The one Metal device, shared by every window. */
    public static function metal(): Metal
    {
        if (self::$metal === null) {
            self::$scale = ObjC::msg('d:', ObjC::msg('p:', ObjC::cls('NSScreen'), 'mainScreen'), 'backingScaleFactor');
            self::$text = new Text(new CoreText(self::$scale), new Atlas(), new ImageAtlas());
            self::$metal = new Metal();
            self::$metal->attachAtlas(self::$text->atlas, self::$scale);
            self::$metal->attachImages(self::$text->images);
        }
        return self::$metal;
    }

    /** The text engine every window measures and paints with. */
    public static function text(): Text
    {
        self::metal();
        return self::$text;
    }
}
