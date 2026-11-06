<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

// A film, or a URL opening (the player's indicator); else the error, if opening failed.
return fn() => $this->player !== null || $this->opening !== null
    ? VideoPlayer(player: $this)
    : VStack()(
        $this->error === '' ? null : Text(verbatim: $this->error)->font(Font::callout)->foregroundStyle(Color::rgb(0xff6b6b)),
    )->frame(maxWidth: INF, maxHeight: INF)->background(Color::black);
