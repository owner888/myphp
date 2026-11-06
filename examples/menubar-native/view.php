<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\{App, Clipboard};
use MyPHP\UI\{Context, Font, Key};

return function (Context $c): void {
    if ($c->shortcut(Key::ESCAPE) || $c->shortcut(Key::W, Key::COMMAND)) { $this->win?->hide(); }
    $c->onShortcut(Key::Q, Key::COMMAND, App::quit(...));
    $t = $c->theme;
    $c->column(20, 12, 1);
      $c->row(0, 12);
        $c->column(0, 4, 1);
          $c->text('Quick Notes', 22, style: Font::BOLD);
          $c->text('A little space for a quick thought.', 12, $t->textMuted);
        $c->end();
        if ($c->clicked($c->button('Hide')->label('Hide Quick Notes'))) { $this->win?->hide(); }
      $c->end();
      if ($c->changed($c->key('note')->textarea($this->text, 'Write something to remember…', 0, 0)->bind($this->editor)->label('Note'))) { $this->status = ''; }
      $c->row(0, 8);
        if ($c->clicked($c->button('Clear')->disabled($this->text->text === ''))) {
            $this->text->text = ''; $this->status = '';
            $this->editor->focus($c);
        }
        $c->spacer();
        if ($c->clicked($c->primaryButton('Copy note')->disabled($this->text->text === ''))) {
            Clipboard::writeText($this->text->text);
            $this->status = 'Copied to clipboard.';
        }
      $c->end();
      $c->divider();
      $c->row(0, 8);
        $c->text($this->status === '' ? 'Kept here until you quit.' : $this->status, 11, $t->textMuted)->grow(1);
        $c->button('Quit')->onClick(App::quit(...));
      $c->end();
    $c->end();
};
