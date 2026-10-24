<?php
// A file manager, the fifth real tool on MyPHP, built from the controls:
// a sidebar of places, breadcrumbs for the path, the folder's entries as
// a grid of icons or a sortable table (the toolbar switches), several
// chosen with Cmd and Shift, a double click or Enter opens a folder (or
// the file, with the system), Backspace goes up, Cmd+F finds among the
// entries, Space previews an image in a modal, Delete… asks before
// moving to the Trash. The window remembers its frame.
// Run: ./run.sh files [folder]
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Shell, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, GridState, Image, Key, ListState, Node, Selection, SVG, TableColumn, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** The folder shown: its entries, how they are shown and chosen, the find bar. */
final class Files
{
    public string $path;
    /** @var list<array{name: string, path: string, dir: bool, size: int, mtime: int}> the entries, filtered by the find bar and sorted by the table */
    public array $entries = [];
    /** @var array<string, int> the index of each entry by path */
    private array $index = [];
    public string $place = 'home';
    public int $view = 0; // 0 a grid of icons, 1 a table
    public GridState $grid;
    public ListState $table;
    public Selection $chosen;
    /** @var list<TableColumn> */
    public array $columns;
    public bool $finding = false;
    /** Whether the entries were last read with the find bar open (its query filtering them). */
    public bool $filtered = false;
    public TextState $find;
    public int $found = 0;
    public bool $asking = false;
    public ?Image $preview = null;
    public bool $previewing = false;
    public ?Window $window = null;
    /** @var array<string, SVG> */
    public array $icons;
    /** @var array<string, Image> thumbnails of images in the folder, by path, as they are decoded between frames */
    public array $thumbs = [];
    public array $wanted = [];
    private bool $decoding = false;
    public string $status = '';

    public function __construct(string $path)
    {
        $this->grid = new GridState; $this->grid->selects = true; $this->grid->label = fn(int $i) => $this->entries[$i]['name'];
        $this->table = new ListState; $this->table->selected = -1;
        $this->chosen = new Selection; $this->grid->selection = $this->chosen; $this->table->selection = $this->chosen;
        $this->grid->key = $this->table->key = fn(int $i) => $this->entries[$i]['path']; // the choice follows the entries as they are sorted or filtered
        $this->columns = [new TableColumn('Name', minWidth: 160, sortable: true), new TableColumn('Size', 90, align: Node::END, sortable: true), new TableColumn('Modified', 150, sortable: true)];
        $this->find = new TextState;
        $this->icons = ['folder' => SVG::fromFile(__DIR__ . '/icons/folder.svg'), 'file' => SVG::fromFile(__DIR__ . '/icons/file.svg')];
        $this->open($path);
    }

    /** Shows folder $path: its entries read, folders first, the choice cleared, the table scrolled to the top. */
    public function open(string $path): void
    {
        $path = rtrim(realpath($path) ?: $path, '/') ?: '/';
        if (!is_dir($path) || !is_readable($path)) { $this->status = "Can't open $path"; return; }
        $this->path = $path;
        $this->chosen->clear(); $this->grid->selected = -1; $this->table->selected = -1;
        $this->thumbs = []; $this->wanted = [];
        $this->table->scrollTo(0, false); $this->grid->scrollTo(0);
        $this->table->forgetHeights();
        $this->reload();
        $this->place = array_search($path, self::places(), true) ?: '';
        $this->status = '';
        $base = basename($path); // a folder named 0 is a name, not nothing: not ?:
        $this->window?->setTitle($base !== '' ? $base : $path);
    }

