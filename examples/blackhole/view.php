<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Cursor, Node, Painter};

return function (Context $c): void {
    $reduce = $c->preferences()->reduceMotion;
    $root = $c->row(0, 0, 0, 0x000000)->fill()->alignItems(Node::END)->padding(0, 28, 26, 30);
    $root->cursor($this->down ? Cursor::Grabbing : Cursor::Grab);
    $root->draw(fn(Painter $p, array $r) => $this->draw($p, $r, $reduce));
    $root->handleInput($this->input(...));
      $c->column(0, 4);
        $c->text('Gargantua', 22)->fontWeight(600)->textColor(0xffffff, WHITE_ALPHA);
        $c->text('A black hole, its light traced through gravity by shaders written in PHP', 12)->textColor(0xffffff, MUTED_ALPHA);
      $c->end();
      $c->spacer();
      $c->text('Drag to orbit · Scroll to zoom', 12)->textColor(0xffffff, MUTED_ALPHA)->opacity($this->hint);
    $c->end();
};
