<?php
// Video is an animated background in native UI (mygo's examples/video,
// here examples/wavelength, as examples/video is the film player): a
// short GIF that loops behind the window's content, as a launcher's
// library or a music player's hero shows one.
// The clip's frames decode as each comes due, and each reaches the
// screen then, so the window draws one frame for each of the clip's and
// none between. The clip stands still while the window is out of sight,
// and shows its first frame while the desktop asks for less motion.
// Run: myphp run -d macos examples/wavelength
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, GifDecoder, VideoPlayer};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

/** The state the window shows. */
final class Wavelength
{
    public VideoPlayer $waves;

    public function __construct()
    {
        $this->waves = (new VideoPlayer(GifDecoder::fromFile(__DIR__ . '/waves.gif')))->setLoop(true);
    }
}

$s = new Wavelength();
App::setName('Video');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Video', width: 640, height: 360, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
