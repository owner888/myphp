<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\UI\Editor\Rope;

/**
 * Editable UTF-8 text in an indexed, persistent tree (mygo's TextBuffer):
 * a small edit copies the chunks it touches and the path to them, not the
 * document. Offsets are runes, as slice() and replace() take them;
 * utf16Offset, runeOffset and textForRange bridge native input's UTF-16.
 * Each change advances its version; a snapshot keeps the text it saw.
 */
final class TextBuffer
{
    private TextSnapshot $s;

    public function __construct(string $text = '')
    {
        $this->s = new TextSnapshot(Rope::build($text), 0);
    }

    /** The text as it is now, which later edits leave as it was. */
    public function snapshot(): TextSnapshot
    {
        return $this->s;
    }

    public function len(): int { return $this->s->len(); }
    public function byteLen(): int { return $this->s->byteLen(); }
    public function utf16Len(): int { return $this->s->utf16Len(); }
    public function lineCount(): int { return $this->s->lineCount(); }
    public function version(): int { return $this->s->version; }
    public function string(): string { return $this->s->string(); }
    public function slice(int $start, int $end): string { return $this->s->slice($start, $end); }
    public function utf16Offset(int $index): int { return $this->s->utf16Offset($index); }
    public function runeOffset(int $index): int { return $this->s->runeOffset($index); }
    /** @return array{0: string, 1: TextInputRange} */
    public function textForRange(TextInputRange $r): array { return $this->s->textForRange($r); }
    /** @param resource $stream */
    public function writeTo($stream): int { return $this->s->writeTo($stream); }

    /** Replaces the document; snapshots keep their own. */
    public function set(string $text): void
    {
        $this->restore(new TextSnapshot(Rope::build($text)));
    }

    /** Takes the text of snapshot $s, as an undo does, advancing the version. */
    public function restore(TextSnapshot $s): void
    {
        $this->s = new TextSnapshot($s->root, $this->s->version + 1);
    }

    /** Replaces runes $start to $end, clamped to the document, with $text. */
    public function replace(int $start, int $end, string $text): void
    {
        $this->s = new TextSnapshot(Rope::replace($this->s->root, $start, $end, $text), $this->s->version + 1);
    }

    /** Replaces the UTF-16 range $r, its ends brought down to whole runes, with $text, and returns the range it replaced. */
    public function replaceUTF16(TextInputRange $r, string $text): TextInputRange
    {
        $a = $this->s->runeOffset(min($r->start, $r->end)); $z = $this->s->runeOffset(max($r->start, $r->end));
        $actual = new TextInputRange($this->s->utf16Offset($a), $this->s->utf16Offset($z));
        $this->replace($a, $z, $text);
        return $actual;
    }
}
