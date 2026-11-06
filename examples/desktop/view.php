<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\Context;

return function (Context $c): void {
    $c->column(16, 10, 1);
      $c->row(0, 8);
        foreach ($this->actions as $label => $run) {
            if ($c->clicked($c->button($label))) { $run(); }
        }
      $c->end();
      $area = $c->column(12, 4, 1, $c->theme->surface);
        foreach (array_slice($this->log, -12) as $line) {
            $c->text($line);
        }
      $c->end();
      if ($c->rightClicked($area)) { $this->context->popup($this->window); }
    $c->end();
};
