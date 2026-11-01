<?php
// Video is an animated background in native UI (mygo's examples/video,
// here examples/wavelength, as examples/video is the film player): a
// short GIF that loops behind the window's content, as a launcher's
// library or a music player's hero shows one.
// The clip's frames decode as each comes due, and each reaches the
// screen then, so the window draws one frame for each of the clip's and
// none between. The clip stands still while the window is out of sight,
// and shows its first frame while the desktop asks for less motion.
// Run: ./run.sh wavelength
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, GifDecoder, Node, VideoPlayer};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** The state the window shows. */
final class Wavelength
{
    public VideoPlayer $waves;

    public function __construct()
    {
        $this->waves = (new VideoPlayer(GifDecoder::fromFile(__DIR__ . '/waves.gif')))->setLoop(true);
    }
}

/** The interface of the state, built whenever the window needs a frame. */
function view(Context $c, Wavelength $s): void
{
    $c->column()->fill();
      $c->video($s->waves)->fill()->fit(Node::COVER);
      // Over the clip, which takes the window.
      $c->column(0, 16)->absolute()->top(0)->left(0)->right(0)->bottom(0)->center();
        $c->text('Wavelength', 44, 0xffffff, style: Font::BOLD);
        if ($c->clicked($c->key('play')->button($s->waves->playing() ? 'Pause' : 'Play')->size(96, 0))) {
            if ($s->waves->playing()) { $s->waves->pause(); } else { $s->waves->play(); }
        }
      $c->end();
    $c->end();
}

$s = new Wavelength();
App::setName('Video');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Video', width: 640, height: 360, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
