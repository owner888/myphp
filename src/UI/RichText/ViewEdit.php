<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use Closure;
use MyPHP\Text\Layout;
use MyPHP\UI\TextInputRange;

/**
 * Every edit of the editor goes through its document, as one of its steps
 * of undo, so that undoing it brings its attributes back with its text
 * (mygo's richtext edit.go).
 *
 * @internal part of View
 */
trait ViewEdit
{
    /** Gives the document the editor's selection. A selection moved forgets the typing attributes set at the caret, as in NSTextView. */
    public function publish(): void
    {
        $d = $this->doc;
        if ($d->anchor !== $this->selAnchor || $d->caret !== $this->caret) {
            $d->anchor = $this->selAnchor; $d->caret = $this->caret;
            $d->typing = null;
            $d->coalesce = false;
        }
    }

    /** Starts an action of the editor: typing joins the step typing made last, anything else starts a step of its own. */
    private function record(bool $typing): void
    {
        $this->mode = $typing && $this->coalesce ? self::TYPING_STEP : self::NEW_STEP;
        $this->coalesce = $typing;
    }

    /** Replaces the runes from $start to $end with $s in the typing attributes, as part of the action's step of undo, and puts the caret after it. */
    private function replace(int $start, int $end, string $s): void
    {
        $d = $this->doc;
        if (($c = $this->sync()) !== null) {
            $start = Change::mapPosition($start, $c->start, $c->oldEnd - $c->start, $c->newEnd - $c->start, false);
            $end = Change::mapPosition($end, $c->start, $c->oldEnd - $c->start, $c->newEnd - $c->start, true);
        }
        $this->publish();
        $attrs = $d->typing !== null ? clone $d->typing : Document::typingAttributesOf($d->text, $start, $end);
        $this->editInStep(fn(Edit $e) => $e->replaceString($start, $end, $s, $attrs));
        $caret = $start + mb_strlen($s, 'UTF-8');
        $this->selAnchor = $this->caret = $caret;
        $d->anchor = $d->caret = $caret;
        if ($s === '' && $start < $end) {
            // Typing again where text was deleted gets its attributes, until the caret moves, as in NSTextView.
            $d->typing = clone $attrs;
        }
        $this->affinity = Layout::DOWNSTREAM;
        $this->hasDesired = false;
        $this->reveal = true;
    }

    /** Runs $fn as an edit of the document in the action's step, and takes what it changed (mygo's lockedEdit). @param Closure(Edit): void $fn */
    private function editInStep(Closure $fn): void
    {
        $d = $this->doc;
        $before = $d->version;
        if ($this->mode === self::SAME_STEP && $d->undo !== []) {
            $s = $d->undo[count($d->undo) - 1];
            $fn(new Edit($d, $s));
            $s->selAfter = [$d->anchor, $d->caret];
            $d->redo = [];
        } else {
            $d->editStep($this->mode === self::TYPING_STEP, $fn);
            // Typing that starts a step lets the typing after it join it.
            $d->coalesce = $this->coalesce;
        }
        $this->mode = self::SAME_STEP;
        if ($d->version !== $before) {
            $this->changed = true;
            $this->sync();
        }
    }

    /** Runs $fn as a step of undo of its own, as a shortcut's conversion does. @param Closure(Edit): void $fn */
    private function editStep(Closure $fn): void
    {
        $d = $this->doc;
        $this->sync();
        $this->publish();
        $this->mode = self::NEW_STEP;
        $this->editInStep($fn);
        $this->mode = self::NEW_STEP;
        $this->coalesce = false;
        $this->selAnchor = $d->anchor; $this->caret = $d->caret;
        $this->selVersion = $d->selVersion;
        $this->reveal = true;
    }

    /** Runs a command of the document on the editor's selection. @param Closure(Document): void $fn */
    private function command(Closure $fn): void
    {
        $this->publish();
        $before = $this->doc->version;
        $fn($this->doc);
        if ($this->doc->version !== $before) { $this->changed = true; }
        $this->sync();
        $this->mode = self::NEW_STEP; $this->coalesce = false;
    }

    /** Undoes the document's last step, or redoes the last undone. */
    private function takeBack(bool $redo): void
    {
        $this->command(fn(Document $d) => $redo ? $d->redo() : $d->undo());
        $this->reveal = true;
    }

