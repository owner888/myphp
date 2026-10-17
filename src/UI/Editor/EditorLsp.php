<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * What a language server gives an editor, as Zed shows it (mygo's
 * lsp.go): completions, hovers, diagnostics and inlay hints, which the app
 * asks its own server for, or makes itself.
 */
trait EditorLsp
{
    /** Where the word before $off starts. */
    public function wordStartBefore(int $off): int
    {
        $r = $this->view->text;
        $extra = $this->wordChars();
        $a = $off;
        while ($a > 0) {
            [$c, $size] = $r->runeBefore($a);
            if (Chars::classOf($c, $extra) !== Chars::WORD) { break; }
            $a -= $size;
        }
        return $a;
    }

    /** Asks the app for completions at the newest caret. */
    public function requestCompletions(bool $explicit): bool
    {
        if ($this->o->onCompletion === null || $this->o->readOnly) { return false; }
        $p = $this->primary();
        if (!$p->empty()) { return false; }
        $start = $this->wordStartBefore($p->head);
        $prefix = $this->slice($start, $p->head);
        $cc = $this->complete;
        if (!$explicit && $prefix === '') {
            // After a trigger character, as a dot: a new list.
            $cc->close();
        }
        if ($cc->shown && $cc->at === $start && $cc->items !== []) {
            $cc->filter($prefix);
            if ($cc->filtered !== [] || !$explicit) {
                if ($cc->filtered === []) { $cc->close(); }
                return true;
            }
        }
        $cc->at = $start;
        $cc->request = true;
        $cc->version = $this->view->version;
        ($this->o->onCompletion)(new CompletionRequest($p->head, $prefix, $this->view->version, $explicit));
        return true;
    }

    /** Shows $items for the word starting at $at. @param list<Completion> $items */
    public function showCompletions(int $at, array $items): void
    {
        $cc = $this->complete;
        $p = $this->primary();
        if ($items === [] || !$p->empty()) {
            $cc->close();
            return;
        }
        $start = $this->wordStartBefore($p->head);
        if ($at >= 0 && $at <= $p->head) { $start = $at; }
        $cc->shown = true; $cc->at = $start; $cc->items = $items; $cc->request = false;
        $cc->filter($this->slice($start, $p->head));
        if ($cc->filtered === []) { $cc->shown = false; }
        $this->needsBuild = true;
    }

    /** Inserts the completion chosen in place of the word typed, at every caret. */
    public function acceptCompletion(): bool
    {
        $cc = $this->complete;
        if (!$cc->open()) { return false; }
        $it = $cc->items[$cc->filtered[$cc->selected]];
        $ins = $it->insert !== '' ? $it->insert : $it->label;
        $p = $this->primary();
        $n = $p->head - $cc->at;
        $typed = $this->slice($cc->at, $p->head);
        $cc->close();
        // The word typed goes where the other carets have it before them too, as Zed's; elsewhere the completion goes in at the caret.
        $edits = [];
        foreach ($this->sels as $s) {
            $a = $s->start();
            if ($s->head - $n >= 0 && $this->slice($s->head - $n, $s->head) === $typed) { $a = $s->head - $n; }
            $edits[] = new Edit($a, $s->end(), $ins);
        }
        $sels = self::cloneSels($this->sels);
        $this->apply($edits, Transaction::OTHER, function (\Closure $move) use ($sels): array {
            foreach ($sels as $s) { $s->collapse($move($s->end(), Change::RIGHT)); }
            return $sels;
        });
        return true;
    }

    /** Closes the hover, and gives the keyboard back to the editor when the hover had it. */
    public function closeHover(): void
    {
        if ($this->hover->focused) { $this->refocus = true; }
        $this->hover->close();
    }

    /** Asks the app about the text at $off. */
    public function requestHover(int $off): bool
    {
        $h = $this->hover;
        $h->diags = $this->diagnosticsAt($off);
        if ($this->o->onHover !== null && $h->asked !== $off) {
            $h->asked = $off;
            ($this->o->onHover)(new HoverRequest($off, $this->view->version));
        }
        if ($h->diags !== []) {
            $h->shown = true;
            $h->start = $h->diags[0]->start; $h->end = $h->diags[0]->end;
            $this->needsBuild = true;
        }
        return $this->o->onHover !== null || $h->diags !== [];
    }

    /** The diagnostics holding $off. @return list<Diagnostic> */
    public function diagnosticsAt(int $off): array
    {
        $out = [];
        foreach ($this->diags as $d) {
            if ($off >= $d->start && $off <= max($d->end, $d->start + 1)) { $out[] = $d; }
        }
        return $out;
    }

    /** Inserts the suggestion shown at the caret. */
    public function acceptSuggestion(): bool
    {
        foreach ($this->view->inlays as $i => $in) {
            if ($in['kind'] === 1 && count($this->sels) === 1 && $this->sels[0]->empty() && $this->sels[0]->head === $in['at']) {
                $text = $this->suggestion;
                array_splice($this->view->inlays, $i, 1);
                $this->suggestion = '';
                $this->replaceEach(Transaction::OTHER, false, fn() => $text);
                return true;
            }
        }
        return false;
    }

    /** Takes the suggestion away, as an edit or a move of the caret does. */
    public function dropSuggestion(): void
    {
        if ($this->suggestion === '') { return; }
        $this->setSuggestion(0, '');
    }

    /** Shows $text at $off, which Tab inserts. */
    public function setSuggestion(int $off, string $text): void
    {
        $this->view->inlays = array_values(array_filter($this->view->inlays, fn(array $in) => $in['kind'] !== 1));
        $this->suggestion = $text;
        if ($text !== '') {
            $first = explode("\n", $text, 2)[0];
            if ($first !== $text) { $first .= ' …'; }
            $this->view->inlays[] = ['at' => $off, 'text' => $first, 'kind' => 1];
            self::sortInlays($this->view->inlays);
        }
        $this->view->forgetLines();
    }

    /** @param list<array{at: int, text: string, kind: int}> $in */
    private static function sortInlays(array &$in): void
    {
        usort($in, fn(array $a, array $b) => $a['at'] <=> $b['at']);
    }

    /** Shows the inlay hints. @param list<Inlay> $hints */
    public function setInlays(array $hints): void
    {
        $this->view->inlays = array_values(array_filter($this->view->inlays, fn(array $in) => $in['kind'] !== 0));
        foreach ($hints as $h) { $this->view->inlays[] = ['at' => $h->offset, 'text' => $h->text, 'kind' => 0]; }
        self::sortInlays($this->view->inlays);
        $this->view->forgetLines();
    }
}
