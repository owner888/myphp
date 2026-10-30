<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use Closure;
use MyPHP\App;
use MyPHP\Plugins\Terminal\Pty\{Options as PtyOptions, Pty};
use MyPHP\Plugins\Terminal\Vt\{Lib, RenderState, Terminal as VtTerminal, Effects};
use MyPHP\UI\{Context, Key, Node, Theme as UITheme};
use RuntimeException;

/**
 * A terminal emulator and the program it runs (mygo's plugins/terminal): a
 * view that runs a shell, or any program, in a pseudo-terminal, with the
 * terminal emulator of Ghostty, libghostty-vt, and draws it with MyPHP's
 * native UI.
 *
 *     $term = Terminal::new(new Options);
 *     new Window(new WindowOptions(title: 'Terminal', content: fn(Context $c) => Terminal::view($c, $term)->grow()));
 *
 * It does what terminals do: colors, styles and Unicode (wide characters,
 * emoji, grapheme clusters), the scrollback with reflow on resize, the
 * alternate screen of full-screen programs, the keyboard as legacy
 * sequences or the Kitty keyboard protocol, mouse reporting, bracketed
 * paste, focus reports, synchronized output, hyperlinks (OSC 8), titles,
 * the working directory (OSC 7) and the clipboard (OSC 52). Selecting with
 * the pointer copies as Ghostty does: a drag selects cells, a double click
 * words and a triple click lines; Option drags a rectangle.
 *
 * libghostty-vt is a native library loaded at run time through FFI, the
 * build mygo's release carries (Terminal::libraryPath()). PHP runs the app
 * on one thread: the terminal polls the program's output on the run loop.
 */
final class Terminal
{
    use TerminalPaste, TerminalGhostty;

    /** How soon the output is read again after some came or input went, and at most while the program is quiet. */
    private const POLL_BUSY = 0.004, POLL_IDLE = 0.05;
    /** How much output one turn of the poll processes, so that a flood leaves the run loop room for frames and keys. */
    private const READ_PER_POLL = 1 << 20;

    /** @internal runs $fn $seconds from now on the main thread: App::after, or a test's clock */
    public static ?Closure $after = null;
    private static ?string $loaded = null;
    private static ?\Throwable $loadError = null;

    /** @internal */ public Options $opts;
    /** @internal */ public InputQueue $in;
    /** @internal the emulator: null once closed */
    public ?VtTerminal $term = null;
    /** @internal the render state, which a program's synchronized update (mode 2026) holds */
    public ?RenderState $rs = null;
    /** @internal the size of the screen in cells and of a cell in pixels: [cols, rows, cellW, cellH] @var array{0: int, 1: int, 2: int, 3: int} */
    public array $size = [80, 24, 8, 16];
    /** What a program did while the output was processed, for the callbacks, which run after it. */
    private bool $titleEvent = false;
    private int $bells = 0;
    /** When the command the shell marked started running (OSC 133 C), hrtime; null while none runs. */
    private ?int $commandStart = null;
    /** @var list<array{0: int, 1: ?int}> the commands that ended in the output written: how long each ran, in nanoseconds, and its exit code */
    private array $finished = [];
    /** @var list<array{0: string, 1: string}> */
    private array $notify = [];
    private bool $closed = false;
    /** @internal what programs copied, for the view to write @var list<string> */
    public array $clipOut = [];
    /** @internal the programs' reads of the clipboard waiting for a frame to answer them (View::answerReads) @var list<ClipboardRequest> */
    public array $clipReads = [];
    /** @internal the keys bound to actions @var list<Keybind> */
    public array $binds = [];
    /** The size zooms set, 0 for the font's. */
    private float $fontSize = 0;
    /** The program's pseudo-terminal, or Conn: null until the program starts, from $launch. */
    private Pty|Conn|null $src = null;
    private ?Pty $proc = null;
    private ?PtyOptions $launch = null;
    private bool $srcClosed = false;
    /** How dropped files' paths are quoted, for the program's shell (ShellQuote). */
    private int $quoting = ShellQuote::POSIX;
    /** When the program exited, while what it wrote is still read. */
    private ?float $exitedAt = null;
    /** The size a Conn was last told. @var ?array{0: int, 1: int} */
    private ?array $connSize = null;
    /** @internal a program's synchronized update holds the render state, since then */
    public bool $held = false;
    public float $heldSince = 0;
    /** @internal asks for a frame of the view */
    public ?Closure $draw = null;
    private bool $done = false;
    private int $code = 0;
    /** The poll's interval now, and which scheduling of it is current. */
    private float $interval = self::POLL_BUSY;
    private int $pollGen = 0;
    /** @internal the view's theme, for the program's questions */
    public ?Theme $curTheme = null;
    /** @internal the view's state */
    public ?View $v = null;

