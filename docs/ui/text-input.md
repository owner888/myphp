# Text input

`TextField(title, text:)`, `SecureField(title, text:)`, `SearchField(text:)`, and `TextEditor(text:)` bind directly to a `string` property, a `Binding`, or a reactive `State<string>`, as SwiftUI's, with full native IME composition, selection, undo/redo, clipboard shortcuts, and accessibility.

```php
$app = new class {
    public string $name = '';
    public string $password = '';
    public string $notes = '';
    public int $sent = 0;
};
$view = fn() => Form()(
    TextField('Name', text: $app->name, prompt: 'Jane Doe')
        ->onSubmit(fn() => $app->sent++),
    SecureField('Password', text: $app->password),
    TextEditor(text: $app->notes)->frame(height: 80),
);
```

## Styles, multi-line fields and modifiers

- **`axis: Axis::vertical`** on `TextField` makes it grow vertically with its lines up to `->lineLimit($maxLines, reservesSpace: $bool)`, as a chat composer's input field (see [`examples/markdown-chat`](../../examples/markdown-chat)).
- **`->textFieldStyle(TextFieldStyle::roundedBorder)`** (the default) or **`TextFieldStyle::plain`** (borderless, for custom pill or toolbar search fields).
- **`->textInputSuggestions($items)`** shows an autocomplete popup filtered as the user types ([suggestions](autocomplete.md)).
- **`->onSubmit(fn() => …)`** runs when the user presses Return in a `TextField` or `SearchField`.
- **`->defaultFocus()`** focuses the field when it first appears; **`->focused($boolProp)`** reads and writes whether it has focus.
- **`TextEditor(buffer: $textBuffer)`** edits a million-line indexed `TextBuffer` in $O(\log N)$ time without copying the document string ([text-input-client](text-input-client.md)); **`TextEditor(document: $richDoc)`** edits formatted rich text ([rich-text-editor](rich-text-editor.md)).
