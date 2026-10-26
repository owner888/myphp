<?php
// Notes is a notes app in native UI (mygo's examples/notes): notes in a
// sidebar, each edited as rich text (MyPHP\UI\RichText), with a toolbar of
// its formats, the Format menu, links, Markdown shortcuts and export, and
// the notes saved as JSON in the user's Application Support folder.
// Cmd+N makes a note.
// Run: ./run.sh notes
declare(strict_types=1);

use MyPHP\{App, Clipboard, Menu, MenuItem, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Key, Node, Painter, Span, TextState, TextStyle};
use MyPHP\UI\RichText\{Attachment, Attributes, Document, EditorOptions, FormatState, ListKind, Paragraph, ParagraphStyle, RichText, Run, Text};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

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

function view(Context $c, Notes $a): void
{
    $t = $c->theme;
    if ($a->welcome) { // the first note, its chip measured in the window's font
        $a->welcome = false;
        $a->notes = [new Note(++$a->next, welcome($c), new DateTimeImmutable())];
    }
    $n = $a->current();
    $doc = $n->document();
    if ($c->shortcut('n', Key::COMMAND)) {
        $n = $a->add();
        $doc = $n->document();
    }
    $c->row(0, 0, 1)->align(Node::STRETCH);
      $c->column(0, 8, 0, $t->surface)->width(230)->shrink(0)->padding(12, 0);
        $c->row(0, 0)->padding(0, 16)->align(Node::CENTER);
          $c->text('Notes')->bold()->grow(1);
          if ($c->clicked($c->button('New')->label('New note'))) { $a->add(); }
        $c->end();
        $c->sidebar($a->selected, function () use ($c, $a) {
            foreach ($a->notes as $note) {
                $c->key($note->id)->sidebarItem((string)$note->id, $note->title());
            }
        }, 230)->label('Notes')->grow(1);
      $c->end();
      $c->column(0, 10, 1)->minWidth(0)->padding(12, 20);
        toolbar($c, $a, $doc);
        $editor = RichText::editor($c->key($n->id), $doc, new EditorOptions(label: 'Note', placeholder: 'Title', onLinkCommand: fn() => $a->openLink($doc)))->grow(1);
        if ($c->changed($editor)) {
            $n->text = $doc->text(); $n->updated = new DateTimeImmutable();
            $a->save();
        }
        $words = count(preg_split('/\s+/u', $doc->text()->plainText(), -1, PREG_SPLIT_NO_EMPTY));
        $c->text(sprintf('%d words · edited %s', $words, $n->updated->format('M j, H:i')), 12, $t->textMuted);
      $c->end();
    $c->end();
}

