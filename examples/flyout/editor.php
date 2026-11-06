<?php
// The note editor's flyout, $this the state main.php makes (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Key};

return function (Context $c): void {
    $c->root->color = null;
    panel($c)->padding(12)->gap(8);
      $c->text('Note')->bold();
      $c->key('note')->input($this->note, 'Write something', 0)->autoFocus();
      if ($c->shortcut(Key::RETURN)) { $this->editor?->close(); }
    $c->end();
};
