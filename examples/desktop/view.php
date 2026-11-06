<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

// The buttons, then the log, whose context menu (a secondary click) opens, saves or clears it, as SwiftUI's contextMenu.
return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
    HStack(spacing: 8)(Each($this->actions)(fn(Closure $run, string $label) => Button($label, action: $run))),
    VStack(alignment: HorizontalAlignment::leading, spacing: 4)(Each(array_slice($this->log, -12), id: fn(string $line, int $i) => $i)(fn(string $line) => Text(verbatim: $line))) // lines repeat: told apart by their place
        ->padding(12)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading)->background(Color::gray->opacity(0.12))
        ->contextMenu(
            Button('Open…', action: $this->open),
            Button('Save As…', action: $this->save),
            Divider(),
            Button('Clear log', action: fn() => $this->log = []),
        ),
)->padding(16);