    /**
     * A terminal running $opts->command, or connected to $opts->conn
     * (mygo's New). The program starts once a view shows the terminal, at
     * the size of the view, or half a second later at 80×24 when none does.
     */
    public static function new(Options $opts): self
    {
        self::load();
        return new self($opts);
    }

    private function __construct(Options $opts)
    {
        $this->opts = $opts;
        $this->in = new InputQueue;
        $this->binds = Keybind::of($opts);
        $this->term = new VtTerminal(80, 24, new Effects(
            writePTY: function (string $p): void {
                if (!$this->replying) {
                    $this->push($p);
                    return;
                }
                $this->replying = false;
                $this->sendReply($p);
            },
            bell: function (): void { $this->bells++; },
            semanticPrompt: function (int $kind, ?int $exit): void {
                if ($kind === VtTerminal::OUTPUT_START) {
                    $this->commandStart = hrtime(true);
                } elseif ($kind === VtTerminal::COMMAND_END && $this->commandStart !== null) {
                    $this->finished[] = [hrtime(true) - $this->commandStart, $exit];
                    $this->commandStart = null;
                }
            },
            titleChanged: function (): void { $this->titleEvent = true; },
            renderHold: $this->renderHold(...),
            clipboard: function (string $text, bool $primary): bool {
                if ($primary || $this->opts->noClipboardWrite) { return false; }
                $this->clipOut[] = $text;
                $this->redraw();
                return true;
            },
            clipboardRead: $this->clipboardRead(...),
            size: fn(): array => $this->size,
            dark: fn(): bool => $this->curTheme === null || $this->curTheme->isDark(),
            notify: function (string $title, string $body): void { $this->notify[] = [$title, $body]; },
        ));
        $this->term->setTerminfoName('xterm-256color');
        // Grapheme clusters take the cells of their width, as in Ghostty: emoji with modifiers and joined emoji take two.
        $this->term->setModeDefault(VtTerminal::MODE_GRAPHEMES, true);
        $this->term->setScrollback($opts->scrollback < 0 ? 0 : ($opts->scrollback > 0 ? $opts->scrollback : 10 << 20));
        $this->term->setDefaultCursor(match ($opts->cursor) {
            CursorStyle::CursorBar => VtTerminal::CURSOR_BAR,
            CursorStyle::CursorUnderline => VtTerminal::CURSOR_UNDERLINE,
            CursorStyle::CursorBlock => VtTerminal::CURSOR_BLOCK,
        }, !$opts->noBlink);
        $this->rs = new RenderState;
        // Programs asking the colors before a view shows the terminal, or in one no view shows, get the theme asked for.
        $this->useTheme($opts->theme);
        if ($opts->conn !== null) {
            $this->src = $opts->conn;
            $this->quoting = ShellQuote::forProgram('');
            $this->kick();
            return;
        }
        [$path, $args] = self::command($opts->command);
        $this->quoting = ShellQuote::forProgram($path);
        $dir = $opts->dir !== '' ? $opts->dir : (string)getenv('HOME');
        $env = self::environment($opts->env);
        if ($opts->shellIntegration) {
            [$args, $env] = ShellIntegration::apply($path, $args, $env);
        }
        $this->launch = new PtyOptions($path, $args, $dir, $env);
        // The program starts once a view knows the size of the screen, so that it draws its first screen at that size; a terminal no view shows starts after half a second, at 80×24.
        self::schedule(0.5, function (): void {
            $o = $this->launch;
            $this->launch = null;
            $this->start($o);
        });
    }

