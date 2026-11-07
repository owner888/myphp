<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{ConicGradient, Painter, Path};

/** The send button's icon: an arrow up, or a square while the assistant works. */
$sendIcon = fn(bool $busy) => Canvas(function (GraphicsContext $g, CGSize $s) use ($busy) {
    [$cx, $cy] = [$s->width / 2, $s->height / 2];
    if ($busy) { $g->fill(Path::rect($cx - 5, $cy - 5, 10, 10, 2), with: Color::background); return; }
    $g->stroke((new Path())->moveTo($cx, $cy + 7)->lineTo($cx, $cy - 7)->moveTo($cx - 6, $cy - 1)->lineTo($cx, $cy - 7)->lineTo($cx + 6, $cy - 1), with: Color::background, lineWidth: 2.2);
});

/** The empty conversation: the orb, a greeting, and suggestions to ask. */
$welcome = fn() => VStack(spacing: 10)(
    Canvas(fn(GraphicsContext $g) => $g->withPainter($this->drawOrb(...)))->frame(width: 150, height: 150)->accessibilityLabel('Assistant'),
    Text('How can I help?')->font(Font::system(size: 28, weight: Weight::semibold))->padding(Edge::top, 4),
    Text('Ask anything, or start with one of these.')->font(Font::system(size: 14))->foregroundStyle(Color::secondary),
    HStack(spacing: 8)(Each(SUGGESTIONS)(fn(string $s) => Button(action: fn() => $this->send($s), label: Text($s)->font(Font::system(size: 13))
        ->padding(Edge::vertical, 9)->padding(Edge::horizontal, 14)->overlay(Capsule()->stroke(Color::separator)))->buttonStyle(ButtonStyle::plain)))->padding(Edge::top, 18),
)->padding(Edge::horizontal, 24)->padding(Edge::top, 40)->frame(maxWidth: INF, maxHeight: INF);

/** A message: the user's in a bubble on the right, the assistant's beside its orb, thinking until it writes. */
$message = function (Message $m) {
    if ($m->mine) {
        return HStack()(Spacer(), Text(verbatim: $m->text)->textSelection(TextSelectability::enabled)->padding(Edge::vertical, 9)->padding(Edge::horizontal, 14)
            ->background(Color::gray->opacity(0.15))->cornerRadius(18)->frame(maxWidth: 460, alignment: Alignment::trailing));
    }
    $last = $m === $this->messages[count($this->messages) - 1];
    return HStack(alignment: VerticalAlignment::top, spacing: 12)(
        Canvas(fn(GraphicsContext $g) => $g->withPainter($last ? $this->drawOrb(...) : fn(Painter $p, array $r) => $this->drawStillOrb($p, $r, $m->id)))->frame(width: 30, height: 30)->offset(y: -3),
        $m->shown === 0 && $last && $this->busy
            ? Canvas(fn(GraphicsContext $g) => $g->withPainter(fn(Painter $p, array $r) => $this->drawThinking($p, $r, $p->theme())))->frame(width: 120, height: REPLY_SIZE * REPLY_LEADING)->accessibilityLabel('Thinking')
            : Text(verbatim: implode('', array_slice($m->words, 0, $m->shown)))->font(Font::system(size: REPLY_SIZE))->textSelection(TextSelectability::enabled)->frame(maxWidth: INF, alignment: Alignment::leading),
    );
};

/** The field to write in, in its glowing ring, and the button that sends, or stops the assistant (Escape too). */
$composer = function () use ($sendIcon) {
    $ready = trim($this->input) !== '';
    $send = Button(action: fn() => $this->busy ? $this->stop() : ($ready ? $this->send($this->input) : null), label: $sendIcon($this->busy)->frame(width: 34, height: 34)
        ->background($this->busy || $ready ? Color::primary : Color::separator)->clipShape(Circle()))
        ->buttonStyle(ButtonStyle::plain)->accessibilityLabel($this->busy ? 'Stop' : 'Send');
    if ($this->busy) { $send->keyboardShortcut(KeyEquivalent::escape, modifiers: []); } // Escape stops it
    return HStack(spacing: 8)(
        TextField('Message', text: $this->input, prompt: 'Ask anything')->textFieldStyle(TextFieldStyle::plain)->defaultFocus()->padding(Edge::vertical, 8)
            ->onSubmit(fn() => !$this->busy && $ready && $this->send($this->input)),
        $send,
    )
        ->padding(Edge::vertical, 6)->padding(Edge::trailing, 6)->padding(Edge::leading, 16)->background(Color::secondarySystemBackground)->cornerRadius(FIELD_RADIUS)
        ->padding(RING_PADDING)->background(Canvas(fn(GraphicsContext $g) => $g->withPainter($this->drawRing(...))))
        ->frame(maxWidth: MAX_WIDTH + 2 * RING_PADDING)
        ->padding(Edge::horizontal, 24 - RING_PADDING)->padding(Edge::bottom, 22 - RING_PADDING);
};

/**
 * The window's edges glowing while the assistant works: its outline stroked with an angular gradient of the glow's
 * colors, their stops moving about, a crisp line and lines blurred over it, which the pointer goes through.
 */
$edge = function () {
    if ($this->glow <= 0.001) { return null; }
    $g = new ConicGradient($this->stops->gradient($this->now), angle: 90, oklab: true); // from the right, as SwiftUI's AngularGradient goes, blended in Oklab as SwiftUI blends it
    return ZStack()(Each(GLOW_LAYERS)(fn(array $layer) => RoundedRectangle(cornerRadius: CORNER)->stroke($g, lineWidth: $layer[0])->blur(0.95 * $layer[1])))
        ->opacity(ease($this->glow))->allowsHitTesting(false);
};

// While something moves on its own (the assistant working, the glow, the orb), a frame every frame; the conversation's
// clock follows it.
return fn() => TimelineView(paused: !$this->moving())(function (TimelineViewContext $context) use ($welcome, $message, $composer, $edge) {
    $this->tick($context->nanoseconds);
    // The conversation glides up as the reply grows, but jumps to its end when much came at once, as when the window shows again after it was hidden.
    $shown = array_sum(array_map(fn(Message $m) => $m->shown, $this->messages));
    $glide = $shown - $this->seen <= JUMP_WORDS;
    $this->seen = $shown;
    return ZStack(alignment: Alignment::top)(
        VStack(spacing: 0)(
            $this->messages === [] ? $welcome() : ScrollView()(LazyVStack(spacing: 22)(Each($this->messages, id: fn(Message $m) => $m->id)(fn(Message $m) => $message($m)->frame(maxWidth: MAX_WIDTH))))
                ->defaultScrollAnchor(UnitPoint::bottom)->animation($glide ? Animation::smooth() : null, value: $shown)->padding(Edge::top, 52)->padding(Edge::horizontal, 24)->padding(Edge::bottom, 16),
            $composer(),
        )->background(Color::background)->frame(maxWidth: INF, maxHeight: INF),
        Color(Color::clear)->frame(height: 32)->gesture(WindowDragGesture()), // the title bar, under the window controls, drags the window
        $edge(),
    );
});
