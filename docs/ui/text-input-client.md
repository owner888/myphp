# Large text buffers and custom text input (`TextBuffer` & `TextInputClient`)

For ordinary single-line and multi-line text editing, use [`TextField` and `TextEditor(text:)`](text-input.md). When editing huge documents (100,000+ lines) or building a custom text surface with its own storage and selection model, use `TextBuffer` or `TextInputClient`.

## `TextBuffer` with `TextEditor(buffer:)`

A `TextBuffer` is a balanced B-tree of bounded UTF-8 chunks indexing bytes, Unicode scalar values (runes), UTF-16 code units, and newline counts. Edits copy only the affected leaf chunk and tree path in $O(\log N)$ time without copying the whole document string.

Pass `buffer: $document` to the declarative `TextEditor` view:

```php
$document = new TextBuffer(implode("\n", array_map(fn($i) => "Line $i", range(1, 1000))));
$view = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
    Text(verbatim: "{$document->lineCount()} lines · v{$document->version()}")->font(Font::caption)->foregroundStyle(Color::secondary),
    TextEditor(buffer: $document)->monospaced()->frame(height: 240),
)->padding();
```

`snapshot()` captures an $O(1)$ copy-on-write snapshot of the buffer, and `restore($snap)` adopts it immediately; `string()` materializes the full string, and `writeTo($stream)` streams chunks directly to a file. See [`examples/text-buffer`](../../examples/text-buffer) for a 100,000-line editable document.

## Custom `TextInputClient` inside a `Representable`

For a widget with its own storage, multi-caret model, or custom painting (such as [`CodeEditor`](code-editor.md) or [`TerminalView`](../plugins/terminal.md)), implement `MyPHP\UI\TextInputClient` and attach it to a node inside a `Representable` ([custom widgets](custom-widgets.md)):

```php
$view = fn() => Representable(function (Context $c) {
    $n = $c->box(200, 48, $c->theme->surface)->radius(6)->label('Custom field');
    return $n;
});
```

See [`examples/text-input`](../../examples/text-input) for a complete `TextInputClient` implementation with IME preedit composition, UTF-16 surrogate pair clamping, and custom caret rendering.
