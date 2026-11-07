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
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

/** The log being viewed: its lines, how far it was read, and what the filter leaves of it. */
final class Log
{
    public string $path = '';
    /** @var list<string> */
    public array $lines = [];
    private int $read = 0;
    private string $rest = '';
    public string $filter = '';
    /** The find bar (Cmd+F): open, its query, and which of the lines matching it is shown. */
    public bool $finding = false;
    public string $find = '';
    public int $found = 0;
    private string $applied = '';
    /** @var list<int> indexes of the lines the filter shows */
    public array $shown = [];
    /** The line chosen, by its index. */
    public ?int $chosen = null;
    /** Counts the lists shown: a new one (a log opened, the filter changed) starts again from its end. */
    public int $generation = 0;
    public ?Window $window = null;
    public array $counts = ['ERROR' => 0, 'WARN' => 0];

    public function open(string $path): void
    {
        $this->path = $path;
        $this->lines = [];
        $this->read = 0;
        $this->rest = '';
        $this->shown = [];
        $this->counts = ['ERROR' => 0, 'WARN' => 0];
        $this->chosen = null;
        $this->generation++;
        $this->poll();
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

    /** Applies the filter, changed. */
    public function refilter(): void
    {
        if ($this->filter === $this->applied) {
            return;
        }
        $this->applied = $this->filter;
        $this->shown = [];
        foreach ($this->lines as $i => $line) {
            if ($this->matches($line)) {
                $this->shown[] = $i;
            }
        }
        $this->chosen = null;
        $this->generation++;
    }

    public function selectedLine(): ?string
    {
        return $this->chosen === null ? null : $this->lines[$this->chosen];
    }

    /** The rows shown holding the find bar's query. @return list<int> */
    public function hits(): array
    {
        if (!$this->finding || $this->find === '') { return []; }
        return array_values(array_filter($this->shown, fn(int $i) => stripos($this->lines[$i], $this->find) !== false));
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
