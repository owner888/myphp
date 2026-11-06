<?php
// A counter in native UI (mygo's examples/counter-native): a window MyPHP
// draws itself from PHP, with no web page. The arrow keys count too.
// Run: ./run.sh counter-native
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

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
