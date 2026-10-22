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
    public const VERSION = '0.85.0';
    /** @var list<Closure> */
    private static array $whenReady = [];
    private static bool $ready = false;
    private static bool $running = false;
    private static ?string $name = null;
    private static ?string $version = null;
    /** App::relaunch() asked for the app to start again once it quit. */
    private static bool $relaunch = false;
    private static ?Menu $menu = null;
    private static ?Theme $theme = null;
    private static bool $themeFixed = false;
    /** Whether the desktop asks for less motion, once read (reduceMotion). */
    private static ?bool $reduceMotion = null;
    private static $nsApp = null;
    private static ?Metal $metal = null;
    private static ?Text $text = null;
    private static float $scale = 1.0;
    /** Quit once the last window closes, as a document-less app does. */
    public static bool $quitOnLastWindowClosed = true;

    /** @var array<int, Closure> */
    private static array $onQuit = [];
    private static int $nextQuit = 0;

    /**
     * Adds plugins to the app (mygo's Use): binds each one's service as
     * "plugin:<name>" and runs its setup. Call it before App::run, like
     * Ipc::bind. It throws when a plugin is invalid, already used, or its
     * setup throws.
     */
    public static function use(Plugin ...$plugins): void
    {
        foreach ($plugins as $p) {
            if (preg_match('/^[a-z][a-z0-9-]*$/', $p->name) !== 1) {
                throw new RuntimeException("myphp: plugin name \"{$p->name}\" must be lowercase letters, digits and hyphens, starting with a letter");
            }
            if ($p->name === 'myphp' || $p->name === 'mygo') {
                throw new RuntimeException("myphp: plugin name \"{$p->name}\" is reserved");
            }
            if (Ipc::pluginUsed($p->name)) {
                throw new RuntimeException("myphp: plugin {$p->name} is already used");
            }
            try {
                Ipc::usePlugin($p->name, $p->service);
            } catch (RuntimeException $e) {
                throw new RuntimeException("myphp: plugin {$p->name}: " . preg_replace('/^Ipc: /', '', $e->getMessage()), 0, $e);
            }
            if ($p->setup !== null) {
                try {
                    ($p->setup)();
                } catch (\Throwable $e) {
                    throw new RuntimeException("myphp: plugin {$p->name}: {$e->getMessage()}", 0, $e);
                }
            }
        }
    }

    /** Calls $fn as the app quits, after its windows' state is saved (mygo's OnQuit). Returns a function that removes it. */
    public static function onQuit(Closure $fn): Closure
    {
        $k = self::$nextQuit++;
        self::$onQuit[$k] = $fn;
        return function () use ($k): void { unset(self::$onQuit[$k]); };
    }

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
                $quit = self::$onQuit;
                self::$onQuit = [];
                foreach ($quit as $fn) {
                    $fn();
                }
                if (self::$relaunch) {
                    self::relaunchNow(); // after onQuit, which releases what the app holds
                }
            }],
            'localeChanged:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) { Locales::changedFromSystem(); }],
            'displayOptionsChanged:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) { // Reduce Motion turned on or off, as mygo's preferences change
                self::$reduceMotion = null;
                foreach (Window::all() as $w) { $w->invalidate(); }
            }],
            'applicationDidFinishLaunching:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                self::$ready = true;
                Locales::start(); // the language the app shows and the user's formats, before the menus and windows show them
                ObjC::msg('v:pppp', ObjC::msg('p:', ObjC::cls('NSNotificationCenter'), 'defaultCenter'), 'addObserver:selector:name:object:', $self, ObjC::sel('localeChanged:'), ObjC::str('NSCurrentLocaleDidChangeNotification'), null);
                $workspace = ObjC::msg('p:', ObjC::cls('NSWorkspace'), 'sharedWorkspace');
                ObjC::msg('v:pppp', ObjC::msg('p:', $workspace, 'notificationCenter'), 'addObserver:selector:name:object:', $self, ObjC::sel('displayOptionsChanged:'), ObjC::str('NSWorkspaceAccessibilityDisplayOptionsDidChangeNotification'), null);
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

    /** Whether the desktop asks for less motion (System Settings' Reduce Motion, mygo's Preferences.ReduceMotion): what animates goes to its target at once (Context::animateTo). */
    public static function reduceMotion(): bool
    {
        return self::$reduceMotion ??= self::$nsApp !== null && (bool)ObjC::msg('b:', ObjC::msg('p:', ObjC::cls('NSWorkspace'), 'sharedWorkspace'), 'accessibilityDisplayShouldReduceMotion');
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

    /** The language the app shows (Locales::locale), a tag of the languages it is written in. */
    public static function locale(): string { return Locales::locale(); }

    /** Makes the app show the language of $tag, or '' to follow the user's languages again (Locales::setLocale). */
    public static function setLocale(string $tag): void { Locales::setLocale($tag); }

    /** The languages the app is written in, its own first (Locales::setLanguages). */
    public static function setLanguages(string ...$tags): void { Locales::setLanguages(...$tags); }

    /** @return list<string> */
    public static function languages(): array { return Locales::languages(); }

    /** The languages the user reads, most preferred first (Locales::preferredLanguages). @return list<string> */
    public static function preferredLanguages(): array { return Locales::preferredLanguages(); }

    public static function onLocaleChanged(Closure $fn): Closure { return Locales::onLocaleChanged($fn); }

    /** The user's regional formats (Locales::region). */
    public static function region(): Region { return Locales::region(); }

    public static function onRegionChanged(Closure $fn): Closure { return Locales::onRegionChanged($fn); }

    /** Says the menus' items in the language the app shows: the menu bar is installed again with its roles' labels in it. */
    public static function relabelMenus(): void
    {
        if (self::$ready) { self::installMenu(self::$menu ?? Menu::default()); }
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

    /**
     * Watches the file or directory at $path, and with $opts->recursive the
     * directories in it, but not those symlinks point to (mygo's
     * WatchFiles). It returns once everything is watched; what is already
     * there is not reported. Take the changes with FileWatcher::next().
     *
     * A file is watched through its directory, so that a file replaced, as
     * editors save, is reported as written, one removed as removed, and one
     * that appears again as created. When the directory itself (or the
     * directory of the file) is removed, renamed or replaced, the watch
     * reports a FileRemove of "." and ends: next() returns null. The app
     * stops its watches when it quits.
     */
    public static function watchFiles(string $path, FileWatchOptions $opts = new FileWatchOptions): FileWatcher
    {
        if ($path === '') {
            throw new \InvalidArgumentException('myphp: empty watch path');
        }
        if ($opts->maxEntries < 0) {
            throw new \InvalidArgumentException('myphp: negative watch limit');
        }
        $debounce = $opts->debounce == 0 ? 0.05 : max($opts->debounce, 0.0);
        $maxDelay = max($opts->maxDelay == 0 ? 0.5 : $opts->maxDelay, $debounce);
        return new FileWatcher(Native\FileWatch\Fs::realpath($path), $debounce, $maxDelay, $opts->recursive, $opts->ignore, $opts->maxEntries === 0 ? 8192 : $opts->maxEntries);
    }

    /** @internal whether run() is running the event loop, which a wait must keep turning */
    public static function running(): bool
    {
        return self::$nsApp !== null;
    }

    /**
     * The version of the app (mygo's App.Version): that of the bundle it
     * runs from (myphp.json's "version", which `myphp build` writes in
     * Info.plist), or what setVersion() set; "" when neither says.
     */
    public static function version(): string
    {
        return self::$version ?? Native\Bundle::info('CFBundleShortVersionString') ?? '';
    }

    /** Overrides the version of the app (mygo's SetVersion). */
    public static function setVersion(string $version): void
    {
        self::$version = $version;
    }

    /** Whether the app runs from an app bundle that `myphp build` made, rather than from its project (mygo's IsPackaged). */
    public static function isPackaged(): bool
    {
        return Native\Bundle::path() !== null;
    }

    /** Whether the app runs from its project, as `myphp dev` and `php app.php` run it, not from a bundle `myphp build` made (mygo's IsDev). */
    public static function isDev(): bool
    {
        return !self::isPackaged();
    }

    /**
     * Quits the app, then starts it again (mygo's Relaunch): the bundle's
     * executable, which an update may have replaced. Under `myphp dev`,
     * myphp dev starts it again. Nothing happens when quitting is
     * canceled.
     */
    public static function relaunch(): void
    {
        self::$relaunch = true;
        self::quit();
    }

    private static function relaunchNow(): void
    {
        if (getenv('MYPHP_DEV') === '1') {
            exit(self::DEV_RELAUNCH_CODE); // myphp dev starts it again
        }
        $cmd = implode(' ', array_map('escapeshellarg', Native\Bundle::command()));
        exec("nohup $cmd > /dev/null 2>&1 &");
    }

    /** The exit code with which an app under `myphp dev` asks to be started again (mygo's devRelaunchCode). */
    public const DEV_RELAUNCH_CODE = 75;

    /**
     * Runs $fn in a fiber of its own, as a goroutine of mygo's: what waits
     * in it, as Updater::check() and Update::install() do, suspends the
     * fiber and lets the app run until the run loop resumes it. It runs at
     * once, until its first wait.
     */
    public static function go(Closure $fn): void
    {
        Native\Fibers::go($fn);
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
            $scale = max($scale, ObjC::msg('d:', $w->nativeHandle(), 'backingScaleFactor'));
        }
        if ($scale === self::$scale) {
            return;
        }
        self::$scale = $scale;
        self::$text->rescale($scale);
        self::$metal->setAtlasScale($scale);
        foreach (Window::all() as $w) {
            $w->invalidate();
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
