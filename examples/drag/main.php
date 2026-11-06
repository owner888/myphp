<?php
// Draggable nodes: a card the pointer drags anywhere, which stays where it
// is let go; a puck thrown along a track to the nearest of three stops, at
// the pointer's speed; a sheet pulled up or down to its heights, rubber-
// banded past them, lifting as it is dragged; words dragged into a basket,
// which lights up when one is over it and takes it in, the word flying to
// its place there (Escape gives a drag up; a click on one takes it back).
// Run: ./run.sh drag
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

$s = (object)['card' => [0.0, 0.0], 'puck' => [0.0, 0.0], 'sheet' => [0.0, 180.0], 'words' => ['apple', 'pear', 'plum'], 'basket' => []];

App::setName('Drag');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — Drag', width: 640, height: 720,
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
