<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Node};

return function (Context $c): void {
    $t = $c->theme;
    $bar = $c->titleBar();
    $c->column(0, 0, 1);
      // The title bar: as high as the controls' room (or 32), padded past them, a handle moving the window; the field and the button in it keep their presses.
      $c->row(0, $t->space(2), 0, $t->surface)->size(0, max($bar['height'], 32))->alignItems(Node::CENTER)->dragWindow();
        $c->column(0, 0)->size($bar['left'], 0); $c->end();
        $c->text("Title bar: {$this->style}", $t->fontSize, $t->text, Font::BOLD);
        $c->muted(sprintf('controls take %d × %d', $bar['left'], $bar['height']));
        $c->row(0, 0, 1); $c->end();
        $c->searchField($this->query, 180);
        if ($c->clicked($c->button('Count'))) { $this->count++; }
        $c->column(0, 0)->size($bar['right'] + $t->space(3), 0); $c->end();
      $c->end();
      $c->divider();
      $c->column($t->space(5), $t->space(3), 1);
        $c->text('Drag the bar above to move the window; double-click it to zoom.');
        $c->muted("Counted {$this->count}; searching “{$this->query->text}”.");
      $c->end();
    $c->end();
};
