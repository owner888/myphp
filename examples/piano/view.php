<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Audio\Audio;
use MyPHP\UI\{Context, Node};

return function (Context $c): void {
    $t = $c->theme;
    $c->column(20, 18, 1)->alignItems(Node::CENTER)->justify(Node::CENTER);
      $c->row(0, 8);
        foreach (SONGS as $i => [$name]) {
            if ($c->clicked($c->key($name)->button($name))) { $this->play($i); }
        }
        $stop = $c->key('stop')->button('Stop');
        $stop->disabled = $this->song === null;
        if ($c->clicked($stop) && $this->song !== null) { $this->stop(); }
      $c->end();
      $kb = $c->box(keyboardWidth(), KEYBOARD_HEIGHT, 0)->focusable()->autoFocus()->label('Piano')
          ->handleInput($this->input(...))->draw($this->draw(...));
      $kb->color = null;
      $c->text("Keys Z–M and Q–I play from C{$this->octave} · Space sustains · ← → change octaves", 12, $t->textMuted);
      if (($err = Audio::err()) !== null) { $c->text($err->getMessage(), 12, $t->textMuted); }
    $c->end();
};
