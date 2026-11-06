<?php
// The window's view, $this the state app.php makes (Content::file). Saved
// under myphp dev or myphp run, it hot reloads: the window shows the new
// code with the state as it was. Keep it a file of closures: a named class
// or function here waits for a restart (R).
declare(strict_types=1);

use MyPHP\UI\Context;

return function (Context $c): void {
    $c->column(24, 12, 1);
      $c->text("Count: {$this->count}", 24);
      $c->row(0, 8);
        if ($c->clicked($c->button('Increment'))) { $this->count++; }
        if ($c->clicked($c->button('Reset'))) { $this->count = 0; }
      $c->end();
      $c->muted('Native UI drawn by MyPHP on the GPU. Edit view.php and save: it hot reloads.');
    $c->end();
};
