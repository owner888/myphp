<?php
// A video player as IINA: a Matroska, WebM, MP4 or MOV file decoded and
// shown by the system (Media\Player), in a window of the controls
// Context::videoPlayer builds over it (the title bar, the control bar,
// the messages, the sidebars of quick settings and of the playlist,
// picture in picture, IINA's keys). It starts at the welcome window
// (Context::videoPlayerWelcome: the film to resume, the recent ones), or
// the open panel, or nothing, as Settings > General says. File > Open, or
// a film dropped on a window, opens a film, the films of its folder its
// playlist, as one opened from the Finder; File > Open URL a film over
// http(s); File > Playback History the films played
// (Context::videoPlayerHistory); a subtitle file dropped on a window
// loads over its film. Each film in a window of its own, or in the one in
// front, as the settings say (IINA's "Open media in new window", its
// simultaneous opens in one window, its duplicates). Video > Settings…
// (Cmd+,) has the player's settings, in a window as IINA's
// (Context::videoPlayerSettings). Run: ./run.sh video [file or URL], which
// logs what the players do to the terminal (MYPHP_VIDEO_LOG=1). In
// examples/video, ../../bin/myphp build makes Video.app and its .dmg.
declare(strict_types=1);

use MyPHP\{App, Dialog, FileDropEvent, Menu, MenuItem, OpenDialogOptions, Theme, ThemeSource, Window, WindowOptions};
use MyPHP\Media\{PlayerSettings, Subtitles};
use MyPHP\Plugins\Updater\{Options, Updater};
use MyPHP\UI\{Content, Context, TextState, VideoPlayerState};

// MyPHP from inside the built app, else from this checkout.
require_once is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp/src/autoload.php' : __DIR__ . '/../../src/autoload.php';

/**
 * With MYPHP_VIDEO_LOG set, what the players do, on stderr: the film
 * opened, its events, a frame of a window that took long (the UI held
 * up), and, where PHP has pcntl, every 3 s: that a frame is held up, and
 * where what is opening waits while it opens.
 */
final class Log
{
    /** @var array<int, ?\MyPHP\Media\Player> the player each state was last seen with */
    private static array $players = [];
    /** @var array<int, string> */
    private static array $opening = [], $error = [];
    private static float $start = 0.0;
    private static int $depth = 0;
    private static float $frameAt = 0.0;

    public static function on(): bool
    {
        return getenv('MYPHP_VIDEO_LOG') !== false && getenv('MYPHP_VIDEO_LOG') !== '';
    }

    public static function line(string $s): void
    {
        self::$start = self::$start ?: microtime(true);
        fwrite(STDERR, sprintf("[video %7.2f] %s\n", microtime(true) - self::$start, $s));
    }

    /** Around a frame of a player's window: what changed, how long it took. */
    public static function frame(VideoPlayerState $s, Closure $draw): void
    {
        if (!self::on()) { $draw(); return; }
        $id = spl_object_id($s);
        if (self::$depth > 0) { self::line('a frame drawn inside a frame (the UI waited, nested, depth ' . self::$depth . ')'); }
        if (($s->opening ?? '') !== (self::$opening[$id] ?? '')) { self::$opening[$id] = $s->opening ?? ''; if (self::$opening[$id] !== '') { self::line('opening ' . self::$opening[$id]); } }
        if ($s->error !== (self::$error[$id] ?? '')) { self::$error[$id] = $s->error; if ($s->error !== '') { self::line("error: {$s->error}"); } }
        if ($s->player !== (self::$players[$id] ?? null)) { self::watch($s); }
        if (self::$depth === 0) { self::$frameAt = microtime(true); }
        self::$depth++;
        $t = microtime(true);
        try {
            $draw();
        } finally {
            self::$depth--;
            if (($d = microtime(true) - $t) > 0.1) { self::line(sprintf('a frame took %.2f s', $d)); }
        }
    }

