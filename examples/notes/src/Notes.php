<?php
// The state of examples/notes and its actions: the view (view.php) reads it and calls them.
declare(strict_types=1);

use MyPHP\UI\{Painter, Span};
use MyPHP\UI\RichText\{Attachment, Attributes, Document, ListKind, Paragraph, ParagraphStyle, Run, Text};

/** The notes and what the window shows of them: the view reads it and calls its actions, and changes it nowhere else. */
final class Notes
{
    /** @var list<Note> Never empty: deleting the last note makes a new one. */
    public array $notes = [];
    /** The id of the note shown. */
    public ?int $selected = null;
    /** The sidebar's search. */
    public string $query = '';
    /** The alert asking before a note is deleted. */
    public bool $asking = false;
    /** The popover asking for a link's URL. */
    public bool $linkOpen = false;
    public string $linkURL = '';
    private int $next = 0;

    /** $path is where the notes are saved, '' for nowhere (a test's). */
    public function __construct(private string $path = '')
    {
        $json = $path === '' ? false : @file_get_contents($path);
        if ($json !== false) {
            foreach (json_decode($json, true, 64, JSON_THROW_ON_ERROR) as $n) {
                $this->notes[] = new Note($n['id'], Text::unmarshalJSON(json_encode($n['text'], JSON_THROW_ON_ERROR)), new DateTimeImmutable($n['updated']));
                $this->next = max($this->next, $n['id']);
            }
        }
        if ($this->notes === []) { // nothing saved: the welcome note is the first
            $this->notes = [new Note(++$this->next, self::welcome(), new DateTimeImmutable())];
        }
        $this->selected = $this->notes[0]->id;
    }

    /** The note shown: the one selected, else the first. */
    public function current(): Note
    {
        foreach ($this->notes as $n) {
            if ($n->id === $this->selected) { return $n; }
        }
        return $this->notes[0];
    }

    /** @return list<Note> The notes the search finds, all without one. */
    public function shown(): array
    {
        $q = trim($this->query);
        return $q === '' ? $this->notes : array_values(array_filter($this->notes, fn(Note $n) => mb_stripos($n->current()->plainText(), $q) !== false));
    }

    /** Adds an empty note at the top, and shows it. */
    public function add(): void
    {
        $n = new Note(++$this->next, Text::of(new Paragraph(new ParagraphStyle(heading: 1))), new DateTimeImmutable());
        array_unshift($this->notes, $n);
        $this->selected = $n->id;
        $this->query = ''; // so the new note shows in the sidebar
    }

    /** Deletes the note shown, which the alert asked about, and shows the next; the last one gone, a new one. */
    public function delete(): void
    {
        $id = $this->current()->id;
        $at = array_search($id, array_map(fn(Note $n) => $n->id, $this->notes), true);
        array_splice($this->notes, $at, 1);
        if ($this->notes === []) {
            $this->add();
        } else {
            $this->selected = $this->notes[min($at, count($this->notes) - 1)]->id;
        }
        $this->save();
    }

    /** The editor changed $n: its time, and the notes saved. */
    public function edited(Note $n): void
    {
        $n->text = $n->current();
        $n->updated = new DateTimeImmutable();
        $this->save();
    }

    /** Asks for the URL of a link over the selection. */
    public function openLink(Document $doc): void
    {
        $this->linkURL = $doc->formatting()->link;
        $this->linkOpen = true;
    }

    /** Sets the link the popover asked for over the selection ('' takes it off), and closes it. */
    public function setLink(Document $doc, string $url): void
    {
        $doc->setLink(trim($url));
        $this->linkOpen = false;
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
        file_put_contents($this->path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== false
            || throw new RuntimeException("cannot save the notes to {$this->path}");
    }

    /** The first note. */
    private static function welcome(): Text
    {
        $code = new Attributes(code: true);
        return Text::of(
            new Paragraph(new ParagraphStyle(heading: 1), [new Run('Welcome to Notes')]),
            new Paragraph(runs: [new Run('Write with '), new Run('bold', new Attributes(weight: 700)), new Run(', '), new Run('italic', new Attributes(italic: true)), new Run(', '),
                new Run('links', new Attributes(link: 'https://github.com/owner888/myphp')), new Run(' and lists, from the toolbar, the Format menu or the keyboard.')]),
            new Paragraph(new ParagraphStyle(heading: 2), [new Run('Try')]),
            new Paragraph(new ParagraphStyle(list: ListKind::Tasks, checked: true), [new Run('Open this note')]),
            new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Type '), new Run('- ', $code), new Run(' to start a list, '), new Run('# ', $code), new Run(' for a heading')]),
            new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Copy the note as Markdown')]),
            new Paragraph(runs: [new Run('Attachments sit in the line, as '),
                new Run(Text::OBJECT_REPLACEMENT, new Attributes(attachment: self::fileChip('itinerary.pdf'))),
                new Run(' does.')]),
        );
    }

    /** An attachment drawn as a chip with a file's name, as wide as the name in the editor's font. */
    private static function fileChip(string $name): Attachment
    {
        return new Attachment(name: $name, bounds: fn(Closure $measure) => [$measure($name, 12) + 28, 20, 4], draw: function (Painter $p, array $r) use ($name): void {
            $t = $p->theme();
            $p->fill($r, $t->accent, $r[3] / 2, 0.12);
            $p->fill([$r[0] + 8, $r[1] + 6, 7, 8], $t->accent, 1.5);
            [, $lh] = $p->measureText(0, new Span($name, 12));
            $p->richText($r[0] + 20, $r[1] + ($r[3] - $lh) / 2, 0, new Span($name, 12, color: $t->accent));
        });
    }
}