    /** Replaces the selection with $s, as typing does. */
    private function insert(string $s): void
    {
        if ($this->opts->readOnly) { return; }
        $s = self::normalizeNewlines($s);
        [$a, $z] = $this->sel();
        $this->record($a === $z && mb_strlen($s, 'UTF-8') === 1 && $s !== "\n");
        $this->replace($a, $z, $s);
    }

    /** Deletes the runes from $a to $z. */
    private function deleteRange(int $a, int $z, bool $typing): void
    {
        if ($a === $z || $this->opts->readOnly) { return; }
        $this->record($typing);
        $this->replace(min($a, $z), max($a, $z), '');
    }

    /** Makes the next edit join the step of the edit before it, unless the action started a step no edit made yet: a composition at a caret starts one that its commit makes. */
    private function joinStep(): void
    {
        if ($this->mode !== self::NEW_STEP) { $this->mode = self::SAME_STEP; }
    }

    /** Puts the composition into the text, in the typing attributes, as part of the step its start made. */
    private function commitCompose(): void
    {
        if ($this->compose !== '') {
            $s = $this->compose;
            $this->compose = '';
            $this->joinStep();
            $this->replace($this->caret, $this->caret, $s);
        }
        $this->composeCaret = 0; $this->composeSelected = new TextInputRange(0, 0);
        $this->composing = false;
    }

    /** The newlines of other systems as "\n". */
    private static function normalizeNewlines(string $s): string
    {
        if (!str_contains($s, "\r") && !str_contains($s, "\u{2029}")) { return $s; }
        return strtr($s, ["\r\n" => "\n", "\r" => "\n", "\u{2029}" => "\n"]);
    }

    /** Whether a paragraph of style $st is more than a body paragraph, which Backspace at its start makes it before joining. */
    private static function special(ParagraphStyle $st): bool
    {
        return $st->list !== ListKind::NoList || $st->heading > 0 || $st->quote || $st->codeBlock || $st->indent > 0;
    }

    /** Takes away what Backspace at the start of a paragraph takes first: a level of indentation of a list's item, the list, a heading, a quotation, a block of code. */
    private static function unstyle(ParagraphStyle $s): void
    {
        if ($s->list !== ListKind::NoList && $s->indent > 0) {
            $s->indent--;
        } elseif ($s->list !== ListKind::NoList) {
            $s->list = ListKind::NoList; $s->checked = false;
        } elseif ($s->heading > 0) {
            $s->heading = 0;
        } elseif ($s->quote) {
            $s->quote = false;
        } elseif ($s->codeBlock) {
            $s->codeBlock = false;
        } elseif ($s->indent > 0) {
            $s->indent--;
        }
    }

    /** Breaks the paragraph at the selection: a list's item continues the list and an empty one leaves it, a heading ends at its end, and "```" starts a block of code. */
    private function enter(): void
    {
        [$a, $z] = $this->sel();
        $p = $this->shown->paraAt($this->caret);
        $st = $this->para($p)->style;
        $ps = $this->shown->paraStart($p);
        $pe = $ps + $this->para($p)->n;
        if ($a === $z && $ps === $pe) {
            $exit = function (ParagraphStyle $s) use ($p): bool {
                if ($s->list !== ListKind::NoList && $s->indent > 0) {
                    $s->indent--;
                } elseif ($s->list !== ListKind::NoList) {
                    $s->list = ListKind::NoList; $s->checked = false;
                } elseif ($s->quote) {
                    $s->quote = false;
                } elseif ($s->codeBlock && $p > 0 && $this->para($p - 1)->style->codeBlock) {
                    $s->codeBlock = false;
                } else {
                    return false;
                }
                return true;
            };
            if ($exit(clone $st)) {
                $this->command(fn(Document $d) => $d->formatParagraphs(function (ParagraphStyle $s) use ($exit): void { $exit($s); }));
                return;
            }
        }
        if (!$this->opts->noMarkdown && $a === $z && $this->caret === $pe && !$st->codeBlock) {
            $line = $this->lineOf($p);
            if (str_starts_with($line, '```') && !str_contains(substr($line, 3), '`')) {
                $this->editStep(function (Edit $e) use ($ps, $pe): void {
                    $e->replaceString($ps, $pe, '', new Attributes);
                    $e->formatParagraphs($ps, $ps, function (ParagraphStyle $s): void {
                        $indent = $s->indent;
                        foreach (get_object_vars(new ParagraphStyle(codeBlock: true, indent: $indent)) as $k => $v) { $s->$k = $v; }
                    });
                    $e->select($ps, $ps);
                });
                return;
            }
        }
        $atEnd = $this->caret === $pe && $a === $z;
        $atStart = $a === $z && $a === $ps && $ps < $pe;
        $this->publish();
        $attrs = $this->doc->typingAttributes();
        $this->record(false);
        $this->replace($a, $z, "\n");
        $d = $this->doc;
        $this->editInStep(function (Edit $e) use ($atStart, $atEnd): void {
            // The new paragraph: after the caret's, or before it at its start, as Notes keeps the item it moves down checked.
            $at = $atStart ? $this->caret - 1 : $this->caret;
            $e->formatParagraphs($at, $at, function (ParagraphStyle $s) use ($atEnd): void {
                if ($atEnd && $s->heading > 0) { $s->heading = 0; }
                $s->checked = false;
            });
        });
        $d->anchor = $d->caret = $this->caret;
        $d->typing = clone $attrs;
        $this->coalesce = false;
    }

