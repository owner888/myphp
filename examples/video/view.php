<?php
// A player's window: its film, or what failed to open; $this the player's MediaPlayerState (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\Context;

return function (Context $c): void {
    if ($this->player !== null || $this->opening !== null) { // a film, or a URL opening (the player's indicator)
        $c->mediaPlayer($this);
        return;
    }
    $c->column(0, 0, 1, 0x000000)->center();
      if ($this->error !== '') { $c->text($this->error, 13, 0xff6b6b); }
    $c->end();
};
