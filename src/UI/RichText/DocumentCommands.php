<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use Closure;

/**
 * The commands of a Document (mygo's richtext commands.go): the formats of
 * the selection, and the toggles of a toolbar and a Format menu.
 *
 * @internal part of Document
 */
trait DocumentCommands
{
    /** The formats of the selection. */
    public function formatting(): Formatting
    {
        [$a, $z] = $this->span();
        $f = new Formatting($this->typingAttributes());
        if ($a < $z) {
            // From the first rune that takes a format, not a newline.
            foreach ($this->text->runs($a, $z) as $r) {
                if ($r->text !== "\n") {
                    $f->attributes = $r->attributes;
                    $f->attributes->attachment = null;
                    break;
                }
            }
        }
        $state = fn (bool $on): FormatState => $on ? FormatState::FormatOn : FormatState::FormatOff;
        $at = $f->attributes;
        $f->bold = $state($at->bold());
        $f->italic = $state($at->italic);
        $f->underline = $state($at->underline);
        $f->strikethrough = $state($at->strikethrough);
        $f->code = $state($at->code);
        $f->link = $at->link;
        if ($a < $z) {
            $mix = function (FormatState $s, bool $on): FormatState {
                return ($s === FormatState::FormatOn) !== $on ? FormatState::FormatMixed : $s;
            };
            foreach ($this->text->runs($a, $z) as $r) {
                if ($r->text === "\n") { continue; } // a newline selected takes no format
                $x = $r->attributes;
                $f->bold = $mix($f->bold, $x->bold());
                $f->italic = $mix($f->italic, $x->italic);
                $f->underline = $mix($f->underline, $x->underline);
                $f->strikethrough = $mix($f->strikethrough, $x->strikethrough);
                $f->code = $mix($f->code, $x->code);
                if ($x->link !== $f->link) { $f->link = ''; }
            }
        }
        [$pa, $pz] = $this->text->paragraphSpan($a, $z);
        Seq::each($this->text->seq(), $pa, function (int $i, RichPara $p) use ($pa, $pz, $f): bool {
            if ($i === $pa) {
                $f->paragraph = clone $p->style;
            } elseif (!$p->style->withID($f->paragraph->id)->equals($f->paragraph)) { // paragraphs of other IDs look the same
                $f->paragraphsMixed = true;
            }
            return $i < $pz;
        });
        return $f;
    }

    /**
     * Changes the attributes of the selection with $fn, as one step of
     * undo, or the typing attributes at a caret.
     *
     * @param Closure(Attributes): void $fn
     */
    public function format(Closure $fn): void
    {
        [$a, $z] = $this->span();
        if ($a === $z) {
            $t = $this->typingAttributes();
            $fn($t);
            $t->attachment = null;
            $this->typing = $t;
            return;
        }
        [$anchor, $caret] = [$this->anchor, $this->caret];
        $this->edit(fn (Edit $e) => $e->format($a, $z, $fn));
        [$this->anchor, $this->caret] = [$anchor, $caret];
    }

    /**
     * Turns a format on for the selection unless all of it has it, then off.
     *
     * @param Closure(Formatting): FormatState $state
     * @param Closure(Attributes, bool): void $set
     */
    private function toggle(Closure $state, Closure $set): void
    {
        $on = $state($this->formatting()) !== FormatState::FormatOn;
        $this->format(fn (Attributes $a) => $set($a, $on));
    }

    /** Makes the selection bold, or not when all of it is, as ⌘B does. */
    public function toggleBold(): void
    {
        $this->toggle(fn (Formatting $f) => $f->bold, function (Attributes $a, bool $on): void { $a->weight = $on ? 700 : 0; });
    }

    /** Turns italics on for the selection, or off when all of it has them; and so for the toggles after it. */
    public function toggleItalic(): void
    {
        $this->toggle(fn (Formatting $f) => $f->italic, function (Attributes $a, bool $on): void { $a->italic = $on; });
    }

    public function toggleUnderline(): void
    {
        $this->toggle(fn (Formatting $f) => $f->underline, function (Attributes $a, bool $on): void { $a->underline = $on; });
    }

    public function toggleStrikethrough(): void
    {
        $this->toggle(fn (Formatting $f) => $f->strikethrough, function (Attributes $a, bool $on): void { $a->strikethrough = $on; });
    }

    public function toggleCode(): void
    {
        $this->toggle(fn (Formatting $f) => $f->code, function (Attributes $a, bool $on): void { $a->code = $on; });
    }

    /**
     * Links the selection to $url, or takes its link away for ''. At a caret
     * inside a link, it changes or takes away that link; elsewhere it
     * inserts $url, linked to itself.
     */
    public function setLink(string $url): void
    {
        [$a, $z] = $this->span();
        if ($a === $z) {
            [$la, $lz] = $this->text->linkRange($a);
            if ($la === $lz && $a > 0) {
                [$la, $lz] = $this->text->linkRange($a - 1);
                if ($lz !== $a) { $la = $lz = $a; }
            }
            if ($la === $lz) {
                if ($url !== '') {
                    $attrs = $this->typingAttributes();
                    $attrs->link = $url;
                    $this->edit(fn (Edit $e) => $e->replaceString($a, $a, $url, $attrs));
                }
                return;
            }
            [$a, $z] = [$la, $lz];
        }
        [$anchor, $caret] = [$this->anchor, $this->caret];
        $this->edit(fn (Edit $e) => $e->format($a, $z, function (Attributes $x) use ($url): void { $x->link = $url; }));
        [$this->anchor, $this->caret] = [$anchor, $caret];
    }

