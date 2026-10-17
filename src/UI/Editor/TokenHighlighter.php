<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * The highlighter a Language uses by default (mygo's tokenHighlighter):
 * a Tokenizer tokenizes each line from the state the line before ends
 * in, and an edit tokenizes the lines after it again until one starts in
 * the state it started in before, as TextMate grammars and Monaco do, a
 * frame's budget at a time. It keeps the state each line starts in,
 * valid for the lines before $frontier; past it they are what they were
 * before the edits since, which a line starting as it did then, past
 * $dirty, confirms.
 */
final class TokenHighlighter implements Highlighter
{
    /** Leaves longer lines plain, as Pierre's tokenizeMaxLineLength does. */
    private const MAX_TOKENIZE = 10000;

    /** @var list<int> the state line i starts in */
    private array $states = [0];
    /** How many lines from the top start in states known right. */
    private int $frontier = 1;
    /** How many would be, were the edits since the last pass to leave the states after them as they were: a pass that tokenizes past the edits into a line starting as it did confirms the lines up to it, and no further, as those after it were never tokenized since an earlier edit. */
    private int $confirmed = 1;
    /** The last line an edit changed, -1 for none. */
    private int $dirty = -1;

    public function __construct(private readonly Tokenizer $t, private Snapshot $text) {}

    public function edit(Snapshot $old, Snapshot $new, array $changes): void
    {
        $this->text = $new;
        foreach ($changes as $c) {
            $oldLine = $old->lineOf($c->oldStart); $oldEndLine = $old->lineOf($c->oldEnd);
            $newLine = $new->lineOf($c->newStart); $newEndLine = $new->lineOf($c->newEnd);
            $removed = $oldEndLine - $oldLine; $added = $newEndLine - $newLine;
            // The states of the lines after the change move with them.
            $from = $newLine + 1;
            if ($from < count($this->states)) {
                array_splice($this->states, $from, min(count($this->states) - $from, $removed), array_fill(0, $added, 0));
            }
            // Line newLine starts as it did; those after it may not, and those after a change within the lines confirmed move with them.
            $this->frontier = min($this->frontier, $newLine + 1);
            if ($oldEndLine < $this->confirmed) { $this->confirmed += $added - $removed; }
            else { $this->confirmed = min($this->confirmed, $newLine + 1); }
            if ($this->dirty > $oldEndLine) { $this->dirty += $added - $removed; }
            $this->dirty = max($this->dirty, $newEndLine);
        }
    }

    public function highlight(int $last, float $budget): bool
    {
        $n = $this->text->lineCount();
        $last = min($last, $n - 1);
        if (count($this->states) > $n) { $this->states = array_slice($this->states, 0, $n); }
        if ($this->states === []) { $this->states[] = 0; }
        $this->frontier = max(1, min($this->frontier, count($this->states)));
        $this->confirmed = min($this->confirmed, count($this->states));
        $start = hrtime(true);
        $count = 0;
        $stopped = false;
        while ($this->frontier <= $last && !$stopped) {
            $this->text->t->eachLine($this->frontier - 1, $last, function (int $i, int $_, string $line) use (&$count, $start, $budget, &$stopped): bool {
                if ((++$count & 63) === 0 && (hrtime(true) - $start) / 1e9 > $budget) { $stopped = true; return false; }
                [, $end] = $this->t->tokenize($line, $this->states[$i], []);
                if ($i + 1 < count($this->states)) {
                    if ($this->states[$i + 1] === $end && $i + 1 > $this->dirty && $this->confirmed > $i + 2) {
                        // The lines after start as they did, up to those confirmed before the edits.
                        $this->frontier = $this->confirmed;
                        return false;
                    }
                    $this->states[$i + 1] = $end;
                } else {
                    $this->states[] = $end;
                }
                $this->frontier = $i + 2;
                return true;
            });
        }
        // Whatever way the pass ends, the lines it leaves known are those confirmed: those past where it stopped wait for the next.
        $this->confirmed = $this->frontier;
        if ($this->frontier > $this->dirty) { $this->dirty = -1; }
        return !$stopped;
    }

    public function tokens(int $i, string $text, array $dst): array
    {
        [$state, $ok] = $this->stateOf($i);
        if (strlen($text) > self::MAX_TOKENIZE) { return [$dst, $ok]; }
        [$dst] = $this->t->tokenize($text, $state, $dst);
        return [$dst, $ok];
    }

    /** The state $line starts in, which highlight may not have confirmed yet: then the line is tokenized from the state it had before the edits since, which is usually right. @return array{0: int, 1: bool} */
    public function stateOf(int $line): array
    {
        if ($line < count($this->states)) { return [$this->states[$line], $line < $this->frontier]; }
        return [0, false];
    }
}
