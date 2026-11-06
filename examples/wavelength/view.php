<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Node};

// The clip takes the window, a layer of its own (as SwiftUI's AVPlayerLayer in a representable), the title and its
// button over it.
return fn() => ZStack()(
    Representable(fn(Context $c) => $c->video($this->waves)->fit(Node::COVER), fills: true),
    VStack(spacing: 16)(
        Text('Wavelength')->font(Font::system(size: 44, weight: Weight::bold))->foregroundStyle(Color::white),
        Button($this->waves->playing() ? 'Pause' : 'Play', action: fn() => $this->waves->playing() ? $this->waves->pause() : $this->waves->play())
            ->frame(width: 96)->id('play'),
    ),
);
