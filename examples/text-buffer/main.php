<?php
// A large editable document in an indexed text buffer (mygo's
// examples/text-buffer): 100 000 lines, with emoji and right-to-left text.
// Run: ./run.sh text-buffer
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\UI\{Content, TextBuffer};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

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
