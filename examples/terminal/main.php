<?php
// A terminal app in native UI (mygo's examples/terminal): the user's shell
// in a window, with the terminal plugin, which emulates it with Ghostty's
// libghostty-vt. The window takes the shell's title and closes when the
// shell exits.
// Run: myphp run -d macos examples/terminal
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Terminal\{Options, Terminal};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

App::setName('Terminal');
App::whenReady(function () {
    $win = null;
    $term = Terminal::new(new Options(
        onTitle: function (string $title) use (&$win) { $win?->setTitle($title); },
        onExit: function (int $code) use (&$win) { $win?->close(); },
    ));
    $win = new Window(new WindowOptions(title: 'Terminal', width: 760, height: 480,
        content: Content::file(__DIR__ . '/view.php', $term)));
    $win->on('closed', fn() => $term->close());
});
App::run();
