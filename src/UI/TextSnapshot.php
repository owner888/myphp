<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\UI\Editor\{Rope, RopeNode};

/** An unchanging view of a TextBuffer at one version, sharing the chunks the buffer did not change since (mygo's TextSnapshot). Offsets are runes, as TextBuffer's; utf16Offset and runeOffset bridge native input's. */
final class TextSnapshot
{
    /** @internal */
    public function __construct(public readonly ?RopeNode $root = null, public readonly int $version = 0) {}

    public function len(): int { return Rope::sum($this->root)->runes; }
    public function byteLen(): int { return Rope::sum($this->root)->bytes; }
    public function utf16Len(): int { return Rope::sum($this->root)->units; }
    public function lineCount(): int { return Rope::sum($this->root)->lines + 1; }
    public function version(): int { return $this->version; }

    /** The whole text, for export: controls read slices instead. */
    public function string(): string
    {
        return $this->slice(0, $this->len());
    }

    /** Runes $start to $end, clamped to the text. */
    public function slice(int $start, int $end): string
    {
        $n = $this->len();
        $a = max(0, min($start, $end, $n)); $z = max(0, min(max($start, $end), $n));
        $out = '';
        Rope::read($this->root, Rope::byteOfRune($this->root, $a), Rope::byteOfRune($this->root, $z), $out);
        return $out;
    }

    /** The UTF-16 offset of rune $index. */
    public function utf16Offset(int $index): int
    {
        return Rope::prefix($this->root, Rope::byteOfRune($this->root, $index))->units;
    }

    /** The rune of UTF-16 offset $index, a unit inside a surrogate pair down to its rune. */
    public function runeOffset(int $index): int
    {
        return Rope::runeOfUnit($this->root, max(0, min($index, $this->utf16Len())));
    }

    /** The text of $r, its ends brought to whole runes, and the range it covers then. @return array{0: string, 1: TextInputRange} */
    public function textForRange(TextInputRange $r): array
    {
        $a = $this->runeOffset(min($r->start, $r->end)); $z = $this->runeOffset(max($r->start, $r->end));
        return [mb_scrub($this->slice($a, $z), 'UTF-8'), new TextInputRange($this->utf16Offset($a), $this->utf16Offset($z))];
    }

    /** The rune offsets of line $line, its newline left out; the empty line after a last newline counts, and $line clamps. @return array{0: int, 1: int} */
    public function lineRange(int $line): array
    {
        $line = max(0, min($line, $this->lineCount() - 1));
        $a = Rope::prefix($this->root, Rope::lineStart($this->root, $line))->runes;
        $z = $line + 1 < $this->lineCount() ? Rope::prefix($this->root, Rope::lineStart($this->root, $line + 1))->runes - 1 : $this->len();
        return [$a, $z];
    }

    /** The text of line $line, without its newline. */
    public function line(int $line): string
    {
        [$a, $z] = $this->lineRange($line);
        return $this->slice($a, $z);
    }

    /** The line rune $index is on. */
    public function lineAt(int $index): int
    {
        return Rope::prefix($this->root, Rope::byteOfRune($this->root, $index))->lines;
    }

    /** Writes the text to $stream, a chunk at a time, and returns how many bytes it wrote. @param resource $stream */
    public function writeTo($stream): int
    {
        $n = 0;
        Rope::chunks($this->root, 0, $this->byteLen(), function (string $s) use ($stream, &$n): bool {
            $w = fwrite($stream, $s);
            if ($w === false) { throw new \RuntimeException('cannot write the text'); }
            $n += $w;
            return true; // on to the next chunk
        });
        return $n;
    }
}
