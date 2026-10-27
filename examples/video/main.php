<?php
// A video player laid out as IINA's window, in a few lines: a Matroska,
// WebM, MP4 or MOV file decoded and shown by the system (Media\Player),
// with the controls Context::videoPlayer builds over it (the title bar,
// the control bar, the messages, the sidebars of quick settings and of
// the playlist, picture in picture, IINA's keys). File > Open, or a film
// dropped on the window, opens a film, the films of its folder its
// playlist; a subtitle file dropped on it loads over the film playing.
// Run: ./run.sh video [file]
declare(strict_types=1);

use MyPHP\{App, Dialog, FileDropEvent, Menu, MenuItem, OpenDialogOptions, Theme, ThemeSource, Window, WindowOptions};
use MyPHP\Media\Subtitles;
use MyPHP\UI\{Content, Context, VideoPlayerState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** Opens a dropped file: a subtitle file over the film playing, else a film. */
function drop(VideoPlayerState $s, string $path): void
{
    if ($s->player !== null && isset(Subtitles::FILES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
        $s->loadSubtitles($path);
        return;
    }
    $s->open($path);
}

function view(Context $c, VideoPlayerState $s): void
{
    if ($s->player !== null) {
        $c->videoPlayer($s);
        return;
    }
    $c->column(0, 0, 1, 0x000000)->center();
      $c->text('Open a video (Cmd+O) or drop one on the window', 15, 0xffffff)->opacity(0.7);
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
    new MenuItem('File', submenu: [new MenuItem('Open…', $openDialog, 'CmdOrCtrl+O'), MenuItem::separator(), new MenuItem(role: 'close')]),
    new MenuItem(role: 'windowMenu'),
]));
App::setName('Video');
App::whenReady(function () use ($s, $argv) {
    Theme::setSource(ThemeSource::Dark); // as IINA's window: dark whatever the system's
    $w = new Window(new WindowOptions(title: 'Video', width: 960, height: 540, stateKey: 'video', backgroundColor: '#000000', titleBarStyle: 'hidden',
        content: Content::view(fn(Context $c) => view($c, $s))));
    $w->on('fileDrop', fn(FileDropEvent $e) => drop($s, $e->paths[0]));
    if (isset($argv[1])) {
        $s->open($argv[1]);
    }
});
App::run();
