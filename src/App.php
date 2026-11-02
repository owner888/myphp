<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
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
    /** The desktop's settings, once read until they change (preferences). */
    private static ?UI\Preferences $preferences = null;
    /** Whether the backend initialized the app, which runs from then on. */
    private static bool $initialized = false;
    /** The platform's backend, made on first use (mygo's backend()). */
    private static ?Platform\Backend $backend = null;
    private static ?Text $text = null;
    private static ActivationPolicy $policy = ActivationPolicy::Regular;
    /** Whether a quit is closing the windows, whose documents it took care of. */
    private static bool $quitting = false;
    /** @var array<string, string> directories App::setPath overrode, by PathName */
    private static array $paths = [];

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

    /** The working directory the app started in, which relative paths of its command line are relative to. */
    private static string $startDir = '';

    /**
     * Calls $fn with every URL of a scheme the app handles that it is asked
     * to open (mygo's OnOpenURL): one listed in urlSchemes in myphp.json,
     * which `myphp build` declares in the bundle, or registered with
     * registerURLScheme(). Register it before run() to get the URL the app
     * was launched with; with requestSingleInstanceLock(), the first
     * instance gets those of later ones. Returns a function that removes it.
     */
    public static function onOpenURL(Closure $fn): Closure
    {
        return Native\DeepLink::add(Native\DeepLink::$onURL, $fn);
    }

    /**
     * Calls $fn with every file opened with the app (mygo's OnOpenFile):
     * one of the types of fileAssociations in myphp.json opened from the
     * Finder, a file dropped on the Dock icon, or one passed on the command
     * line. Register it before run() to get those the app was launched
     * with. Returns a function that removes it.
     */
    public static function onOpenFile(Closure $fn): Closure
    {
        return Native\DeepLink::add(Native\DeepLink::$onFile, $fn);
    }

    /**
     * Makes the app the handler of the URLs of $scheme for the user (mygo's
     * RegisterURLScheme), as "myapp" for myapp://open?item=1: they reach
     * onOpenURL. The scheme must be listed in urlSchemes in myphp.json,
     * which registers it with the system as the app is built; this makes
     * the app its default handler when others claim it too. Throws when
     * the bundle does not declare it or Launch Services refuses.
     */
    public static function registerURLScheme(string $scheme): void
    {
        Native\DeepLink::register($scheme);
    }

    /** Undoes registerURLScheme() (mygo's UnregisterURLScheme): not on macOS, where the scheme belongs to the bundle; it throws. */
    public static function unregisterURLScheme(string $scheme): void
    {
        throw new RuntimeException('myphp: unregisterURLScheme is not supported on macOS: the scheme belongs to the bundle');
    }

    /** Whether the system opens URLs of $scheme with this app (mygo's IsURLSchemeRegistered). */
    public static function isURLSchemeRegistered(string $scheme): bool
    {
        return Native\DeepLink::isRegistered($scheme);
    }

    /**
     * Makes sure a single instance of the app runs (mygo's
     * RequestSingleInstanceLock): true in the first instance; in a later
     * one, false after handing its command line and working directory to
     * the first (onSecondInstance), and that one should then exit:
     *
     *     if (!App::requestSingleInstanceLock()) { exit; }
     *
     * The lock is named after the app's name and, in a bundle, its
     * identifier, and released as the app quits.
     */
    public static function requestSingleInstanceLock(): bool
    {
        return Native\SingleInstance::request();
    }

    /** Calls $fn(list<string> $args, string $workingDir) in the first instance when another started and called requestSingleInstanceLock() (mygo's OnSecondInstance): apps usually focus their main window. Returns a function that removes it. */
    public static function onSecondInstance(Closure $fn): Closure
    {
        return Native\DeepLink::add(Native\SingleInstance::$handlers, $fn);
    }

    /**
     * Records $path as a document the user opened (mygo's
     * AddRecentDocument): it goes first in recentDocuments(), and the
     * system offers it again in the app's Dock menu, whose choice opens it
     * through onOpenFile. Call it whenever the app opens a document or
     * folder, also one the user chose with a file dialog. A relative path
     * is resolved against the working directory.
     */
    public static function addRecentDocument(string $path): void
    {
        Native\RecentDocuments::add($path);
    }

    /** Empties recentDocuments() and the app's recent documents of the system (mygo's ClearRecentDocuments). */
    public static function clearRecentDocuments(): void
    {
        Native\RecentDocuments::clear();
    }

    /** The documents the app opened lately, newest first, up to ten, also in earlier runs (mygo's RecentDocuments): as in AppKit, files that no longer exist are left out, and come back if they do again. @return list<string> */
    public static function recentDocuments(): array
    {
        return Native\RecentDocuments::list();
    }

    /**
     * Makes the app start when the user logs in, or stops it (mygo's
     * SetOpenAtLogin), as a setting of the app: on macOS 13 and later the
     * app becomes a login item of the user (System Settings > General >
     * Login Items), on macOS 12 a launch agent starts it. It needs an app
     * bundle, and throws without one, or when the user must allow it.
     */
    public static function setOpenAtLogin(bool $open): void
    {
        Native\Login::set($open);
    }

    /** Whether the app starts when the user logs in (mygo's OpenAtLogin). */
    public static function openAtLogin(): bool
    {
        return Native\Login::enabled();
    }

    /** Whether the system started the app because the user logged in (mygo's WasOpenedAtLogin), as to start it in the background. */
    public static function wasOpenedAtLogin(): bool
    {
        return Native\Login::$openedAtLogin;
    }

    /** @var array<int, Closure(QuitEvent): void> */
    private static array $onBeforeQuit = [], $onWillQuit = [];
    /** @var array<int, Closure(bool): void> */
    private static array $onActivate = [];
    /** @var array<int, Closure> */
    private static array $onReady = [], $onWindowAllClosed = [], $onDidBecomeActive = [], $onDidResignActive = [];
    /** @var array<int, Closure(Window): void> */
    private static array $onWindowCreated = [];

    private static function listen(array &$list, Closure $fn): Closure
    {
        $k = self::$nextQuit++;
        $list[$k] = $fn;
        return function () use (&$list, $k): void { unset($list[$k]); };
    }

    /**
     * Calls $fn(QuitEvent) as the app is asked to quit, by App::quit(), the
     * Quit menu item or the system, before it quits (mygo's OnBeforeQuit):
     * QuitEvent::cancel() keeps it running. Documents use it to save or ask
     * first. Returns a function that removes it.
     */
    public static function onBeforeQuit(Closure $fn): Closure
    {
        return self::listen(self::$onBeforeQuit, $fn);
    }

    /** Calls $fn(QuitEvent) once the windows closed as the app quits, right before it does (mygo's OnWillQuit): QuitEvent::cancel() keeps it running. Returns a function that removes it. */
    public static function onWillQuit(Closure $fn): Closure
    {
        return self::listen(self::$onWillQuit, $fn);
    }

    /**
     * @internal the quit sequence (mygo's prepareQuit), and whether the app
     * may quit: the onBeforeQuit listeners, then each window is closed as
     * the user would close it, whose 'close' listeners may keep it, then
     * the onWillQuit listeners.
     */
    public static function shouldQuit(): bool
    {
        if (self::$quitting) {
            return false;
        }
        $e = new QuitEvent;
        foreach (self::$onBeforeQuit as $fn) {
            $fn($e);
        }
        if ($e->canceled) {
            return false;
        }
        self::$quitting = true;
        foreach (Window::all() as $w) {
            $w->close();
            if (!$w->isDestroyed()) {
                self::$quitting = false;
                return false;
            }
        }
        $will = new QuitEvent;
        foreach (self::$onWillQuit as $fn) {
            $fn($will);
        }
        self::$quitting = false; // the windows are closed: the app terminates, or stays as onWillQuit asked
        return !$will->canceled;
    }

    /** @internal whether a quit is under way, which closes the windows: documents were taken care of before */
    public static function quitting(): bool
    {
        return self::$quitting;
    }

    /** @internal the last window closed (mygo's lastWindowClosed): the onWindowAllClosed listeners, or without any, quitting */
    public static function lastWindowClosed(): void
    {
        if (self::$quitting) {
            return;
        }
        if (self::$onWindowAllClosed !== []) {
            foreach (self::$onWindowAllClosed as $fn) {
                $fn();
            }
            return;
        }
        self::quit();
    }

    /** Calls $fn when the last window closed, unless the app is quitting (mygo's OnWindowAllClosed): without listeners the app quits; one keeps it running, as Mac apps usually do. Returns a function that removes it. */
    public static function onWindowAllClosed(Closure $fn): Closure
    {
        return self::listen(self::$onWindowAllClosed, $fn);
    }

    /** Calls $fn once the app finished launching (mygo's OnReady), before the whenReady() callbacks. Returns a function that removes it. */
    public static function onReady(Closure $fn): Closure
    {
        return self::listen(self::$onReady, $fn);
    }

    /** Calls $fn when the app becomes the active one (mygo's OnDidBecomeActive). Returns a function that removes it. */
    public static function onDidBecomeActive(Closure $fn): Closure
    {
        return self::listen(self::$onDidBecomeActive, $fn);
    }

    /** Calls $fn when the app stops being the active one (mygo's OnDidResignActive). Returns a function that removes it. */
    public static function onDidResignActive(Closure $fn): Closure
    {
        return self::listen(self::$onDidResignActive, $fn);
    }

    /** Calls $fn(Window) for every new window (mygo's OnWindowCreated). Returns a function that removes it. */
    public static function onWindowCreated(Closure $fn): Closure
    {
        return self::listen(self::$onWindowCreated, $fn);
    }

    /** @internal a window was created */
    public static function windowCreated(Window $w): void
    {
        foreach (self::$onWindowCreated as $fn) {
            $fn($w);
        }
    }

    /** Calls $fn(bool $hasVisibleWindows) when the user activates the app again, by clicking its Dock icon or opening it while it runs (mygo's OnActivate): an app without windows usually opens one. Returns a function that removes it. */
    public static function onActivate(Closure $fn): Closure
    {
        return self::listen(self::$onActivate, $fn);
    }

    /** Calls $fn as the app quits, after its windows' state is saved (mygo's OnQuit). Returns a function that removes it. */
    public static function onQuit(Closure $fn): Closure
    {
        return self::listen(self::$onQuit, $fn);
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
        self::$startDir = (string)getcwd();
        Native\Login::takeArg();
        if (($out = getenv('MYPHP_GENERATE')) !== false && $out !== '') {
            file_put_contents($out, TypeScript::generate());
            return;
        }
        $b = self::backend();
        $b->init(self::$policy);
        self::$initialized = true;
        if (\MyPHP\Theme::source() !== ThemeSource::System) {
            \MyPHP\Theme::apply(); // the appearance setSource() asked for before
        }
        $b->run();
    }

    /** @internal the backend finished launching the app (mygo's handleReady) */
    public static function ready(): void
    {
        self::$ready = true;
        Locales::start(); // the language the app shows and the user's formats, before the menus and windows show them
        self::installMenu(self::$menu ?? Menu::default());
        if (Dock::menu() !== null) {
            Dock::setMenu(Dock::menu());
        }
        if (self::$policy === ActivationPolicy::Regular) { // a plain php does not come to the front on its own
            self::backend()->app()->activate();
        }
        foreach (self::$onReady as $fn) {
            $fn();
        }
        $callbacks = self::$whenReady;
        self::$whenReady = [];
        foreach ($callbacks as $fn) {
            $fn();
        }
        // After the windows the app opens when ready, which URLs may target.
        self::later(function (): void {
            Native\DeepLink::deliverArgs(array_slice($_SERVER['argv'] ?? [], 1), self::$startDir);
            Native\Docs\Registry::start(); // once the files the app was started with are in
        });
    }

    /** @internal the app is about to end (mygo's finish): the windows' state saved, the clipboard's data kept, the onQuit listeners, a relaunch */
    public static function terminating(): void
    {
        Native\WindowStates::save();
        try {
            Clipboard::flush(); // the app's data stays on the clipboard once it quit
        } catch (\Throwable $e) {
            Logger::warn($e->getMessage(), 'clipboard');
        }
        $quit = self::$onQuit;
        self::$onQuit = [];
        foreach ($quit as $fn) {
            $fn();
        }
        if (self::$relaunch) {
            self::relaunchNow(); // after onQuit, which releases what the app holds
        }
    }

    /** @internal the app became the active one, or stopped being it */
    public static function activeChanged(bool $active): void
    {
        foreach ($active ? self::$onDidBecomeActive : self::$onDidResignActive as $fn) { $fn(); }
    }

    /** @internal the user activated the app again (mygo's Activated) */
    public static function activated(bool $hasVisibleWindows): void
    {
        foreach (self::$onActivate as $fn) {
            $fn($hasVisibleWindows);
        }
    }

    /**
     * The theme windows draw with: light or dark after the system's
     * appearance, with its accent color, unless setTheme() fixed one.
     */
    public static function theme(): Theme
    {
        if (self::$theme === null) {
            self::$theme = !self::$initialized ? Theme::light() : self::systemTheme();
        }
        return self::$theme;
    }

    /** The settings of the desktop that controls follow (mygo's Preferences): the accent color, Reduce Motion, Increase Contrast; read once until they change. */
    public static function preferences(): UI\Preferences
    {
        if (self::$preferences === null) {
            if (!self::$initialized) {
                return self::$preferences = new UI\Preferences();
            }
            $p = \MyPHP\Theme::preferences();
            self::$preferences = new UI\Preferences($p->accent, $p->reduceMotion, $p->highContrast, $p->textScale);
        }
        return self::$preferences;
    }

    /** Whether the desktop asks for less motion (System Settings' Reduce Motion, mygo's Preferences.ReduceMotion): what animates goes to its target at once (Context::animateTo). */
    public static function reduceMotion(): bool
    {
        return self::preferences()->reduceMotion;
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
        $dark = self::backend()->theme()->isDark();
        return ($dark ? Theme::dark() : Theme::light())->follow(self::preferences());
    }

    /** @internal the appearance or the desktop's settings changed (mygo's ThemeChanged): what was read of them is forgotten, the theme and the windows follow, then Theme's listeners hear it */
    public static function systemChanged(): void
    {
        self::$preferences = null;
        \MyPHP\Theme::forget();
        self::themeChanged();
        foreach (Window::all() as $w) {
            foreach ($w->pages() as $p) { $p->settingsChanged(false); } // the accent and the accessibility settings, which media queries do not tell pages
        }
        \MyPHP\Theme::changed();
    }

    private static function themeChanged(): void
    {
        if (!self::$themeFixed) {
            self::$theme = !self::$initialized ? null : self::systemTheme();
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

    /** Installs the menu bar again: its roles' labels in the language the app shows, its Open Recent menus' documents as they are now. */
    public static function relabelMenus(): void
    {
        if (self::$ready) { self::installMenu(self::$menu ?? Menu::default()); }
    }

    /** @internal installs the menus in use again once their items changed (mygo's refreshMenus): the menu bar and the Dock's */
    public static function refreshMenus(): void
    {
        if (self::$ready) {
            self::installMenu(self::$menu ?? Menu::default());
            Dock::setMenu(Dock::menu());
        }
    }

    /** The menu bar; Menu::default() unless set (none off macOS, but for an app with documents). Can be set before or after run(). */
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

    /** The menu bar installed, null before the app is ready. */
    private static ?Menu $installed = null;

    /**
     * Serves the files of the directory $dir as the app's frontend at
     * myphp://localhost/ (mygo's SetFrontend), which windows load with URLs
     * without a scheme ("/", "/settings"); paths without an extension that
     * match no file serve index.html, for client-side routing. `myphp
     * build` ships myphp.json's frontendDist in the bundle, which the app
     * serves without this; null returns to it.
     */
    public static function setFrontend(?string $dir): void
    {
        if ($dir !== null && !is_dir($dir)) {
            throw new \InvalidArgumentException("myphp: $dir is not a directory");
        }
        Native\Frontend::$dir = $dir;
    }

    /** @internal the menu bar installed, whose items are built: setMenu()'s, or Menu::default()'s */
    public static function menuBar(): ?Menu
    {
        return self::$installed;
    }

    private static function installMenu(?Menu $menu): void
    {
        self::backend()->setApplicationMenu($menu?->snapshot());
        self::$installed = $menu;
        Native\Docs\Registry::refreshMenus();
    }

    /**
     * Runs the event loop until $until() is true, for a call that must
     * return a result an asynchronous API delivers later (a sheet's
     * completion handler). Events keep flowing meanwhile.
     */
    public static function wait(Closure $until): void
    {
        while (!$until()) {
            self::backend()->step();
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

    /**
     * A well-known directory (mygo's Path), as where the app keeps its
     * data, App::path(PathName::UserData): what setPath() set, else the
     * Mac's; UserData, Cache and Logs are made on first use. Throws when
     * one cannot be made.
     */
    public static function path(PathName $name): string
    {
        if (isset(self::$paths[$name->value])) {
            return self::$paths[$name->value];
        }
        $home = (string)getenv('HOME');
        $lib = "$home/Library";
        [$dir, $make] = PHP_OS_FAMILY === 'Linux' ? self::xdgPath($name, $home) : match ($name) {
            PathName::Home => [$home, false],
            PathName::AppData => ["$lib/Application Support", false],
            PathName::UserData => ["$lib/Application Support/" . self::name(), true],
            PathName::Cache => ["$lib/Caches/" . self::name(), true],
            PathName::Logs => ["$lib/Logs/" . self::name(), true],
            PathName::Temp => [rtrim(sys_get_temp_dir(), '/'), false],
            PathName::Resources => [($app = Native\Bundle::path()) !== null ? "$app/Contents/Resources" : dirname((string)realpath($_SERVER['argv'][0] ?? '.')) . '/resources', false],
            PathName::Exe => [Native\Bundle::command()[0], false],
            PathName::Desktop => ["$home/Desktop", false],
            PathName::Documents => ["$home/Documents", false],
            PathName::Downloads => ["$home/Downloads", false],
            PathName::Music => ["$home/Music", false],
            PathName::Pictures => ["$home/Pictures", false],
            PathName::Videos => ["$home/Movies", false],
        };
        if ($make && !is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("myphp: cannot create $dir");
        }
        return $dir;
    }

    /**
     * A well-known directory on Linux, as XDG has them (mygo's defaultPath
     * there): the user's configuration directory holds the app's data and
     * logs, the user dirs of user-dirs.dirs the folders. @return array{0: string, 1: bool} the path, and whether path() makes it
     */
    private static function xdgPath(PathName $name, string $home): array
    {
        $config = (string)(getenv('XDG_CONFIG_HOME') ?: "$home/.config");
        $cache = (string)(getenv('XDG_CACHE_HOME') ?: "$home/.cache");
        $userDir = function (string $key, string $folder) use ($config, $home): string {
            foreach (is_file("$config/user-dirs.dirs") ? (array)file("$config/user-dirs.dirs", FILE_IGNORE_NEW_LINES) : [] as $line) {
                [$k, $v] = array_pad(explode('=', trim($line), 2), 2, null);
                if ($k === $key && $v !== null) {
                    return str_replace('$HOME', $home, trim($v, '"'));
                }
            }
            return "$home/$folder";
        };
        return match ($name) {
            PathName::Home => [$home, false],
            PathName::AppData => [$config, false],
            PathName::UserData => ["$config/" . self::name(), true],
            PathName::Cache => ["$cache/" . self::name(), true],
            PathName::Logs => ["$config/" . self::name() . '/logs', true],
            PathName::Temp => [rtrim(sys_get_temp_dir(), '/'), false],
            PathName::Resources => [Native\Bundle::resources() ?? dirname((string)realpath($_SERVER['argv'][0] ?? '.')) . '/resources', false],
            PathName::Exe => [Native\Bundle::path() !== null ? Native\Bundle::command()[0] : (string)realpath('/proc/self/exe'), false],
            PathName::Desktop => [$userDir('XDG_DESKTOP_DIR', 'Desktop'), false],
            PathName::Documents => [$userDir('XDG_DOCUMENTS_DIR', 'Documents'), false],
            PathName::Downloads => [$userDir('XDG_DOWNLOAD_DIR', 'Downloads'), false],
            PathName::Music => [$userDir('XDG_MUSIC_DIR', 'Music'), false],
            PathName::Pictures => [$userDir('XDG_PICTURES_DIR', 'Pictures'), false],
            PathName::Videos => [$userDir('XDG_VIDEOS_DIR', 'Videos'), false],
        };
    }

    /** Overrides a directory path() returns (mygo's SetPath). */
    public static function setPath(PathName $name, string $path): void
    {
        self::$paths[$name->value] = $path;
    }

    /**
     * Keeps the states of windows (WindowOptions::$stateKey) in $store,
     * such as the app's own database, instead of window-state.json in
     * PathName::UserData; null restores the file (mygo's
     * SetWindowStateStore). Call it before the first window with a state
     * key is created.
     */
    public static function setWindowStateStore(?WindowStateStore $store): void
    {
        Native\WindowStates::$store = $store;
    }

    /** @var list<Closure> */
    private static array $later = [];

    /** Runs $fn on the next turn of the run loop, after the current event; events in between are handled first. */
    public static function later(Closure $fn): void
    {
        self::$later[] = $fn;
        if (count(self::$later) === 1) {
            self::backend()->signal();
        }
    }

    /** @internal runs what later() queued (mygo's loop.drain) */
    public static function dispatch(): void
    {
        $queue = self::$later;
        self::$later = [];
        foreach ($queue as $fn) {
            $fn();
        }
    }

    /** Runs $fn once, $seconds from now, on the main thread (mygo's After). */
    public static function after(float $seconds, Closure $fn): void
    {
        self::backend()->timer($seconds, $fn);
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
        $max = $opts->maxEntries === 0 ? (PHP_OS_FAMILY === 'Darwin' ? 65536 : 8192) : $opts->maxEntries;
        return new FileWatcher(Native\FileWatch\Fs::realpath($path), $debounce, $maxDelay, $opts->recursive, $opts->ignore, $max, $opts->hintsOnly);
    }

    /** @internal whether run() is running the event loop, which a wait must keep turning */
    public static function running(): bool
    {
        return self::$initialized;
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

    /** Whether windows can show web pages (mygo's WebViewAvailable): WKWebView is part of macOS, so wherever the app runs. */
    public static function webViewAvailable(): bool
    {
        return self::$initialized && self::backend()->webViewAvailable();
    }

    /**
     * Whether the app runs in development (mygo's IsDev): true unless it
     * was built for production with `myphp build` (its Info.plist's
     * MyPHPProduction; `myphp build -debug` keeps development features), or
     * MYPHP_ENV=production is set. Development builds enable the inspector
     * and the web inspector by default.
     */
    public static function isDev(): bool
    {
        if ((Native\Bundle::plist()['MyPHPProduction'] ?? false) === true) {
            return false;
        }
        return getenv('MYPHP_ENV') !== 'production';
    }

    /**
     * Quits the app, then starts it again (mygo's Relaunch): the bundle
     * through Launch Services, as the Dock opens it; an update installed
     * (Update::install), once it has replaced the bundle as the app exited.
     * Under `myphp dev`, myphp dev starts it again. Nothing happens when
     * quitting is canceled.
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
        if (Update::relaunchStaged()) {
            return; // the update's helper opens the new version once this one exited
        }
        $bundle = Native\Bundle::path();
        $rest = array_slice($_SERVER['argv'] ?? [], 1);
        if ($bundle !== null && PHP_OS_FAMILY === 'Darwin') { // through Launch Services, as the Dock (mygo's openBundle): a child of this process stays in its coalition, whose other processes loginwindow ends with an app the new one started
            exec('/usr/bin/open -n ' . escapeshellarg($bundle) . ($rest !== [] ? ' --args ' . implode(' ', array_map('escapeshellarg', $rest)) : ''), $out, $code);
            if ($code === 0) { return; }
            Logger::warn("open -n $bundle failed ($code): starting its executable", 'app');
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

    /** Brings the app to the front, as a click on its Dock icon does (mygo's Focus). */
    public static function focus(): void
    {
        self::controller('focus')->activate();
    }

    /** Hides the app's windows, as Cmd+H does (mygo's Hide). */
    public static function hide(): void
    {
        self::controller('hide')->hide();
    }

    /** Shows the app's windows again after hide() (mygo's Show). */
    public static function show(): void
    {
        self::controller('show')->unhide();
    }

    /** Whether the app is hidden (mygo's IsHidden). */
    public static function isHidden(): bool
    {
        return self::controller('isHidden')->isHidden();
    }

    /** How the app shows in the Dock and the app switcher: Regular unless setActivationPolicy() changed it (mygo's ActivationPolicy). */
    public static function activationPolicy(): ActivationPolicy
    {
        return self::$policy;
    }

    /** Whether the app is the active one, in front (mygo's IsActive). */
    public static function isActive(): bool
    {
        return self::controller('isActive')->isActive();
    }

    /** Changes how the app shows in the Dock and the app switcher (mygo's SetActivationPolicy); before run() too, which starts it so. Regular brings it to the front. */
    public static function setActivationPolicy(ActivationPolicy $p): void
    {
        self::$policy = $p;
        if (self::$initialized) {
            $c = self::backend()->app();
            $c->setActivationPolicy($p);
            if ($p === ActivationPolicy::Regular) {
                $c->activate();
            }
        }
    }

    /** Shows $n on the app's icon; 0 clears it (mygo's SetBadgeCount). */
    public static function setBadgeCount(int $n): void
    {
        Dock::setBadge($n > 0 ? (string)$n : '');
    }

    /** Shows the standard about panel, with what $opts says in place of the bundle's (mygo's ShowAboutPanel). */
    public static function showAboutPanel(AboutPanelOptions $opts = new AboutPanelOptions): void
    {
        self::controller('showAboutPanel')->showAboutPanel($opts);
    }

    /**
     * Deletes what the app's pages stored: cookies, local and session
     * storage, IndexedDB, service workers and caches, as when the user
     * signs out (mygo's ClearBrowsingData). Open pages keep what they
     * hold in memory until they reload. It returns once WebKit is done.
     */
    public static function clearBrowsingData(): void
    {
        self::controller('clearBrowsingData')->clearBrowsingData(null, SiteData::ALL);
    }

    /** The backend's controller of the app, for $call, which needs the running app. */
    private static function controller(string $call): Platform\AppController
    {
        if (!self::$initialized) {
            throw new RuntimeException("myphp: App::$call needs the running app (App::whenReady)");
        }
        return self::backend()->app();
    }

    public static function quit(): void
    {
        if (self::$initialized) {
            self::backend()->quit();
        }
    }

    /** Ends the process with $code at once, without the quit events or asking the windows (mygo's Exit); the windows' state is saved. */
    public static function exit(int $code): never
    {
        foreach (Window::all() as $w) {
            $w->destroy();
        }
        Native\WindowStates::save();
        exit($code);
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
        return self::text()->scale();
    }

    /**
     * A window's display changed, or its content's zoom (Context::setZoom):
     * glyphs are rasterized at the largest backing scale times zoom among
     * the open windows (a window on a lesser display, or less zoomed,
     * samples them down), so a change there remakes the atlas.
     */
    public static function rescale(): void
    {
        $scale = 1.0;
        foreach (Window::all() as $w) {
            $scale = max($scale, $w->native()->backingScale() * $w->contentZoom());
        }
        $text = self::text();
        if ($scale === $text->scale()) {
            return;
        }
        $text->rescale($scale);
        self::backend()->rescaled($scale);
        foreach (Window::all() as $w) {
            $w->invalidate();
        }
    }

    /** The text engine every window measures and paints with, rasterizing at the main display's scale to begin with. */
    public static function text(): Text
    {
        return self::$text ??= new Text(self::backend()->fonts(), new Atlas(), new ImageAtlas());
    }

    /**
     * @internal the platform's backend (mygo's backend()): Cocoa on macOS, GTK on Linux.
     * Made on first use, before App::run too, as Secrets need it.
     */
    public static function backend(): Platform\Backend
    {
        return self::$backend ??= match (PHP_OS_FAMILY) {
            'Darwin' => new Platform\Darwin\Backend(new AppHandler),
            'Linux' => new Platform\Linux\Backend(new AppHandler),
            default => throw new Platform\Unsupported('myphp: ' . PHP_OS_FAMILY . ' has no backend yet'),
        };
    }
}