    private static function watch(VideoPlayerState $s): void
    {
        $p = self::$players[spl_object_id($s)] = $s->player;
        if ($p === null) { return; }
        $v = $p->videoTrack();
        self::line(sprintf('opened %s: "%s", %.2f s, video %s %dx%d%s, audio %s', $p->path(), $p->title(), $p->duration(), $v?->codec ?? 'none', ...$p->size(),
            ...[$p->remote() ? ' (over the network)' : '', $p->audioTrack()?->codec ?? 'none']));
        $at = fn(): string => sprintf('%.2f', $p->time());
        $p->on('seek', fn(float $to) => self::line(sprintf('seek to %.2f', $to)));
        $p->on('buffering', fn(bool $on) => self::line(($on ? 'buffering at ' : 'buffered at ') . $at() . ($on ? '' : sprintf(' (cache %.1f MB, %.1f MB/s)', $p->cacheBytes() / 1e6, $p->cacheSpeed() / 1e6))));
        $p->on('play', fn() => self::line('play at ' . $at()));
        $p->on('pause', fn() => self::line('pause at ' . $at()));
        $p->on('error', fn(string $why) => self::line("error: $why"));
        $p->on('ended', fn() => self::line('ended'));
        $p->on('track', fn(string $kind) => self::line("$kind track changed"));
    }

    /**
     * Every 3 s (SIGALRM): that a frame is held up 3 s (not where: a
     * backtrace taken in the handler hung in zend_fetch_debug_backtrace
     * under the tracing JIT; `sample` the process for that); while opening,
     * where each fiber waiting waits (a suspended fiber's frames are whole).
     */
    public static function watchdog(): void
    {
        if (!self::on() || !function_exists('pcntl_async_signals')) { return; }
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function (): void {
            pcntl_alarm(3);
            if (self::$depth > 0 && microtime(true) - self::$frameAt > 3) {
                self::line(sprintf('a frame held up %.0f s (a dialog of its open, or stuck: sample the process for where)', microtime(true) - self::$frameAt));
            }
            if (array_filter(self::$opening) === []) { return; }
            $waiters = (new ReflectionProperty(\MyPHP\Native\CurlLoop::class, 'waiters'))->getValue();
            foreach ($waiters as $k => [, $fiber]) {
                $trace = array_map(fn(array $f): string => ($f['file'] ?? '?') . '(' . ($f['line'] ?? '?') . '): ' . (isset($f['class']) ? $f['class'] . $f['type'] : '') . $f['function'], (new ReflectionFiber($fiber))->getTrace());
                self::line("still opening; fiber $k waits at:\n  " . implode("\n  ", $trace));
            }
            if ($waiters === []) { self::line('still opening; no fiber waits on the network'); }
        });
        pcntl_alarm(3);
    }
}

/** A player's window: its film, or what failed to open. */
function view(Context $c, VideoPlayerState $s): void
{
    if ($s->player !== null || $s->opening !== null) { // a film, or a URL opening (the player's indicator)
        $c->videoPlayer($s);
        return;
    }
    $c->column(0, 0, 1, 0x000000)->center();
      if ($s->error !== '') { $c->text($s->error, 13, 0xff6b6b); }
    $c->end();
}

/**
 * The players and the app's other windows, as IINA's PlayerCore and
 * AppDelegate keep them: a window a player, the one in front the active
 * one; the welcome, Open URL, playback history and settings windows, one
 * each, brought forward when open.
 */
final class Players
{
    /** @var array<int, array{Window, VideoPlayerState}> by window id */
    private static array $players = [];
    private static ?Window $welcome = null, $history = null, $settings = null, $url = null;
    /** @var list<string> the files the Finder opens, in one go as IINA's openFile timer gathers them */
    private static array $incoming = [];
    private static int $gathering = 0;
    /** Whether a film opened since launch (IINA's openFileCalled): the welcome window is not shown then. */
    public static bool $opened = false;
    private static ?VideoPlayerState $settingsState = null;

