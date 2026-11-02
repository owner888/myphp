<?php
declare(strict_types=1);

if (!defined('STDIN')) { define('STDIN', fopen('php://stdin', 'r')); }
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require_once __DIR__ . '/src/Native/LocalePolyfill.php';

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, Key, ScrollState, Span, TextState, Theme};

spl_autoload_register(function ($c) {
    if (str_starts_with($c, 'MyPHP\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($c, 6)) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

$s = [
    'name' => new TextState('Ada'),
    'city' => new TextState('北京'),
    'note' => new TextState,
    'draft' => new TextState,
    'log' => new TextState,
    'greeting' => '',
    'submits' => 0,
    'strip' => new ScrollState,
    'subscribe' => false,
    'mode' => 'system',
    'applied' => 'system',
    'volume' => 40.0,
    'lang' => 0,
];
$s['log']->readOnly = true;

function form(Context $c, array &$s): void
{
    $c->column(24, 14, 1);
      $c->text('Profile', 24, style: Font::BOLD);

      foreach (['name' => 'Name', 'city' => 'City（试试中文输入法）'] as $key => $label) {
          $c->row(0, 8);
            $c->column(0, 0); $c->muted($label); $c->end();
            $field = $c->input($s[$key], $label, 300);
            if ($c->submitted($field)) {
                $s['submits']++;
                $s['greeting'] = "Hello, {$s['name']->text} from {$s['city']->text}";
            }
          $c->end();
      }

      $c->row(0, 8);
        $c->column(0, 0); $c->muted('Note'); $c->end();
        $c->textarea($s['note'], 'A few lines of note', 300, 80);
      $c->end();

      // Horizontal strip of tags to test horizontal scrolling & gestures
      $c->text('Horizontal Scroll Tags:', 14);
      $c->row(8, 8, 0, $c->theme->surface)->scroll($s['strip'], horizontal: true, vertical: false);
        for ($i = 0; $i < 20; $i++) {
            $c->column(6, 2, 0, $c->theme->background);
            $c->text("Tag $i");
            $c->muted(sprintf('#%06x', ($i * 2654435761) & 0xffffff), 11);
            $c->end();
        }
      $c->end();

      // Controls
      $c->row(0, 16);
        $c->checkbox('Subscribe', $s['subscribe']);
      $c->end();

      $c->row(0, 12);
        if ($c->clicked($c->primaryButton('Submit'))) {
            $s['submits']++;
            $s['greeting'] = "Hello, {$s['name']->text} from {$s['city']->text}";
        }
        if ($c->clicked($c->button('Clear'))) {
            foreach (['name', 'city', 'note'] as $k) {
                $s[$k]->text = '';
                $s[$k]->setCursor(0);
            }
            $s['greeting'] = '';
        }
      $c->end();

      if ($s['greeting'] !== '') {
          $c->text($s['greeting'] . " (#{$s['submits']})", 16);
      }
    $c->end();
}

App::setName('Form');
App::whenReady(function () use (&$s) {
    new Window(new WindowOptions(
        title: 'Form',
        width: 360,
        height: 640,
        content: Content::view(function (Context $c) use (&$s) { form($c, $s); })
    ));
});

echo "[myphp] Running Form app on Android...\n";
App::run();
