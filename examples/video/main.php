<?php
// A video player laid out as IINA's window, in a few lines: a Matroska,
// WebM, MP4 or MOV file decoded and shown by the system (Media\Player),
// with the controls Context::videoPlayer builds over it (the title bar,
// the control bar, the messages, the sidebar of quick settings, IINA's
// keys). File > Open, or a file dropped on the window, opens a film; a
// subtitle file dropped on it loads over the film playing.
// Run: ./run.sh video [file]
declare(strict_types=1);

use MyPHP\{App, Dialog, FileDropEvent, Menu, MenuItem, OpenDialogOptions, Theme, ThemeSource, Window, WindowOptions};
use MyPHP\Media\{Player, Subtitles};
use MyPHP\UI\{Content, Context, VideoPlayerState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

final class Videos
{
    public ?Window $window = null;
    public ?Player $player = null;
    public VideoPlayerState $state;
    public string $error = '';

    public function __construct()
    {
        $this->state = new VideoPlayerState();
    }

    public function open(string $path): void
    {
        try {
            $p = new Player($path);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->window?->invalidate();
            return;
        }
        $this->player?->pause();
        $this->player = $p;
        $this->error = '';
        $this->state->title = basename($path);
        $this->window?->setTitle($this->state->title);
        $p->play();
        $this->window?->invalidate();
    }

    /** Opens a dropped file: a subtitle file over the film playing, else a film. */
    public function drop(string $path): void
    {
        if ($this->player !== null && isset(Subtitles::FILES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
            $this->state->loadSubtitles($path);
            return;
        }
        $this->open($path);
    }
}

function view(Context $c, Videos $v): void
{
    if ($v->player !== null) {
        $c->videoPlayer($v->player, $v->state);
        return;
    }
    $c->column(0, 0, 1, 0x000000)->center();
      $c->text('Open a video (Cmd+O) or drop one on the window', 15, 0xffffff)->opacity(0.7);
      if ($v->error !== '') { $c->text($v->error, 13, 0xff6b6b); }
    $c->end();
}

$s = new Videos;
$openDialog = function () use ($s) {
    $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: 'Open a video'));
    if ($paths) { $s->open($paths[0]); }
};
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('File', submenu: [new MenuItem('Open…', $openDialog, 'CmdOrCtrl+O'), MenuItem::separator(), new MenuItem(role: 'close')]),
    new MenuItem(role: 'windowMenu'),
]));
App::setName('Video');
App::whenReady(function () use ($s, $argv) {
    Theme::setSource(ThemeSource::Dark); // as IINA's window: dark whatever the system's
    $s->window = new Window(new WindowOptions(title: 'Video', width: 960, height: 540, stateKey: 'video', backgroundColor: '#000000', titleBarStyle: 'hidden',
        content: Content::view(fn(Context $c) => view($c, $s))));
    $s->window->on('fileDrop', fn(FileDropEvent $e) => $s->drop($e->paths[0]));
    if (isset($argv[1])) {
        $s->open($argv[1]);
    }
});
App::run();