    /** A new player's window (IINA's PlayerCore.createPlayerCore and its window). */
    private static function create(): array
    {
        $s = new VideoPlayerState();
        $w = new Window(new WindowOptions(title: 'Video', width: 960, height: 540, stateKey: 'video', backgroundColor: '#000000', titleBarStyle: 'hidden',
            content: Content::view(function (Context $c) use ($s): void { Log::frame($s, function () use ($c, $s): void { view($c, $s); }); })));
        $w->on('fileDrop', fn(FileDropEvent $e) => self::drop($s, $e->paths));
        $id = $w->id;
        $w->on('closed', function () use ($id, $s): void { $s->player?->pause(); unset(self::$players[$id]); });
        return self::$players[$id] = [$w, $s];
    }

    /** The player in front, else the first (IINA's PlayerCore.active). */
    private static function active(): ?array
    {
        $front = Window::focused();
        return $front !== null && isset(self::$players[$front->id]) ? self::$players[$front->id] : (array_values(self::$players)[0] ?? null);
    }

    /** A player playing nothing, else a new one (IINA's newPlayerCore). */
    private static function fresh(): array
    {
        foreach (self::$players as $p) {
            if ($p[1]->player === null && $p[1]->opening === null) { return $p; }
        }
        return self::create();
    }

    /**
     * Opens $paths as IINA's PlayerCore.openURLs: all in the player in front
     * where films do not open in new windows (Settings > General), else all
     * in one new one where simultaneous opens make a playlist, else each in
     * a new one, a film one plays already brought forward instead unless
     * duplicates are allowed; $alternative the other way of new windows
     * (IINA's Open in New Window). @param list<string> $paths
     */
    public static function open(array $paths, bool $alternative = false): void
    {
        if ($paths === []) { return; }
        self::$opened = true;
        $set = PlayerSettings::default();
        if ($set->get('alwaysOpenInNewWindow') === $alternative) {
            self::play(self::active() ?? self::fresh(), $paths);
        } elseif (count($paths) > 1 && $set->get('groupSimultaneousOpensInPlaylist')) {
            self::play(self::fresh(), $paths);
        } else {
            foreach ($paths as $path) {
                if (!$set->get('allowDuplicatePlayers') && ($there = self::playing($path)) !== null) { $there->focus(); continue; }
                self::play(self::fresh(), [$path]);
            }
        }
        self::$welcome?->isDestroyed() === false && self::$welcome->close(); // IINA's: the welcome window goes as a film opens
    }

    /** The window of a player of the film $path, if one plays it. */
    private static function playing(string $path): ?Window
    {
        foreach (self::$players as [$w, $s]) {
            $p = $s->player?->path();
            if ($p !== null && ($p === $path || realpath($p) !== false && realpath($p) === realpath($path))) { return $w; }
        }
        return null;
    }

    /** Plays the first of $paths in $player, the others after it in its playlist. @param array{Window, VideoPlayerState} $player @param list<string> $paths */
    private static function play(array $player, array $paths): void
    {
        [$w, $s] = $player;
        $w->show();
        $w->focus();
        $s->open($paths[0]);
        foreach (array_slice($paths, 1) as $p) { if (!in_array($p, $s->playlist?->files() ?? [], true)) { $s->playlist?->add($p); } }
        self::menu(); // Open Recent, the film first
    }

    /** Files from the Finder or the Dock: gathered for 0.2 s, as IINA's openFile timer gathers them, then opened together. */
    public static function fromFinder(string $path): void
    {
        self::$opened = true;
        self::$incoming[] = $path;
        $n = ++self::$gathering;
        App::after(0.2, function () use ($n): void {
            if ($n !== self::$gathering) { return; }
            $paths = self::$incoming;
            self::$incoming = [];
            self::open($paths);
        });
    }

