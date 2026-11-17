# Code editor

`CodeEditor($state)` edits code in a declarative view, taking the room it is given: `$state` is an `Editor\State` the app keeps over a `Buffer`, with syntax highlighting, multi-cursor editing, code folding, find and replace, completions, hovers, diagnostics, and inlay hints.

```php
use MyPHP\UI\Editor\{Buffer, Languages, State};

$buf = new Buffer("<?php\n\necho 'Hello';\n");
$buf->setLanguage(Languages::for('main.php'));
$code = new State($buf);
$code->wrapGuides = [100];

$view = fn() => CodeEditor($code)->frame(height: 300);
```

It lays out and paints only the lines in view, so a file of a million lines scrolls and edits as smoothly as one of ten. The [code editor example](../../examples/code-editor) edits files across 28 languages and shows live Git diffs.

## Buffers

A `Buffer` holds the text an editor edits, with its undo history and language. Several editors, and both sides of a [`DiffView`](diff-viewer.md), can share one buffer: an edit in one shows in the others, their carets moving with the text around them.

```php
use MyPHP\UI\Editor\{Buffer, Edit};

$buf = new Buffer("one\ntwo\n");
$buf->applyEdits(new Edit(0, 0, "// Generated.\n"));
$buf->transact(function () use ($buf) { // one step of undo
    $buf->applyEdits(new Edit(14, 17, 'uno'));
    $buf->applyEdits(new Edit(18, 21, 'dos'));
});
if ($buf->modified()) {
    $saved = (string)$buf;
    $buf->markSaved();
}
```

Offsets count bytes of UTF-8, and a `Pos` is a 0-based line and byte column: `pos()` and `offset()` convert between them, and `line()`, `slice()` and `(string)` read the text. `set()` replaces the text as an undoable edit, and `reset()` replaces it as a new document without history; `undo()` and `redo()` step through history.

## Options and diagnostics

Configure the editor through properties on `Editor\State`:

- **`readOnly`** — prevents editing while keeping selection, copy, and find.
- **`wrap`** and **`wrapGuides`** — soft-wraps long lines and draws vertical column guides (e.g. `[80, 120]`).
- **`tabSize`** and **`indentStyle`** (`IndentStyle::Spaces` or `IndentStyle::Tabs`).
- **`setDiagnostics($list)`** — underlines ranges with `Diagnostic` (`Severity::Error`, `Warning`, `Information`, `Hint`), shown on hover and jumped between with F8.
- **`setInlayHints($hints)`** and **`setInlineSuggestion($text)`** — renders inline parameter/type hints and ghost-text completions.
- **`setBaseText($text)`** — marks added, modified, and deleted lines in the gutter against a base revision (such as `HEAD`).
