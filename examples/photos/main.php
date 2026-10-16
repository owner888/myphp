<?php
// A photo browser: a folder's images in a grid of thumbnails, one at a
// time full size. Built to shake out the image path: lazy decoding a few
// per frame, the image atlas's room, window-level shortcuts, drops.
// Run: ./run.sh photos [folder]
declare(strict_types=1);

use MyPHP\{App, Dialog, FileDropEvent, Menu, MenuItem, OpenDialogOptions, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, GridState, Image, Key};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

final class Photos
{
    public const THUMB = 160, FULL = 2048; // pixels for FULL: an image atlas page is 2048², and a full-size image takes one
    public string $dir = '';
    /** @var list<string> */
    public array $files = [];
    /** @var array<string, Image> thumbnails by path, least recently shown first, at most KEEP of them */
    public array $thumbs = [];
    public const KEEP = 200;
    /** @var list<string> files in view whose thumbnails are wanted, in order; decoded one a run-loop turn */
    public array $wanted = [];
    private bool $decoding = false;
    public int $thumbPixels = 160;
    /** @var array<string, Image> full-size images by path: the one shown and its neighbors */
    public array $full = [];
    public int $selected = 0;
    public bool $viewing = false;
    /** The selection whose neighbors were last queued for decoding, so each frame of the viewer does not queue them again. */
    public int $prefetched = -1;
    public GridState $grid;
    public ?Window $window = null;

    public function __construct() { $this->grid = new GridState; $this->grid->selects = true; $this->grid->label = fn(int $i) => basename($this->files[$i]); }

    public function open(string $path, ?string $select = null): void
    {
        if (!is_dir($path)) {
            $select = $path; $path = dirname($path);
        }
        $files = [];
        foreach (scandir($path) as $f) {
            if ($f[0] !== '.' && preg_match('/\.(jpe?g|png|gif|heic|heif|webp|tiff?|bmp)$/i', $f)) {
                $files[] = "$path/$f";
            }
        }
        natcasesort($files);
        $this->dir = $path; $this->files = array_values($files);
        $this->thumbs = []; $this->wanted = []; $this->full = []; $this->prefetched = -1;
        $this->selected = $select === null ? 0 : max(0, (int)array_search($select, $this->files, true));
        $this->viewing = $select !== null;
        $this->grid->scrollTo(0);
        $this->window?->setTitle(basename($path));
        $this->window?->update();
    }

    public function select(int $i): void
    {
        $this->selected = max(0, min(count($this->files) - 1, $i));
    }

    /** Decodes the wanted thumbnails one a run-loop turn, between frames, and shows each as it comes. */
    public function decode(): void
    {
        if ($this->decoding) {
            return;
        }
        $this->decoding = true;
        App::later($next = function () use (&$next) {
            while ($this->wanted !== [] && isset($this->thumbs[$this->wanted[0]])) {
                array_shift($this->wanted);
            }
            if ($this->wanted === []) {
                $this->decoding = false;
                return;
            }
            $f = array_shift($this->wanted);
            $this->thumbs[$f] = Image::fromFile($f, $this->thumbPixels);
            while (count($this->thumbs) > self::KEEP) {
                unset($this->thumbs[array_key_first($this->thumbs)]);
            }
            $this->window->update();
            App::later($next);
        });
    }

    /** The thumbnail of $f if it is decoded, moved to the recent end of the cache. */
    public function thumb(string $f): ?Image
    {
        $img = $this->thumbs[$f] ?? null;
        if ($img !== null) {
            unset($this->thumbs[$f]);
            $this->thumbs[$f] = $img;
        }
        return $img;
    }

    /** The full-size image of file $i, decoded now if need be. */
    public function fullImage(int $i): Image
    {
        return $this->full[$this->files[$i]] ??= Image::fromFile($this->files[$i], self::FULL);
    }

    /** Keeps the full-size images of the file shown and its neighbors; drops the rest. */
    public function trimFull(): void
    {
        foreach ($this->full as $path => $img) {
            if (abs((int)array_search($path, $this->files, true) - $this->selected) > 1) {
                unset($this->full[$path]);
            }
        }
    }
}

