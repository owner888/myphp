<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Key};

$grid = function (Context $c, Photos $p): void {
    $t = $c->theme; $s = Photos::THUMB;
    // A grid view: rows are built only as they show, so only their thumbnails are wanted; they decode off the main thread, in order.
    $p->wanted = [];
    $p->grid->selected = $p->selected;
    $grid = $c->gridView($p->grid, count($p->files), $s, $s, function (int $i) use ($c, $t, $p, $s) {
        $f = $p->files[$i];
        $c->column(0, 0, 1, $t->surface)->size(0, 0)->radius($t->radius)->center()->tooltip(basename($f)); // fills the cell inside its border of 2 (mygo's Image.Fit(Contain).Grow(1)): the thumbnail centered in it
          if (($img = $p->thumb($f)) !== null) {
              $k = min(($s - 4) / $img->w, ($s - 4) / $img->h); // the longer side fills the cell's height inside the border
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
};

$viewer = function (Context $c, Photos $p) use (&$size): void {
    $t = $c->theme;
    if ($c->shortcut(Key::LEFT)) { $p->select($p->selected - 1); }
    if ($c->shortcut(Key::RIGHT)) { $p->select($p->selected + 1); }
    if ($c->shortcut(Key::ESCAPE) || $c->shortcut(Key::RETURN) || $c->shortcut(Key::SPACE)) { $p->viewing = false; }
    $f = $p->files[$p->selected];
    $full = $p->fullImage($p->selected);
    $img = $full ?? $p->thumb($f); // the thumbnail, larger, until the full-size image is decoded
    $c->column(0, 8, 1, 0x000000)->center();
      if ($img !== null) {
          $room = $c->height - 48;
          $k = min($c->width / $img->w, $room / $img->h, ($full === null ? Photos::FULL / max($img->w, $img->h) : 1) / $c->scale);
          $shown = $c->image($img, $img->w * $k, $img->h * $k);
          if ($c->doubleClicked($shown)) { $p->viewing = false; }
      }
      $c->row(0, 12);
        $c->text(basename($f), 13, 0xffffff);
        $c->muted(sprintf('%d / %d · %s', $p->selected + 1, count($p->files), $size($f)), 12);
      $c->end();
    $c->end();
    // The neighbors start decoding too, so the arrows do not wait; asked once per selection.
    if ($p->prefetched !== $p->selected) {
        $p->prefetched = $p->selected;
        $p->trimFull();
        foreach ([$p->selected - 1, $p->selected + 1] as $i) {
            if (isset($p->files[$i])) { $p->fullImage($i); }
        }
    }
};

$size = function (string $f): string {
    $b = filesize($f);
    return $b >= 1 << 20 ? sprintf('%.1f MB', $b / (1 << 20)) : sprintf('%d KB', intdiv($b, 1024));
};

return function (Context $c) use (&$viewer, &$grid): void {
    $t = $c->theme;
    if ($this->files === []) {
        $c->column(0, 8, 1)->center();
          $c->text('No photos', 18, style: Font::BOLD);
          $c->muted($this->dir === '' ? 'Open a folder (Cmd+O) or drop one on the window.' : "No images in {$this->dir}.");
        $c->end();
        return;
    }
    if ($this->viewing) {
        $viewer($c, $this);
    } else {
        $grid($c, $this);
    }
};
