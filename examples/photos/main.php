<?php
// A photo browser: a folder's images in a grid of thumbnails, one at a
// time full size. Built to shake out the image path: decoding off the main
// thread as the images show, the image atlas's room, window-level
// shortcuts, drops.
// Run: myphp run -d macos examples/photos [folder]
declare(strict_types=1);

use MyPHP\{App, Dialog, FileDropEvent, Menu, MenuItem, OpenDialogOptions, Screen, Window, WindowOptions};
use MyPHP\UI\{Content, Image};

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

final class Photos
{
    public const THUMB = 160, FULL = 2048; // pixels for FULL: an image atlas page is 2048², and a full-size image takes one
    public string $dir = '';
    /** @var list<string> */
    public array $files = [];
    /** @var array<string, Image> thumbnails by path, least recently shown first, at most KEEP of them */
    public array $thumbs = [];
    public const KEEP = 200;
    /** @var list<string> files in view whose thumbnails are wanted, in order; a fiber decodes them one after the other */
    public array $wanted = [];
    private bool $decoding = false;
    public int $thumbPixels = 160;
    /** @var array<string, Image> full-size images by path: the one shown and its neighbors */
    public array $full = [];
    /** @var array<string, true> the full-size images decoding */
    public array $loading = [];
    public int $selected = 0;
    public bool $viewing = false;
    /** The selection whose neighbors were last asked for, so each frame of the viewer does not ask again. */
    public int $prefetched = -1;
    public ?Window $window = null;

    /** The sharpest display's pixels to a point: thumbnails are decoded for it, full images shown no larger than their pixels. */
    public float $scale = 1.0;

    /** Fits the images to the displays, once the app runs (Screen's). */
    public function fitScreens(): void
    {
        foreach (Screen::displays() as $d) { $this->scale = max($this->scale, $d->scaleFactor); }
        $this->thumbPixels = (int)(self::THUMB * $this->scale);
    }

    /** The thumbnail of $f, or null while it is wanted: those the grid shows are decoded, in order, off the main thread. */
    public function thumbnail(string $f): ?Image
    {
        $img = $this->thumb($f);
        if ($img === null && !in_array($f, $this->wanted, true)) { $this->wanted[] = $f; $this->decode(); }
        return $img;
    }

    /** The full-size image shown, the thumbnail larger until it is decoded; its neighbors start decoding, so the arrows do not wait. */
    public function shown(): ?Image
    {
        if ($this->prefetched !== $this->selected) {
            $this->prefetched = $this->selected;
            $this->trimFull();
            foreach ([$this->selected - 1, $this->selected + 1] as $i) { if (isset($this->files[$i])) { $this->fullImage($i); } }
        }
        return $this->fullImage($this->selected) ?? $this->thumb($this->files[$this->selected]);
    }

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
        $this->window?->setTitle(basename($path));
        $this->window?->invalidate();
    }

    public function select(int $i): void
    {
        $this->selected = max(0, min(count($this->files) - 1, $i));
    }

    /** Decodes the wanted thumbnails in a fiber, off the main thread, one after the other, and shows each as it comes. */
    public function decode(): void
    {
        if ($this->decoding) {
            return;
        }
        $this->decoding = true;
        App::go(function () {
            while (($f = array_shift($this->wanted)) !== null) {
                if (isset($this->thumbs[$f])) {
                    continue;
                }
                $this->thumbs[$f] = Image::fromFile($f, $this->thumbPixels);
                while (count($this->thumbs) > self::KEEP) {
                    unset($this->thumbs[array_key_first($this->thumbs)]);
                }
                $this->window->invalidate();
            }
            $this->decoding = false;
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

    /** The full-size image of file $i, or null while a fiber decodes it, which the first ask starts. */
    public function fullImage(int $i): ?Image
    {
        $f = $this->files[$i];
        if (!isset($this->full[$f]) && !isset($this->loading[$f])) {
            $this->loading[$f] = true;
            App::go(function () use ($f) {
                $img = Image::fromFile($f, self::FULL);
                unset($this->loading[$f]);
                if (in_array($f, $this->files, true)) { // still of the folder open
                    $this->full[$f] = $img;
                    $this->trimFull();
                    $this->window->invalidate();
                }
            });
        }
        return $this->full[$f] ?? null;
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

$p = new Photos;
$openDialog = function () use ($p) {
    $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: 'Open a folder of photos', directory: true));
    if ($paths) { $p->open($paths[0]); }
};
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('File', submenu: [new MenuItem('Open…', $openDialog, 'CmdOrCtrl+O'), MenuItem::separator(), new MenuItem(role: 'close')]),
    new MenuItem(role: 'windowMenu'),
]));
App::setName('Photos');
App::whenReady(function () use ($p, $argv) {
    $p->fitScreens();
    $p->window = new Window(new WindowOptions(title: 'Photos', width: 900, height: 640, stateKey: 'photos',
        content: Content::file(__DIR__ . '/view.php', $p)));
    $p->window->on('fileDrop', fn(FileDropEvent $e) => $p->open($e->paths[0]));
    if (isset($argv[1])) {
        $p->open($argv[1]);
    }
});
App::run();
