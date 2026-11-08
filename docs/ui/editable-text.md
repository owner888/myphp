# Editable text

`EditableText(text:)` shows a string (bound by reference) as a text that the
user edits in place, as a file's name in Finder: a double click on it, or
Enter while it has the focus, shows a field of it, with the text before its
extension selected. Enter keeps what was typed, as moving the focus away
does, and Escape goes back. SwiftUI has none: it is myphp's own.

```php
$app = new class { public string $title = 'Notes.txt'; public int $renames = 0; };
$view = fn() => EditableText(text: $app->title)
    ->onChange(of: $app->title, action: fn() => $app->renames++)->padding();
```

## Accessibility

Assistive technology sees the text, and the field while it is edited.