    /** Dropped on a player's window: subtitle files over its film, films in it. @param list<string> $paths */
    private static function drop(VideoPlayerState $s, array $paths): void
    {
        foreach ($paths as $i => $path) {
            if ($s->player !== null && isset(Subtitles::FILES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) { $s->loadSubtitles($path); unset($paths[$i]); }
        }
        $paths = array_values($paths);
        foreach (self::$players as $p) {
            if ($p[1] === $s && $paths !== []) { self::$opened = true; self::play($p, $paths); }
        }
    }

    /** File > Open…: the open panel, the films chosen opened. */
    public static function openPanel(bool $alternative = false): void
    {
        $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: 'Choose Media File', multiple: true));
        if ($paths) { self::open($paths, $alternative); }
    }

    /** What the app does as it starts or its Dock icon is clicked without a window (IINA's showWelcomeWindow): Settings > General's welcome window, open panel or nothing. */
    public static function welcome(): void
    {
        match (PlayerSettings::default()->get('actionAfterLaunch')) {
            0 => self::showWelcome(),
            1 => self::openPanel(),
            default => null,
        };
    }

    private static function showWelcome(): void
    {
        if (self::$welcome !== null && !self::$welcome->isDestroyed()) { self::$welcome->focus(); return; }
        self::$welcome = new Window(new WindowOptions(title: 'Welcome', width: 560, height: 440, disableResize: true, disableMaximize: true, titleBarStyle: 'hidden', vibrancy: 'under-window',
            content: Content::view(function (Context $c): void {
                $c->videoPlayerWelcome(fn(string $path) => self::open([$path]), fn() => self::askURL());
            })));
    }

    /** File > Open URL…: IINA's Open URL window, a URL and Open. */
    public static function askURL(bool $alternative = false): void
    {
        if (self::$url !== null && !self::$url->isDestroyed()) { self::$url->focus(); return; }
        $field = new TextState();
        $w = self::$url = new Window(new WindowOptions(title: 'Open URL', width: 480, height: 150, disableResize: true, disableMaximize: true, disableMinimize: true,
            content: Content::view(function (Context $c) use ($field, $alternative, &$w): void {
                $c->column(20, 12, 1);
                  $c->text('Enter the URL of a video (http or https):', 13);
                  $f = $c->input($field, 'https://example.com/film.mkv', 0)->fillWidth();
                  $c->row(0, 8)->alignSelf(\MyPHP\UI\Node::END);
                    $cancel = $c->button('Cancel');
                    $open = $c->button('Open');
                  $c->end();
                $c->end();
                if ($c->clicked($cancel) || $c->shortcut(\MyPHP\UI\Key::ESCAPE)) { App::later(fn() => $w->close()); }
                elseif (($c->clicked($open) || $c->submitted($f)) && preg_match('~^https?://\S+$~i', trim($field->text)) === 1) {
                    $url = trim($field->text);
                    App::later(function () use ($url, $alternative, &$w): void { $w->close(); self::open([$url], $alternative); });
                }
            })));
    }

    /** File > Playback History: the films played (IINA's history window), played in the player in front or a new one. */
    public static function history(): void
    {
        if (self::$history !== null && !self::$history->isDestroyed()) { self::$history->focus(); return; }
        self::$history = new Window(new WindowOptions(title: 'Playback History', width: 800, height: 600, minWidth: 500, minHeight: 300, stateKey: 'video history', titleBarStyle: 'hiddenInset',
            content: Content::view(function (Context $c): void {
                $c->videoPlayerHistory(fn(array $paths, bool $newWindow) => self::open($paths, $newWindow !== PlayerSettings::default()->get('alwaysOpenInNewWindow')));
            })));
    }

    /** Video > Settings…: the settings window, as IINA's (600 by 480, the sidebar's material under a hidden title bar). */
    public static function settings(): void
    {
        if (self::$settings !== null && !self::$settings->isDestroyed()) { self::$settings->focus(); return; }
        $s = self::$settingsState ??= new VideoPlayerState(); // the settings are the app's, whatever player; this one logs in to OpenSubtitles from them
        self::$settings = new Window(new WindowOptions(title: 'Settings', width: 600, height: 480, minWidth: 600, minHeight: 300, stateKey: 'video settings v2',
            titleBarStyle: 'hiddenInset', trafficLightPosition: [18, 18], vibrancy: 'sidebar',
            content: Content::view(function (Context $c) use ($s): void { $c->videoPlayerSettings($s); })));
    }

    /** The menus, as IINA's: Open Recent of the recent documents, built again as they change. */
    public static function menu(): void
    {
        $recent = array_map(fn(string $p) => new MenuItem(basename($p), fn() => self::open([$p])), App::recentDocuments());
        App::setMenu(new Menu([
            new MenuItem(role: 'appMenu', submenu: [ // the system's, Settings… and Check for Updates… after About, as IINA's
                new MenuItem(role: 'about'), Updater::menuItem(), MenuItem::separator(), new MenuItem('Settings…', fn() => self::settings(), 'Cmd+,'), MenuItem::separator(),
                new MenuItem(role: 'services'), MenuItem::separator(), new MenuItem(role: 'hide'), new MenuItem(role: 'hideOthers'), new MenuItem(role: 'unhide'), MenuItem::separator(), new MenuItem(role: 'quit'),
            ]),
            new MenuItem('File', submenu: [
                new MenuItem('Open…', fn() => self::openPanel(), 'CmdOrCtrl+O'),
                new MenuItem('Open in New Window…', fn() => self::openPanel(true), 'CmdOrCtrl+Alt+O'),
                new MenuItem('Open URL…', fn() => self::askURL(), 'CmdOrCtrl+Shift+O'),
                new MenuItem('Open URL in New Window…', fn() => self::askURL(true), 'CmdOrCtrl+Alt+Shift+O'),
                new MenuItem('Open Recent', submenu: [...$recent, ...($recent === [] ? [] : [MenuItem::separator()]),
                    new MenuItem('Clear Menu', function (): void { App::clearRecentDocuments(); self::menu(); }, disabled: $recent === [])]),
                MenuItem::separator(),
                new MenuItem('Playback History', fn() => self::history(), 'CmdOrCtrl+Shift+H'),
                MenuItem::separator(),
                new MenuItem(role: 'close'),
            ]),
            new MenuItem(role: 'editMenu'), // Cmd+V, Cmd+C and the rest in the URL's field: a menu's key equivalents, as macOS has them
            new MenuItem(role: 'windowMenu'),
        ]));
    }
}

