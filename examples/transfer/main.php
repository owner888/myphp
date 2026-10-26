<?php
// Drags the system carries: a note dragged out to any app taking text or
// HTML (TextEdit, Mail, the Finder makes a clipping), its HTML made only
// if the destination asks for it; a well taking text, URLs and Markdown
// dragged in from other apps, which shows what it is offered as it is
// over it and reads it once dropped; a well taking files from the
// Finder; and cards dragged between two lists, as values within the
// window and as text to other apps (drag and dragData). Option copies,
// Command moves, where the source allows it.
// Run: ./run.sh transfer
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Transfer\{Data, DragOptions, DropOptions, Format, Item, Operation, Representation, Result};
use MyPHP\UI\{Content, Context, Font};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$s = (object)['ended' => '', 'dropped' => '', 'files' => [], 'todo' => ['Write the spec', 'Review the port'], 'done' => ['Ship v0.78']];

function view(Context $c, object $s): void
{
    $t = $c->theme;
    $c->column(20, 14, 1);
      $c->text('Transfer', 20, style: Font::BOLD);
      $c->muted('Drag the note to TextEdit or Mail; drag text, a link or files from other apps onto the wells.', 12);
      $note = $c->column(12, 4, 0, $t->surface)->round(10)->border($t->border)->size(260, 0);
        $c->text('A note', 15, style: Font::BOLD);
        $c->muted($s->ended === '' ? 'Drag me out of the window' : $s->ended, 12);
      $c->end();
      $c->dragData($note, new Data(new Item(
          Representation::bytes(Format::TEXT, 'A note from MyPHP'),
          Representation::lazy(Format::HTML, fn() => '<p><b>A note</b> from MyPHP</p>'), // made only for a destination taking HTML
      )), new DragOptions(Operation::COPY, done: function (Result $r) use ($s) {
          $s->ended = $r->error !== null ? 'Failed: ' . $r->error->getMessage() : ($r->canceled ? 'Nothing took it' : 'Copied');
      }));
      $c->row(0, 12);
        $well = $c->column(12, 4, 0, $t->surface)->round(10)->border($t->border)->size(260, 110);
          $c->text('Text and links', 13, style: Font::BOLD);
          $o = new DropOptions([Format::URI_LIST, Format::MARKDOWN, Format::TEXT]);
          $over = $c->dataDragOver($well, $o, $offer);
          $c->muted($over ? 'Takes ' . $offer->preferred(...$o->formats) : ($s->dropped === '' ? 'Drop here' : mb_strimwidth($s->dropped, 0, 120, '…')), 12);
        $c->end();
        if ($over) { $well->border($t->accent, 2); }
        if ($c->dropData($well, $o, $drop)) {
            $f = $drop->data->preferred(...$o->formats);
            $s->dropped = $f === Format::URI_LIST ? implode(' ', $drop->data->urls()) : $drop->data->read($f);
        }
        $files = $c->column(12, 4, 0, $t->surface)->round(10)->border($t->border)->size(260, 110);
          $c->text('Files', 13, style: Font::BOLD);
          if ($s->files === []) { $c->muted('Drop files from the Finder', 12); }
          foreach (array_slice($s->files, -4) as $p) { $c->muted(basename($p), 12); }
        $c->end();
        if ($c->fileDragOver($files)) { $files->border($t->accent, 2); }
        if (($paths = $c->droppedFiles($files)) !== null) { $s->files = [...$s->files, ...$paths]; }
      $c->end();
      // Cards: within the window, the card itself moves between the lists; to another app, its text.
      $c->row(0, 12);
        foreach (['todo' => 'To do', 'done' => 'Done'] as $list => $title) {
            $col = $c->column(10, 6, 0, $t->surface)->round(10)->size(260, 0);
              $c->text($title, 13, style: Font::BOLD);
              foreach ($s->$list as $card) {
                  $n = $c->key("$list-$card")->row(10, 0, 0, $t->background)->round(8)->border($t->border);
                    $c->text($card);
                  $c->end();
                  $c->dragData($c->drag($n, [$list, $card]), Data::text($card)); // the card itself to this window's lists, its text to other apps
              }
            $c->end();
            if ($c->dragOver($col, 'array', $v) && $v[0] !== $list) { $col->border($t->accent, 2); }
            if ($c->drop($col, 'array', $v) && $v[0] !== $list) {
                [$from, $card] = $v;
                $s->$from = array_values(array_diff($s->$from, [$card]));
                $s->$list[] = $card;
            }
        }
      $c->end();
    $c->end();
}

App::setName('Transfer');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — Transfer', width: 600, height: 560,
        content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
