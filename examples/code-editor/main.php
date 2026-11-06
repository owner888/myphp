<?php
// Code editor shows MyPHP's code editor (mygo's examples/code-editor):
// files of several languages to edit, with completions of the words of
// the file, hovers, diagnostics of TODO comments and the changes against
// the file as it was in the gutter; and the diff of those changes, side by
// side or unified, which edits the same buffer.
// Run: ./run.sh code-editor
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\UI\Content;
use MyPHP\UI\Editor\{Buffer, DiffState, Languages, State};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

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
