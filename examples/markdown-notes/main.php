<?php
// Markdown notes, the second real tool on MyPHP: notes are .md files in a
// folder (~/Library/Application Support/Notes/notes, or the folder on the
// command line); a list on the left, the editor in the middle, a live
// preview on the right (Markdown()). Edits save themselves half a second after you stop
// typing (Cmd+S saves now). Cmd+N makes a note, Cmd+Backspace deletes the
// chosen one. The preview scrolls along with the editor.
// Run: myphp run -d macos examples/markdown-notes [folder]
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, PathName, Window, WindowOptions};
use MyPHP\UI\Content;
use MyPHP\UI\Markdown\{Markdown, Options};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

/** The notes folder: files, the chosen one, its text in the editor, and saving. */
final class Notes
{
    /** @var list<array{path: string, title: string, mtime: int}> newest first */
    public array $items = [];
    /** The text of the open note, as the editor edits it. */
    public string $text = '';
    /** Where the preview scrolls to, following the editor; how far it scrolls. */
    public ScrollPosition $preview;
    public float $previewRange = 0;
    public ?string $open = null;
    /** The note chosen in the list, opened as it is chosen. */
    public ?string $chosen = null;
    private string $saved = '';

    /** The text as last written to the file. */
    public function savedText(): string
    {
        return $this->saved;
    }
    private int $edit = 0;
    public ?Window $window = null;
    /** The search: what is typed, and the items whose titles match it, as indexes into $items. */
    public string $query = '';
    /** @var list<int> */
    public array $shown = [];
    public bool $previewing = true, $asking = false;
    /** How the preview shows Markdown: images from the notes' folder. */
    public Options $md;

    public function __construct(public readonly string $dir)
    {
        is_dir($dir) || mkdir($dir, 0755, true) || throw new RuntimeException("cannot create $dir");
        $this->preview = new ScrollPosition();
        $this->md = new Options(loadImage: Markdown::fileImages($dir));
        $this->scan();
    }

    /** The items the search shows, in order. @return list<array{path: string, title: string, mtime: int}> */
    public function shown(): array
    {
        $q = trim($this->query);
        return array_values(array_filter($this->items, fn(array $it) => $q === '' || stripos($it['title'], $q) !== false));
    }

    /** The preview scrolled the same share of the way through as the editor, $f. */
    public function follow(float $f): void
    {
        $this->preview->scrollTo(y: $f * $this->previewRange);
    }

    /** Words in the editor: runs between spaces, as mygo's notes count them. */
    public function words(): int
    {
        return count(preg_split('/\s+/u', $this->text, -1, PREG_SPLIT_NO_EMPTY));
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
        $this->open = $this->chosen = $path;
        $this->saved = $this->text = (string)file_get_contents($path);
        $this->preview->scrollTo(y: 0);
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
        $this->open = $this->chosen = null;
        $this->text = $this->saved = '';
        $this->scan();
    }

    /** Writes the editor's text when it differs from the file. */
    public function save(): void
    {
        if ($this->open === null || $this->text === $this->saved) { return; }
        file_put_contents($this->open, $this->text);
        $this->saved = $this->text;
        $this->scan(); // the title or order may have changed
    }

    /** An edit schedules a save half a second after the last change. */
    public function tick(): void
    {
        if ($this->open === null || $this->text === $this->saved) { return; }
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