function view(Context $c, Photos $p): void
{
    $t = $c->theme;
    if ($p->files === []) {
        $c->column(0, 8, 1)->center();
          $c->text('No photos', 18, style: Font::BOLD);
          $c->muted($p->dir === '' ? 'Open a folder (Cmd+O) or drop one on the window.' : "No images in {$p->dir}.");
        $c->end();
        return;
    }
    if ($p->viewing) {
        viewer($c, $p);
    } else {
        grid($c, $p);
    }
}

function grid(Context $c, Photos $p): void
{
    $t = $c->theme; $s = Photos::THUMB;
    // A grid view: rows are built only as they show, so only their thumbnails are wanted; they decode between frames, in order.
    $p->wanted = [];
    $p->grid->selected = $p->selected;
    $grid = $c->gridView($p->grid, count($p->files), $s, $s, function (int $i) use ($c, $t, $p, $s) {
        $f = $p->files[$i];
        $c->column(0, 0, 0, $t->surface)->size(0, $s)->round($t->radius)->center()->tooltip(basename($f)); // as wide as the cell, which the cells share (Basis(0)): the thumbnail centered in it
          if (($img = $p->thumb($f)) !== null) {
              $k = min($s / $img->w, $s / $img->h); // the longer side fills the square
              $c->image($img, $img->w * $k, $img->h * $k, $t->radius);
          } else {
              $p->wanted[] = $f;
          }
        $c->end();
    });
    $p->select($p->grid->selected);
    if ($p->grid->rows->submitted()) { $p->viewing = true; } // a double click, Enter
    if ($c->shortcut(Key::SPACE)) { $p->viewing = true; }
    if ($p->wanted !== []) {
        $p->thumbPixels = (int)($s * $c->scale); // the screen's pixels
        $p->decode();
    }
}

function viewer(Context $c, Photos $p): void
{
    $t = $c->theme;
    if ($c->shortcut(Key::LEFT)) { $p->select($p->selected - 1); }
    if ($c->shortcut(Key::RIGHT)) { $p->select($p->selected + 1); }
    if ($c->shortcut(Key::ESCAPE) || $c->shortcut(Key::RETURN) || $c->shortcut(Key::SPACE)) { $p->viewing = false; }
    $img = $p->fullImage($p->selected);
    $f = $p->files[$p->selected];
    $c->column(0, 8, 1, 0x000000)->center();
      $room = $c->height - 48;
      $k = min($c->width / $img->w, $room / $img->h, 1 / $c->scale);
      $shown = $c->image($img, $img->w * $k, $img->h * $k);
      if ($c->doubleClicked($shown)) { $p->viewing = false; }
      $c->row(0, 12);
        $c->text(basename($f), 13, 0xffffff);
        $c->muted(sprintf('%d / %d · %s', $p->selected + 1, count($p->files), size($f)), 12);
      $c->end();
    $c->end();
    // The neighbors decode after this frame, one a run-loop turn, so the arrows do not wait; queued once per selection.
    if ($p->prefetched !== $p->selected) {
        $p->prefetched = $p->selected;
        $p->trimFull();
        $next = function () use ($p, &$next) {
            if (!$p->viewing) { return; }
            foreach ([$p->selected - 1, $p->selected + 1] as $i) {
                if (isset($p->files[$i]) && !isset($p->full[$p->files[$i]])) { $p->fullImage($i); App::later($next); return; }
            }
        };
        App::later($next);
    }
}

function size(string $f): string
{
    $b = filesize($f);
    return $b >= 1 << 20 ? sprintf('%.1f MB', $b / (1 << 20)) : sprintf('%d KB', intdiv($b, 1024));
}

$p = new Photos;
$openDialog = function () use ($p) {
    $paths = Dialog::open(new OpenDialogOptions(parent: Window::key(), title: 'Open a folder of photos', directory: true));
    if ($paths) { $p->open($paths[0]); }
};
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('File', submenu: [new MenuItem('Open…', $openDialog, 'CmdOrCtrl+O'), MenuItem::separator(), new MenuItem(role: 'close')]),
    new MenuItem(role: 'windowMenu'),
]));
App::setName('Photos');
App::whenReady(function () use ($p, $argv) {
    $p->window = new Window(new WindowOptions(title: 'Photos', width: 900, height: 640, stateKey: 'photos',
        content: Content::view(fn(Context $c) => view($c, $p))));
    $p->window->on('fileDrop', fn(FileDropEvent $e) => $p->open($e->paths[0]));
    if (isset($argv[1])) {
        $p->open($argv[1]);
    }
});
App::run();