    /**
     * Loads libghostty-vt, once, from libraryPath() (mygo's Load).
     * Terminal::new calls it; call it earlier to report a missing library
     * before showing a terminal.
     */
    public static function load(): void
    {
        if (self::$loaded === null && self::$loadError === null) {
            try {
                self::$loaded = self::libraryPath();
                Lib::load(self::$loaded);
            } catch (\Throwable $e) {
                self::$loaded = null;
                self::$loadError = $e;
            }
        }
        if (self::$loadError !== null) {
            throw self::$loadError;
        }
    }

    /**
     * The libghostty-vt the terminal loads (mygo's LibraryPath), the first
     * of: the file $MYGO_GHOSTTY_VT names; the one among the app's
     * resources (Contents/Resources of the .app); the one next to the
     * executable; the one in the user's cache, where mygo's CLI downloads
     * it (<cache>/mygo/natives/<sha256>/), which programs that are not
     * packaged apps download there when it is missing, checking its
     * SHA-256. Packaged apps never download it.
     */
    public static function libraryPath(): string { return \MyPHP\Plugins\NativeLibrary::find(__DIR__ . '/mygo-plugin.json', 'libghostty-vt', 'MYGO_GHOSTTY_VT', 'terminal'); }

    /** The view of a terminal (mygo's View): see View. */
    public static function view(Context $c, self $t): Node { return View::build($c, $t); }

    /** Runs $fn $seconds from now on the main thread. */
    private static function schedule(float $seconds, Closure $fn): void
    {
        $after = self::$after ?? App::after(...);
        $after($seconds, $fn);
    }

    /** Polls the program now, as input waits to be written. */
    private function kick(): void
    {
        $this->interval = self::POLL_BUSY;
        $gen = ++$this->pollGen;
        self::schedule(0, function () use ($gen): void { if ($gen === $this->pollGen) { $this->poll(); } });
    }

    /** Reads the output and writes the input of the program, or Conn, and polls again. */
    private function poll(): void
    {
        $src = $this->src;
        if ($src === null || $this->srcClosed) {
            return;
        }
        $busy = $this->in->flush($src);
        $got = 0;
        $eof = false;
        while ($got < self::READ_PER_POLL && !$this->srcClosed) {
            $b = $src->read();
            if ($b === null) { $eof = true; break; }
            if ($b === '') { break; }
            $got += strlen($b);
            $this->write($b);
        }
        if ($this->srcClosed) { return; } // closed by a callback
        if ($this->in->flush($src)) { $busy = true; } // the answers to what it wrote
        if (!$eof && $this->proc !== null && $this->proc->exited() !== null) {
            // What the program wrote before exiting is read; then hang up whatever else has the terminal, as terminal apps do.
            $this->exitedAt ??= microtime(true);
            $eof = microtime(true) - $this->exitedAt >= 0.1;
        }
        if ($eof) {
            $this->ended();
            return;
        }
        $this->interval = $busy || $got > 0 ? self::POLL_BUSY : min($this->interval * 2, self::POLL_IDLE);
        $gen = ++$this->pollGen;
        self::schedule($got >= self::READ_PER_POLL ? 0 : $this->interval, function () use ($gen): void { if ($gen === $this->pollGen) { $this->poll(); } });
    }

    /** Starts the program of $o, which the caller took, in a pseudo-terminal of the screen's size. */
    private function start(?PtyOptions $o): void
    {
        if ($o === null) {
            return;
        }
        [$cols, $rows, $cw, $ch] = $this->size;
        [$o->cols, $o->rows, $o->width, $o->height] = [$cols, $rows, $cols * $cw, $rows * $ch];
        try {
            $proc = Pty::start($o);
        } catch (\Throwable $e) {
            $this->write("\x1b[31m" . $e->getMessage() . "\x1b[0m");
            $this->code = -1;
            $this->finish();
            return;
        }
        if ($this->closed) {
            $proc->close();
            $this->finish();
            return;
        }
        $this->proc = $proc;
        $this->src = $proc;
        $this->kick();
    }

