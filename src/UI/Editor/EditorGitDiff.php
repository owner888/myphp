<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** The editor's changes against its DiffBase (mygo's gitdiff.go). */
trait EditorGitDiff
{
    /** Follows the DiffBase. */
    public function syncGitDiff(): void
    {
        $base = $this->o->decor !== null ? null : $this->o->diffBase;
        if ($base === null) {
            $this->gitDiff = null;
            return;
        }
        $this->gitDiff ??= new GitDiff;
        $this->gitDiff->d->set($base, $this->buf());
        $this->gitDiff->d->update();
    }

    /** The diff the editor's gutter and hunk commands use, its DiffBase's, and whether the editor shows its old side. @return array{0: ?DiffModel, 1: bool} */
    public function diffModel(): array
    {
        if ($this->gitDiff !== null) { return [$this->gitDiff->d, false]; }
        if (($ds = $this->o->decor) instanceof DiffSideView) { return [$ds->d->model, $ds->old]; }
        return [null, false];
    }

    /** Moves the caret to the next hunk after it ($dir 1) or the one before. */
    public function gotoHunk(int $dir): bool
    {
        [$d, $old] = $this->diffModel();
        if ($d === null || $d->hunks === []) { return false; }
        $r = $this->view->text;
        $line = $r->lineOf($this->primary()->head);
        $start = fn(Hunk $h) => $old ? $h->a0 : $h->b0;
        $target = -1;
        if ($dir > 0) {
            foreach ($d->hunks as $h) {
                if ($start($h) > $line) { $target = $start($h); break; }
            }
            if ($target < 0) { $target = $start($d->hunks[0]); }
        } else {
            for ($i = count($d->hunks) - 1; $i >= 0; $i--) {
                if (($s = $start($d->hunks[$i])) < $line) { $target = $s; break; }
            }
            if ($target < 0) { $target = $start($d->hunks[count($d->hunks) - 1]); }
        }
        $target = min($target, $r->lineCount() - 1);
        $off = $r->lineStart($target);
        $this->setSels([$this->newSel($off, $off)], self::REVEAL_CENTER);
        return true;
    }

    /** Puts back the old lines of the hunks the selections touch. */
    public function revertHunks(): bool
    {
        [$d, $old] = $this->diffModel();
        if ($d === null || $old || $this->o->readOnly) { return false; }
        $idx = [];
        foreach ($this->selLines() as [$from, $to]) {
            foreach ($d->hunksTouching($from, $to) as $i) {
                if (!in_array($i, $idx, true)) { $idx[] = $i; }
            }
        }
        if ($idx === []) { return false; }
        $edits = [];
        foreach ($idx as $i) { $edits[] = DiffModel::hunkEdit($d->hunks[$i], $d->oldText, $d->newText); }
        return $this->apply($edits, Transaction::OTHER, null);
    }
}
