<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Font};

return function (Context $c): void {
    $c->column(20, 10, 1);
      $c->text('Indexed text input', 22, style: Font::BOLD);
      $c->text(sprintf('%d lines · %d runes · version %d', $this->lineCount(), $this->len(), $this->version()));
      $c->textAreaBuffer($this, width: 0, height: 0)->label('Document')->font('monospace')->autoFocus();
    $c->end();
};