    /** Runs the Markdown shortcuts after $s was typed at the caret. */
    private function typed(string $s): void
    {
        if ($this->opts->noMarkdown || $this->opts->readOnly || mb_strlen($s, 'UTF-8') !== 1) { return; }
        $p = $this->shown->paraAt($this->caret);
        $ps = $this->shown->paraStart($p);
        if ($this->para($p)->style->codeBlock) { return; }
        $before = mb_str_split($this->shown->slice($ps, $this->caret)->string(), 1, 'UTF-8');
        if ($before === []) { return; }
        if ($s === ' ') {
            $this->blockShortcut($ps, implode('', array_slice($before, 0, -1)));
            return;
        }
        if ($s === '*' || $s === '_' || $s === '`' || $s === '~') { $this->inlineShortcut($ps, $before); }
    }

    /** Turns what was typed before a space at the start of a paragraph into its style: "# " a heading, "- " a list, "> " a quotation. */
    private function blockShortcut(int $ps, string $prefix): void
    {
        $set = null;
        $n = strlen($prefix);
        if ($prefix !== '' && trim($prefix, '#') === '' && $n <= 6) {
            $set = function (ParagraphStyle $s) use ($n): void { $s->heading = $n; $s->list = ListKind::NoList; $s->checked = false; };
        } elseif ($prefix === '-' || $prefix === '*' || $prefix === '+') {
            $set = function (ParagraphStyle $s): void { $s->list = ListKind::Bullets; $s->heading = 0; };
        } elseif (in_array($prefix, ['[]', '[ ]', '- [ ]', '-[ ]'], true)) {
            $set = function (ParagraphStyle $s): void { $s->list = ListKind::Tasks; $s->heading = 0; $s->checked = false; };
        } elseif (in_array($prefix, ['[x]', '- [x]', '[X]'], true)) {
            $set = function (ParagraphStyle $s): void { $s->list = ListKind::Tasks; $s->heading = 0; $s->checked = true; };
        } elseif ($prefix === '>') {
            $set = function (ParagraphStyle $s): void { $s->quote = true; };
        } elseif ($n >= 2 && ($prefix[$n - 1] === '.' || $prefix[$n - 1] === ')') && trim(substr($prefix, 0, -1), '0123456789') === '') {
            $set = function (ParagraphStyle $s): void { $s->list = ListKind::Numbers; $s->heading = 0; };
        }
        if ($set === null) { return; }
        $len = mb_strlen($prefix, 'UTF-8') + 1;
        $this->editStep(function (Edit $e) use ($ps, $len, $set): void {
            $e->replaceString($ps, $ps + $len, '', new Attributes);
            $e->formatParagraphs($ps, $ps, $set);
            $e->select($ps, $ps);
        });
    }

