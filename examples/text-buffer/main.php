<?php
// A large editable document in an indexed text buffer (mygo's
// examples/text-buffer): 100 000 lines, with emoji and right-to-left text.
// Run: ./run.sh text-buffer
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, TextBuffer};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

function document(int $lines): TextBuffer
{
    $source = '';
    for ($i = 1; $i <= $lines; $i++) { $source .= "Line $i: editable text, 😀, العربية, עברית\n"; }
    return new TextBuffer($source);
}

function view(Context $c, TextBuffer $buffer): void
{
    $c->column(20, 10, 1);
      $c->text('Indexed text input', 22, style: Font::BOLD);
      $c->text(sprintf('%d lines · %d runes · version %d', $buffer->lineCount(), $buffer->len(), $buffer->version()));
      $c->textAreaBuffer($buffer, width: 0, height: 0)->label('Document')->font('monospace')->autoFocus();
    $c->end();
}

$s = document((int)($argv[1] ?? 100000));
App::setName('Text buffer');
App::whenReady(function () use ($s) {
    App::setMenu(new Menu([new MenuItem(role: 'appMenu'), new MenuItem(role: 'editMenu')]));
    new Window(new WindowOptions(title: 'Indexed text buffer', width: 900, height: 650, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