App::setName('Video');
// The updates of the built app (myphp.json's "updates"), checked as Settings > General's Check for Updates says, every interval it says
// (from the next launch: the updater takes it as it starts).
$set = PlayerSettings::default();
App::use(Updater::new(new Options(interval: (float)$set->get('updateCheckInterval'), disableAutomaticChecks: !$set->get('checkForUpdates'))));
$set->onChange('checkForUpdates', fn(bool $on) => Updater::setAutomaticChecks($on));
// From the Finder (myphp.json's fileAssociations) or the Dock icon; from the command line too in the built app.
App::onOpenFile(fn(string $path) => Players::fromFinder($path));
// IINA's "Quit after all windows are closed": the app stays otherwise, as Mac apps do; the Dock icon then shows the welcome window again.
App::onWindowAllClosed(function (): void { if (PlayerSettings::default()->get('quitWhenNoOpenedWindow')) { App::quit(); } });
App::onActivate(function (bool $visible): void { if (!$visible) { Players::welcome(); } });
Log::watchdog();
App::whenReady(function () use ($argv): void {
    $appearance = fn(int $theme) => Theme::setSource(match ($theme) { 2 => ThemeSource::Light, 4 => ThemeSource::System, default => ThemeSource::Dark }); // Settings > Interface > Appearance, IINA's: dark by default
    $appearance(PlayerSettings::default()->get('themeMaterial'));
    PlayerSettings::default()->onChange('themeMaterial', $appearance);
    Players::menu();
    if (isset($argv[1])) { // ./run.sh video file: no bundle, whose document types onOpenFile takes files from the command line by
        Players::open([$argv[1]]);
    }
    App::after(0.3, function (): void { if (!Players::$opened) { Players::welcome(); } }); // IINA's checkForShowingInitialWindow: unless the Finder opened a film
});
App::run();
