<?php
// One data model for copying and dragging a note (mygo's
// examples/clipboard): the same note as text, HTML, or JSON made only when
// a reader asks for it; pasted or dropped back in the best format offered.
// Run: myphp run -d macos examples/clipboard
declare(strict_types=1);

use MyPHP\{App, Clipboard, Window, WindowOptions};
use MyPHP\Transfer\{Data, Format, FormatUnavailable, Item, Representation};
use MyPHP\UI\Content;

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

const NOTE_FORMAT = 'application/vnd.myphp.clipboard-note+json';

final class Demo
{
    public string $title = 'A portable note', $body = 'Shared clipboard and drag representations.', $path = '';
    /** A drag of what the zone takes is over it. */
    public bool $targeted = false;
    public string $status = '';
    public int $providers = 0, $releases = 0;
    public ?Window $win = null;

    public function __construct()
    {
    }

    /** The note as data: every representation describes the same note, frozen as it is now. */
    public function data(): Data
    {
        [$title, $body] = [$this->title, $this->body];
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
                $this->title = (string)($n['Title'] ?? '');
                $this->body = (string)($n['Body'] ?? '');
            } else {
                $this->body = $b;
            }
            $this->status = "Received $f";
        } elseif ($data->preferred(Format::FILE_LIST, Format::URI_LIST) !== null) {
            $this->status = 'Files: ' . implode(', ', $data->paths());
        }
    }
}

$s = new Demo();
App::setName('Clipboard');
App::whenReady(function () use ($s) {
    $s->win = new Window(new WindowOptions(title: 'MyPHP Clipboard', width: 640, height: 580, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
