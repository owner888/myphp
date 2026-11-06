<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\{App, Clipboard};

// The panel of the menu bar's icon: Escape and Cmd+W hide it, Cmd+Q quits, as its buttons' keyboard shortcuts.
return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    HStack(alignment: VerticalAlignment::top, spacing: 12)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            Text('Quick Notes')->font(Font::system(size: 22, weight: Weight::bold)),
            Text('A little space for a quick thought.')->font(Font::caption)->foregroundStyle(Color::secondary),
        )->frame(maxWidth: INF, alignment: Alignment::leading),
        Button('Hide', action: fn() => $this->win?->hide())->accessibilityLabel('Hide Quick Notes')->keyboardShortcut(KeyEquivalent::escape, modifiers: []),
        Button('', action: fn() => $this->win?->hide())->keyboardShortcut('w')->hidden(), // Cmd+W, as a window's Close
    ),
    TextField('Write something to remember…', text: $this->text, axis: Axis::vertical)->accessibilityLabel('Note')->focused($this->editing)->id('note')
        ->frame(maxWidth: INF, maxHeight: INF)->onChange(of: $this->text, action: fn() => $this->status = ''),
    HStack(spacing: 8)(
        Button('Clear', action: function () { [$this->text, $this->status, $this->editing] = ['', '', true]; })->disabled($this->text === ''),
        Spacer(),
        Button('Copy note', action: function () { Clipboard::writeText($this->text); $this->status = 'Copied to clipboard.'; })->buttonStyle(ButtonStyle::borderedProminent)->disabled($this->text === ''),
    ),
    Divider(),
    HStack(spacing: 8)(
        Text($this->status === '' ? 'Kept here until you quit.' : $this->status)->font(Font::caption2)->foregroundStyle(Color::secondary)->frame(maxWidth: INF, alignment: Alignment::leading),
        Button('Quit', action: App::quit(...))->keyboardShortcut('q'),
    ),
)->padding(20);
