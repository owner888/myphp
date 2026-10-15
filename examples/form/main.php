<?php
// Text fields in native UI: click or Tab to focus, type (input methods
// included: try Pinyin), select with the mouse or Shift+arrows, Cmd+A/C/X/V
// through the Edit menu, Enter submits. The view is a function of $state.
// Run: ./run.sh form
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$s = ['name' => new TextState('Ada'), 'city' => new TextState, 'note' => new TextState, 'greeting' => '', 'submits' => 0];

function form(Context $c, array &$s): void
{
    $c->column(24, 14, 1);
      $c->text('Profile', 20);
      foreach (['name' => 'Name', 'city' => 'City（试试中文输入法）', 'note' => 'Note'] as $key => $label) {
          $c->row(0, 12);
            $c->column(0, 0); $c->muted($label); $c->end();
            $field = $c->input($s[$key], $label, 320);
            if ($c->submitted($field)) { $s['submits']++; $s['greeting'] = "Hello, {$s['name']->text} from {$s['city']->text}"; }
          $c->end();
      }
      $c->row(0, 8);
        if ($c->clicked($c->button('Submit'))) { $s['submits']++; $s['greeting'] = "Hello, {$s['name']->text} from {$s['city']->text}"; }
        if ($c->clicked($c->button('Clear'))) { foreach (['name', 'city', 'note'] as $k) { $s[$k]->text = ''; $s[$k]->setCursor(0); } $s['greeting'] = ''; }
      $c->end();
      $c->text($s['greeting'] === '' ? 'Submit, or press Enter in a field.' : $s['greeting'] . " (#{$s['submits']})", 15);
      $n = $s['name'];
      $c->muted(sprintf('name: %d bytes, cursor %d, anchor %d%s', strlen($n->text), $n->cursor, $n->anchor, $n->marked !== null ? ", composing \"{$n->marked}\"" : ''), 12);
    $c->end();
}

App::setName('Form');
App::whenReady(function () use (&$s) {
    new Window(new WindowOptions(title: 'MyPHP native — text input', width: 560, height: 360,
        content: Content::view(function (Context $c) use (&$s) { form($c, $s); })));
});
App::run();
