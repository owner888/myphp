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
use MyPHP\Transfer\{Drop, Format, Operation, Result};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

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
            content: Content::file(__DIR__ . '/view.php', $p)));
    }
});
App::run();
