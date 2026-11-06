<?php
// Markdown notes, the second real tool on MyPHP: notes are .md files in a
// folder (~/Library/Application Support/Notes/notes, or the folder on the
// command line); a list on the left, the editor in the middle, a live
// preview on the right (Markdown::view). Edits save themselves half a second after you stop
// typing (Cmd+S saves now). Cmd+N makes a note, Cmd+Backspace deletes the
// chosen one. The preview scrolls along with the editor.
// Run: ./run.sh markdown-notes [folder]
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, PathName, Window, WindowOptions};
use MyPHP\UI\{Content, ListState, ScrollState, TextState};
use MyPHP\UI\Markdown\{Markdown, Options};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

/** The notes folder: files, the chosen one, its text in the editor, and saving. */
final class Notes
{
    /** @var list<array{path: string, title: string, mtime: int}> newest first */
    public array $items = [];
    public ListState $list;
    public TextState $editor;
    public ScrollState $preview;
    public ?string $open = null;
    private string $saved = '';

    /** The text as last written to the file. */
    public function savedText(): string
    {
        return $this->saved;
    }
    private int $edit = 0;
    public ?Window $window = null;
    /** The editor scroll share the preview was last moved to. */
    public float $followed = 0;
    /** The panes' widths, as the dividers leave them. */
    public float $listW = 220, $editorW = 380;
    /** The search: what is typed, and the items whose titles match it, as indexes into $items. */
    public TextState $query;
    /** @var list<int> */
    public array $shown = [];
    public bool $previewing = true, $asking = false;
    /** How the preview shows Markdown: images from the notes' folder. */
    public Options $md;

    public function __construct(public readonly string $dir)
    {
        is_dir($dir) || mkdir($dir, 0755, true) || throw new RuntimeException("cannot create $dir");
        $this->list = new ListState();
        $this->list->selected = -1;
        $this->editor = new TextState();
        $this->editor->multiline = true;
        $this->preview = new ScrollState();
        $this->query = new TextState();
        $this->md = new Options(loadImage: Markdown::fileImages($dir));
        $this->scan();
    }

    /** The items the search shows, in order, with the list's choice on the open one. */
    public function filter(): void
    {
        $q = trim($this->query->text);
        $this->shown = [];
        foreach ($this->items as $i => $it) {
            if ($q === '' || stripos($it['title'], $q) !== false) { $this->shown[] = $i; }
        }
        $this->list->selected = -1;
        foreach ($this->shown as $row => $i) {
            if ($this->items[$i]['path'] === $this->open) { $this->list->selected = $row; }
        }
    }

    /** Words in the editor: runs between spaces, as mygo's notes count them. */
    public function words(): int
    {
        return count(preg_split('/\s+/u', $this->editor->text, -1, PREG_SPLIT_NO_EMPTY));
    }

    /** When the open note was last written (its file's time). */
    public function edited(): int
    {
        foreach ($this->items as $it) { if ($it['path'] === $this->open) { return $it['mtime']; } }
        return 0;
    }

    public function scan(): void
    {
        $this->items = [];
        foreach (glob($this->dir . '/*.md') as $path) {
            $first = strtok((string)file_get_contents($path, false, null, 0, 200), "\n");
            $title = trim(ltrim((string)$first, '# ')); // a note whose first line is 0 has that title: not ?:
            $this->items[] = ['path' => $path, 'title' => $title !== '' ? $title : basename($path, '.md'), 'mtime' => filemtime($path)];
        }
        usort($this->items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    }

    /** Opens the note at $path in the editor, saving the one before. */
    public function choose(string $path): void
    {
        $this->save();
        $this->open = $path;
        $this->saved = (string)file_get_contents($path);
        $this->editor = new TextState($this->saved);
        $this->editor->multiline = true;
        $this->editor->setCursor(0);
        $this->preview->y = 0;
        $this->scan(); // puts the selection on it
    }

    public function create(): void
    {
        $this->save();
        $path = $this->dir . '/' . date('Y-m-d His') . '.md';
        file_put_contents($path, "# New note\n\n");
        $this->choose($path);
    }

    public function delete(): void
    {
        if ($this->open === null) { return; }
        unlink($this->open);
        $this->open = null;
        $this->editor = new TextState();
        $this->editor->multiline = true;
        $this->saved = '';
        $this->scan();
    }

    /** Writes the editor's text when it differs from the file. */
    public function save(): void
    {
        if ($this->open === null || $this->editor->text === $this->saved) { return; }
        file_put_contents($this->open, $this->editor->text);
        $this->saved = $this->editor->text;
        $this->scan(); // the title or order may have changed
    }

    /** Called each frame: a changed editor schedules a save half a second after the last change. */
    public function tick(): void
    {
        if ($this->open === null || $this->editor->text === $this->saved) { return; }
        $n = ++$this->edit;
        App::after(0.5, function () use ($n) { if ($n === $this->edit) { $this->save(); $this->window?->invalidate(); } });
    }
}

App::setName('Notes'); // before App::path(), which is named after the app
$notes = new Notes($argv[1] ?? App::path(PathName::UserData) . '/notes');

$confirmDelete = function () use ($notes) { // the same modal the toolbar's Delete… opens
    if ($notes->open !== null) { $notes->asking = true; $notes->window?->invalidate(); }
};
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('File', submenu: [
        new MenuItem('New Note', function () use ($notes) { $notes->create(); $notes->window?->invalidate(); }, 'CmdOrCtrl+N'),
        new MenuItem('Save', function () use ($notes) { $notes->save(); $notes->window?->invalidate(); }, 'CmdOrCtrl+S'),
        new MenuItem('Delete Note', $confirmDelete, 'CmdOrCtrl+Backspace'),
        MenuItem::separator(), new MenuItem(role: 'close'),
    ]),
    new MenuItem(role: 'editMenu'),
    new MenuItem(role: 'windowMenu'),
]));

App::whenReady(function () use ($notes) {
    $notes->window = new Window(new WindowOptions(title: 'Notes', width: 1100, height: 680, stateKey: 'markdown-notes',
        content: Content::file(__DIR__ . '/view.php', $notes)));
    if ($notes->items !== []) {
        $notes->choose($notes->items[0]['path']);
    }
});
App::run();
