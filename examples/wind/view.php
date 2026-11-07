<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, InputEvent, Painter};

// The texture fills the window, drawn by the shaders and stirred by the pointer (its own input, as an MTKView's in a
// representable); the composer over it, its middle where the texture is calmest, 45% down; the wind's look and seed at
// the bottom, which ⌘R changes.
return fn() => ZStack()(
    Representable(function (Context $c) {
        $this->tex->still = $c->preferences()->reduceMotion; // while the desktop asks for less motion, the texture shows the wind as it stands
        $this->tex->makeAtlases($c);
        $dark = $c->theme->dark;
        return $c->box(0, 0, $c->theme->background)->draw(fn(Painter $p, array $r) => $this->tex->draw($p, $r, $dark))
            ->handleInput(function (InputEvent $ev): bool {
                if ($ev->kind === InputEvent::POINTER_MOVE) { // the pointer stirs the air; a frame, to wake the texture as it rests
                    $calm = $this->tex->calm;
                    $this->tex->move($ev->x, $ev->y, hrtime(true));
                    return $calm;
                }
                if ($ev->kind === InputEvent::POINTER_DOWN && $ev->button === 0) { $this->tex->tap($ev->x, $ev->y, hrtime(true)); return true; } // a click blows a ripple
                return false;
            });
    }, fills: true),
    GeometryReader(fn(GeometryProxy $g) => VStack(spacing: 12)(
        TextField('Task', text: $this->draft, prompt: 'Describe a task or ask a question')->textFieldStyle(TextFieldStyle::plain)
            ->padding(Edge::vertical, 14)->padding(Edge::horizontal, 16)->background(Color::background)->cornerRadius(14)->shadow(Color::black->opacity(0.06), radius: 9, y: 4)
            ->onChange(of: $this->draft, action: fn() => $this->tex->wake()), // typing keeps the wind blowing
        Text('Move the pointer to warm the air, click to blow a ripple (every third a heart), ⌘R for another wind.')->font(Font::caption)->foregroundStyle(Color::secondary)->multilineTextAlignment(TextAlignment::center),
    )->frame(width: min(640, $g->size[0] - 48))->padding(Edge::top, max(0, $g->size[1] * 0.45 - 40))->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::top)),
    VStack()(
        Spacer(),
        HStack()(
            Spacer(),
            Button(action: fn() => $this->tex->reseed(random_int(0, U32)), label: Text(verbatim: sprintf('%s/%08x', $this->tex->w->look, $this->tex->w->seed))->font(Font::system(size: 11, design: FontDesign::monospaced))->foregroundStyle(Color::secondary))
                ->buttonStyle(ButtonStyle::plain)->keyboardShortcut('r')->help('Another wind'),
        ),
    )->padding(Edge::trailing, 14)->padding(Edge::bottom, 10),
);