/** The toolbar: it formats the selection of $doc and shows its formats. */
function toolbar(Context $c, Notes $a, Document $doc): void
{
    $t = $c->theme;
    $f = $doc->formatting();
    $button = function (string $label, string $tip, bool $on, Closure $run) use ($c, $t): Node {
        $b = $c->button($label)->keepFocus()->padding(4, 9)->minWidth(30)->tooltip($tip);
        if ($on) { $b->background($t->accent, 0.18); }
        if ($c->clicked($b)) { $run(); }
        return $b;
    };
    $divider = fn() => $c->box(1, 20, $t->border);
    $c->row(0, 4)->wrap($c->width - 230 - 40)->align(Node::CENTER);
      $styles = ['Title', 'Heading', 'Subheading', 'Body'];
      $style = $f->paragraphsMixed ? -1 : [1 => 0, 2 => 1, 3 => 2][$f->paragraph->heading] ?? 3;
      $before = $style;
      $c->select($styles, $style, 130)->keepFocus()->label('Paragraph style');
      if ($style !== $before && $style >= 0) {
          $doc->setHeading([1, 2, 3, 0][$style]);
      }
      $divider();
      $button('B', 'Bold (⌘B)', $f->bold === FormatState::FormatOn, $doc->toggleBold(...))->bold();
      $button('I', 'Italic (⌘I)', $f->italic === FormatState::FormatOn, $doc->toggleItalic(...))->italic();
      $button('U', 'Underline (⌘U)', $f->underline === FormatState::FormatOn, $doc->toggleUnderline(...))->underline();
      $button('S', 'Strikethrough (⇧⌘X)', $f->strikethrough === FormatState::FormatOn, $doc->toggleStrikethrough(...))->strikethrough();
      $button('</>', 'Code (⌘E)', $f->code === FormatState::FormatOn, $doc->toggleCode(...));
      $link = $button('Link', 'Link (⌘K)', $f->link !== '', fn() => $a->openLink($doc));
      $c->popover($link, $a->linkOpen, function () use ($c, $a, $doc, $f) {
          $c->row(0, 6)->align(Node::CENTER);
            $input = $c->input($a->linkURL, 'https://', 260)->autoFocus()->label('URL');
            if ($c->submitted($input) || $c->clicked($c->primaryButton('Link'))) {
                $doc->setLink(trim($a->linkURL->text));
                $a->linkOpen = false;
            }
            if ($f->link !== '' && $c->clicked($c->button('Remove'))) {
                $doc->setLink('');
                $a->linkOpen = false;
            }
          $c->end();
      });
      $divider();
      $button('•', 'Bulleted list (⇧⌘8)', $f->paragraph->list === ListKind::Bullets, fn() => $doc->toggleList(ListKind::Bullets));
      $button('1.', 'Numbered list (⇧⌘7)', $f->paragraph->list === ListKind::Numbers, fn() => $doc->toggleList(ListKind::Numbers));
      $button('☐', 'Checklist (⇧⌘L)', $f->paragraph->list === ListKind::Tasks, fn() => $doc->toggleList(ListKind::Tasks));
      $button('❝', 'Quote', $f->paragraph->quote, $doc->toggleQuote(...));
      $button('{}', 'Code block', $f->paragraph->codeBlock, $doc->toggleCodeBlock(...));
      $divider();
      if ($c->clicked($c->button('Copy Markdown')->keepFocus())) {
          Clipboard::writeText($doc->text()->markdown());
      }
    $c->end();
}

/** The first note. */
function welcome(Context $c): Text
{
    $code = new Attributes(code: true);
    return Text::of(
        new Paragraph(new ParagraphStyle(heading: 1), [new Run('Welcome to Notes')]),
        new Paragraph(runs: [new Run('Write with '), new Run('bold', new Attributes(weight: 700)), new Run(', '), new Run('italic', new Attributes(italic: true)), new Run(', '),
            new Run('links', new Attributes(link: 'https://github.com/egoist/mygo')), new Run(' and lists, from the toolbar, the Format menu or the keyboard.')]),
        new Paragraph(new ParagraphStyle(heading: 2), [new Run('Try')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks, checked: true), [new Run('Open this note')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Type '), new Run('- ', $code), new Run(' to start a list, '), new Run('# ', $code), new Run(' for a heading')]),
        new Paragraph(new ParagraphStyle(list: ListKind::Tasks), [new Run('Copy the note as Markdown')]),
        new Paragraph(runs: [new Run('Attachments sit in the line, as '),
            new Run(Text::OBJECT_REPLACEMENT, new Attributes(attachment: fileChip($c, 'itinerary.pdf'))),
            new Run(' does.')]),
    );
}

/** An attachment drawn as a chip with a file's name. */
function fileChip(Context $c, string $name): Attachment
{
    $font = new TextStyle();
    $font->size = 12;
    [$w] = $c->shapeText($name, 0, $font)->size();
    return new Attachment(name: $name, width: $w + 28, height: 20, draw: function (Painter $p, array $r) use ($name): void {
        $t = $p->theme();
        $p->fill($r, $t->accent, $r[3] / 2, 0.12);
        $p->fill([$r[0] + 8, $r[1] + 6, 7, 8], $t->accent, 1.5);
        [, $lh] = $p->measureText(0, new Span($name, 12));
        $p->richText($r[0] + 20, $r[1] + ($r[3] - $lh) / 2, 0, new Span($name, 12, color: $t->accent));
    });
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
        content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
