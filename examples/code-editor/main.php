<?php
// Code editor shows MyPHP's code editor (mygo's examples/code-editor):
// files of several languages to edit, with completions of the words of
// the file, hovers, diagnostics of TODO comments and the changes against
// the file as it was in the gutter; and the diff of those changes, side by
// side or unified, which edits the same buffer.
// Run: ./run.sh code-editor
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, Node};
use MyPHP\UI\Editor\{Buffer, Completion, CompletionRequest, Diagnostic, DiffState, HoverRequest, Languages, Pos, State};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** A file of the example: its text as it was, and the buffer and editor state editing it, and the diff of its changes, kept across frames. */
final class File
{
    public Buffer $original;
    public State $editor;
    public DiffState $diff;

    public function __construct(public string $name, string $text)
    {
        $this->original = new Buffer($text);
        $buf = new Buffer($text);
        $lang = Languages::for($name);
        $buf->setLanguage($lang);
        $this->original->setLanguage($lang);
        $this->editor = new State($buf);
        $this->editor->diffBase = $this->original;
        $this->editor->wrapGuides = [100];
        $this->diff = new DiffState($this->original, $buf);
        $this->diff->editable = true; $this->diff->hunkActions = true; $this->diff->lineSelection = true;
    }
}

final class EditorApp
{
    /** @var list<File> */
    public array $files = [];
    public string $selected = 'server.go';
    /** 0 the editor, 1 the diff. */
    public int $page = 0;
    /** The diff's: 0 split, 1 unified. */
    public int $layout = 0;
    /** The diff's highlights within lines: 0 words, 1 characters, 2 off. */
    public int $inline = 0;
    public bool $wrap = false;

    public function current(): File
    {
        foreach ($this->files as $f) { if ($f->name === $this->selected) { return $f; } }
        return $this->files[0];
    }
}

function view(Context $c, EditorApp $a): void
{
    $t = $c->theme;
    $f = $a->current();
    $f->editor->softWrap = $a->wrap;
    $f->diff->wrap = $a->wrap;
    $c->row(0, 0, 1)->align(Node::STRETCH);
      $c->column(0, 0, 0, $t->surface)->padding(12, 0);
        $c->sidebar($a->selected, function () use ($c, $a) {
            foreach ($a->files as $f) { $c->sidebarItem($f->name, $f->name . ($f->editor->buffer->modified() ? ' •' : '')); }
        }, 200)->grow = 1;
      $c->end();
      $c->column(0, 0, 1);
        $c->row(0, 12)->padding(8, 12)->align(Node::CENTER);
          $c->segmented(['Editor', 'Changes'], $a->page);
          if ($a->page === 1) {
              $c->segmented(['Split', 'Unified'], $a->layout);
              $c->select(['Words', 'Characters', 'Off'], $a->inline);
          }
          $c->spacer();
          $c->text('Wrap', null, $t->textMuted);
          $c->switch('Soft wrap', $a->wrap);
        $c->end();
        $c->divider();
        if ($a->page === 0) { editorPage($c, $f); } else { diffPage($c, $a, $f); }
      $c->end();
    $c->end();
}

function editorPage(Context $c, File $f): void
{
    $t = $c->theme;
    $st = $f->editor;
    $st->onCompletion = function (CompletionRequest $r) use ($st) {
        $st->showCompletions($r->offset - strlen($r->prefix), wordsOf($st->buffer, $r->prefix));
    };
    $st->onHover = function (HoverRequest $r) use ($st, $f) {
        [$word, $start, $end] = wordAt($st->buffer, $r->offset);
        if ($word === '') { return; }
        $n = substr_count((string)$st->buffer, $word);
        $st->showHover($start, $end, sprintf('%s: %d occurrence%s in %s', $word, $n, $n === 1 ? '' : 's', $f->name));
    };
    $st->setDiagnostics($st->buffer->version(), todos($st->buffer));
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
}

function diffPage(Context $c, EditorApp $a, File $f): void
{
    $d = $f->diff;
    $d->layout = $a->layout === 1 ? DiffState::UNIFIED : DiffState::SPLIT;
    $d->inline = [DiffState::INLINE_WORD_ALT, DiffState::INLINE_CHAR, DiffState::INLINE_NONE][$a->inline];
    if ($d->hunks() === []) {
        $c->column(0, 0, 1)->center();
          $c->text('No changes', 18, null, Font::BOLD);
          $c->text('Edit ' . $f->name . ' to see its changes here.', null, $c->theme->textMuted);
        $c->end();
        return;
    }
    $d->view($c)->grow = 1;
}

/** Completes $prefix with the words of the buffer, as editors do without a language server. @return list<Completion> */
function wordsOf(Buffer $b, string $prefix): array
{
    $seen = []; $out = [];
    foreach (preg_split('/[^\p{L}\p{Nd}_]+/u', (string)$b, -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (strlen($w) < 3 || isset($seen[$w]) || $w === $prefix) { continue; }
        $seen[$w] = true;
        $out[] = new Completion($w, 'word', Completion::TEXT);
    }
    if ($b->language() !== null) {
        foreach (['func', 'return', 'import', 'package', 'const', 'struct', 'interface', 'range'] as $k) {
            if (!isset($seen[$k])) { $out[] = new Completion($k, 'keyword', Completion::KEYWORD); }
        }
    }
    usort($out, fn(Completion $x, Completion $y) => strcmp($x->label, $y->label));
    return $out;
}

/** The word at $off: it, and where it starts and ends. @return array{0: string, 1: int, 2: int} */
function wordAt(Buffer $b, int $off): array
{
    $p = $b->pos($off);
    $line = $b->line($p->line);
    $isWord = fn(string $c) => $c === '_' || ctype_alnum($c);
    $a = $p->col; $z = $p->col;
    while ($a > 0 && $isWord($line[$a - 1])) { $a--; }
    while ($z < strlen($line) && $isWord($line[$z])) { $z++; }
    $start = $off - ($p->col - $a);
    return [substr($line, $a, $z - $a), $start, $start + $z - $a];
}

/** The TODO comments, as warnings. @return list<Diagnostic> */
function todos(Buffer $b): array
{
    $out = [];
    for ($i = 0; $i < $b->lineCount(); $i++) {
        $line = $b->line($i);
        if (($at = strpos($line, 'TODO')) !== false) {
            $start = $b->offset(new Pos($i, $at));
            $out[] = new Diagnostic($start, $start + strlen($line) - $at, Diagnostic::WARNING, trim(substr($line, $at)), 'todo');
        }
    }
    return $out;
}

$a = new EditorApp;
foreach (require __DIR__ . '/samples.php' as [$name, $text]) { $a->files[] = new File($name, $text); }
App::setMenu(new Menu([new MenuItem(role: 'appMenu'), new MenuItem(role: 'editMenu'), new MenuItem(role: 'windowMenu')]));
App::whenReady(function () use ($a) {
    new Window(new WindowOptions(title: 'Code editor', width: 1200, height: 800, content: Content::view(fn(Context $c) => view($c, $a))));
});
App::run();
