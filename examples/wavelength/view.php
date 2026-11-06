<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Node};

/** The interface of the state, built whenever the window needs a frame. */
return function (Context $c): void {
    $c->column()->fill();
      $c->video($this->waves)->fill()->fit(Node::COVER);
      // Over the clip, which takes the window.
      $c->column(0, 16)->absolute()->top(0)->left(0)->right(0)->bottom(0)->center();
        $c->text('Wavelength', 44, 0xffffff, style: Font::BOLD);
        if ($c->clicked($c->key('play')->button($this->waves->playing() ? 'Pause' : 'Play')->size(96, 0))) {
            if ($this->waves->playing()) { $this->waves->pause(); } else { $this->waves->play(); }
        }
      $c->end();
    $c->end();
};
