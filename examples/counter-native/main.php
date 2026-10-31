<?php
// A counter in native UI (mygo's examples/counter-native): a window MyPHP
// draws itself from PHP, with no web page. The arrow keys count too.
// Run: ./run.sh counter-native
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, Key, Node};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** The state the window shows. */
final class Counter
{
    public int $n = 0;
}

/** The interface of the state, built whenever the window needs a frame. */
function view(Context $c, Counter $s): void
{
    if ($c->shortcut(Key::UP)) { $s->n++; }
    if ($c->shortcut(Key::DOWN)) { $s->n--; }
    $t = $c->theme;
    $c->column(0, 16, 1)->fill()->alignItems(Node::CENTER)->justify(Node::CENTER);
      $c->text((string)$s->n, 56, style: Font::BOLD);
      $c->row(0, 8);
        if ($c->clicked($c->key('decrement')->button('−')->label('Decrement')->size(44, 0))) { $s->n--; }
        if ($c->clicked($c->key('reset')->button('Reset')->disabled($s->n === 0))) { $s->n = 0; }
        if ($c->clicked($c->key('increment')->primaryButton('+')->label('Increment')->size(44, 0))) { $s->n++; }
      $c->end();
      $c->text('↑ and ↓ count too', 12, $t->textMuted);
    $c->end();
}

$s = new Counter();
App::setName('Counter');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'Counter', width: 360, height: 260, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
