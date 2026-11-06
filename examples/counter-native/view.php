<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Key, Node};

/** The interface of the state, built whenever the window needs a frame. */
return function (Context $c): void {
    if ($c->shortcut(Key::UP)) { $this->n++; }
    if ($c->shortcut(Key::DOWN)) { $this->n--; }
    $t = $c->theme;
    $c->column(0, 16, 1)->fill()->alignItems(Node::CENTER)->justify(Node::CENTER);
      $c->text((string)$this->n, 56, style: Font::BOLD);
      $c->row(0, 8);
        if ($c->clicked($c->key('decrement')->button('−')->label('Decrement')->size(44, 0))) { $this->n--; }
        if ($c->clicked($c->key('reset')->button('Reset')->disabled($this->n === 0))) { $this->n = 0; }
        if ($c->clicked($c->key('increment')->primaryButton('+')->label('Increment')->size(44, 0))) { $this->n++; }
      $c->end();
      $c->text('↑ and ↓ count too', 12, $t->textMuted);
    $c->end();
};
