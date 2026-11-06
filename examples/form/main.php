<?php
// Text fields and a text area in native UI: click or Tab to focus, type
// (input methods included: try Pinyin), select with the mouse or Shift+arrows,
// Cmd+A/C/X/V/Z through the Edit menu, Enter submits a field. The view is a
// function of $state.
// Run: ./run.sh form
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, ScrollState, TextState};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

$s = (object)['name' => new TextState('Ada'), 'city' => new TextState, 'note' => new TextState, 'draft' => new TextState, 'log' => new TextState, 'greeting' => '', 'submits' => 0, 'strip' => new ScrollState,
      'subscribe' => false, 'mode' => 'system', 'applied' => 'system', 'volume' => 40.0, 'lang' => 0];
$s->log->readOnly = true;

App::setName('Form');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — text input', width: 600, height: 560,
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