    /** The program to run and its arguments, with args[0] its name: the user's shell as a login shell ("-zsh") when $cmd is empty. @param list<string> $cmd @return array{0: string, 1: list<string>} */
    private static function command(array $cmd): array
    {
        if ($cmd !== []) {
            $path = self::lookPath($cmd[0]);
            if ($path === null) {
                throw new RuntimeException("exec: \"{$cmd[0]}\": executable file not found in \$PATH");
            }
            return [$path, $cmd];
        }
        $shell = (string)getenv('SHELL');
        if ($shell === '') {
            $shell = PHP_OS_FAMILY === 'Darwin' ? '/bin/zsh' : '/bin/sh';
        }
        return [$shell, ['-' . basename($shell)]];
    }

    /** The program a name runs, as a shell finds it in $PATH (Go's exec.LookPath). */
    private static function lookPath(string $name): ?string
    {
        if (str_contains($name, '/')) {
            return is_file($name) && is_executable($name) ? $name : null;
        }
        foreach (explode(':', (string)getenv('PATH')) as $dir) {
            $p = ($dir === '' ? '.' : $dir) . '/' . $name;
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }
        return null;
    }

    /** The environment of the program: this process's with the terminal's variables and $extra. @param list<string> $extra @return list<string> */
    private static function environment(array $extra): array
    {
        $env = [];
        foreach (getenv() as $k => $v) { $env[$k] = "$k=$v"; }
        $set = function (string $kv) use (&$env): void { $env[explode('=', $kv, 2)[0]] = $kv; };
        $set('TERM=xterm-256color');
        $set('COLORTERM=truecolor');
        if (getenv('LANG') === false && getenv('LC_ALL') === false && getenv('LC_CTYPE') === false) {
            $set('LANG=en_US.UTF-8');
        }
        foreach ($extra as $kv) { $set($kv); }
        return array_values($env);
    }

    /** Processes output, then runs the callbacks of what it did. */
    private function write(string $p): void
    {
        $title = '';
        if ($this->term !== null) {
            $this->term->write($p);
            if ($this->titleEvent) { $title = $this->term->title(); }
        }
        [$titled, $bells, $notify, $finished] = [$this->titleEvent, $this->bells, $this->notify, $this->finished];
        [$this->titleEvent, $this->bells, $this->notify, $this->finished] = [false, 0, [], []];
        $o = $this->opts;
        foreach ($finished as [$ns, $exit]) {
            [$bell, $note] = $this->commandFinished($ns, $exit);
            $bells += $bell ? 1 : 0;
            if ($note !== null) { $notify[] = $note; }
        }
        if ($titled && $o->onTitle !== null) { ($o->onTitle)($title); }
        for ($i = 0; $i < $bells; $i++) {
            if ($o->onBell !== null) { ($o->onBell)(); }
        }
        foreach ($notify as [$t, $b]) {
            if ($o->onNotify !== null) { ($o->onNotify)($t, $b); }
        }
        $this->redraw();
    }

    /**
     * How a command that ran $ns nanoseconds and exited with $exit (null
     * when the shell did not say) tells the user, as Ghostty's
     * notify-on-command-finish: whether it rings the bell, and the desktop
     * notification it sends, [title, body], or null.
     * @return array{0: bool, 1: ?array{0: string, 1: string}}
     */
    private function commandFinished(int $ns, ?int $exit): array
    {
        $o = $this->opts;
        if ($o->notifyOnCommandFinish === NotifyOnCommandFinish::Never || ($o->notifyOnCommandFinish === NotifyOnCommandFinish::Unfocused && ($this->v?->focused ?? false))
            || $ns <= $o->notifyOnCommandFinishAfter * 1e9) {
            return [false, null];
        }
        $a = $o->notifyOnCommandFinishAction;
        if (!$a->notify) {
            return [$a->bell, null];
        }
        $tx = \MyPHP\L10n::in(\MyPHP\App::locale());
        $took = self::duration($ns);
        return [$a->bell, $exit === null ? [$tx->get('commandFinished'), $tx->get('commandTook', ['duration' => $took])]
            : [$tx->get($exit === 0 ? 'commandSucceeded' : 'commandFailed'), $tx->get('commandTookExit', ['duration' => $took, 'code' => $exit])]];
    }

