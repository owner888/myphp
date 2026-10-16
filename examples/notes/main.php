<?php
// Markdown notes, the second real tool on MyPHP: notes are .md files in a
// folder (~/Library/Application Support/Notes/notes, or the folder on the
// command line); a list on the left, the editor in the middle, a live
// preview on the right. Edits save themselves half a second after you stop
// typing (Cmd+S saves now). Cmd+N makes a note, Cmd+Backspace deletes the
// chosen one. The preview scrolls along with the editor.
// Run: ./run.sh notes [folder]
declare(strict_types=1);

use MyPHP\{App, Dialog, Menu, MenuItem, MessageOptions, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, Image, ListState, Node, ScrollState, Span, TextState, Theme};

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
    private int $edit = 0;
    public ?Window $window = null;
    /** The editor scroll share the preview was last moved to. */
    public float $followed = 0;
    /** The panes' widths, as the dividers leave them. */
    public float $listW = 220, $editorW = 380;

    public function __construct(public readonly string $dir)
    {
        is_dir($dir) || mkdir($dir, 0755, true) || throw new RuntimeException("cannot create $dir");
        $this->list = new ListState();
        $this->list->selected = -1;
        $this->editor = new TextState();
        $this->editor->multiline = true;
        $this->preview = new ScrollState();
        $this->scan();
    }

    public function scan(): void
    {
        $this->items = [];
        foreach (glob($this->dir . '/*.md') as $path) {
            $first = strtok((string)file_get_contents($path, false, null, 0, 200), "\n");
            $this->items[] = ['path' => $path, 'title' => trim(ltrim((string)$first, '# ')) ?: basename($path, '.md'), 'mtime' => filemtime($path)];
        }
        usort($this->items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        $this->list->count = count($this->items);
        $this->list->selected = -1;
        foreach ($this->items as $i => $it) {
            if ($it['path'] === $this->open) { $this->list->selected = $i; }
        }
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
        App::after(0.5, function () use ($n) { if ($n === $this->edit) { $this->save(); $this->window?->update(); } });
    }
}

/** A small Markdown-to-UI renderer: headings, paragraphs with inline styles, bullets, code blocks, images. */
final class Markdown
{
    /** @var array<string, Image> */
    private static array $images = [];

    public static function render(Context $c, string $md, float $width, string $baseDir): void
    {
        $t = $c->theme;
        $lines = explode("\n", $md);
        $para = [];
        $flush = function () use (&$para, $c, $t, $width) {
            if ($para !== []) {
                $c->rich(self::inline(implode(' ', $para), $t), 14, width: $width);
                $para = [];
            }
        };
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if (str_starts_with($line, '```')) { // a code block to the closing fence
                $flush();
                $code = [];
                for ($i++; $i < $n && !str_starts_with($lines[$i], '```'); $i++) { $code[] = $lines[$i]; }
                $c->column(10, 2, 0, $t->surface);
                  foreach ($code as $cl) { $c->text($cl === '' ? ' ' : $cl, 12, $t->text, Font::MONO); }
                $c->end();
            } elseif (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
                $flush();
                $c->rich(self::inline($m[2], $t), [24, 19, 16][strlen($m[1]) - 1], width: $width);
            } elseif (preg_match('/^!\[[^\]]*\]\(([^)]+)\)\s*$/', $line, $m)) {
                $flush();
                $path = str_starts_with($m[1], '/') ? $m[1] : "$baseDir/{$m[1]}";
                if (is_file($path)) {
                    self::$images[$path] ??= Image::fromFile($path, 512);
                    $c->image(self::$images[$path], min($width, self::$images[$path]->w / 2), 0, 6);
                } else {
                    $c->muted("missing image: {$m[1]}", 12);
                }
            } elseif (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
                $flush();
                $c->row(0, 6)->align(Node::START); $c->text('•', 14, $t->textMuted); $c->rich(self::inline($m[1], $t), 14, width: $width - 16); $c->end();
            } elseif (trim($line) === '') {
                $flush();
            } else {
                $para[] = $line;
            }
        }
        $flush();
    }

    /** `**bold**`, `*italic*`, `` `code` `` and `[text](url)` into spans. */
    private static function inline(string $s, Theme $t): array
    {
        $spans = [];
        $parts = preg_split('/(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`|\[[^\]]+\]\([^)]+\))/', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $p) {
            if (str_starts_with($p, '**')) { $spans[] = new Span(substr($p, 2, -2), style: Font::BOLD); }
            elseif (str_starts_with($p, '`')) { $spans[] = new Span(substr($p, 1, -1), style: Font::MONO, background: $t->surface); }
            elseif (str_starts_with($p, '*')) { $spans[] = new Span(substr($p, 1, -1), style: Font::ITALIC); }
            elseif (preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/', $p, $m)) { $spans[] = new Span($m[1], color: $t->accent, underline: true); }
            else { $spans[] = new Span($p); }
        }
        return $spans;
    }
}

