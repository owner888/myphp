<?php
// Loading shows the 29 loading indicators of the loading plugin, after
// loading.dev, in native UI, with their options: size, color, easing, the
// ends of their strokes, and a pause (mygo's examples/loading).
// Run: ./run.sh loading
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Loading\Loading;
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

/** What the window shows: the options chosen. */
final class Gallery
{
    public int $size = 1, $color = 0, $easing = 0, $cap = 0;
    public bool $paused = false;
}

const SIZES = [16, 24, 32, 48];

$s = new Gallery();
App::setName('Loading');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Loading', width: 780, height: 640, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