    /** $ns nanoseconds as Ghostty writes a duration, to the millisecond: "1m 5s 230ms". */
    private static function duration(int $ns): string
    {
        $ms = (int)round($ns / 1e6);
        $out = [];
        foreach (['y' => 365 * 86400000, 'w' => 7 * 86400000, 'd' => 86400000, 'h' => 3600000, 'm' => 60000, 's' => 1000, 'ms' => 1] as $unit => $f) {
            if ($ms >= $f) {
                $out[] = intdiv($ms, $f) . $unit;
                $ms %= $f;
            }
        }
        return $out === [] ? '0ms' : implode(' ', $out);
    }

    /** Marks the program ended, once its output did. */
    private function ended(): void
    {
        $this->closeSrc();
        if ($this->proc !== null && $this->proc->exited() === null) {
            // Hung up: it exits in a moment.
            self::schedule(0.01, $this->ended(...));
            return;
        }
        if ($this->proc !== null) { $this->code = (int)$this->proc->exited(); }
        if ($this->term !== null && !$this->closed) {
            $msg = $this->code !== 0 ? "[Process exited with code {$this->code}]" : '[Process exited]';
            if ($this->opts->conn !== null) { $msg = '[Disconnected]'; }
            if ($this->term->cursor()[0] > 0) { $msg = "\r\n" . $msg; }
            $this->term->write("\x1b[0;2m" . $msg . "\x1b[0m");
        }
        $this->finish();
    }

    /** Ends the terminal's program for good and calls onExit, once. */
    private function finish(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->in->close();
        if ($this->opts->onExit !== null) { ($this->opts->onExit)($this->code); }
        $this->redraw();
    }

    /** Whether the program exited (or Conn ended) (mygo's Done, a channel closed then). */
    public function done(): bool { return $this->done; }

    /** The exit code of the program once it exited: 128 plus the signal's number for a program a signal killed, -1 for one that could not start (mygo's ExitCode). */
    public function exitCode(): int { return $this->code; }

    /** The title the program set (OSC 0, OSC 2). */
    public function title(): string { return $this->term?->title() ?? ''; }

    /** The working directory the shell reported (OSC 7, which the shells of macOS report), or "" (mygo's Dir). */
    public function dir(): string { return self::fileURLPath($this->term?->pwd() ?? ''); }

    /** Changes the font, as Options::$font, and the size a reset zooms back to; the font takes its size, as zooming did not happen. */
    public function setFont(Font $f): void
    {
        $this->opts->font = clone $f;
        $this->fontSize = 0;
        $this->redraw();
    }

    /** @internal the font, at the size zooms set */
    public function font(): Font
    {
        $f = clone $this->opts->font;
        if ($this->fontSize > 0) { $f->size = $this->fontSize; }
        return $f;
    }

    /** @internal changes the font's size as $z asks, a point a step, from 1 point, as Ghostty does */
    public function zoom(Zoom $z): void
    {
        if ($z->reset) {
            $this->fontSize = 0;
        } elseif ($z->steps !== 0) {
            $this->fontSize = max($this->font()->key(1)['size'] + $z->steps, 1.0);
        }
        $this->redraw();
    }

    /** Changes where a selection is copied as it ends, as Options::$copyOnSelect, as when the user changes a setting (mygo's SetCopyOnSelect). */
    public function setCopyOnSelect(CopyOnSelect $c): void
    {
        $this->opts->copyOnSelect = $c;
    }