    /** Reads the folder again: the entries the find bar leaves, in the table's order. */
    public function reload(): void
    {
        $entries = [];
        foreach (scandir($this->path) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f[0] === '.') { continue; }
            $p = "$this->path/$f";
            $entries[] = ['name' => $f, 'path' => $p, 'dir' => is_dir($p), 'size' => is_dir($p) ? -1 : (filesize($p) ?: 0), 'mtime' => filemtime($p) ?: 0];
        }
        $q = $this->finding ? trim($this->find->text) : '';
        if ($q !== '') { $entries = array_values(array_filter($entries, fn($e) => stripos($e['name'], $q) !== false)); }
        [$by, $desc] = $this->table->sort ?? ['Name', false];
        $key = fn(array $e) => match ($by) { 'Size' => $e['size'], 'Modified' => $e['mtime'], default => strtolower($e['name']) };
        usort($entries, fn($a, $b) => $a['dir'] !== $b['dir'] ? ($a['dir'] ? -1 : 1) : ($desc ? $key($b) <=> $key($a) : $key($a) <=> $key($b)));
        $this->entries = $entries;
        $this->index = array_flip(array_column($entries, 'path'));
    }

    /** The places of the sidebar: id => path. @return array<string, string> */
    public static function places(): array
    {
        $home = getenv('HOME') ?: '/';
        $places = ['home' => $home];
        foreach (['Desktop', 'Documents', 'Downloads', 'Pictures'] as $d) { if (is_dir("$home/$d")) { $places[strtolower($d)] = "$home/$d"; } }
        $places['root'] = '/';
        return $places;
    }

    /** The entries chosen, in order; by the choice's keys, not a pass over the entries. @return list<array> */
    public function chosenEntries(): array
    {
        $at = [];
        foreach ($this->chosen->all() as $path) { if (isset($this->index[$path])) { $at[] = $this->index[$path]; } }
        sort($at);
        return array_map(fn(int $i) => $this->entries[$i], $at);
    }

    /** Opens entry $i: a folder is shown, a file goes to the system. */
    public function enter(int $i): void
    {
        $e = $this->entries[$i] ?? null;
        if ($e === null) { return; }
        if ($e['dir']) { $this->open($e['path']); } else { Shell::openPath($e['path']); }
    }

    /** Moves the entries chosen to the Trash, through Finder, and reads the folder again. */
    public function trash(): void
    {
        $paths = array_map(fn($e) => $e['path'], $this->chosenEntries());
        if ($paths === []) { return; }
        $list = implode(', ', array_map(fn($p) => 'POSIX file ' . json_encode($p), $paths));
        $out = []; $code = 0;
        exec('osascript -e ' . escapeshellarg("tell application \"Finder\" to delete {{$list}}") . ' 2>&1', $out, $code);
        $this->status = $code === 0 ? count($paths) . ' moved to the Trash' : 'Trash failed: ' . implode(' ', $out);
        $this->chosen->clear();
        $this->reload();
    }

    public static function isImage(array $e): bool
    {
        return !$e['dir'] && preg_match('/\.(jpe?g|png|gif|heic|heif|webp|tiff?|bmp)$/i', $e['name']) === 1;
    }

    /** The thumbnail of an image entry if decoded; wanted otherwise, decoded between frames one a run-loop turn. */
    public function thumb(array $e): ?Image
    {
        if (isset($this->thumbs[$e['path']])) { return $this->thumbs[$e['path']]; }
        if (!in_array($e['path'], $this->wanted, true)) { $this->wanted[] = $e['path']; }
        $this->decode();
        return null;
    }

    private function decode(): void
    {
        if ($this->decoding) { return; }
        $this->decoding = true;
        App::later($next = function () use (&$next) {
            $p = array_shift($this->wanted);
            if ($p === null) { $this->decoding = false; return; }
            if (!isset($this->thumbs[$p])) { $this->thumbs[$p] = Image::fromFile($p, 128); $this->window?->invalidate(); }
            App::later($next);
        });
    }

    public static function size(int $bytes): string
    {
        if ($bytes < 0) { return '—'; }
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $u) { if ($bytes < 1024 ** ($i + 1) || $u === 'GB') { return $i === 0 ? "$bytes B" : sprintf('%.1f %s', $bytes / 1024 ** $i, $u); } }
        return '';
    }
}

function view(Context $c, Files $f): void
{
    $t = $c->theme;
    // Keys for the folder: Cmd+F finds, Backspace goes up, Enter opens the entry chosen, Space previews an image, Delete… asks.
    if ($c->shortcut('f', Key::COMMAND)) { $f->finding = true; }
    if ($c->shortcut(Key::DELETE, Key::COMMAND) && $f->chosenEntries() !== []) { $f->asking = true; }
    $c->row(0, 0, 1)->align(Node::STRETCH);
      // Places
      $side = $c->sidebar($f->place, function () use ($c, $f) {
          $open = null;
          $c->sidebarSection('Places', $open, function () use ($c, $f) {
              foreach (Files::places() as $id => $path) { $c->sidebarItem($id, $id === 'root' ? 'Macintosh HD' : ($id === 'home' ? basename($path) : ucfirst($id))); }
          });
      }, 180);
      if ($c->changed($side) && isset(Files::places()[$f->place])) { $f->open(Files::places()[$f->place]); }
      $c->column(0, 0, 1);
        // The find bar, over the folder, filtering its entries as the query changes.
        $hits = $f->finding && $f->find->text !== '' ? count($f->entries) : 0;
        $bar = $c->findBar($f->finding, $f->find, $hits, $f->found);
        if ($f->find->seen !== $f->find->text || $f->filtered !== $f->finding) { $f->filtered = $f->finding; $f->reload(); } // the query or the bar changed: the entries it leaves
        $f->find->seen = $f->find->text;
        if ($bar !== null && $c->changed($bar) && $f->entries !== []) { $f->grid->selected = $f->table->selected = $f->found; $f->chosen->clear(); $f->chosen->add($f->entries[$f->found]['path']); }
        // The toolbar: the path, the view, Delete….
        $c->toolbar(function () use ($c, $f) {
            $crumbs = array_values(array_filter(explode('/', $f->path), fn($s) => $s !== '')); array_unshift($crumbs, 'Macintosh HD');
            $at = count($crumbs) - 1;
            if ($c->changed($c->breadcrumbs($crumbs, $at))) { $f->open('/' . implode('/', array_slice($crumbs, 1, $at))); }
            $c->spacer();
            if ($c->changed($c->segmented(['Icons', 'List'], $f->view))) { $f->chosen->clear(); }
            if ($c->clicked($c->button('Delete…')) && $f->chosenEntries() !== []) { $f->asking = true; }
        });
        $c->divider();
        if ($f->view === 0) { grid($c, $f); } else { table($c, $f); }
        $c->divider();
        $c->row(10, 10)->align(Node::CENTER);
          $n = count($f->chosenEntries());
          $c->muted($f->status !== '' ? $f->status : ($n > 0 ? "$n of " . count($f->entries) . ' chosen' : count($f->entries) . ' items'), 12);
        $c->end();
      $c->end();
    $c->end();
    // Space previews the image chosen, Escape or Space closes the preview.
    $one = $f->chosenEntries(); $one = count($one) === 1 ? $one[0] : null;
    if (!$f->previewing && $one !== null && Files::isImage($one) && !$f->finding && $c->shortcut(Key::SPACE)) { $f->preview = Image::fromFile($one['path'], 2048); $f->previewing = true; }
    $modal = $c->modal($f->previewing, function () use ($c, $f, $one) {
        if ($f->preview === null) { return; }
        $k = min(($c->width - 120) / $f->preview->w, ($c->height - 160) / $f->preview->h, 1 / $c->scale); // fits the window, never larger than its pixels
        $c->image($f->preview, $f->preview->w * $k, $f->preview->h * $k, $c->theme->radius);
        $c->text($one['name'] ?? '', 13);
        if ($c->shortcut(Key::SPACE)) { $f->previewing = false; }
    });
    if (!$f->previewing) { $f->preview = null; }
    // Delete…: an alert, Delete the default.
    $names = array_map(fn($e) => $e['name'], $f->chosenEntries());
    $what = count($names) === 1 ? "“{$names[0]}”" : count($names) . ' items';
    if ($c->alertDialog($f->asking, "Move $what to the Trash?", 'You can put them back from the Trash.', 'Cancel', 'Delete') === 1) { $f->trash(); }
}

