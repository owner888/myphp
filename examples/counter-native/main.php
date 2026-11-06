<?php
// A counter in native UI (mygo's examples/counter-native): a window MyPHP
// draws itself from PHP, with no web page. The arrow keys count too.
// Run: ./run.sh counter-native
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

/** The state the window shows. */
final class Counter
{
    public int $n = 0;
}

$s = new Counter();
App::setName('Counter');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Counter', width: 360, height: 260, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