    /** Changes the colors, as Options::$theme and $darkTheme. */
    public function setTheme(?Theme $theme, ?Theme $dark): void
    {
        $this->opts->theme = $theme;
        $this->opts->darkTheme = $dark;
        $this->redraw();
    }

    /** The size of the screen in cells. @return array{0: int, 1: int} */
    public function size(): array { return [$this->size[0], $this->size[1]]; }

    /** The text of the screen and its scrollback, with the lines a program's long output wrapped joined. */
    public function text(): string { return $this->term?->text() ?? ''; }

    /** Sets the size of the screen in cells, for a terminal no view shows, as one a server keeps for a session while no window does: a view sets the size of the terminal it shows. */
    public function resize(int $cols, int $rows): void
    {
        $this->resizeGrid([max($cols, 1), max($rows, 1), $this->size[2], $this->size[3]]);
    }

    /** What the terminal shows as escape sequences: its scrollback and screen with their styles, the cursor and the modes programs set. Fed to a new terminal of the same size, it shows the same. */
    public function snapshot(): string { return $this->term?->vt() ?? ''; }

    /**
     * The link Command is held over in the view, which a click opens and
     * which shows underlined, as Options::$openLink takes it; whether it is
     * a file path; and the rectangle of its cells in points, [x, y, w, h]
     * from the view's corner (mygo's HoveredLink). It is "" over no link,
     * or while no view shows the terminal. An app shows a preview of a
     * link with it, over the view: read it in the view function, after
     * Terminal::view.
     * @return array{0: string, 1: bool, 2: array{0: float, 1: float, 2: float, 3: float}}
     */
    public function hoveredLink(): array
    {
        $v = $this->v;
        if ($v === null || $v->hover === null || $v->scale == 0) {
            return ['', false, [0.0, 0.0, 0.0, 0.0]];
        }
        [$row, $x0, $x1] = $v->hover;
        $cw = $v->cellW / $v->scale; $ch = $v->cellH / $v->scale;
        return [$v->hoverLink, $v->hoverPath, [$v->ox / $v->scale + $x0 * $cw, $v->oy / $v->scale + $row * $ch, ($x1 - $x0) * $cw, $ch]];
    }

    /**
     * Clears the scrollback and the screen above the cursor's line, which
     * moves to the top, as ActionClearScreen does and Kitty's
     * clear_terminal to_cursor: the prompt the shell shows stays, and the
     * shell, which moves its cursor from where it is, goes on drawing there.
     * A full-screen program's screen, which this would break, is left
     * alone, as in Ghostty; it reports whether it cleared. A server calls it
     * for a window that shows its terminal (Options::$onClear).
     */
    public function clearScreen(): bool
    {
        if ($this->term === null || $this->term->altScreen()) {
            return false;
        }
        $this->term->setSelection(null);
        $this->term->scrollToBottom();
        [$x, $y] = $this->term->cursor();
        $seq = "\x1b[3J";
        if ($y > 0) {
            // Delete the rows above the cursor's from the top, then put the cursor back on its line, now the first.
            $seq .= "\x1b[H\x1b[{$y}M\x1b[1;" . ($x + 1) . 'H';
        }
        $this->write($seq);
        $this->redraw();
        return true;
    }

    /**
     * Gives a terminal no view shows the colors of one that does, as a
     * server's for the window showing its session (mygo's UseTheme):
     * programs asking the colors (OSC 4, 10, 11, 12), or whether they are
     * dark (CSI ? 996 n), get them. A view's own theme takes over in a
     * terminal it shows.
     */
    public function useTheme(?Theme $theme): void
    {
        if ($theme === null || $this->term === null) {
            return;
        }
        $theme->apply($this->term);
        $this->schemeChanged($this->curTheme, $theme);
        $this->curTheme = $theme;
    }

    /** @internal tells a program that asked to be told (mode 2031) that the colors went from light to dark or back, from $old to $theme, as Ghostty does */
    public function schemeChanged(?Theme $old, Theme $theme): void
    {
        if ($old === null || $old === $theme || $old->isDark() === $theme->isDark() || !$this->term->mode(VtTerminal::MODE_COLOR_SCHEME)) {
            return;
        }
        $this->push("\x1b[?997;" . ($theme->isDark() ? 1 : 2) . 'n');
    }

