<?php
// A counter in native UI (mygo's examples/counter-native): a window MyPHP
// draws itself from PHP, with no web page. The arrow keys count too.
// Run: myphp run -d macos examples/counter-native
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

/** The state the window shows, reactive via PHP 8.4 Property Hooks. */
final class Counter
{
    public int $n = 0 {
        get => Observer::get($this, 'n', $this->n);
        set => Observer::set($this, 'n', $this->n, $value);
    }
}

$s = new Counter();
App::setName('Counter');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Counter', width: 360, height: 260, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
