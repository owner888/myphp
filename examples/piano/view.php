<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Audio\Audio;
use MyPHP\UI\Context;

// The songs' buttons, the keyboard (drawn and played by the example's own input, as an NSView in a representable), and
// its keys.
return fn() => VStack(spacing: 18)(
    HStack(spacing: 8)(
        Each(SONGS)(fn(array $song, int $i) => Button($song[0], action: fn() => $this->play($i))->id($song[0])),
        Button('Stop', action: fn() => $this->stop())->disabled($this->song === null)->id('stop'),
    ),
    Representable(function (Context $c) {
        $kb = $c->box(keyboardWidth(), KEYBOARD_HEIGHT, 0)->focusable()->autoFocus()->label('Piano')->handleInput($this->input(...))->draw($this->draw(...));
        $kb->color = null;
        return $kb;
    }),
    Text(verbatim: "Keys Z–M and Q–I play from C{$this->octave} · Space sustains · ← → change octaves")->font(Font::system(size: 12))->foregroundStyle(Color::secondary),
    ($err = Audio::err()) === null ? null : Text(verbatim: $err->getMessage())->font(Font::system(size: 12))->foregroundStyle(Color::secondary),
)->padding(20)->frame(maxWidth: INF, maxHeight: INF);
