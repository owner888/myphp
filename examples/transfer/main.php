<?php
// Drags the system carries: a note dragged out to any app taking text or
// HTML (TextEdit, Mail, the Finder makes a clipping), its HTML made only
// if the destination asks for it; a well taking text, URLs and Markdown
// dragged in from other apps, lit as one is over it; a well taking files
// from the Finder; and cards dragged between two lists, as values within
// the window and as text to other apps (draggable, dropDestination). Option copies,
// Command moves, where the source allows it.
// Run: ./run.sh transfer
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

$s = (object)['ended' => '', 'dropped' => '', 'files' => [], 'over' => '', 'todo' => ['Write the spec', 'Review the port'], 'done' => ['Ship v0.78']];

App::setName('Transfer');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — Transfer', width: 600, height: 560,
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