/** The entries as a grid of icons, 96 wide and 108 high: a thumbnail for an image, the folder or file icon otherwise, the name below. */
function grid(Context $c, Files $f): void
{
    $t = $c->theme;
    $g = $c->gridView($f->grid, count($f->entries), 96, 108, function (int $i) use ($c, $t, $f) {
        $e = $f->entries[$i];
        $c->column(0, 4, 1)->align(Node::CENTER);
          $c->column(0, 0)->size(64, 64)->center();
            $img = Files::isImage($e) ? $f->thumb($e) : null;
            if ($img !== null) { $k = min(64 / $img->w, 64 / $img->h); $c->image($img, $img->w * $k, $img->h * $k, $t->radius); } // the longer side fills the 64 square
            else { $c->icon($f->icons[$e['dir'] ? 'folder' : 'file'], 48, $e['dir'] ? $t->accent : $t->textMuted); }
          $c->end();
          $c->text($e['name'], 12)->singleLine();
        $c->end();
    });
    if ($f->grid->rows->submitted() && $f->grid->selected >= 0) { $f->enter($f->grid->selected); }
    if ($c->shortcut(Key::DELETE) && $f->path !== '/' && !$f->finding) { $f->open(dirname($f->path)); }
}

/** The entries as a table: name with its icon, size, modified; the headers sort. */
function table(Context $c, Files $f): void
{
    $t = $c->theme;
    if ($f->table->sortChanged()) { $f->reload(); }
    $c->table($f->table, $f->columns, count($f->entries), function (int $i, int $j) use ($c, $t, $f) {
        $e = $f->entries[$i];
        switch ($j) {
            case 0: $c->row(0, 6)->align(Node::CENTER); $c->icon($f->icons[$e['dir'] ? 'folder' : 'file'], 16, $e['dir'] ? $t->accent : $t->textMuted); $c->text($e['name'])->singleLine(); $c->end(); break;
            case 1: $c->muted(Files::size($e['size'])); break;
            case 2: $c->muted(date('Y-m-d H:i', $e['mtime'])); break;
        }
    });
    if ($f->table->submitted() && $f->table->selected >= 0) { $f->enter($f->table->selected); }
    if ($c->shortcut(Key::DELETE) && $f->path !== '/' && !$f->finding) { $f->open(dirname($f->path)); }
}

$files = new Files($argv[1] ?? (getenv('HOME') ?: '/'));
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('File', submenu: [
        new MenuItem('Enclosing Folder', function () use ($files) { $files->open(dirname($files->path)); $files->window?->invalidate(); }, 'CmdOrCtrl+Up'),
        new MenuItem('Find', function () use ($files) { $files->finding = true; $files->window?->invalidate(); }, 'CmdOrCtrl+F'),
        MenuItem::separator(), new MenuItem(role: 'close'),
    ]),
    new MenuItem('Edit', submenu: [new MenuItem(role: 'selectAll')]),
]));
App::whenReady(function () use ($files) {
    $files->window = new Window(new WindowOptions(title: basename($files->path), width: 960, height: 640, stateKey: 'files',
        content: Content::view(fn(Context $c) => view($c, $files))));
});
App::run();
