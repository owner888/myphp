<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Node};
use MyPHP\UI\Editor\{Buffer, Completion, CompletionKind, CompletionRequest, Diagnostic, DiffInline, DiffLayout, HoverRequest, Pos, Severity};

$editorPage = function (Context $c, File $f) use (&$wordsOf, &$wordAt, &$todos): void {
    $t = $c->theme;
    $st = $f->editor;
    $st->onCompletion = function (CompletionRequest $r) use ($st, $wordsOf) {
        $st->showCompletions($r->offset - strlen($r->prefix), $wordsOf($st->buffer, $r->prefix));
    };
    $st->onHover = function (HoverRequest $r) use ($st, $f, $wordAt) {
        [$word, $start, $end] = $wordAt($st->buffer, $r->offset);
        if ($word === '') { return; }
        $n = substr_count((string)$st->buffer, $word);
        $st->showHover($start, $end, sprintf('%s: %d occurrence%s in %s', $word, $n, $n === 1 ? '' : 's', $f->name));
    };
    $st->setDiagnostics($st->buffer->version(), $todos($st->buffer));
    $ed = $st->view($c);
    $ed->grow = 1;
    if ($c->runtime !== null && $c->runtime->focus === null) { $c->runtime->focusOn($ed->id); } // AutoFocus
    // The status bar.
    $pos = $st->cursorPos();
    $sels = count($st->selections());
    $lang = $st->buffer->language()?->name ?? 'Plain Text';
    $status = sprintf('Ln %d, Col %d', $pos->line + 1, $pos->col + 1);
    if ($sels > 1) { $status .= sprintf(' · %d selections', $sels); }
    [$added, $deleted] = $f->diff->stats();
    $c->divider();
    $c->row(0, 16)->padding(4, 12);
      $c->text($status, 12, $t->textMuted);
      $c->text(sprintf('+%d −%d', $added, $deleted), 12, $t->textMuted);
      $c->spacer();
      $c->text(sprintf('%d lines · %s', $st->buffer->lineCount(), $lang), 12, $t->textMuted);
    $c->end();
};

$diffPage = function (Context $c, EditorApp $a, File $f): void {
    $d = $f->diff;
    $d->layout = $a->layout === 1 ? DiffLayout::DiffUnified : DiffLayout::DiffSplit;
    $d->inline = [DiffInline::DiffInlineWordAlt, DiffInline::DiffInlineChar, DiffInline::DiffInlineNone][$a->inline];
    if ($d->hunks() === []) {
        $c->column(0, 0, 1)->center();
          $c->text('No changes', 18, null, Font::BOLD);
          $c->text('Edit ' . $f->name . ' to see its changes here.', null, $c->theme->textMuted);
        $c->end();
        return;
    }
    $d->view($c)->grow = 1;
};

/** Completes $prefix with the words of the buffer, and the keywords of its language, as editors do without a language server. @return list<Completion> */
$wordsOf = function (Buffer $b, string $prefix): array {
    $seen = []; $out = [];
    foreach (preg_split('/[^\p{L}\p{Nd}_]+/u', (string)$b, -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (strlen($w) < 3 || isset($seen[$w]) || $w === $prefix) { continue; }
        $seen[$w] = true;
        $out[] = new Completion($w, 'word', CompletionKind::CompletionText);
    }
    foreach (KEYWORDS[$b->language()?->name ?? ''] ?? [] as $k) {
        if (!isset($seen[$k])) { $out[] = new Completion($k, 'keyword', CompletionKind::CompletionKeyword); }
    }
    usort($out, fn(Completion $x, Completion $y) => strcmp($x->label, $y->label));
    return $out;
};

/** The word at $off: it, and where it starts and ends. @return array{0: string, 1: int, 2: int} */
$wordAt = function (Buffer $b, int $off): array {
    $p = $b->pos($off);
    $line = $b->line($p->line);
    $isWord = fn(string $c) => $c === '_' || ctype_alnum($c);
    $a = $p->col; $z = $p->col;
    while ($a > 0 && $isWord($line[$a - 1])) { $a--; }
    while ($z < strlen($line) && $isWord($line[$z])) { $z++; }
    $start = $off - ($p->col - $a);
    return [substr($line, $a, $z - $a), $start, $start + $z - $a];
};

/** The TODO comments, as warnings. @return list<Diagnostic> */
$todos = function (Buffer $b): array {
    $out = [];
    for ($i = 0; $i < $b->lineCount(); $i++) {
        $line = $b->line($i);
        if (($at = strpos($line, 'TODO')) !== false) {
            $start = $b->offset(new Pos($i, $at));
            $out[] = new Diagnostic($start, $start + strlen($line) - $at, Severity::SeverityWarning, trim(substr($line, $at)), 'todo');
        }
    }
    return $out;
};

return function (Context $c) use (&$editorPage, &$diffPage): void {
    $t = $c->theme;
    $f = $this->current();
    $f->editor->softWrap = $this->wrap;
    $f->diff->wrap = $this->wrap;
    $c->row(0, 0, 1)->alignItems(Node::STRETCH);
      $c->column(0, 0, 0, $t->surface)->padding(12, 0);
        $c->sidebar($this->selected, function () use ($c) {
            foreach ($this->files as $f) { $c->sidebarItem($f->name, $f->name . ($f->editor->buffer->modified() ? ' •' : '')); }
        }, 200)->grow = 1;
      $c->end();
      $c->column(0, 0, 1);
        $c->row(0, 12)->padding(8, 12)->alignItems(Node::CENTER);
          $c->segmented(['Editor', 'Changes'], $this->page);
          if ($this->page === 1) {
              $c->segmented(['Split', 'Unified'], $this->layout);
              $c->select(['Words', 'Characters', 'Off'], $this->inline);
          }
          $c->spacer();
          $c->text('Wrap', null, $t->textMuted);
          $c->switch('', $this->wrap)->label('Soft wrap'); // named for assistive technology, as mygo's: the text before it shows
        $c->end();
        $c->divider();
        if ($this->page === 0) { $editorPage($c, $f); } else { $diffPage($c, $this, $f); }
      $c->end();
    $c->end();
};