App::setName('Notes'); // before userDataPath(), which is named after the app
$notes = new Notes($argv[1] ?? App::userDataPath() . '/notes');

function view(Context $c, Notes $notes): void
{
    $t = $c->theme;
    $notes->tick();
    // Three panes the dividers resize: notes | editor | preview.
    $c->split($notes->listW, function () use ($c, $t, $notes) {
        $c->column(0, 0, 1, $t->surface);
          $c->row(10, 8); $c->text('Notes', 15, style: Font::BOLD); if ($c->clicked($c->primaryButton('New'))) { $notes->create(); } $c->end();
          $c->list($notes->list, count($notes->items), function (int $i) use ($c, $t, $notes) {
              $it = $notes->items[$i];
              $c->column(10, 2); $c->text($it['title'], 13, style: $it['path'] === $notes->open ? Font::BOLD : 0);
                $c->muted(date('M j, H:i', $it['mtime']), 11); $c->end();
          });
          if ($notes->list->changed() && $notes->list->selected >= 0 && $notes->items[$notes->list->selected]['path'] !== $notes->open) {
              $notes->choose($notes->items[$notes->list->selected]['path']);
          }
        $c->end();
    }, function () use ($c, $t, $notes) {
        $c->split($notes->editorW, function () use ($c, $notes) {
            if ($notes->open === null) {
                $c->column(20, 8); $c->muted('Choose a note, or make one with New (Cmd+N).'); $c->end();
            } else {
                $c->textarea($notes->editor, 'Write Markdown…', 0, 0, 14); // 0, 0: fills the pane
            }
        }, function () use ($c, $t, $notes) {
            // The preview follows the editor: scrolled the same share of the way through.
            if ($notes->editor->scrollFraction !== $notes->followed) {
                $notes->followed = $notes->editor->scrollFraction;
                $notes->preview->scrollTo($notes->followed * $notes->preview->maxY, false);
            }
            $previewW = $c->width - $notes->listW - $notes->editorW - 2;
            $c->column(16, 10, 1, $t->background)->scroll($notes->preview);
              Markdown::render($c, $notes->editor->text, $previewW - 32, $notes->dir);
            $c->end();
        });
    });
}

$confirmDelete = function () use ($notes) {
    if ($notes->open === null) { return; }
    $r = Dialog::message(new MessageOptions(parent: Window::key(), type: 'warning', message: 'Delete this note?',
        detail: basename($notes->open), buttons: ['Delete', 'Cancel'], cancelButton: 1));
    if ($r->button === 0) { $notes->delete(); $notes->window?->update(); }
};
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('File', submenu: [
        new MenuItem('New Note', function () use ($notes) { $notes->create(); $notes->window?->update(); }, 'CmdOrCtrl+N'),
        new MenuItem('Save', function () use ($notes) { $notes->save(); $notes->window?->update(); }, 'CmdOrCtrl+S'),
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