    /** Changes the least contrast of text with its background, as Options::$minimumContrast. */
    public function setMinimumContrast(float $ratio): void
    {
        $this->opts->minimumContrast = $ratio;
        $this->redraw();
    }

    /** Clears the scrollback, leaving the screen as it is. */
    public function clearScrollback(): void { $this->write("\x1b[3J"); }

    /** Sends data to the program as if typed. */
    public function send(string $data): void
    {
        if ($data !== '') { $this->push($data); }
    }

    /** Shows data as if the program wrote it: text and escape sequences. */
    public function feed(string $data): void { $this->write($data); }

    /** @internal queues input for the program and has it written soon */
    public function push(string $p, ?Closure $done = null): void
    {
        $this->in->pushDone($p, $done);
        if (!$this->srcClosed && $this->src !== null) { $this->kick(); }
    }

    /**
     * Closes the terminal: the program is hung up (SIGHUP), or Conn closed,
     * and the terminal frees its memory. A view of it then shows nothing.
     * A program that ignores the hangup is killed after two seconds.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->offer = null;
        $this->clipReads = [];
        $waiting = $this->launch !== null;
        $this->launch = null;
        $this->pollGen++; // no poll runs any more
        if ($this->src !== null) {
            $this->closeSrc();
        } elseif ($waiting) {
            // The program never started.
            $this->code = -1;
            $this->finish();
        }
        if (($proc = $this->proc) !== null) {
            self::schedule(2.0, function () use ($proc): void { if ($proc->exited() === null) { $proc->kill(); } });
            $this->reap($proc);
        }
        $this->in->close();
        $this->v?->release();
        $this->term?->free();
        $this->term = null;
        $this->rs?->free();
        $this->rs = null;
        $this->redraw();
    }

    /** Waits for a program closed to exit, then tells, as the end of its output would have. */
    private function reap(Pty $proc): void
    {
        if ($proc->exited() === null) {
            self::schedule(0.05, fn() => $this->reap($proc));
            return;
        }
        $this->code = (int)$proc->exited();
        $this->finish();
    }

    /** Closes the program's pseudo-terminal, or Conn, once. */
    private function closeSrc(): void
    {
        if ($this->srcClosed || $this->src === null) {
            return;
        }
        $this->srcClosed = true;
        $this->src->close();
    }

    /** @internal sets the size of the screen, from the view */
    public function resizeGrid(array $s): void
    {
        if ($this->term === null) {
            return;
        }
        if (($o = $this->launch) !== null) {
            $this->launch = null;
            self::schedule(0, fn() => $this->start($o)); // at this size
        }
        if ($s === $this->size) {
            return;
        }
        $this->size = $s;
        [$cols, $rows, $cw, $ch] = $s;
        $this->term->resize($cols, $rows, $cw, $ch);
        if ($this->srcClosed) {
            return;
        }
        if ($this->src instanceof Pty) {
            $this->src->resize($cols, $rows, $cols * $cw, $rows * $ch);
        } elseif ($this->src !== null && method_exists($this->src, 'resize') && $this->connSize !== [$cols, $rows]) {
            $this->connSize = [$cols, $rows];
            $this->src->resize($cols, $rows);
        }
    }

    /** Starts or ends a program's synchronized update, during which frames show the screen as it was when it started. */
    private function renderHold(bool $held): void
    {
        if ($held && $this->rs !== null && $this->term !== null) {
            $this->rs->update($this->term);
            $this->heldSince = microtime(true);
        }
        $this->held = $held;
    }

    /** @internal asks the view for a frame */
    public function redraw(): void
    {
        if ($this->draw !== null) { ($this->draw)(); }
    }

    /** @internal the binding of a key, with exactly $mods held */
    public function binding(int $mods, int $key): ?Keybind
    {
        foreach ($this->binds as $b) {
            if ($b->mods === $mods && $b->key === $key) { return $b; }
        }
        return null;
    }

