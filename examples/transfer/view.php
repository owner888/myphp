<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Transfer\{Data, DragOptions, DropOptions, Format, Item, Operation, Representation, Result};
use MyPHP\UI\{Context, Font};

return function (Context $c): void {
    $t = $c->theme;
    $c->column(20, 14, 1);
      $c->text('Transfer', 20, style: Font::BOLD);
      $c->muted('Drag the note to TextEdit or Mail; drag text, a link or files from other apps onto the wells.', 12);
      $note = $c->column(12, 4, 0, $t->surface)->radius(10)->border(1, $t->border)->size(260, 0);
        $c->text('A note', 15, style: Font::BOLD);
        $c->muted($this->ended === '' ? 'Drag me out of the window' : $this->ended, 12);
      $c->end();
      $c->dragData($note, new Data(new Item(
          Representation::bytes(Format::TEXT, 'A note from MyPHP'),
          Representation::lazy(Format::HTML, fn() => '<p><b>A note</b> from MyPHP</p>'), // made only for a destination taking HTML
      )), new DragOptions(Operation::COPY, done: function (Result $r) {
          $this->ended = $r->error !== null ? 'Failed: ' . $r->error->getMessage() : ($r->canceled ? 'Nothing took it' : 'Copied');
      }));
      $c->row(0, 12);
        $well = $c->column(12, 4, 0, $t->surface)->radius(10)->border(1, $t->border)->size(260, 110);
          $c->text('Text and links', 13, style: Font::BOLD);
          $o = new DropOptions([Format::URI_LIST, Format::MARKDOWN, Format::TEXT]);
          $over = $c->dataDragOver($well, $o, $offer);
          $c->muted($over ? 'Takes ' . $offer->preferred(...$o->formats) : ($this->dropped === '' ? 'Drop here' : mb_strimwidth($this->dropped, 0, 120, '…')), 12);
        $c->end();
        if ($over) { $well->border(2, $t->accent); }
        if ($c->dropData($well, $o, $drop)) {
            $f = $drop->data->preferred(...$o->formats);
            $this->dropped = $f === Format::URI_LIST ? implode(' ', $drop->data->urls()) : $drop->data->read($f);
        }
        $files = $c->column(12, 4, 0, $t->surface)->radius(10)->border(1, $t->border)->size(260, 110);
          $c->text('Files', 13, style: Font::BOLD);
          if ($this->files === []) { $c->muted('Drop files from the Finder', 12); }
          foreach (array_slice($this->files, -4) as $p) { $c->muted(basename($p), 12); }
        $c->end();
        if ($c->fileDragOver($files)) { $files->border(2, $t->accent); }
        if (($paths = $c->droppedFiles($files)) !== null) { $this->files = [...$this->files, ...$paths]; }
      $c->end();
      // Cards: within the window, the card itself moves between the lists; to another app, its text.
      $c->row(0, 12);
        foreach (['todo' => 'To do', 'done' => 'Done'] as $list => $title) {
            $col = $c->column(10, 6, 0, $t->surface)->radius(10)->size(260, 0);
              $c->text($title, 13, style: Font::BOLD);
              foreach ($this->$list as $card) {
                  $n = $c->key("$list-$card")->row(10, 0, 0, $t->background)->radius(8)->border(1, $t->border);
                    $c->text($card);
                  $c->end();
                  $c->dragData($c->drag($n, [$list, $card]), Data::text($card)); // the card itself to this window's lists, its text to other apps
              }
            $c->end();
            if ($c->dragOver($col, 'array', $v) && $v[0] !== $list) { $col->border(2, $t->accent); }
            if ($c->drop($col, 'array', $v) && $v[0] !== $list) {
                [$from, $card] = $v;
                $this->$from = array_values(array_diff($this->$from, [$card]));
                $this->$list[] = $card;
            }
        }
      $c->end();
    $c->end();
};
