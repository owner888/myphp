# Editable text

`$c->editableText()` shows a string (by reference) as a text that the user
edits in place, as a file's name in Finder: a double click on it, or Enter
while it has the focus, shows a text input of it, with the text before its
extension selected. Enter keeps what was typed, as moving the focus away
does, and Escape goes back; `$c->changed()` reports a new value.

```php
$app = new class {
    public string $title = 'Notes.txt';
    public int $saves = 0;
    public function save(): void { $this->saves++; }
};
$view = function (Context $c) use ($app) {
    if ($c->changed($c->editableText($app->title))) {
        $app->save();
    }
};
```

## In lists and tables

In a [list](list.md)'s or a [table](table.md)'s rows, the list keeps the
focus and the text takes none: Enter and F2 edit the text of the row
chosen, while a double click on the row is `submitted()`; a click on the
text of a row already chosen edits it, once a second click would no longer
make a double click.

```php
$app = new class {
    public ListState $files;
    public array $names = ['a.txt', 'b.txt', 'c.txt'];
    public function __construct() { $this->files = new ListState(); $this->files->selected = 0; }
    public function rename(int $row): void {}
};
$cols = [new TableColumn('Name', 200), new TableColumn('Size', 80)];
$view = function (Context $c) use ($app, $cols) {
    $c->table($app->files, $cols, count($app->names), function (int $row, int $col) use ($c, $app) {
        if ($col === 0) {
            if ($c->changed($c->editableText($app->names[$row]))) {
                $app->rename($row);
            }
        } else {
            $c->text('1 KB');
        }
    });
};
```

## Accessibility

Assistive technology reads the text, and the text field while it is
edited.
