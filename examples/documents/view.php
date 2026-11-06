<?php
// A note's view, $this its Note (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;
use MyPHP\UI\{Context, Node};

return function (Context $c): void {
    $t = $c->theme;
    $state = $this->doc->state();
    $c->column(0, 0, 1);
      if ($state->conflict) {
          $c->row(10, 8)->background($t->warning, 0.15)->alignItems(Node::CENTER);
            $c->text('Another app changed this note. Save to keep yours, or revert to theirs.', 13);
            $c->spacer();
            if ($c->clicked($c->button('Revert'))) { App::later(fn() => $this->doc->revert()); }
            if ($c->clicked($c->primaryButton('Save'))) { App::later(fn() => $this->doc->saveAnyway()); }
          $c->end();
      }
      $c->key('text')->textarea($this->text, '', 0, 0)->font('monospace')->label('Note')->autoFocus(); // 0, 0: fills the window
      if ($this->text->text !== $this->doc->content()) {
          $this->doc->set($this->text->text);
      }
      $c->row(0, 12)->padding(6, 12)->alignItems(Node::CENTER); // mygo's Padding(6, 12).Gap(12)
        $c->muted(sprintf('%d words', count(preg_split('/\s+/u', $this->text->text, -1, PREG_SPLIT_NO_EMPTY))), 12);
        $c->spacer();
        $c->muted(match (true) { $state->path === '' => 'Not saved', $state->readOnly => 'Read-only', $state->edited => 'Edited', default => 'Saved' }, 12);
      $c->end();
    $c->end();
};