    /**
     * Changes the styles of the paragraphs selected with $fn, as one step of
     * undo.
     *
     * @param Closure(ParagraphStyle): void $fn
     */
    public function formatParagraphs(Closure $fn): void
    {
        [$a, $z] = $this->span();
        [$anchor, $caret, $typing] = [$this->anchor, $this->caret, $this->typing];
        $this->edit(fn (Edit $e) => $e->formatParagraphs($a, $z, $fn));
        [$this->anchor, $this->caret, $this->typing] = [$anchor, $caret, $typing];
    }

    /** Whether every paragraph selected satisfies $fn. @param Closure(ParagraphStyle): bool $fn */
    private function paragraphsAll(Closure $fn): bool
    {
        [$a, $z] = $this->span();
        [$pa, $pz] = $this->text->paragraphSpan($a, $z);
        $all = true;
        Seq::each($this->text->seq(), $pa, function (int $i, RichPara $p) use ($fn, $pz, &$all): bool {
            $all = $fn($p->style);
            return $all && $i < $pz;
        });
        return $all;
    }

    /** Makes the paragraphs selected headings of $level, from 1, or body paragraphs for 0. */
    public function setHeading(int $level): void
    {
        $this->formatParagraphs(function (ParagraphStyle $p) use ($level): void {
            $p->heading = max(0, min($level, 6));
            if ($p->heading > 0) {
                $p->list = ListKind::NoList; $p->checked = false; $p->codeBlock = false;
            }
        });
    }

    /** Makes the paragraphs selected items of a list of $kind, or takes them out of it when all of them are. */
    public function toggleList(ListKind $kind): void
    {
        $off = $this->paragraphsAll(fn (ParagraphStyle $p) => $p->list === $kind);
        $this->formatParagraphs(function (ParagraphStyle $p) use ($off, $kind): void {
            if ($off) {
                $p->list = ListKind::NoList; $p->checked = false;
                return;
            }
            $p->list = $kind; $p->heading = 0; $p->codeBlock = false;
            if ($kind !== ListKind::Tasks) { $p->checked = false; }
        });
    }

    /** Makes the paragraphs selected a quotation, or not when all of them are. */
    public function toggleQuote(): void
    {
        $off = $this->paragraphsAll(fn (ParagraphStyle $p) => $p->quote);
        $this->formatParagraphs(function (ParagraphStyle $p) use ($off): void { $p->quote = !$off; });
    }

    /** Makes the paragraphs selected a block of code, or not when all of them are. */
    public function toggleCodeBlock(): void
    {
        $off = $this->paragraphsAll(fn (ParagraphStyle $p) => $p->codeBlock);
        $this->formatParagraphs(function (ParagraphStyle $p) use ($off): void {
            $p->codeBlock = !$off;
            if ($p->codeBlock) {
                $p->heading = 0; $p->list = ListKind::NoList; $p->checked = false;
            }
        });
    }

    /** Indents the paragraphs selected by $levels, or outdents them for a negative count, as Tab and Shift-Tab do in a list. */
    public function indent(int $levels): void
    {
        $this->formatParagraphs(function (ParagraphStyle $p) use ($levels): void {
            $p->indent = max(0, min($p->indent + $levels, ParagraphStyle::MAX_INDENT));
        });
    }

    /** Aligns the lines of the paragraphs selected: Params::START, CENTER or END. */
    public function setAlign(int $a): void
    {
        $this->formatParagraphs(function (ParagraphStyle $p) use ($a): void { $p->align = $a; });
    }

    /** Checks the task holding rune $i, or unchecks it. */
    public function toggleChecked(int $i): void
    {
        [$anchor, $caret, $typing] = [$this->anchor, $this->caret, $this->typing];
        $this->edit(fn (Edit $e) => $e->formatParagraphs($i, $i, function (ParagraphStyle $p): void {
            if ($p->list === ListKind::Tasks) { $p->checked = !$p->checked; }
        }));
        [$this->anchor, $this->caret, $this->typing] = [$anchor, $caret, $typing];
    }

    /** Replaces the selection with $s, in the typing attributes, as typing does but as a step of undo of its own. */
    public function insertText(string $s): void
    {
        [$a, $z] = $this->span();
        $attrs = $this->typingAttributes();
        $this->edit(function (Edit $e) use ($a, $z, $s, $attrs): void {
            $n = $e->text()->len();
            $e->replaceString($a, $z, $s, $attrs);
            $end = $z + $e->text()->len() - $n;
            $e->select($end, $end);
        });
    }

    /** Replaces the selection with $text, as a paste does. */
    public function insert(Text $text): void
    {
        [$a, $z] = $this->span();
        $this->edit(function (Edit $e) use ($a, $z, $text): void {
            $e->replace($a, $z, $text);
            $e->select($a + $text->len(), $a + $text->len());
        });
    }

    /** Replaces the selection with an attachment. */
    public function insertAttachment(Attachment $at): void
    {
        [$s, $z] = $this->span();
        $attrs = clone $this->typingAttributes()->extending();
        $attrs->attachment = $at;
        $this->edit(function (Edit $e) use ($s, $z, $attrs): void {
            $e->replaceString($s, $z, Text::OBJECT_REPLACEMENT, $attrs);
            $e->select($s + 1, $s + 1);
        });
    }
}
