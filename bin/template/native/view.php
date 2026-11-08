<?php
// The window's view, $this the state app.php makes (Content::file). Saved
// under myphp dev or myphp run, it hot reloads: the window shows the new
// code with the state as it was. Keep it a file of closures: a named class
// or function here waits for a restart (R).
declare(strict_types=1);

return fn() => VStack(spacing: 12)(
    Text('Count: {count}', count: $this->count)->font(Font::title),
    HStack(spacing: 8)(
        Button('Increment', action: $this->increment(...))->buttonStyle(ButtonStyle::borderedProminent),
        Button('Reset', action: $this->reset(...))->disabled($this->count === 0),
    ),
    Text('Native UI drawn by MyPHP on the GPU. Edit view.php and save: it hot reloads.')->foregroundStyle(Color::secondary),
)->padding(24);
