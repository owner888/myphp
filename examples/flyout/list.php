<?php
// The list of colors' flyout, $this the state main.php makes (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Key, Node};

$choose = function (int $i): void {
    $this->color = $i;
    $this->list?->close();
    $this->win->invalidate();
};

return function (Context $c) use ($choose): void {
    $t = $c->theme;
    $n = count(COLORS);
    if ($c->shortcut(Key::DOWN)) { $this->hover = ($this->hover + 1) % $n; }
    if ($c->shortcut(Key::UP)) { $this->hover = ($this->hover + $n - 1) % $n; }
    if ($c->shortcut(Key::RETURN)) { $choose($this->hover); }
    $c->root->color = null;
    panel($c)->padding(6);
      foreach (COLORS as $i => [$name, $color]) {
          $row = $c->key($i)->row(0, 8)->height(28)->padding(0, 8)->alignItems(Node::CENTER)->radius(6);
          if ($i === $this->hover) { $row->background($t->accent); }
          if ($c->hovered($row) && $this->hover !== $i) { $this->hover = $i; $c->runtime?->animate(); }
          if ($c->clicked($row)) { $choose($i); }
            $c->box(12, 12, $color)->radius(6);
            $text = $c->text($name);
            if ($i === $this->hover) { $text->textColor($t->accentText); }
          $c->end();
      }
    $c->end();
};
