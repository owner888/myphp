<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

// Three buttons, each with a popover: below, the colors to choose from; to the right, the note; above, the About box.
return fn() => VStack(spacing: 12)(
    HStack(spacing: 8)(
        Circle()->fill(Color::rgb(COLORS[$this->color][1]))->frame(width: 14, height: 14),
        Button(COLORS[$this->color][0], action: fn() => $this->choosing = !$this->choosing)
            ->popover(isPresented: $this->choosing, content: fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                Each(COLORS, id: fn($c) => $c[0])(fn(array $c, int $i) => Button(action: function () use ($i) { $this->color = $i; $this->choosing = false; }, label: HStack(spacing: 8)(
                    Circle()->fill(Color::rgb($c[1]))->frame(width: 12, height: 12),
                    Text($c[0]),
                    Spacer(),
                    $i === $this->color ? Image(systemName: 'checkmark')->foregroundStyle(Color::secondary) : null,
                )->padding(Edge::horizontal, 8)->padding(Edge::vertical, 5))->buttonStyle(ButtonStyle::plain)),
            )->padding(6)->frame(width: 180)),
    ),
    Button('Edit note…', action: fn() => $this->editing = true)
        ->popover(isPresented: $this->editing, arrowEdge: Edge::trailing, content: fn() => TextField('Note', text: $this->note)->frame(width: 240)->padding(12)),
    Button('About', action: fn() => $this->about = true)
        ->popover(isPresented: $this->about, arrowEdge: Edge::top, content: fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            Text('Flyout')->bold(),
            Text('Popovers outside the window, placed next to what opens them.')->font(Font::caption)->foregroundStyle(Color::secondary),
        )->padding(12)->frame(width: 240, alignment: Alignment::leading)),
    Text(verbatim: $this->note === '' ? 'No note' : $this->note)->font(Font::caption)->foregroundStyle(Color::secondary),
)->padding(16);
