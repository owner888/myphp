<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use Closure;

/**
 * What an Editor edits (mygo's richtext.Document): a Text, its selection
 * with the attributes typing gets there, and the history of undo, which
 * the app keeps from frame to frame, as it keeps a text buffer. A toolbar,
 * a menu or a timer formats and edits it while the editor shows it, and
 * the editor follows what changed at its next frame.
 *
 * Commands act on the selection as the editor's shortcuts do: on a range
 * they change its runs or paragraphs, as one step of undo; on a caret,
 * character formats change only the typing attributes, which the next
 * typed text gets and the next move of the caret forgets, as ⌘B does in
 * NSTextView.
 */
final class Document
{
    use DocumentCommands;

    /** @internal */
    public Text $text;
    /** @internal */
    public int $anchor;
    /** @internal */
    public int $caret;
    /** @internal null: derived from the text at the caret */
    public ?Attributes $typing = null;
    /** @internal counts the changes of the text */
    public int $version = 1;
    /** @internal counts changes of the selection made by the document's methods, which the editor then takes */
    public int $selVersion = 0;
    /** @internal @var list<UndoStep> */
    public array $undo = [];
    /** @internal @var list<UndoStep> */
    public array $redo = [];
    /** @internal nanoseconds, hrtime */
    public int $lastEdit = 0;
    /** @internal typing may join the last step */
    public bool $coalesce = false;
    /** @internal what changed since the versions the editor last saw, for it to lay out only the paragraphs that changed; @var list<Change> */
    public array $log = [];

    /** A document of $text, its caret at the end (mygo's NewDocument). */
    public function __construct(Text $text = new Text)
    {
        $this->text = $text;
        $this->anchor = $this->caret = $text->len();
    }

    /** The text of the document, which later edits leave as it is. */
    public function text(): Text { return $this->text; }

    /** Counts the changes of the document's text. */
    public function version(): int { return $this->version; }

    /**
     * Replaces the text, as when the app opens another note: it is not a
     * step of undo, and clears the history. The selection goes to the end.
     */
    public function setText(Text $text): void
    {
        $old = $this->text->len();
        $this->text = $text;
        $this->undo = $this->redo = [];
        $this->coalesce = false;
        $this->changed(0, $old, $text->len());
        $n = $text->len();
        $this->setSelection($n, $n);
    }

    /** The selection: the anchor is where it started, the caret where it ends, equal for a caret. @return array{0: int, 1: int} */
    public function selection(): array { return [$this->anchor, $this->caret]; }

    /** Selects the runes between $anchor and $caret, or puts the caret at $caret when they are equal; the editor scrolls it into view. */
    public function setSelection(int $anchor, int $caret): void
    {
        $n = $this->text->len();
        $this->anchor = max(0, min($anchor, $n));
        $this->caret = max(0, min($caret, $n));
        $this->typing = null;
        $this->coalesce = false;
        $this->selVersion++;
    }

    /** @internal the selection's start and end @return array{0: int, 1: int} */
    public function span(): array { return [min($this->anchor, $this->caret), max($this->anchor, $this->caret)]; }

    /**
     * The attributes typing gets at the caret: those a command set at the
     * caret, else those of the character before it (after it at the start
     * of a paragraph), or of the first selected character, without a link,
     * code or tag at their edges, as NSTextView derives them.
     */
    public function typingAttributes(): Attributes
    {
        if ($this->typing !== null) { return clone $this->typing; }
        [$a, $z] = $this->span();
        return self::typingAttributesOf($this->text, $a, $z);
    }

    /** @internal the attributes typing gets in place of the runes from $start to $end of $t */
    public static function typingAttributesOf(Text $t, int $start, int $end): Attributes
    {
        if ($start < $end) { return $t->attrsAt($start)->plain(); }
        [$ps, $pe] = $t->paragraphRange($start);
        if ($start === $ps) { return clone $t->attrsAt($start)->extending(); }
        $before = $t->attrsAt($start - 1);
        $after = $t->attrsAt($start);
        if ($start === $pe) {
            // The newline: a link or code ends with the line, as a selection
            // of whole lines formats newlines too.
            $after = new Attributes;
        }
        $a = clone $before->extending();
        // What does not extend holds inside: between two runes of a link.
        if ($before->link === $after->link) { $a->link = $before->link; }
        if ($before->code && $after->code) { $a->code = true; }
        if ($before->tag === $after->tag) { $a->tag = $before->tag; }
        return $a;
    }

    /** Sets the attributes the next typed text gets, until the caret moves. */
    public function setTypingAttributes(Attributes $a): void
    {
        $a = clone $a;
        $a->attachment = null;
        $this->typing = $a;
    }

    /** @internal notes that the runes from $start to $oldEnd became those from $start to $newEnd */
    public function changed(int $start, int $oldEnd, int $newEnd): void
    {
        $this->version++;
        $this->log[] = new Change($this->version, $start, $oldEnd, $newEnd);
        if (count($this->log) > 256) {
            // The editor reloads a text it is too far behind.
            $this->log = array_slice($this->log, -128);
        }
    }

