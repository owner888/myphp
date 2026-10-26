<?php
// One data model for copying and dragging a note (mygo's
// examples/clipboard): the same note as text, HTML, or JSON made only when
// a reader asks for it; pasted or dropped back in the best format offered.
// Run: ./run.sh clipboard
declare(strict_types=1);

use MyPHP\{App, Clipboard, ClipboardOptions, Window, WindowOptions};
use MyPHP\Transfer\{Data, DropOptions, Format, FormatUnavailable, Item, Representation};
use MyPHP\UI\{Content, Context, Font, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

const NOTE_FORMAT = 'application/vnd.myphp.clipboard-note+json';

final class Demo
{
    public TextState $title, $body, $path;
    public string $status = '';
    public int $providers = 0, $releases = 0;
    public ?Window $win = null;

    public function __construct()
    {
        $this->title = new TextState('A portable note');
        $this->body = new TextState('Shared clipboard and drag representations.');
        $this->path = new TextState();
    }

    /** The note as data: every representation describes the same note, frozen as it is now. */
    public function data(): Data
    {
        [$title, $body] = [$this->title->text, $this->body->text];
        return new Data(new Item(
            Representation::bytes(Format::TEXT, "$title\n$body"),
            Representation::bytes(Format::HTML, '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($body) . '</p>'),
            Representation::lazy(NOTE_FORMAT, function () use ($title, $body) {
                $this->providers++;
                $this->win?->invalidate();
                return json_encode(['Title' => $title, 'Body' => $body], JSON_THROW_ON_ERROR);
            }),
        ));
    }

    public function receive(Data $data): void
    {
        $f = $data->preferred(NOTE_FORMAT, Format::TEXT);
        if ($f !== null) {
            try {
                $b = $data->read($f);
            } catch (FormatUnavailable $e) {
                $this->status = $e->getMessage();
                return;
            }
            if ($f === NOTE_FORMAT) {
                $n = json_decode($b, true);
                if (!is_array($n)) { $this->status = 'not a note: ' . json_last_error_msg(); return; }
                $this->title->text = (string)($n['Title'] ?? '');
                $this->body->text = (string)($n['Body'] ?? '');
            } else {
                $this->body->text = $b;
            }
            $this->status = "Received $f";
        } elseif ($data->preferred(Format::FILE_LIST, Format::URI_LIST) !== null) {
            $this->status = 'Files: ' . implode(', ', $data->paths());
        }
    }
}

function view(Context $c, Demo $d): void
{
    $t = $c->theme;
    $c->column(20, 12, 1);
      $c->text('Clipboard and drag data', 24, style: Font::BOLD);
      $c->muted('Copy or drag the same note as text, HTML, or lazy JSON. Paste in another app to use its preferred representation.');
      $c->input($d->title, 'Title', 0);
      $c->textarea($d->body, '', 0, 90);
      $c->row(0, 8);
        if ($c->clicked($c->button('Copy note'))) {
            try {
                Clipboard::write($d->data(), new ClipboardOptions(onRelease: function () use ($d) { $d->releases++; $d->win?->invalidate(); }));
                $d->status = 'Copied; JSON is produced only if requested';
            } catch (\RuntimeException $e) {
                $d->status = $e->getMessage();
            }
        }
        if ($c->clicked($c->button('Paste'))) {
            $preferred = Data::preferredFormat(Clipboard::formats(), NOTE_FORMAT, Format::TEXT, Format::FILE_LIST, Format::URI_LIST);
            if ($preferred === null) {
                $d->status = 'No supported clipboard format';
            } else {
                try { $d->receive(Clipboard::read($preferred)); } catch (\RuntimeException $e) { $d->status = $e->getMessage(); }
            }
        }
        if ($c->clicked($c->button('Flush'))) {
            try { Clipboard::flush(); $d->status = 'Clipboard persisted; provider resources released'; } catch (\RuntimeException $e) { $d->status = $e->getMessage(); }
        }
      $c->end();
      $note = $c->column(16)->border($t->border)->round(8);
        $c->text('Drag this note', style: Font::BOLD);
      $c->end();
      $c->dragDataFrom($note, $d->data(...));
      $o = new DropOptions([NOTE_FORMAT, Format::TEXT, Format::FILE_LIST, Format::URI_LIST]);
      $zone = $c->column(16)->border($t->border)->round(8);
        $c->text('Drop notes, text, or files here');
      $c->end();
      if ($c->dataDragOver($zone, $o)) { $zone->border($t->accent, 2); }
      if ($c->dropData($zone, $o, $drop)) { $d->receive($drop->data); }
      $c->row(0, 8);
        $c->input($d->path, 'Absolute file path', 0);
        if ($c->clicked($c->button('Copy file'))) {
            try { Clipboard::writeFiles($d->path->text); $d->status = 'Copied native file list'; } catch (\InvalidArgumentException | \RuntimeException $e) { $d->status = $e->getMessage(); }
        }
      $c->end();
      $c->muted("JSON requests: {$d->providers} · Clipboard releases: {$d->releases}");
      $c->text($d->status);
    $c->end();
}

$s = new Demo();
App::setName('Clipboard');
App::whenReady(function () use ($s) {
    $s->win = new Window(new WindowOptions(title: 'MyPHP Clipboard', width: 640, height: 580, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
