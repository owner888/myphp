<?php
// Drag-drop exchanges notes, text, URLs and files between two native UI
// windows and other applications (mygo's examples/drag-drop). Hold Option
// to ask for a copy, Command for a move. A note is plain text, and JSON
// made only when a destination asks for it, which the receiver decodes:
// so it travels to another instance of the app too. The source removes
// its note only once a move is done; the sample file always copies.
// Escape gives a drag up; the status and the log say how each ended.
// Run: ./run.sh drag-drop
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Transfer\{Data, Drop, DragOptions, DropOptions, Format, Item, Operation, Representation, Result};
use MyPHP\UI\{Content, Context, Font};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

const NOTE_FORMAT = 'application/vnd.myphp.example-note+json';

final class Note
{
    public function __construct(public int $id, public string $title) {}
}

/** The two windows' panes, and the ids of the notes they receive. */
final class Demo
{
    public int $nextID = 2;
    /** @var list<Pane> */
    public array $panes = [];
}

/** A window's notes and what happened to its last drag or drop. */
final class Pane
{
    /** @var list<Note> */
    public array $notes = [];

    public function __construct(public string $name, public string $file, public Demo $app, public string $status = 'Escape cancels a drag.') {}

    public function completed(Result $r): void
    {
        $this->status = match (true) {
            $r->error !== null => $r->error->getMessage(),
            $r->canceled => 'Drag canceled',
            $r->operation === Operation::MOVE => 'Move completed',
            default => 'Copy completed',
        };
        logf('%s: %s', $this->name, $this->status);
    }

    /** Takes a note, decoded from its JSON, or says what files, URLs or text came. */
    public function receive(Drop $drop): void
    {
        $formats = $drop->data->formats();
        if (in_array(NOTE_FORMAT, $formats, true)) {
            try {
                $v = json_decode($drop->data->read(NOTE_FORMAT), true, 4, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $this->status = $e->getMessage();
                return;
            }
            $n = new Note(++$this->app->nextID, (string)($v['Title'] ?? ''));
            $this->notes[] = $n;
            $this->status = 'Received note: ' . $n->title;
        } elseif (in_array(Format::FILE_LIST, $formats, true) && ($paths = $drop->data->paths()) !== []) {
            $this->status = 'Received files: ' . implode(', ', $paths);
        } elseif (in_array(Format::URI_LIST, $formats, true) && ($urls = $drop->data->urls()) !== []) {
            $this->status = 'Received URLs: ' . implode(', ', $urls);
        } elseif (in_array(Format::TEXT, $formats, true)) {
            $this->status = 'Received text: ' . $drop->data->read(Format::TEXT);
        }
        logf('%s: %s (effect %d)', $this->name, $this->status, $drop->operation);
    }
}

/** A window: its notes to drag out, a URL and a file, and a zone taking notes, files, URLs and text. */
function view(Context $c, Pane $p): void
{
    $t = $c->theme;
    $c->column(20, 12, 1);
      $c->text($p->name, 24, style: Font::BOLD);
      $c->text('Drag a card to the other window, a text editor, or Finder/Explorer. Drop files, text, and URLs here.', color: $t->textMuted);
      foreach ($p->notes as $n) {
          $card = $c->key($n->id)->column(14, 0, 0, $t->surface)->round(8)->border($t->border);
            $c->text($n->title);
          $c->end();
          $frozen = clone $n; // the note as it is when the drag starts
          $c->dragDataFrom($c->drag($card, $n), fn() => new Data(new Item(
              Representation::bytes(Format::TEXT, $frozen->title),
              Representation::lazy(NOTE_FORMAT, fn() => json_encode(['ID' => $frozen->id, 'Title' => $frozen->title], JSON_THROW_ON_ERROR)),
          )), new DragOptions(Operation::COPY | Operation::MOVE, done: function (Result $r) use ($p, $n): void {
              $p->completed($r);
              if ($r->operation === Operation::MOVE && $r->error === null) {
                  $p->notes = array_values(array_filter($p->notes, fn(Note $v) => $v !== $n));
              }
          }));
          if ($c->dragging($card)) { $card->opacity(0.5); }
      }
      $url = $c->column(12, 0, 0)->border($t->border)->round(8);
        $c->text('Drag the MyGo website URL');
      $c->end();
      $c->dragData($url, Data::url('https://mygo.egoist.dev'), new DragOptions(done: $p->completed(...)));
      $file = $c->column(12, 0, 0)->border($t->border)->round(8);
        $c->text('Drag a sample .txt file');
      $c->end();
      $c->dragData($file, Data::files($p->file), new DragOptions(done: $p->completed(...)));
      $zone = $c->column(16, 0, 1)->minHeight(100)->round(8)->border($t->border, 2);
        $c->text('Drop here', style: Font::BOLD);
      $c->end();
      $opts = new DropOptions([NOTE_FORMAT, Format::TEXT, Format::FILE_LIST, Format::URI_LIST], Operation::COPY | Operation::MOVE);
      if ($c->dataDragOver($zone, $opts)) {
          $zone->border($t->accent, 2)->background($t->surface);
      }
      if ($c->dropData($zone, $opts, $drop)) {
          $p->receive($drop);
      }
      $c->text($p->status, 12, $t->textMuted);
    $c->end();
}

/** Logs a line to the standard error, as Go's log.Printf does. */
function logf(string $format, mixed ...$args): void
{
    fwrite(STDERR, date('Y/m/d H:i:s ') . sprintf($format, ...$args) . "\n");
}

$dir = sys_get_temp_dir() . '/myphp-drag-demo-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$file = "$dir/MyPHP drag sample.txt";
file_put_contents($file, "A file dragged from MyPHP native UI.\n");
register_shutdown_function(function () use ($dir, $file): void { @unlink($file); @rmdir($dir); });

$s = new Demo();
foreach (['Left', 'Right'] as $i => $name) {
    $s->panes[] = $p = new Pane($name, $file, $s);
    $p->notes = [new Note($i + 1, "A note from $name")];
}
App::setName('MyPHP Drag Demo');
App::whenReady(function () use ($s) {
    foreach ($s->panes as $i => $p) {
        new Window(new WindowOptions(title: "MyPHP Drag — {$p->name}", width: 440, height: 580, x: 60 + $i * 480, y: 80,
            content: Content::view(fn(Context $c) => view($c, $p))));
    }
});
App::run();
