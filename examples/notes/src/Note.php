<?php
// A note of examples/notes: its text, as saved, and the rich-text document the editor edits.
declare(strict_types=1);

use MyPHP\UI\RichText\{Document, Text};

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
