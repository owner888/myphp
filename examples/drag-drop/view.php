<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Transfer\{Data, Drop, DragOptions, DropOptions, Format, Item, Operation, Representation, Result};
use MyPHP\UI\{Context, Font};

/** A window: its notes to drag out, a URL and a file, and a zone taking notes, files, URLs and text. */
return function (Context $c): void {
    $t = $c->theme;
    $c->column(20, 12, 1);
      $c->text($this->name, 24, style: Font::BOLD);
      $c->text('Drag a card to the other window, a text editor, or Finder/Explorer. Drop files, text, and URLs here.', color: $t->textMuted);
      foreach ($this->notes as $n) {
          $card = $c->key($n->id)->column(14, 0, 0, $t->surface)->radius(8)->border(1, $t->border);
            $c->text($n->title);
          $c->end();
          $frozen = clone $n; // the note as it is when the drag starts
          $c->dragDataFrom($c->drag($card, $n), fn() => new Data(new Item(
              Representation::bytes(Format::TEXT, $frozen->title),
              Representation::lazy(NOTE_FORMAT, fn() => json_encode(['ID' => $frozen->id, 'Title' => $frozen->title], JSON_THROW_ON_ERROR)),
          )), new DragOptions(Operation::COPY | Operation::MOVE, done: function (Result $r) use ($n): void {
              $this->completed($r);
              if ($r->operation === Operation::MOVE && $r->error === null) {
                  $this->notes = array_values(array_filter($this->notes, fn(Note $v) => $v !== $n));
              }
          }));
          if ($c->dragging($card)) { $card->opacity(0.5); }
      }
      $url = $c->column(12, 0, 0)->border(1, $t->border)->radius(8);
        $c->text('Drag the myphp repository URL');
      $c->end();
      $c->dragData($url, Data::url('https://github.com/owner888/myphp'), new DragOptions(done: $this->completed(...)));
      $file = $c->column(12, 0, 0)->border(1, $t->border)->radius(8);
        $c->text('Drag a sample .txt file');
      $c->end();
      $c->dragData($file, Data::files($this->file), new DragOptions(done: $this->completed(...)));
      $zone = $c->column(16, 0, 1)->minHeight(100)->radius(8)->border(2, $t->border);
        $c->text('Drop here', style: Font::BOLD);
      $c->end();
      $opts = new DropOptions([NOTE_FORMAT, Format::TEXT, Format::FILE_LIST, Format::URI_LIST], Operation::COPY | Operation::MOVE);
      if ($c->dataDragOver($zone, $opts)) {
          $zone->border(2, $t->accent)->background($t->surface);
      }
      if ($c->dropData($zone, $opts, $drop)) {
          $this->receive($drop);
      }
      $c->text($this->status, 12, $t->textMuted);
    $c->end();
};
