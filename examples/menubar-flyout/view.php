<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;
use MyPHP\UI\{Context, Font, Node};

return function (Context $c): void {
    $t = $c->theme;
    $c->root->color = null;
    $box = $c->column(16, 12, 1);
    if (!$c->vibrancy()) { $box->background($t->surface)->radius(10)->border(1, $t->border); } // over the popover's material, nothing
      $c->text('Focus', 17, style: Font::BOLD);
      $c->row(0, 12)->alignItems(Node::CENTER);
        $c->text('Focus mode')->grow(1);
        $c->key('focus')->switch('', $this->focus)->label('Focus mode');
      $c->end();
      $c->row(0, 12)->alignItems(Node::CENTER);
        $c->text('Sound')->grow(1);
        $c->key('sound')->switch('', $this->sound)->label('Sound');
      $c->end();
      $c->text(sprintf('Sessions of %d minutes', (int)$this->minutes), 12, $t->textMuted);
      $c->key('minutes')->slider($this->minutes, 5, 60, 0)->fillWidth()->label('Session length');
      $c->spacer();
      $c->row();
        $c->spacer();
        $c->key('quit')->button('Quit')->onClick(App::quit(...));
      $c->end();
    $c->end();
};
