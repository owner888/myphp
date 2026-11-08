<?php
// Code editor shows MyPHP's code editor (mygo's examples/code-editor):
// files of several languages to edit, with completions of the words of
// the file, hovers, diagnostics of TODO comments and the changes against
// the file as it was in the gutter; and the diff of those changes, side by
// side or unified, which edits the same buffer.
// Run: myphp run -d macos examples/code-editor
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\UI\Content;
use MyPHP\UI\Editor\{Buffer, Completion, CompletionKind, CompletionRequest, Diagnostic, DiffState, HoverRequest, Languages, Pos, Severity, State};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

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
        $st = $this->editor;
        $st->onCompletion = fn(CompletionRequest $r) => $st->showCompletions($r->offset - strlen($r->prefix), wordsOf($st->buffer, $r->prefix));
        $st->onHover = function (HoverRequest $r) use ($st) {
            [$word, $start, $end] = wordAt($st->buffer, $r->offset);
            if ($word === '') { return; }
            $n = substr_count((string)$st->buffer, $word);
            $st->showHover($start, $end, sprintf('%s: %d occurrence%s in %s', $word, $n, $n === 1 ? '' : 's', $this->name));
        };
    }

    /** Its TODO comments as warnings, for the buffer as it is now. */
    public function check(): void
    {
        $this->editor->setDiagnostics($this->editor->buffer->version(), todos($this->editor->buffer));
    }
}

/** Completes $prefix with the words of the buffer, and the keywords of its language, as editors do without a language server. @return list<Completion> */
function wordsOf(Buffer $b, string $prefix): array
{
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
            $out[] = new Diagnostic($start, $start + strlen($line) - $at, Severity::SeverityWarning, trim(substr($line, $at)), 'todo');
        }
    }
    return $out;
}

final class EditorApp
{
    /** @var list<File> */
    public array $files = [];
    public string $selected = 'Greeter.php';
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

/** The keywords completed in the files of a language, by its name. */
const KEYWORDS = [
    'PHP' => ['function', 'return', 'namespace', 'use', 'class', 'public', 'private', 'readonly', 'static', 'foreach', 'match', 'array'],
    'Go' => ['func', 'return', 'import', 'package', 'const', 'struct', 'interface', 'range'],
];

$a = new EditorApp;
foreach (require __DIR__ . '/samples.php' as [$name, $text]) { $a->files[] = new File($name, $text); }
App::setMenu(new Menu([new MenuItem(role: 'appMenu'), new MenuItem(role: 'editMenu'), new MenuItem(role: 'windowMenu')]));
App::whenReady(function () use ($a) {
    new Window(new WindowOptions(title: 'Code editor', width: 1200, height: 800, content: Content::file(__DIR__ . '/view.php', $a)));
});
App::run();
