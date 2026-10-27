<?php
// A video player: a Matroska, WebM, MP4 or MOV file, decoded and shown by
// the system (Media\Player), with controls built over the picture that
// hide while the pointer rests: play and pause, the time and a bar to
// seek, the audio tracks, the volume, full screen. Space plays and
// pauses, the arrows go 10 s back and on, F goes full screen.
// Run: ./run.sh video [file]
declare(strict_types=1);

use MyPHP\{App, Dialog, FileDropEvent, Menu, MenuItem, OpenDialogOptions, Window, WindowOptions};
use MyPHP\Media\{Player, Track};
use MyPHP\UI\{Content, Context, Cursor, Font, Key, Node};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

final class Videos
{
    /** Seconds the controls stay after the pointer last moved, while it plays. */
    public const LINGER = 2.5;
    public ?Window $window = null;
    public ?Player $player = null;
    public string $file = '', $error = '';
    /** Where the bar is, which follows the player but for a drag of it. */
    public float $at = 0, $volume = 1;
    public int $track = 0;
    /** Where the pointer was last frame, and when it last moved. */
    public array $pointer = [0.0, 0.0];
    public float $moved = 0;

    public function open(string $path): void
    {
        $this->player?->pause();
        try {
            $p = new Player($path);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->window?->invalidate();
            return;
        }
        $this->player = $p;
        $this->file = basename($path);
        $this->error = '';
        $this->track = (int)array_search($p->audioTrack(), $p->audioTracks(), true);
        $p->setVolume($this->volume);
        $p->on('time', fn() => $this->window?->invalidate()); // the time and the bar move
        $p->on('ended', fn() => $this->window?->invalidate());
        $p->on('error', function (string $why) { $this->error = $why; $this->window?->invalidate(); });
        $this->window?->setTitle($this->file);
        $p->play();
        $this->moved = microtime(true);
    }
}

function clock(float $s): string
{
    $s = (int)$s;
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s, 60) % 60, $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

function view(Context $c, Videos $v): void
{
    $box = $c->column(0, 0, 1, 0x000000);
    $p = $v->player;
    if ($p === null) {
        $box->center();
          $c->text('Open a video (Cmd+O) or drop one on the window', 15, 0xffffff)->opacity(0.7);
          if ($v->error !== '') { $c->text($v->error, 13, 0xff6b6b); }
        $c->end();
        return;
    }
    if ($c->shortcut(Key::SPACE)) { $p->playing() ? $p->pause() : $p->play(); }
    if ($c->shortcut(Key::LEFT)) { $p->seek($p->time() - 10); }
    if ($c->shortcut(Key::RIGHT)) { $p->seek($p->time() + 10); }
    if ($c->shortcut('f')) { $v->window?->toggleFullScreen(); }
    $picture = $c->video($p)->grow(1);
    // The controls show while paused, while the pointer moves, and while it is on them.
    [$px, $py, $inside] = $c->pointerPosition($box);
    $now = microtime(true);
    if ([$px, $py] !== $v->pointer) { $v->pointer = [$px, $py]; $v->moved = $now; }
    // A press on the controls leaves the keyboard where it was (a slider or a button would take the arrows and Space).
    $bar = $c->row(10, 10)->absolute()->left(16)->right(16)->bottom(16)->align(Node::CENTER)->background(0x1c1c1e, 0.8)->round(12)->keepFocus();
    $shown = !$p->playing() || $now - $v->moved < Videos::LINGER || $c->hovered($bar);
    $bar->opacity($shown ? 1 : 0);
    if (!$shown) { $picture->cursor(Cursor::None); } // the pointer hides with them
    if ($shown && $p->playing()) { $c->after(Videos::LINGER - ($now - $v->moved) + 0.05); } // to hide them
      $play = $c->button($p->playing() ? 'Pause' : 'Play')->width(64);
      if ($c->clicked($play)) { $p->playing() ? $p->pause() : $p->play(); }
      $c->text(clock($p->time()), 12, 0xffffff, Font::MONO);
      $v->at = $p->time();
      $seek = $c->slider($v->at, 0, max($p->duration(), 0.001), 100)->grow(1);
      if ($c->changed($seek)) { $p->seek($v->at); }
      $c->text(clock($p->duration()), 12, 0xffffff, Font::MONO)->opacity(0.7);
      $tracks = $p->audioTracks();
      if (count($tracks) > 1) {
          $labels = array_map(fn(Track $t) => trim(($t->name !== '' ? $t->name : $t->language) . (Player::playable($t) ? '' : ' (unplayable)')), $tracks);
          $sel = $c->select($labels, $v->track, 150);
          if ($c->changed($sel)) {
              if (Player::playable($tracks[$v->track])) { $p->setAudioTrack($tracks[$v->track]); } else { $v->track = (int)array_search($p->audioTrack(), $tracks, true); }
          }
      }
      $vol = $c->slider($v->volume, 0, 1, 80);
      if ($c->changed($vol)) { $p->setVolume($v->volume); }
      if ($c->clicked($c->button('Full Screen'))) { $v->window?->toggleFullScreen(); }
    $c->end();
    $c->clicked($bar); // a click on the controls that none of them took (the end of a drag of the bar) is theirs, not the picture's
    if ($v->error !== '') {
        $c->text($v->error, 13, 0xff6b6b)->absolute()->left(16)->top(16);
    }
    // A click goes to the first node built that asks for it where it is: the picture asks after the controls over it, and the controls themselves.
    if ($c->clicked($picture)) { $p->playing() ? $p->pause() : $p->play(); }
    if ($c->doubleClicked($picture)) { $v->window?->toggleFullScreen(); }
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
    $s->window = new Window(new WindowOptions(title: 'Video', width: 1100, height: 660, stateKey: 'video', backgroundColor: '#000000',
        content: Content::view(fn(Context $c) => view($c, $s))));
    $s->window->on('fileDrop', fn(FileDropEvent $e) => $s->open($e->paths[0]));
    if (isset($argv[1])) {
        $s->open($argv[1]);
    }
});
App::run();
