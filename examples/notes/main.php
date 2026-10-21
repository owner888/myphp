<?php
// Markdown notes, the second real tool on MyPHP: notes are .md files in a
// folder (~/Library/Application Support/Notes/notes, or the folder on the
// command line); a list on the left, the editor in the middle, a live
// preview on the right (Markdown::view). Edits save themselves half a second after you stop
// typing (Cmd+S saves now). Cmd+N makes a note, Cmd+Backspace deletes the
// chosen one. The preview scrolls along with the editor.
// Run: ./run.sh notes [folder]
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, ListState, Node, ScrollState, TextState};
use MyPHP\UI\Markdown\{Markdown, Options};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

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
            $this->items[] = ['path' => $path, 'title' => trim(ltrim((string)$first, '# ')) ?: basename($path, '.md'), 'mtime' => filemtime($path)];
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

App::setName('Notes'); // before userDataPath(), which is named after the app
$notes = new Notes($argv[1] ?? App::userDataPath() . '/notes');

function view(Context $c, Notes $notes): void
{
    $t = $c->theme;
    $notes->tick();
    $notes->filter();
    // Three panes the dividers resize: notes | editor | preview (while previewing).
    $c->split($notes->listW, function () use ($c, $t, $notes) {
        $c->column(0, 0, 1, $t->surface);
          $c->column(8, 6);
            $c->searchField($notes->query, 0); // as wide as the pane
            $c->toolbar(function () use ($c, $notes) {
                if ($c->clicked($c->button('New'))) { $notes->create(); }
                if ($c->clicked($c->button('Delete…')) && $notes->open !== null) { $notes->asking = true; }
                $c->spacer();
                $c->toggle($notes->previewing, 'Preview');
            });
          $c->end();
          $c->divider();
          $c->list($notes->list, count($notes->shown), function (int $row) use ($c, $t, $notes) {
              $it = $notes->items[$notes->shown[$row]];
              $c->column(10, 2); $c->text($it['title'], 13, style: $it['path'] === $notes->open ? Font::BOLD : 0)->singleLine();
                $c->muted(date('M j, H:i', $it['mtime']), 11); $c->end();
          });
          if ($notes->shown === [] && $notes->query->text !== '') { $c->column(10, 0); $c->muted('No note matches.', 12); $c->end(); }
          if ($notes->list->changed() && $notes->list->selected >= 0 && $notes->items[$notes->shown[$notes->list->selected]]['path'] !== $notes->open) {
              $notes->choose($notes->items[$notes->shown[$notes->list->selected]]['path']);
          }
        $c->end();
    }, function () use ($c, $t, $notes) {
        $editor = function () use ($c, $t, $notes) {
            $c->column(0, 0, 1);
              if ($notes->open === null) {
                  $c->column(20, 8); $c->muted('Choose a note, or make one with New (Cmd+N).'); $c->end();
              } else {
                  $c->textarea($notes->editor, 'Write Markdown…', 0, 0, 14); // 0, 0: fills the pane
                  $c->row(8, 8)->align(Node::CENTER);
                    $c->muted(sprintf('%d words · edited %s', $notes->words(), date('M j, H:i', $notes->edited())), 12); $c->spacer();
                    $c->badge($notes->editor->text === $notes->savedText() ? 'saved' : 'editing');
                  $c->end();
              }
            $c->end();
        };
        if (!$notes->previewing) { $editor(); return; }
        $c->split($notes->editorW, $editor, function () use ($c, $t, $notes) {
            // The preview follows the editor: scrolled the same share of the way through.
            if ($notes->editor->scrollFraction !== $notes->followed) {
                $notes->followed = $notes->editor->scrollFraction;
                $notes->preview->scrollTo($notes->followed * $notes->preview->maxY, false);
            }
            $c->column(16, 10, 1, $t->background)->scroll($notes->preview);
              Markdown::view($c, $notes->editor->text, $notes->md);
            $c->end();
        });
    });
    // Deleting asks first.
    $c->modal($notes->asking, function () use ($c, $notes) {
        $c->text('Delete this note?', 15, style: Font::BOLD);
        $c->muted(basename((string)$notes->open) . " goes to nowhere: there is no undo.");
        $c->row(0, 8)->justify(Node::END);
          if ($c->clicked($c->button('Cancel'))) { $notes->asking = false; }
          if ($c->clicked($c->primaryButton('Delete'))) { $notes->asking = false; $notes->delete(); $c->toast('Note deleted'); }
        $c->end();
    });
}

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
    $notes->window = new Window(new WindowOptions(title: 'Notes', width: 1100, height: 680, stateKey: 'notes',
        content: Content::view(fn(Context $c) => view($c, $notes))));
    if ($notes->items !== []) {
        $notes->choose($notes->items[0]['path']);
    }
});
App::run();
