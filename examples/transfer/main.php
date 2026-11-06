<?php
// Drags the system carries: a note dragged out to any app taking text or
// HTML (TextEdit, Mail, the Finder makes a clipping), its HTML made only
// if the destination asks for it; a well taking text, URLs and Markdown
// dragged in from other apps, which shows what it is offered as it is
// over it and reads it once dropped; a well taking files from the
// Finder; and cards dragged between two lists, as values within the
// window and as text to other apps (drag and dragData). Option copies,
// Command moves, where the source allows it.
// Run: ./run.sh transfer
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

$s = (object)['ended' => '', 'dropped' => '', 'files' => [], 'todo' => ['Write the spec', 'Review the port'], 'done' => ['Ship v0.78']];

App::setName('Transfer');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — Transfer', width: 600, height: 560,
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
