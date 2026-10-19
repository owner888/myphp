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
use MyPHP\UI\{Content, Context, Font, Key, ListState, Node, Span, TableColumn, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

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
        $this->list->end();
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
        $this->list->end();
    }

    public function selectedLine(): ?string
    {
        $i = $this->shown[$this->list->selected] ?? null;
        return $i === null ? null : $this->lines[$i];
    }
}

$log = new Log();

function view(Context $c, Log $log): void
{
    $t = $c->theme;
    $log->refilter();
    $c->column(0, 0, 1);
      // The find bar: Cmd+F opens it; Enter, the arrows and Cmd+G step through the shown lines holding the query, choosing each.
      if ($c->shortcut('f', Key::COMMAND)) { $log->finding = true; }
      $hits = [];
      if ($log->finding && $log->find->text !== '') {
          foreach ($log->shown as $r => $i) { if (stripos($log->lines[$i], $log->find->text) !== false) { $hits[] = $r; } }
      }
      $bar = $c->findBar($log->finding, $log->find, count($hits), $log->found);
      if ($bar !== null && $hits !== [] && ($c->changed($bar) || $log->find->seen !== $log->find->text)) {
          $log->list->choose($hits[$log->found]); $log->list->scrollTo($hits[$log->found]);
      }
      $log->find->seen = $log->find->text;
      $c->row(10, 10, 0, $t->surface);
        $c->input($log->filter, 'Filter lines (type to search)', 320);
        $c->rich($log->path === ''
            ? [new Span('Open a log with ', color: $t->textMuted), new Span('File > Open…', style: Font::BOLD, color: $t->textMuted)]
            : [new Span(number_format(count($log->lines)) . ' lines', color: $t->textMuted),
               new Span($log->filter->text === '' ? '' : ', ' . number_format(count($log->shown)) . ' match', color: $t->textMuted),
               new Span('  ERROR ' . $log->counts['ERROR'], color: $t->danger, style: Font::BOLD),
               new Span('  WARN ' . $log->counts['WARN'], color: $t->warning, style: Font::BOLD)]);
      $c->end();
      $needle = $log->filter->text;
      // A table: the number column and the line, which wraps at the column's width; the header's edge resizes it.
      $c->table($log->list, $log->columns, count($log->shown), function (int $r, int $col) use ($c, $t, $log, $needle) {
          $i = $log->shown[$r];
          if ($col === 0) {
              $c->text((string)($i + 1), 12, $t->textMuted, Font::MONO);
              return;
          }
          $line = $log->lines[$i];
          $color = str_contains($line, 'ERROR') ? $t->danger : (str_contains($line, 'WARN') ? $t->warning : $t->text);
          $spans = []; // the matched part highlighted
          if ($needle !== '' && ($at = stripos($line, $needle)) !== false) {
              $spans[] = new Span(substr($line, 0, $at), style: Font::MONO, color: $color);
              $spans[] = new Span(substr($line, $at, strlen($needle)), style: Font::MONO, color: 0x1d1d1f, background: $t->warning);
              $spans[] = new Span(substr($line, $at + strlen($needle)), style: Font::MONO, color: $color);
          } else {
              $spans[] = new Span($line, style: Font::MONO, color: $color);
          }
          $c->rich($spans, 12, wrap: true); // at the width the cell had last frame
      });
    $c->end();
}

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
        content: Content::view(fn(Context $c) => view($c, $log))));
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
