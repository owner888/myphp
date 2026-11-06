<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Cursor, Painter};

// The hole fills the window, drawn by the shaders and orbited by the pointer (its own input, as an NSView's in a
// representable); the title and the hint over it, at its bottom.
return fn() => ZStack(alignment: Alignment::bottomLeading)(
    Representable(function (Context $c) {
        $reduce = $c->preferences()->reduceMotion;
        $n = $c->box(0, 0, 0x000000)->cursor($this->down ? Cursor::Grabbing : Cursor::Grab)->handleInput($this->input(...));
        $n->draw(fn(Painter $p, array $r) => $this->draw($p, $r, $reduce));
        return $n;
    }, fills: true),
    HStack(alignment: VerticalAlignment::bottom)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            Text('Gargantua')->font(Font::system(size: 22, weight: Weight::semibold))->foregroundStyle(Color::white->opacity(WHITE_ALPHA)),
            Text('A black hole, its light traced through gravity by shaders written in PHP')->font(Font::system(size: 12))->foregroundStyle(Color::white->opacity(MUTED_ALPHA)),
        ),
        Spacer(),
        Text('Drag to orbit · Scroll to zoom')->font(Font::system(size: 12))->foregroundStyle(Color::white->opacity(MUTED_ALPHA))->opacity($this->hint),
    )->padding(Edge::leading, 30)->padding(Edge::trailing, 28)->padding(Edge::bottom, 26),
);
