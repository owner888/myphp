<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;
use MyPHP\UI\{Context, Image, Key, Node};

/** The entries as a grid of icons, 96 wide and 108 high: a thumbnail for an image, the folder or file icon otherwise, the name below. */
$grid = function (Context $c, Files $f): void {
    $t = $c->theme;
    $g = $c->gridView($f->grid, count($f->entries), 96, 108, function (int $i) use ($c, $t, $f) {
        $e = $f->entries[$i];
        $c->column(0, 4, 1)->alignItems(Node::CENTER);
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
};

/** The entries as a table: name with its icon, size, modified; the headers sort. */
$table = function (Context $c, Files $f): void {
    $t = $c->theme;
    if ($f->table->sortChanged()) { $f->reload(); }
    $c->table($f->table, $f->columns, count($f->entries), function (int $i, int $j) use ($c, $t, $f) {
        $e = $f->entries[$i];
        switch ($j) {
            case 0: $c->row(0, 6)->alignItems(Node::CENTER); $c->icon($f->icons[$e['dir'] ? 'folder' : 'file'], 16, $e['dir'] ? $t->accent : $t->textMuted); $c->text($e['name'])->singleLine(); $c->end(); break;
            case 1: $c->muted(Files::size($e['size'])); break;
            case 2: $c->muted(date('Y-m-d H:i', $e['mtime'])); break;
        }
    });
    if ($f->table->submitted() && $f->table->selected >= 0) { $f->enter($f->table->selected); }
    if ($c->shortcut(Key::DELETE) && $f->path !== '/' && !$f->finding) { $f->open(dirname($f->path)); }
};

return function (Context $c) use (&$grid, &$table): void {
    $t = $c->theme;
    // Keys for the folder: Cmd+F finds, Backspace goes up, Enter opens the entry chosen, Space previews an image, Delete… asks.
    if ($c->shortcut('f', Key::COMMAND)) { $this->finding = true; }
    if ($c->shortcut(Key::DELETE, Key::COMMAND) && $this->chosenEntries() !== []) { $this->asking = true; }
    $c->row(0, 0, 1)->alignItems(Node::STRETCH);
      // Places
      $side = $c->sidebar($this->place, function () use ($c) {
          $open = null;
          $c->sidebarSection('Places', $open, function () use ($c) {
              foreach (Files::places() as $id => $path) { $c->sidebarItem($id, $id === 'root' ? 'Macintosh HD' : ($id === 'home' ? basename($path) : ucfirst($id))); }
          });
      }, 180);
      if ($c->changed($side) && isset(Files::places()[$this->place])) { $this->open(Files::places()[$this->place]); }
      $c->column(0, 0, 1);
        // The find bar, over the folder, filtering its entries as the query changes.
        $hits = $this->finding && $this->find->text !== '' ? count($this->entries) : 0;
        $bar = $c->findBar($this->finding, $this->find, $hits, $this->found);
        if ($this->find->seen !== $this->find->text || $this->filtered !== $this->finding) { $this->filtered = $this->finding; $this->reload(); } // the query or the bar changed: the entries it leaves
        $this->find->seen = $this->find->text;
        if ($bar !== null && $c->changed($bar) && $this->entries !== []) { $this->grid->selected = $this->table->selected = $this->found; $this->chosen->clear(); $this->chosen->add($this->entries[$this->found]['path']); }
        // The toolbar: the path, the view, Delete….
        $c->toolbar(function () use ($c) {
            $crumbs = array_values(array_filter(explode('/', $this->path), fn($s) => $s !== '')); array_unshift($crumbs, 'Macintosh HD');
            $at = count($crumbs) - 1;
            if ($c->changed($c->breadcrumbs($crumbs, $at))) { $this->open('/' . implode('/', array_slice($crumbs, 1, $at))); }
            $c->spacer();
            if ($c->changed($c->segmented(['Icons', 'List'], $this->view))) { $this->chosen->clear(); }
            if ($c->clicked($c->button('Delete…')) && $this->chosenEntries() !== []) { $this->asking = true; }
        });
        $c->divider();
        if ($this->view === 0) { $grid($c, $this); } else { $table($c, $this); }
        $c->divider();
        $c->row(10, 10)->alignItems(Node::CENTER);
          $n = count($this->chosenEntries());
          $c->muted($this->status !== '' ? $this->status : ($n > 0 ? "$n of " . count($this->entries) . ' chosen' : count($this->entries) . ' items'), 12);
        $c->end();
      $c->end();
    $c->end();
    // Space previews the image chosen, Escape or Space closes the preview.
    $one = $this->chosenEntries(); $one = count($one) === 1 ? $one[0] : null;
    if (!$this->previewing && $one !== null && Files::isImage($one) && !$this->finding && $c->shortcut(Key::SPACE)) {
        $this->previewing = true;
        App::go(function () use ($one) { // shown once decoded, if the preview is still open on it
            $img = Image::fromFile($one['path'], 2048);
            if ($this->previewing && $this->preview === null && ($this->chosenEntries()[0]['path'] ?? null) === $one['path']) { $this->preview = $img; $this->window?->invalidate(); }
        });
    }
    $modal = $c->modal($this->previewing, function () use ($c, $one) {
        if ($this->preview === null) { return; }
        $k = min(($c->width - 120) / $this->preview->w, ($c->height - 160) / $this->preview->h, 1 / $c->scale); // fits the window, never larger than its pixels
        $c->image($this->preview, $this->preview->w * $k, $this->preview->h * $k, $c->theme->radius);
        $c->text($one['name'] ?? '', 13);
        if ($c->shortcut(Key::SPACE)) { $this->previewing = false; }
    });
    if (!$this->previewing) { $this->preview = null; }
    // Delete…: an alert, Delete the default.
    $names = array_map(fn($e) => $e['name'], $this->chosenEntries());
    $what = count($names) === 1 ? "“{$names[0]}”" : count($names) . ' items';
    if ($c->alertDialog($this->asking, "Move $what to the Trash?", 'You can put them back from the Trash.', 'Cancel', 'Delete') === 1) { $this->trash(); }
};
