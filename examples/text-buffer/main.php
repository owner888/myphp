<?php
// A large editable document in an indexed text buffer (mygo's
// examples/text-buffer): 100 000 lines, with emoji and right-to-left text.
// Run: myphp run -d macos examples/text-buffer
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\UI\{Content, TextBuffer};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

function document(int $lines): TextBuffer
{
    $source = '';
    for ($i = 1; $i <= $lines; $i++) { $source .= "Line $i: editable text, 😀, العربية, עברית\n"; }
    return new TextBuffer($source);
}

$s = document((int)($argv[1] ?? 100000));
App::setName('Text buffer');
App::whenReady(function () use ($s) {
    App::setMenu(new Menu([new MenuItem(role: 'appMenu'), new MenuItem(role: 'editMenu')]));
    new Window(new WindowOptions(title: 'Indexed text buffer', width: 900, height: 650, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