    /** Formats what a pair of marks typed around words holds: **bold**, *italic*, _italic_, `code` and ~~strikethrough~~. @param list<string> $before */
    private function inlineShortcut(int $ps, array $before): void
    {
        $n = count($before);
        $last = $before[$n - 1];
        $mark = $last;
        if (($last === '*' || $last === '~' || $last === '_') && $n >= 2 && $before[$n - 2] === $last) { $mark .= $mark; }
        if ($last === '~' && strlen($mark) === 1) { return; }
        $m = strlen($mark);
        $closeAt = $n - $m;
        $space = fn(string $r): bool => MarkdownWriter::isSpace($r);
        if ($closeAt <= 0 || $space($before[$closeAt - 1])) { return; }
        $open = -1;
        for ($i = $closeAt - 1; $i >= 0; $i--) {
            if (implode('', array_slice($before, $i, min($i + $m, $n) - $i)) === $mark && $i + $m < $closeAt) {
                // Not the inside of a longer mark, and words right after it.
                if (($i > 0 && $before[$i - 1] === $last) || $before[$i + $m] === $last || $space($before[$i + $m])) { continue; }
                // An underscore inside a word, as snake_case, is no mark.
                if ($last === '_' && $i > 0 && preg_match('/^[\pL\pN]$/u', $before[$i - 1]) === 1) { continue; }
                $open = $i;
                break;
            }
        }
        if ($open < 0) { return; }
        $set = function (Attributes $a) use ($mark): void {
            match ($mark) {
                '**', '__' => $a->weight = 700,
                '*', '_' => $a->italic = true,
                '`' => $a->code = true,
                '~~' => $a->strikethrough = true,
            };
        };
        $start = $ps + $open; $end = $ps + $closeAt;
        $this->editStep(function (Edit $e) use ($start, $end, $m, $set): void {
            $e->replaceString($end, $end + $m, '', new Attributes);
            $e->replaceString($start, $start + $m, '', new Attributes);
            $e->format($start, $end - $m, $set);
            $e->select($end - $m, $end - $m);
        });
        // What is typed next is outside the format, as after a closing mark.
        $this->doc->typing = $start > $ps ? clone $this->doc->text->attrsAt($start - 1)->extending() : new Attributes;
    }

    /** Whether $s is a URL, as ⌘K links one to itself. */
    private static function looksLikeURL(string $s): bool
    {
        if (strpbrk($s, " \n\t") !== false) { return false; }
        foreach (['http://', 'https://', 'mailto:', 'www.'] as $prefix) {
            if (str_starts_with($s, $prefix)) { return true; }
        }
        return false;
    }

    /** Runs a command of the Format menu or of a shortcut, and reports whether there is one of that name. */
    private function run(string $cmd): bool
    {
        $fn = match ($cmd) {
            'bold' => fn(Document $d) => $d->toggleBold(),
            'italic' => fn(Document $d) => $d->toggleItalic(),
            'underline' => fn(Document $d) => $d->toggleUnderline(),
            'strikethrough' => fn(Document $d) => $d->toggleStrikethrough(),
            'code' => fn(Document $d) => $d->toggleCode(),
            'bullets' => fn(Document $d) => $d->toggleList(ListKind::Bullets),
            'numbers' => fn(Document $d) => $d->toggleList(ListKind::Numbers),
            'tasks' => fn(Document $d) => $d->toggleList(ListKind::Tasks),
            'indent' => fn(Document $d) => $d->indent(1),
            'outdent' => fn(Document $d) => $d->indent(-1),
            'alignLeft' => fn(Document $d) => $d->setAlign(\MyPHP\Text\Params::START),
            'alignCenter' => fn(Document $d) => $d->setAlign(\MyPHP\Text\Params::CENTER),
            'alignRight' => fn(Document $d) => $d->setAlign(\MyPHP\Text\Params::END),
            'quote' => fn(Document $d) => $d->toggleQuote(),
            'codeBlock' => fn(Document $d) => $d->toggleCodeBlock(),
            default => null,
        };
        if ($cmd === 'link') {
            if ($this->opts->readOnly) { return false; }
            if ($this->opts->onLinkCommand !== null) {
                $this->publish();
                ($this->opts->onLinkCommand)();
                return true;
            }
            [$a, $z] = $this->sel();
            $url = trim($this->shown->slice($a, $z)->string());
            if ($a === $z || !self::looksLikeURL($url)) { return true; }
            $fn = fn(Document $d) => $d->setLink($url);
        } elseif ($fn === null && preg_match('/^heading([0-6])$/', $cmd, $m) === 1) {
            $level = (int)$m[1];
            $fn = fn(Document $d) => $d->setHeading($level);
        }
        if ($fn === null) { return false; }
        if ($this->opts->readOnly) { return true; }
        $this->command($fn);
        return true;
    }
}
