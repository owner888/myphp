<?php
declare(strict_types=1);

if (!defined('STDIN')) { define('STDIN', fopen('php://stdin', 'r')); }
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require_once __DIR__ . '/src/Native/LocalePolyfill.php';

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, Key, Node};

spl_autoload_register(function ($c) {
    if (str_starts_with($c, 'MyPHP\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($c, 6)) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

final class Counter
{
    public int $n = 0;
}

function view(Context $c, Counter $s): void
{
    \MyPHP\Logger::debug("view building: n={$s->n}", 'counter');
    if ($c->shortcut(Key::UP)) { $s->n++; }
    if ($c->shortcut(Key::DOWN)) { $s->n--; }
    $t = $c->theme;
    $c->column(0, 24, 1)->fill()->alignItems(Node::CENTER)->justify(Node::CENTER);
      $c->text((string)$s->n, 72, style: Font::BOLD);
      $c->row(0, 16);
        $dec = $c->key('decrement')->button('−')->label('Decrement')->size(56, 44);
        if ($c->clicked($dec)) {
            $s->n--;
            \MyPHP\Logger::debug("Decrement clicked! n={$s->n}", 'counter');
        }
        $rst = $c->key('reset')->button('Reset')->disabled($s->n === 0)->size(80, 44);
        if ($c->clicked($rst)) {
            $s->n = 0;
            \MyPHP\Logger::debug("Reset clicked! n={$s->n}", 'counter');
        }
        $inc = $c->key('increment')->primaryButton('+')->label('Increment')->size(56, 44);
        if ($r = $c->runtime?->rectOf($inc->id)) {
            \MyPHP\Logger::debug("increment rect: [{$r[0]}, {$r[1]}, {$r[2]}, {$r[3]}]", 'counter');
        }
        if ($c->clicked($inc)) {
            $s->n++;
            \MyPHP\Logger::debug("Increment clicked! n={$s->n}", 'counter');
        }
      $c->end();
      $c->text('Tap buttons to count', 14, $t->textMuted);
    $c->end();
}

$s = new Counter();
App::setName('Counter');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(
        title: 'Counter',
        width: 360,
        height: 640,
        content: Content::view(fn(Context $c) => view($c, $s))
    ));
});

echo "[myphp] Running App::run() on Android...\n";
App::run();
echo "[myphp] App::run() exited.\n";
