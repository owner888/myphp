<?php
// A log viewer, the first real tool on MyPHP: open a log (File > Open…, or
// a path on the command line), follow it as it grows, filter lines as you
// type, levels in color, matches highlighted, long lines wrapped,
// Cmd+F finds among them, Cmd+Shift+C copies the chosen line, a file
// dropped on the window opens.
// The window remembers its frame.
// Run: ./run.sh logview [file]    (try /var/log/system.log or a file you append to)
declare(strict_types=1);

use MyPHP\{App, Dialog, FileDropEvent, Menu, MenuItem, OpenDialogOptions, Window, WindowOptions};
use MyPHP\Native\Pasteboard;
use MyPHP\UI\{Content, ListState, Node, TableColumn, TextState};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

/** The log being viewed: its lines, how far it was read, and what the filter leaves of it. */
final class Log
{
    public string $path = '';
    /** @var list<string> */
    public array $lines = [];
    private int $read = 0;
    private string $rest = '';
    public TextState $filter;
    /** The find bar (Cmd+F): open, its query, and which of the lines matching it is shown. */
    public bool $finding = false;
    public TextState $find;
    public int $found = 0;
    private string $applied = '';
    /** @var list<int> indexes of the lines the filter shows */
    public array $shown = [];
    public ListState $list;
    /** @var list<TableColumn> */
    public array $columns;
    public ?Window $window = null;
    public array $counts = ['ERROR' => 0, 'WARN' => 0];

    public function __construct()
    {
        $this->filter = new TextState();
        $this->find = new TextState;
        $this->list = new ListState();
        $this->list->selected = -1;
        $this->list->followEnd = true;
        $this->columns = [new TableColumn('#', 72, align: Node::END), new TableColumn('Line')];
    }

    public function open(string $path): void
    {
        $this->path = $path;
        $this->lines = [];
        $this->read = 0;
        $this->rest = '';
        $this->shown = [];
        $this->counts = ['ERROR' => 0, 'WARN' => 0];
        $this->list->row = 0;
        $this->list->offset = 0;
        $this->list->forgetHeights();
        $this->list->selected = -1;
        $this->poll();
        $this->list->count = count($this->shown);
        $this->list->scrollToEnd();
        $this->window?->setTitle(basename($path) . ' — Logs');
    }

    /** Reads what was appended since last time; true when there was something. */
    public function poll(): bool
    {
        if ($this->path === '') {
            return false;
        }
        clearstatcache(true, $this->path);
        $size = filesize($this->path);
        if ($size === false || $size === $this->read) {
            return false;
        }
        if ($size < $this->read) { // truncated or rotated: start over
            $this->open($this->path);
            return true;
        }
        $chunk = file_get_contents($this->path, false, null, $this->read, $size - $this->read);
        if ($chunk === false) {
            return false;
        }
        $this->read = $size;
        $text = $this->rest . $chunk;
        $parts = explode("\n", $text);
        $this->rest = array_pop($parts); // an unfinished last line waits
        foreach ($parts as $line) {
            $i = count($this->lines);
            $this->lines[] = $line;
            foreach ($this->counts as $level => $n) {
                if (str_contains($line, $level)) { $this->counts[$level]++; }
            }
            if ($this->matches($line)) {
                $this->shown[] = $i;
            }
        }
        return $parts !== [];
    }

    private function matches(string $line): bool
    {
        return $this->applied === '' || stripos($line, $this->applied) !== false;
    }

    /** Applies the filter when it changed since the last frame. */
    public function refilter(): void
    {
        if ($this->filter->text === $this->applied) {
            return;
        }
        $this->applied = $this->filter->text;
        $this->shown = [];
        foreach ($this->lines as $i => $line) {
            if ($this->matches($line)) {
                $this->shown[] = $i;
            }
        }
        $this->list->row = 0;
        $this->list->offset = 0;
        $this->list->selected = -1;
        $this->list->count = count($this->shown); // end() goes by the count; the list sets it only when built
        $this->list->scrollToEnd();
    }

    public function selectedLine(): ?string
    {
        $i = $this->shown[$this->list->selected] ?? null;
        return $i === null ? null : $this->lines[$i];
    }
}

$log = new Log();

$openDialog = function () use ($log) {
    $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: 'Open a log', defaultPath: '/var/log'));
    if ($paths) { $log->open($paths[0]); }
};
$copy = function () use ($log) {
    $line = $log->selectedLine();
    if ($line !== null) { Pasteboard::setText($line); }
};
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('File', submenu: [new MenuItem('Open…', $openDialog, 'CmdOrCtrl+O'), MenuItem::separator(), new MenuItem(role: 'close')]),
    new MenuItem('Edit', submenu: [new MenuItem(role: 'copy'), new MenuItem('Copy Line', $copy, 'CmdOrCtrl+Shift+C'), new MenuItem(role: 'paste'), new MenuItem(role: 'selectAll')]),
    new MenuItem(role: 'windowMenu'),
]));

App::setName('Logs');
App::whenReady(function () use ($log, $argv) {
    $log->window = new Window(new WindowOptions(title: 'Logs', width: 960, height: 600, stateKey: 'logs',
        content: Content::file(__DIR__ . '/view.php', $log)));
    $log->window->on('fileDrop', fn(FileDropEvent $e) => $log->open($e->paths[0])); // drop a file onto the window
    if (isset($argv[1])) {
        $log->open($argv[1]);
    }
    // Follow the file: look for new lines twice a second and redraw when there are.
    $tick = function () use (&$tick, $log) {
        if ($log->poll()) { $log->window->invalidate(); }
        App::after(0.5, $tick);
    };
    $tick();
});
App::run();
