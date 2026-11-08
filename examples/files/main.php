<?php
// A file manager, the fifth real tool on MyPHP, built from the controls:
// a sidebar of places, breadcrumbs for the path, the folder's entries as
// a grid of icons or a sortable table (the toolbar switches), several
// chosen with Cmd and Shift, a double click or Enter opens a folder (or
// the file, with the system), Backspace goes up, Cmd+F finds among the
// entries, Space previews an image in a modal, Delete… asks before
// moving to the Trash. The window remembers its frame.
// Run: myphp run -d macos examples/files [folder]
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Shell, Window, WindowOptions};
use MyPHP\UI\{Content, Image, SVG};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

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
    /** @var list<string> the paths of the entries chosen */
    public array $chosen = [];
    /** The header the entries are sorted by, folders first. */
    public ?SortOrder $sortOrder = null;
    public bool $finding = false;
    public string $find = '';
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
        $this->icons = ['folder' => SVG::fromFile(__DIR__ . '/icons/folder.svg'), 'file' => SVG::fromFile(__DIR__ . '/icons/file.svg')];
        $this->open($path);
    }

    /** Shows folder $path: its entries read, folders first, the choice cleared, the table scrolled to the top. */
    public function open(string $path): void
    {
        $path = rtrim(realpath($path) ?: $path, '/') ?: '/';
        if (!is_dir($path) || !is_readable($path)) { $this->status = "Can't open $path"; return; }
        $this->path = $path;
        $this->chosen = [];
        $this->thumbs = []; $this->wanted = [];
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
        $q = $this->finding ? trim($this->find) : '';
        if ($q !== '') { $entries = array_values(array_filter($entries, fn($e) => stripos($e['name'], $q) !== false)); }
        $by = $this->sortOrder?->column ?? 'name'; $desc = $this->sortOrder?->descending ?? false;
        $key = fn(array $e) => match ($by) { 'size' => $e['size'], 'mtime' => $e['mtime'], default => strtolower($e['name']) };
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
        foreach ($this->chosen as $path) { if (isset($this->index[$path])) { $at[] = $this->index[$path]; } }
        sort($at);
        return array_map(fn(int $i) => $this->entries[$i], $at);
    }

    /** Opens the entry at $path: a folder is shown, a file goes to the system. */
    public function enter(string $path): void
    {
        $e = $this->entries[$this->index[$path] ?? -1] ?? null;
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
        $this->chosen = [];
        $this->reload();
    }

    /** Decodes the image chosen for the preview, shown once decoded if the preview is still open on it. */
    public function preview(): void
    {
        $this->preview = null;
        $one = $this->chosenEntries()[0]['path'] ?? null;
        if ($one === null) { return; }
        App::go(function () use ($one) {
            $img = Image::fromFile($one, 2048);
            if ($this->previewing && ($this->chosenEntries()[0]['path'] ?? null) === $one) { $this->preview = $img; $this->window?->invalidate(); }
        });
    }

    public static function isImage(array $e): bool
    {
        return !$e['dir'] && preg_match('/\.(jpe?g|png|gif|heic|heif|webp|tiff?|bmp)$/i', $e['name']) === 1;
    }

    /** The thumbnail of an image entry if decoded; wanted otherwise, which a fiber decodes off the main thread. */
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
        App::go(function () { // off the main thread, one after the other
            while (($p = array_shift($this->wanted)) !== null) {
                if (!isset($this->thumbs[$p])) { $this->thumbs[$p] = Image::fromFile($p, 128); $this->window?->invalidate(); }
            }
            $this->decoding = false;
        });
    }

    public static function size(int $bytes): string
    {
        if ($bytes < 0) { return '—'; }
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $u) { if ($bytes < 1024 ** ($i + 1) || $u === 'GB') { return $i === 0 ? "$bytes B" : sprintf('%.1f %s', $bytes / 1024 ** $i, $u); } }
        return '';
    }
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
        content: Content::file(__DIR__ . '/view.php', $files)));
});
App::run();
