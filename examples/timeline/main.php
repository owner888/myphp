<?php
// What a Timeline is for (mygo's examples/timeline): several tweens on one
// playhead, arranged and controlled together. The card slides in as it
// grows, its title follows it, the rows come one after another, and the
// card bounces a little as it lands; Play, Pause, Reverse, Restart and the
// scrubber move the whole intro together. The timeline asks the window for
// frames only while it moves.
// Run: ./run.sh timeline
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

const WINDOW_W = 520;
const WINDOW_H = 480;

/** What the intro's controls set, and what its last frame showed. */
final class Intro
{
    /** Plays the intro again when it changes. */
    public int $run = 0;
    public bool $paused = false, $reverse = false;
    /** The scrubber, whether the pointer held it last frame, and the playhead it asks for while it does. */
    public float $scrub = 0.0;
    public bool $held = false;
}

$s = new Intro();
App::setName('Timeline');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Timeline', width: WINDOW_W, height: WINDOW_H, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
