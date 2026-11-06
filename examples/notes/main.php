<?php
// Notes is a notes app in native UI (mygo's examples/notes): notes in a
// sidebar, each edited as rich text (MyPHP\UI\RichText), with a toolbar of
// its formats, the Format menu, links, Markdown shortcuts and export, and
// the notes saved as JSON in the user's Application Support folder.
// Cmd+N makes a note.
// Run: ./run.sh notes
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\UI\{Content, TextState};
use MyPHP\UI\RichText\{Document, Paragraph, ParagraphStyle, Text};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

/** A note: its text, saved, and the document the editor edits. */
final class Note
{
    private ?Document $doc = null;

    public function __construct(public int $id, public Text $text, public DateTimeImmutable $updated) {}

    /** The note's document, made the first time. */
    public function document(): Document
    {
        return $this->doc ??= new Document($this->text);
    }

    /** The text as it is now: the document's once there is one. */
    public function current(): Text
    {
        return $this->doc?->text() ?? $this->text;
    }

    /** The first line of the note, or "New Note". */
    public function title(): string
    {
        $s = $this->current()->plainText();
        if (($i = strpos($s, "\n")) !== false) { $s = substr($s, 0, $i); }
        return ($s = trim($s)) === '' ? 'New Note' : $s;
    }
}

final class Notes
{
    /** @var list<Note> */
    public array $notes = [];
    /** The id of the note shown. */
    public string $selected = '';
    public int $next = 0;
    /** The popover asking for a link's URL. */
    public bool $linkOpen = false;
    public TextState $linkURL;
    /** Where the notes are saved, '' for nowhere. */
    public string $path = '';
    /** Nothing was saved: the first frame makes the welcome note, whose chip it measures. */
    public bool $welcome = false;

    public function __construct()
    {
        $this->linkURL = new TextState();
    }

    public function current(): Note
    {
        foreach ($this->notes as $n) {
            if ((string)$n->id === $this->selected) { return $n; }
        }
        if ($this->notes === []) { $this->add(); }
        $this->selected = (string)$this->notes[0]->id;
        return $this->notes[0];
    }

    /** Adds an empty note at the top, and shows it. */
    public function add(): Note
    {
        $n = new Note(++$this->next, Text::of(new Paragraph(new ParagraphStyle(heading: 1))), new DateTimeImmutable());
        array_unshift($this->notes, $n);
        $this->selected = (string)$n->id;
        return $n;
    }

    /** Asks for the URL of a link over the selection. */
    public function openLink(Document $doc): void
    {
        $this->linkURL = new TextState($doc->formatting()->link);
        $this->linkOpen = true;
    }

    /** Reads the saved notes, or leaves the welcome note to the first frame. */
    public function load(string $dir): void
    {
        $this->path = "$dir/MyPHP Notes/notes.json";
        $json = @file_get_contents($this->path);
        if ($json !== false) {
            foreach (json_decode($json, true, 64, JSON_THROW_ON_ERROR) as $n) {
                $this->notes[] = new Note($n['id'], Text::unmarshalJSON(json_encode($n['text'], JSON_THROW_ON_ERROR)), new DateTimeImmutable($n['updated']));
                $this->next = max($this->next, $n['id']);
            }
        }
        $this->welcome = $this->notes === [];
    }

    /** Writes the notes, where the app has a place for them. */
    public function save(): void
    {
        if ($this->path === '') { return; }
        $out = [];
        foreach ($this->notes as $n) {
            $n->text = $n->current();
            $out[] = ['id' => $n->id, 'text' => json_decode($n->text->marshalJSON(), true, 512, JSON_THROW_ON_ERROR), 'updated' => $n->updated->format(DATE_RFC3339_EXTENDED)];
        }
        if (!is_dir(dirname($this->path))) { @mkdir(dirname($this->path), 0755, true); }
        @file_put_contents($this->path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}

$s = new Notes();
$s->load(getenv('HOME') . '/Library/Application Support');
App::setName('Notes');
App::whenReady(function () use ($s) {
    App::setMenu(new Menu([
        new MenuItem(role: 'appMenu'),
        new MenuItem(role: 'fileMenu'),
        new MenuItem(role: 'editMenu'),
        new MenuItem(role: 'formatMenu'),
        new MenuItem(role: 'windowMenu'),
    ]));
    new Window(new WindowOptions(title: 'Notes', width: 900, height: 640, minWidth: 560, minHeight: 400, stateKey: 'notes',
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
