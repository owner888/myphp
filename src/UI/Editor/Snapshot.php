<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A version of a buffer's text, which stays as it is while the buffer changes (mygo's editor.Snapshot): what a parser reads, or what is saved. */
final class Snapshot
{
    public function __construct(public readonly RopeText $t, public readonly int $version, public readonly bool $crlf) {}

    public function len(): int { return $this->t->len(); }
    public function lineCount(): int { return $this->t->lineCount(); }
    public function __toString(): string { return (string)$this->t; }
    /** Line $i, without its newline. */
    public function line(int $i): string { return $this->t->line($i); }
    public function lineStart(int $i): int { return $this->t->lineStart($i); }
    /** The byte line $i ends at, before its newline. */
    public function lineEnd(int $i): int { return $this->t->lineEnd($i); }
    public function lineOf(int $offset): int { return $this->t->lineOf($offset); }
    public function pos(int $offset): Pos { return Buffer::posOf($this->t, $offset); }
    public function offset(Pos $p): int { return Buffer::offsetOf($this->t, $p); }
    public function slice(int $start, int $end): string { return $this->t->slice($start, $end); }
    /** Calls $fn with bytes $start to $end, a piece at a time, until $fn returns false: what a parser reads text with. */
    public function chunks(int $start, int $end, callable $fn): void { $this->t->chunks($start, $end, $fn); }

    /** The text with its line ending (\r\n where the text was set with them), as for saving it. */
    public function text(): string
    {
        $s = (string)$this->t;
        return $this->crlf ? str_replace("\n", "\r\n", $s) : $s;
    }
}
