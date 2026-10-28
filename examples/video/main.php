<?php
// A video player laid out as IINA's window, in a few lines: a Matroska,
// WebM, MP4 or MOV file decoded and shown by the system (Media\Player),
// with the controls Context::videoPlayer builds over it (the title bar,
// the control bar, the messages, the sidebars of quick settings and of
// the playlist, picture in picture, IINA's keys). File > Open, or a film
// dropped on the window, opens a film, the films of its folder its
// playlist, as one opened from the Finder; File > Open URL a film over
// http(s); a subtitle file dropped on it loads over the film playing.
// Run: ./run.sh video [file or URL], which logs what the player does to the
// terminal (MYPHP_VIDEO_LOG=1); in examples/video, ../../bin/myphp build
// makes Video.app and its .dmg.
declare(strict_types=1);

use MyPHP\{App, Dialog, FileDropEvent, Menu, MenuItem, OpenDialogOptions, Theme, ThemeSource, Window, WindowOptions};
use MyPHP\Media\Subtitles;
use MyPHP\UI\{Content, Context, TextState, VideoPlayerState};

// MyPHP from inside the built app, else from this checkout.
require_once is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp/src/autoload.php' : __DIR__ . '/../../src/autoload.php';

/** Opens a dropped file: a subtitle file over the film playing, else a film. */
function drop(VideoPlayerState $s, string $path): void
{
    if ($s->player !== null && isset(Subtitles::FILES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
        $s->loadSubtitles($path);
        return;
    }
    $s->open($path);
}

/**
 * With MYPHP_VIDEO_LOG set, what the player does, on stderr: the film
 * opened, its events, a frame of the window that took long (the UI held
 * up), and, where PHP has pcntl, every 3 s: where it is when a frame is
 * held up, and where what is opening waits while it opens.
 */
final class Log
{
    private static ?\MyPHP\Media\Player $player = null;
    private static float $start = 0.0;
    private static int $depth = 0;
    private static float $frameAt = 0.0;
    private static string $opening = '', $error = '';

    public static function on(): bool
    {
        return getenv('MYPHP_VIDEO_LOG') !== false && getenv('MYPHP_VIDEO_LOG') !== '';
    }

    public static function line(string $s): void
    {
        self::$start = self::$start ?: microtime(true);
        fwrite(STDERR, sprintf("[video %7.2f] %s\n", microtime(true) - self::$start, $s));
    }

    /** Around a frame of the window: what changed, how long it took. */
    public static function frame(VideoPlayerState $s, Closure $draw): void
    {
        if (!self::on()) { $draw(); return; }
        if (self::$depth > 0) { self::line('a frame drawn inside a frame (the UI waited, nested, depth ' . self::$depth . ')'); }
        if (($s->opening ?? '') !== self::$opening) { self::$opening = $s->opening ?? ''; if (self::$opening !== '') { self::line('opening ' . self::$opening); } }
        if ($s->error !== self::$error) { self::$error = $s->error; if ($s->error !== '') { self::line("error: {$s->error}"); } }
        if ($s->player !== self::$player) { self::watch($s); }
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
        $p = self::$player = $s->player;
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

    /** Every 3 s (SIGALRM): where PHP is when a frame is held up 3 s; while opening, where each fiber waiting waits. */
    public static function watchdog(): void
    {
        if (!self::on() || !function_exists('pcntl_async_signals')) { return; }
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function (): void {
            pcntl_alarm(3);
            if (self::$depth > 0 && microtime(true) - self::$frameAt > 3) {
                self::line(sprintf("a frame held up %.0f s, at:\n", microtime(true) - self::$frameAt) . (new \Exception())->getTraceAsString());
            }
            if (self::$opening === '') { return; }
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

/** File > Open URL's field (IINA's Open URL window), null while it is closed. */
$askUrl = null;

function view(Context $c, VideoPlayerState $s, ?TextState &$askUrl = null): void
{
    if ($askUrl !== null) { // a film over http(s), read as it plays
        $c->column(0, 12, 1, 0x000000)->center();
          $c->text('Open a video at a URL (http or https)', 15, 0xffffff)->opacity(0.8);
          $field = $c->input($askUrl, 'https://example.com/film.mkv', 420);
          $c->row(0, 8);
            $cancel = $c->button('Cancel');
            $open = $c->button('Open');
          $c->end();
        $c->end();
        if ($c->clicked($cancel)) { $askUrl = null; }
        elseif (($c->clicked($open) || $c->submitted($field)) && trim($askUrl->text) !== '') { $s->open(trim($askUrl->text)); $askUrl = null; }
        return;
    }
    if ($s->player !== null || $s->opening !== null) { // a film, or a URL opening (the player's indicator)
        $c->videoPlayer($s);
        return;
    }
    $c->column(0, 0, 1, 0x000000)->center();
      $c->text('Open a video (Cmd+O), a URL (Cmd+Shift+O), or drop one on the window', 15, 0xffffff)->opacity(0.7);
      if ($s->error !== '') { $c->text($s->error, 13, 0xff6b6b); }
    $c->end();
}

$s = new VideoPlayerState();
$openDialog = function () use ($s) {
    $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: 'Open a video'));
    if ($paths) { $s->open($paths[0]); }
};
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('File', submenu: [new MenuItem('Open…', $openDialog, 'CmdOrCtrl+O'), new MenuItem('Open URL…', function () use (&$askUrl) { $askUrl = new TextState(); Window::focused()?->invalidate(); }, 'CmdOrCtrl+Shift+O'), MenuItem::separator(), new MenuItem(role: 'close')]),
    new MenuItem(role: 'editMenu'), // Cmd+V, Cmd+C and the rest in the URL's field: a menu's key equivalents, as macOS has them
    new MenuItem(role: 'windowMenu'),
]));
App::setName('Video');
// From the Finder (myphp.json's fileAssociations) or the Dock icon; from the command line too in the built app, which the film given
// below already plays.
App::onOpenFile(fn(string $path) => $s->player !== null && realpath($s->player->path()) === realpath($path) ? null : drop($s, $path));
Log::watchdog();
App::whenReady(function () use ($s, $argv, &$askUrl) {
    Theme::setSource(ThemeSource::Dark); // as IINA's window: dark whatever the system's
    $w = new Window(new WindowOptions(title: 'Video', width: 960, height: 540, stateKey: 'video', backgroundColor: '#000000', titleBarStyle: 'hidden',
        content: Content::view(function (Context $c) use ($s, &$askUrl) { Log::frame($s, function () use ($c, $s, &$askUrl) { view($c, $s, $askUrl); }); })));
    $w->on('fileDrop', fn(FileDropEvent $e) => drop($s, $e->paths[0]));
    if (isset($argv[1])) { // ./run.sh video file: no bundle, whose document types onOpenFile takes files from the command line by
        $s->open($argv[1]);
    }
});
App::run();