    /** @internal the change from version $v to the document's, as one region, or null when the log does not hold all of it */
    public function since(int $v): ?Change
    {
        if ($v === $this->version) { return new Change($v); }
        $c = null;
        foreach ($this->log as $l) {
            if ($l->version <= $v) { continue; }
            if ($c === null) {
                if ($l->version !== $v + 1) { return null; }
                $c = $l;
                continue;
            }
            $c = Change::compose($c, $l);
        }
        return $c !== null && $c->version === $this->version ? $c : null;
    }

    /**
     * @internal makes $t, in which the runes from $start to $start +
     * $removed of the text are now $start to $start + $inserted, the
     * document's text, as part of $step
     */
    public function apply(?UndoStep $step, int $start, int $removed, int $inserted, Text $t): void
    {
        $formatted = $t->text === $this->text->text; // the text is the same: only formats changed
        $this->text = $t;
        if ($step !== null) {
            if ($step->start < 0) {
                $step->start = $start; $step->oldEnd = $start + $removed; $step->newEnd = $start + $inserted;
            } else {
                $c = Change::compose(new Change(0, $step->start, $step->oldEnd, $step->newEnd), new Change(0, $start, $start + $removed, $start + $inserted));
                $step->start = $c->start; $step->oldEnd = $c->oldEnd; $step->newEnd = $c->newEnd;
            }
            $step->after = $t;
        }
        $this->changed($start, $start + $removed, $start + $inserted);
        if ($formatted) { return; }
        [$a, $z] = $this->span();
        if ($a === $z) {
            $a = $z = Change::mapPosition($a, $start, $removed, $inserted, false);
        } else {
            $a = Change::mapPosition($a, $start, $removed, $inserted, false);
            $z = Change::mapPosition($z, $start, $removed, $inserted, true);
        }
        if ($this->caret >= $this->anchor) {
            $this->anchor = $a; $this->caret = $z;
        } else {
            $this->anchor = $z; $this->caret = $a;
        }
        $this->typing = null;
    }

    /**
     * Changes the document with $fn, as one step of undo, which undoing
     * takes back whole with the selection before it:
     *
     *     $doc->edit(function (Edit $e) {
     *         $e->replaceString(0, 0, "Dear Ann,\n", new Attributes);
     *         $e->format(0, 8, fn (Attributes $a) => $a->italic = true);
     *     });
     *
     * @param Closure(Edit): void $fn
     */
    public function edit(Closure $fn): void { $this->editStep(false, $fn); }

    /**
     * @internal runs $fn as a step of undo, joined to the last one when
     * $join is set and the last edit was typing a moment ago (mygo's
     * Document.edit)
     *
     * @param Closure(Edit): void $fn
     */
    public function editStep(bool $join, Closure $fn): void
    {
        $now = hrtime(true);
        if ($join && $this->coalesce && $now - $this->lastEdit < 1_000_000_000 && $this->undo !== []) {
            $step = $this->undo[count($this->undo) - 1];
        } else {
            $step = new UndoStep($this->text, $this->text, [$this->anchor, $this->caret]);
            $this->undo[] = $step;
        }
        $fn(new Edit($this, $step));
        if ($step->start < 0) {
            array_pop($this->undo); // nothing changed
            return;
        }
        if (count($this->undo) > 200) { array_shift($this->undo); }
        $step->selAfter = [$this->anchor, $this->caret];
        $this->redo = [];
        $this->coalesce = $join;
        $this->lastEdit = $now;
    }

    /** Whether there is a step to undo. */
    public function canUndo(): bool { return $this->undo !== []; }

    /** Whether there is a step to redo. */
    public function canRedo(): bool { return $this->redo !== []; }

    /** Takes back the last step of undo, restoring the text, its attributes and the selection. */
    public function undo(): void { $this->takeBack(false); }

    /** Takes back the last step undone. */
    public function redo(): void { $this->takeBack(true); }

    private function takeBack(bool $redo): void
    {
        $s = $redo ? array_pop($this->redo) : array_pop($this->undo);
        if ($s === null) { return; }
        if ($redo) {
            $this->text = $s->after;
            $this->changed($s->start, $s->oldEnd, $s->newEnd);
            $this->setSelection($s->selAfter[0], $s->selAfter[1]);
            $this->undo[] = $s;
        } else {
            $this->text = $s->before;
            $this->changed($s->start, $s->newEnd, $s->oldEnd);
            $this->setSelection($s->selBefore[0], $s->selBefore[1]);
            $this->redo[] = $s;
        }
    }

    /** Ends the step typing adds to, as at a save, so that what is typed next undoes on its own. */
    public function breakUndoCoalescing(): void { $this->coalesce = false; }
}