    /** @internal the key a menu item of an action shows: its first binding that always takes the key, as Ghostty's menus show no performable one, else its first */
    public function shortcut(Action $a): ?Keybind
    {
        $found = null;
        foreach ($this->binds as $b) {
            if ($b->action !== $a) { continue; }
            if (!$b->performable) { return $b; }
            $found ??= $b;
        }
        return $found;
    }

    /**
     * The keys a terminal binds unless Options::$noDefaultKeybinds,
     * Ghostty's of macOS (mygo's DefaultKeybinds): Command+C copies and
     * Command+V pastes, Command+A selects all, Command+K clears the screen,
     * Command+= (and +) and Command+- zoom, and Command+0 zooms back;
     * Command+Home, End, Page Up and Page Down scroll, as do Shift+Page Up
     * and Page Down. The keys that clear the screen and scroll are
     * performable: a full-screen program, as an editor, gets them.
     *
     * @return list<Keybind>
     */
    public static function defaultKeybinds(): array
    {
        $cmd = Key::COMMAND;
        $b = [
            new Keybind($cmd, Key::C, Action::ActionCopy),
            new Keybind($cmd, Key::V, Action::ActionPaste),
            new Keybind($cmd, Key::A, Action::ActionSelectAll),
            new Keybind($cmd, Key::K, Action::ActionClearScreen, true),
            new Keybind($cmd, Key::EQUAL, Action::ActionIncreaseFontSize),
            new Keybind($cmd | Key::SHIFT, Key::EQUAL, Action::ActionIncreaseFontSize),
            new Keybind($cmd, Key::MINUS, Action::ActionDecreaseFontSize),
            new Keybind($cmd, Key::N0, Action::ActionResetFontSize),
        ];
        foreach ([[Key::PAGE_UP, Action::ActionScrollPageUp], [Key::PAGE_DOWN, Action::ActionScrollPageDown], [Key::HOME, Action::ActionScrollToTop], [Key::END, Action::ActionScrollToBottom]] as [$k, $a]) {
            $b[] = new Keybind($cmd, $k, $a, true);
        }
        $b[] = new Keybind(Key::SHIFT, Key::PAGE_UP, Action::ActionScrollPageUp, true);
        $b[] = new Keybind(Key::SHIFT, Key::PAGE_DOWN, Action::ActionScrollPageDown, true);
        return $b;
    }

    /** The colors of terminals in dark windows (mygo's DarkTheme): the window's background and text, with Visual Studio Code's palette. */
    public static function darkTheme(): Theme
    {
        $t = UITheme::dark();
        return new Theme($t->text, $t->background, palette: [0x000000, 0xcd3131, 0x0dbc79, 0xe5e510, 0x2472c8, 0xbc3fbc, 0x11a8cd, 0xe5e5e5,
            0x666666, 0xf14c4c, 0x23d18b, 0xf5f543, 0x3b8eea, 0xd670d6, 0x29b8db, 0xffffff]);
    }

    /** The colors of terminals in light windows (mygo's LightTheme). */
    public static function lightTheme(): Theme
    {
        $t = UITheme::light();
        return new Theme($t->text, $t->background, palette: [0x000000, 0xcd3131, 0x00bc00, 0x949800, 0x0451a5, 0xbc05bc, 0x0598bc, 0x555555,
            0x666666, 0xcd3131, 0x14ce14, 0xb5ba00, 0x0451a5, 0xbc05bc, 0x0598bc, 0xa5a5a5]);
    }

    /** The path of a file URL, as shells report their directory (file://host/path), or $s when it is not one. */
    private static function fileURLPath(string $s): string
    {
        if (!str_starts_with($s, 'file://')) {
            return $s;
        }
        $rest = substr($s, 7);
        if (($i = strpos($rest, '/')) !== false) { $rest = substr($rest, $i); }
        return (string)preg_replace_callback('/%([0-9a-fA-F]{2})/', fn($m) => chr((int)hexdec($m[1])), $rest);
    }
}
