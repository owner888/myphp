<?php
// Text fields and a text area in native UI: click or Tab to focus, type
// (input methods included: try Pinyin), select with the mouse or Shift+arrows,
// Cmd+A/C/X/V/Z through the Edit menu, Enter submits a field. The view is a
// function of $state.
// Run: ./run.sh form
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, ScrollState, Span, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$s = ['name' => new TextState('Ada'), 'city' => new TextState, 'note' => new TextState, 'greeting' => '', 'submits' => 0, 'strip' => new ScrollState];

function form(Context $c, array &$s): void
{
    $c->column(24, 14, 1);
      $c->text('Profile', 20);
      foreach (['name' => 'Name', 'city' => 'City（试试中文输入法）'] as $key => $label) {
          $c->row(0, 12);
            $c->column(0, 0); $c->muted($label); $c->end();
            $field = $c->input($s[$key], $label, 320);
            if ($c->submitted($field)) { $s['submits']++; $s['greeting'] = "Hello, {$s['name']->text} from {$s['city']->text}"; }
          $c->end();
      }
      $c->row(0, 12);
        $c->column(0, 0); $c->muted('Note'); $c->end();
        $c->textarea($s['note'], 'A few lines: Enter breaks, Up/Down move, Cmd+Z undoes', 320, 96); // wraps at the width
      $c->end();
      // A strip wider than the window: scrolls sideways with the trackpad or its thumb.
      $c->row(8, 8, 0, $t = $c->theme->surface)->scroll($s['strip'], horizontal: true, vertical: false);
        for ($i = 0; $i < 30; $i++) {
            $c->column(6, 2, 0, $c->theme->background); $c->text("Tag $i"); $c->muted(sprintf('#%06x', ($i * 2654435761) & 0xffffff), 11); $c->end();
        }
      $c->end();
      // A focusable card: Tab reaches it, Enter or a click activates it, hover tints it; the text wraps at the card's width.
      $card = $c->column(10, 4, 0, $c->theme->surface)->focusable();
        if ($c->hovered($card)) { $card->color = $c->theme->surfaceHover; }
        if ($c->clicked($card)) { $s['submits']++; $s['greeting'] = 'card activated'; }
        $c->rich([new Span('A card. ', style: Font::BOLD), new Span('Tab to it and press Enter, or click it. This sentence wraps at the width the window gives the card, '
            . 'so make the window narrower and watch it reflow — 中文也会在合适的地方换行。')], wrap: true);
      $c->end();
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
    new Window(new WindowOptions(title: 'MyPHP native — text input', width: 560, height: 520,
        content: Content::view(function (Context $c) use (&$s) { form($c, $s); })));
});
App::run();
