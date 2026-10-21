<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use Closure;

/**
 * Changes a document as one step of undo, in Document::edit() (mygo's
 * richtext.Edit). Offsets are into the text as the edit's changes so far
 * left it, and the selection follows them.
 */
final class Edit
{
    /** @internal */
    public function __construct(private readonly Document $d, private readonly ?UndoStep $step) {}

    /** The text as the edit's changes so far left it. */
    public function text(): Text { return $this->d->text; }

    /** The selection as the edit's changes so far moved it. @return array{0: int, 1: int} [anchor, caret] */
    public function selection(): array { return [$this->d->anchor, $this->d->caret]; }

    /** Selects the runes between $anchor and $caret. */
    public function select(int $anchor, int $caret): void { $this->d->setSelection($anchor, $caret); }

    /** Replaces the runes from $start to $end with $text. */
    public function replace(int $start, int $end, Text $text): void
    {
        [$start, $end] = $this->clamp($start, $end);
        $this->d->apply($this->step, $start, $end - $start, $text->len(), $this->d->text->replace($start, $end, $text));
    }

    /** Replaces the runes from $start to $end with $s, which takes attributes $a. */
    public function replaceString(int $start, int $end, string $s, Attributes $a): void
    {
        $n = $this->d->text->len();
        [$start, $end] = $this->clamp($start, $end);
        $t = $this->d->text->replaceString($start, $end, $s, $a);
        $this->d->apply($this->step, $start, $end - $start, $t->len() - $n + $end - $start, $t);
    }

    /** Changes the attributes of the runes from $start to $end. @param Closure(Attributes): void $fn */
    public function format(int $start, int $end, Closure $fn): void
    {
        [$start, $end] = $this->clamp($start, $end);
        $t = $this->d->text->format($start, $end, $fn);
        if (!$t->same($this->d->text)) {
            $this->d->apply($this->step, $start, $end - $start, $end - $start, $t);
        }
    }

    /** Changes the styles of the paragraphs from the one holding $start to the one holding $end (see Text::formatParagraphs()). @param Closure(ParagraphStyle): void $fn */
    public function formatParagraphs(int $start, int $end, Closure $fn): void
    {
        $t = $this->d->text;
        [$pa, $pz] = $t->paragraphSpan($start, $end);
        $a = $t->paraStart($pa);
        $z = $t->paraStart($pz) + $t->para($pz)->n;
        $f = $t->formatParagraphs($start, $end, $fn);
        if (!$f->same($t)) {
            $this->d->apply($this->step, $a, $z - $a, $z - $a, $f);
        }
    }

    /** @return array{0: int, 1: int} */
    private function clamp(int $start, int $end): array
    {
        $n = $this->d->text->len();
        return [max(0, min($start, $end, $n)), max(0, min(max($start, $end), $n))];
    }
}
