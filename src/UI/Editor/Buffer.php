<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * The text an editor edits, with its undo history (mygo's editor.Buffer).
 * Several editors, and both sides of a diff view, can show one buffer: an
 * edit in one shows in the others, their carets and selections moving
 * with the text around them, and undo takes back the buffer's last edit,
 * whichever editor made it, as in Zed.
 *
 * The text is a persistent tree of chunks (Rope): an edit copies a few
 * kilobytes and a path of the tree, whatever the size of the document,
 * and each version shares the rest with the one before, so undo history
 * keeps versions rather than copies, and a version (Snapshot) stays as
 * it is while the buffer changes. Offsets count bytes of UTF-8, and a Pos
 * a line and the bytes into it.
 *
 * Lines end in \n, as in Zed: a text set with \r\n line endings keeps \n
 * ones, and lineEnding() says which it had, which Snapshot::text writes.
 */
final class Buffer
{
    private const LOG_MAX = 256, UNDO_MAX = 10000;
    /** Seconds within which edits of one kind by one editor make one step of undo. */
    private const GROUP_TIMEOUT = 0.3;

    private RopeText $text;
    private int $version = 0;
    /** @var list<array{0: int, 1: RopeText, 2: list<Change>}> the changes of the last versions, and the text each made, which editors, the highlighter and diffs follow to keep their state of the text: [version, text, changes] */
    private array $log = [];
    private RopeText $saved;
    /** @var list<Transaction> */
    private array $undo = [], $redo = [];
    /** Counts the transact() calls running, which group edits into $openTx. */
    private int $open = 0;
    private ?Transaction $openTx = null;
    /** When the last edit was made (microtime), 0.0 for none to group with. */
    private float $lastEdit = 0.0;
    /** The text's line endings are \r\n. */
    private bool $crlf;
    private ?Language $language = null;
    /** The buffer's highlighter and the text it follows, [Language, Highlighter, Snapshot], made for the language set. */
    private ?array $syntax = null;

    /** A buffer holding $text, which is its saved text. */
    public function __construct(string $text = '')
    {
        $this->text = RopeText::new(self::lf($text));
        $this->crlf = str_contains($text, "\r\n");
        $this->saved = $this->text;
    }

    /** $s with \n line endings. */
    private static function lf(string $s): string
    {
        return str_contains($s, "\r\n") ? str_replace("\r\n", "\n", $s) : $s;
    }

    /** The line ending the text had as it was set, "\r\n" or "\n", which Snapshot::text writes; setLineEnding changes it. */
    public function lineEnding(): string { return $this->crlf ? "\r\n" : "\n"; }
    public function setLineEnding(string $ending): void { $this->crlf = $ending === "\r\n"; }

    /** The buffer's language, null for plain text. */
    public function language(): ?Language { return $this->language; }

    /** Sets the language that highlights the text, and that comments, brackets and indentation follow; null is plain text. */
    public function setLanguage(?Language $l): void
    {
        if ($this->language !== $l) { $this->language = $l; $this->syntax = null; }
    }

    /**
     * The buffer's highlighter, following the edits since it was last
     * used, or null for plain text. A highlighter too far behind the log
     * to follow them is made anew of the text as it is.
     */
    public function highlighter(): ?Highlighter
    {
        $lang = $this->language;
        if ($lang === null || $lang->tokenizer === null && $lang->highlighter === null) { return null; }
        if ($this->syntax === null || $this->syntax[0] !== $lang) {
            $text = $this->snapshot();
            $this->syntax = [$lang, $lang->newHighlighter($text), $text];
        }
        $entries = $this->entriesSince($this->syntax[2]->version);
        if ($entries === null) {
            $text = $this->snapshot();
            $this->syntax = [$lang, $lang->newHighlighter($text), $text];
            return $this->syntax[1];
        }
        foreach ($entries as [$version, $rope, $changes]) {
            $next = new Snapshot($rope, $version, $this->crlf);
            $this->syntax[1]->edit($this->syntax[2], $next, $changes);
            $this->syntax[2] = $next;
        }
        return $this->syntax[1];
    }

    /** The text as it is now, which stays as it is while the buffer changes. */
    public function snapshot(): Snapshot { return new Snapshot($this->text, $this->version, $this->crlf); }
    /** The current version of the text, for the editor and the highlighter to follow. */
    public function rope(): RopeText { return $this->text; }

    public function __toString(): string { return (string)$this->text; }
    public function len(): int { return $this->text->len(); }
    /** How many lines the text has: one more than its newlines. */
    public function lineCount(): int { return $this->text->lineCount(); }
    /** Line $i, without its newline. */
    public function line(int $i): string { return $this->text->line($i); }
    public function slice(int $start, int $end): string { return $this->text->slice($start, $end); }
    /** The line and column of byte $offset. */
    public function pos(int $offset): Pos { return self::posOf($this->text, $offset); }
    /** The byte offset of $p, its column clamped to its line. */
    public function offset(Pos $p): int { return self::offsetOf($this->text, $p); }
    /** A number that changes with every change of the text. */
    public function version(): int { return $this->version; }

    /** Whether the text differs from the saved one: the text the buffer was made or reset with, or had when markSaved was called. Undoing back to it makes the buffer unmodified again. */
    public function modified(): bool
    {
        return !$this->text->same($this->saved) && (string)$this->text !== (string)$this->saved;
    }

    /** Makes the current text the saved one. */
    public function markSaved(): void { $this->saved = $this->text; }

    /** Replaces the text and forgets the undo history, as for a file loaded anew, and makes it the saved text. */
    public function reset(string $text): void
    {
        $old = $this->text;
        $this->text = RopeText::new(self::lf($text));
        $this->crlf = str_contains($text, "\r\n");
        $this->saved = $this->text;
        $this->undo = []; $this->redo = []; $this->openTx = null;
        $this->record([Change::whole($old, $this->text)]);
    }

    /** Replaces the whole text as one edit, which undo takes back. */
    public function set(string $text): void
    {
        $this->edit([new Edit(0, $this->text->len(), $text)], Transaction::OTHER, null, null);
    }

    /** Applies edits to the text as one step of undo. Their offsets are in the text before any of them; overlapping edits are cut to follow the one before. */
    public function applyEdits(Edit ...$edits): void
    {
        $this->edit($edits, Transaction::OTHER, null, null);
    }

    /** Groups the edits $fn makes, through applyEdits and the editors showing the buffer, into one step of undo. */
    public function transact(callable $fn): void
    {
        $this->open++;
        try {
            $fn();
        } finally {
            $this->open--;
            if ($this->open === 0) { $this->openTx = null; }
        }
    }

    public function canUndo(): bool { return $this->undo !== []; }
    public function canRedo(): bool { return $this->redo !== []; }

    /** Takes back the last step of edits, and reports whether there was one. */
    public function undo(): bool { return $this->undoStep() !== null; }
    /** Makes the step undo took back again, and reports whether there was one. */
    public function redo(): bool { return $this->redoStep() !== null; }

    /** Takes back the last transaction. */
    public function undoStep(): ?Transaction
    {
        $tx = array_pop($this->undo);
        if ($tx === null) { return null; }
        $this->redo[] = $tx;
        $this->openTx = null;
        $this->lastEdit = 0.0;
        for ($i = count($tx->changes) - 1; $i >= 0; $i--) {
            $this->text = $i > 0 ? $tx->texts[$i - 1] : $tx->before;
            $this->record(Change::invert($tx->changes[$i]));
        }
        $this->text = $tx->before;
        return $tx;
    }

    /** Makes the last transaction undone again. */
    public function redoStep(): ?Transaction
    {
        $tx = array_pop($this->redo);
        if ($tx === null) { return null; }
        $this->undo[] = $tx;
        $this->openTx = null;
        $this->lastEdit = 0.0;
        foreach ($tx->changes as $i => $c) {
            $this->text = $tx->texts[$i];
            $this->record($c);
        }
        $this->text = $tx->after;
        return $tx;
    }

    /**
     * Applies $edits as a transaction, or into the open one, grouped with
     * the last by $kind and $owner (the editor) when they follow it
     * closely. Returns the changes, [] when nothing changed.
     * @param list<Edit> $edits @return list<Change>
     */
    public function edit(array $edits, int $kind, mixed $owner, mixed $selBefore): array
    {
        if ($edits === []) { return []; }
        usort($edits, fn(Edit $x, Edit $y) => $x->start <=> $y->start); // stable since PHP 8
        $before = $this->text;
        $text = $before;
        $changes = [];
        $delta = 0; $lineDelta = 0; $last = 0;
        foreach ($edits as $e) {
            $in = str_contains($e->text, "\r\n") ? self::lf($e->text) : $e->text;
            $start = max($last, min($e->start, $before->len()));
            $end = max($start, min($e->end, $before->len()));
            $last = $end;
            if ($start === $end && $in === '' || $end - $start === strlen($in) && $before->slice($start, $end) === $in) { continue; }
            $c = new Change($start, $end);
            $c->oldLine = $before->lineOf($start);
            $c->oldEndLine = $end > $start ? $before->lineOf($end) : $c->oldLine;
            $c->newStart = $start + $delta;
            $c->newEnd = $c->newStart + strlen($in);
            $c->newLine = $c->oldLine + $lineDelta;
            $c->newEndLine = $c->newLine + substr_count($in, "\n");
            $text = $text->replace($c->newStart, $c->newStart + $end - $start, $in);
            $delta += strlen($in) - ($end - $start);
            $lineDelta += ($c->newEndLine - $c->newLine) - ($c->oldEndLine - $c->oldLine);
            $changes[] = $c;
        }
        if ($changes === []) { return []; }
        $this->text = $text;
        $this->record($changes);
        $this->redo = [];
        $now = microtime(true);
        $lastTx = $this->undo === [] ? null : $this->undo[count($this->undo) - 1];
        if ($this->openTx !== null) {
            $tx = $this->openTx;
            $tx->after = $text; $tx->changes[] = $changes; $tx->texts[] = $text;
        } elseif ($this->open === 0 && $kind !== Transaction::OTHER && $lastTx !== null && $lastTx->kind === $kind && $lastTx->owner === $owner
            && ($now - $this->lastEdit < self::GROUP_TIMEOUT || $kind === Transaction::COMPOSE && $this->lastEdit !== 0.0)) {
            $lastTx->after = $text; $lastTx->changes[] = $changes; $lastTx->texts[] = $text;
        } else {
            $tx = new Transaction($before, $text, [$changes], [$text], $owner, $selBefore, $kind);
            $this->undo[] = $tx;
            if (count($this->undo) > self::UNDO_MAX) { $this->undo = array_slice($this->undo, count($this->undo) - self::UNDO_MAX); }
            if ($this->open > 0) { $this->openTx = $tx; }
        }
        $this->lastEdit = $now;
        return $changes;
    }

    /** Ends the group of typing, so that the next edit makes a step of undo of its own, as moving the caret does. */
    public function endGroup(): void { $this->lastEdit = 0.0; }

    /** Notes where the owner of the last transaction has its selections after it. */
    public function setSelectionsAfter(mixed $owner, mixed $sel): void
    {
        $n = count($this->undo);
        if ($n > 0 && $this->undo[$n - 1]->owner === $owner) { $this->undo[$n - 1]->selAfter = $sel; }
    }

    /** Logs the changes of a new version, made of the current text. @param list<Change> $changes */
    private function record(array $changes): void
    {
        $this->version++;
        $this->log[] = [$this->version, $this->text, $changes];
        if (count($this->log) > self::LOG_MAX) { $this->log = array_slice($this->log, count($this->log) - intdiv(self::LOG_MAX, 2)); }
    }

    /**
     * The changes of the versions after $since, the version they lead to
     * and its text, read at once, so that the text is the changes' and not
     * a later one's; null when the log no longer reaches back to $since.
     * @return ?array{0: list<list<Change>>, 1: int, 2: RopeText}
     */
    public function changesTo(int $since): ?array
    {
        $entries = $this->entriesSince($since);
        if ($entries === null) { return null; }
        return [array_map(fn($e) => $e[2], $entries), $this->version, $this->text];
    }

    /** The log's entries after version $since, [version, text, changes] each; null when the log no longer reaches back to $since. @return ?list<array{0: int, 1: RopeText, 2: list<Change>}> */
    public function entriesSince(int $since): ?array
    {
        if ($since === $this->version) { return []; }
        if ($this->log === [] || $this->log[0][0] > $since + 1 || $since > $this->version) { return null; }
        $lo = 0; $hi = count($this->log);
        while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($this->log[$m][0] < $since + 1) { $lo = $m + 1; } else { $hi = $m; } }
        return array_slice($this->log, $lo);
    }

    /** The changes from $old to $new by their lines, for an editor too far behind the log to follow the edits themselves. @return list<Change> */
    public static function changesBetween(RopeText $old, RopeText $new): array
    {
        $lines = function (RopeText $t): array {
            $out = [];
            $t->eachLine(0, $t->lineCount(), function ($i, $start, $s) use (&$out) { $out[] = $s; return true; });
            return $out;
        };
        $at = fn(RopeText $t, int $line) => $line >= $t->lineCount() ? $t->len() : $t->lineStart($line);
        $out = [];
        foreach (Diff::lines($lines($old), $lines($new)) as $h) {
            $c = new Change($at($old, $h->a0), $at($old, $h->a1), $at($new, $h->b0), $at($new, $h->b1));
            $c->oldLine = $old->lineOf($c->oldStart); $c->oldEndLine = $old->lineOf($c->oldEnd);
            $c->newLine = $new->lineOf($c->newStart); $c->newEndLine = $new->lineOf($c->newEnd);
            $out[] = $c;
        }
        return $out;
    }

    /** The line and column, in bytes, of byte $off of $t. */
    public static function posOf(RopeText $t, int $off): Pos
    {
        $off = max(0, min($off, $t->len()));
        $line = $t->lineOf($off);
        return new Pos($line, $off - $t->lineStart($line));
    }

    /** The byte of $p in $t, its column clamped to its line. */
    public static function offsetOf(RopeText $t, Pos $p): int
    {
        if ($p->line < 0) { return 0; }
        if ($p->line >= $t->lineCount()) { return $t->len(); }
        $a = $t->lineStart($p->line);
        return $a + max(0, min($p->col, $t->lineEnd($p->line) - $a));
    }
}
