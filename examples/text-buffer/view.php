<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
    Text('Indexed text input')->font(Font::system(size: 22, weight: Weight::bold)),
    Text(verbatim: sprintf('%d lines · %d runes · version %d', $this->lineCount(), $this->len(), $this->version())),
    TextEditor(buffer: $this)->monospaced()->accessibilityLabel('Document')->defaultFocus(),
)->padding(20);
