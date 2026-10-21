<?php
// What the terminal's tests share (mygo's terminal_test.go helpers): a
// clock for the terminal's polls and timers, a Conn that keeps what the
// terminal sends, and a harness showing a terminal.
declare(strict_types=1);

namespace MyPHP\Tests;

use Closure;
use MyPHP\Plugins\Terminal\{Conn, Options, Terminal};
use MyPHP\Transfer\Data;
use MyPHP\UI\{Context, Node};

/** The terminal's timers, which termPump() runs when they are due, as the run loop would. */
final class TermClock
{
    /** @var list<array{0: float, 1: Closure}> */
    public static array $due = [];

    public static function install(): void
    {
        Terminal::$after = function (float $s, Closure $fn): void { self::$due[] = [microtime(true) + $s, $fn]; };
    }

    /** Runs the timers due. */
    public static function pump(): void
    {
        $now = microtime(true);
        $run = [];
        foreach (self::$due as $i => $e) {
            if ($e[0] <= $now) { $run[] = $e[1]; unset(self::$due[$i]); }
        }
        self::$due = array_values(self::$due);
        foreach ($run as $fn) { $fn(); }
    }
}

/** A Conn whose output the test writes with feed(), and which keeps what the terminal sends (mygo's pipe). */
final class TermPipe implements Conn
{
    public string $sent = '';
    public bool $closed = false;

    public function read(): ?string { return $this->closed ? null : ''; }
    public function write(string $b): int { $this->sent .= $b; return strlen($b); }
    public function close(): void { $this->closed = true; }

    /** What the terminal sent since the last take, once it sent $want bytes or a second passed. */
    public function take(int $want): string
    {
        $deadline = microtime(true) + ($want > 0 ? 1.0 : 0.05);
        while (strlen($this->sent) < max($want, 1) && microtime(true) < $deadline) {
            TermClock::pump();
            usleep(1000);
        }
        TermClock::pump();
        $s = $this->sent;
        $this->sent = '';
        return $s;
    }
}

/** A harness showing a terminal (mygo's ui.NewTester with View(c, term).Fill().AutoFocus()); $node is the view's node of the last frame. */
final class TermTester
{
    public Headless $h;
    public ?Node $node = null;
    /** @var list<string> the URLs the system was asked to open */
    public array $opened = [];

    public function __construct(public Terminal $term, float $w = 400, float $hh = 200, bool $focus = true)
    {
        $this->h = new Headless(function (Context $c) {
            $this->node = Terminal::view($c, $this->term);
            $this->node->grow = 1;
        }, $w, $hh);
        $this->h->theme = \MyPHP\UI\Theme::light();
        $this->h->paints = true;
        $this->h->rt->openURL = function (string $u): void { $this->opened[] = $u; };
        $this->h->frame();
        if ($focus) {
            $this->h->rt->focus = $this->node->id;
            $this->h->frame();
        }
    }

    public function frame(int $n = 2): void { TermClock::pump(); $this->h->frame($n); }

    /** The middle of a cell of the view, in points (mygo's cellCenter). @return array{0: float, 1: float} */
    public function cell(int $col, int $row): array
    {
        $v = $this->term->v;
        return [($v->ox + ($col + 0.5) * $v->cellW) / $v->scale, ($v->oy + ($row + 0.5) * $v->cellH) / $v->scale];
    }

    /** A key pressed and released, the text it types between, as the view sends them: the text goes to the input methods when the view leaves the key (mygo's Key and TypeKey). */
    public function key(int $mods, int $key, string $text = ''): void
    {
        $in = $this->h->input;
        $in->mods = $mods;
        $took = $in->push(['key', $text, $key, $mods, false]);
        if (!$took && $text !== '' && $in->client !== null) {
            $in->client->replaceText(null, $text);
        }
        $in->push(['keyup', $text, $key, $mods]);
        $in->mods = 0;
        $this->frame();
    }

    /** Text without its key, as input methods commit it (mygo's Type). */
    public function type(string $s): void
    {
        $this->h->input->client?->replaceText(null, $s);
        $this->frame();
    }

    /** An edit command of the menus (mygo's Command). */
    public function command(string $name): void
    {
        $this->h->input->push(['edit', $name]);
        $this->frame();
    }

    public function clipboard(): string { return ($this->h->rt->readClipboard)(); }
    public function setClipboard(string $s): void { ($this->h->rt->writeClipboardData)($s === '' ? new Data : Data::text($s)); }
    public function setClipboardData(Data $d): void { ($this->h->rt->writeClipboardData)($d); }

    public function press(float $x, float $y, int $mods = 0): void { $this->h->input->mouse($x, $y); $this->h->input->push(['down', $x, $y, 0, 1, $mods]); $this->frame(); }
    public function move(float $x, float $y): void { $this->h->input->mouse($x, $y); $this->h->input->push(['drag', $x, $y]); $this->frame(); }
    public function hover(float $x, float $y): void { $this->h->input->mouse($x, $y); $this->h->input->push(['move', $x, $y]); $this->frame(); }
    public function release(float $x, float $y, int $mods = 0): void { $this->h->input->push(['up', $x, $y, 0, 1, $mods]); $this->frame(); }

    /** A click; $clicks counts quick successive presses. */
    public function click(float $x, float $y, int $mods = 0, int $clicks = 1): void
    {
        $this->h->input->mods = $mods;
        $this->h->input->mouse($x, $y);
        $this->h->input->push(['down', $x, $y, 0, $clicks, $mods]);
        $this->h->input->push(['up', $x, $y, 0, $clicks, $mods]);
        $this->frame();
        $this->h->input->mods = 0;
    }

    /** The wheel: $lines notches of a mouse (negative up), or $dy points of a trackpad (negative up), as the view sends them. */
    public function scroll(float $x, float $y, float $lines, int $mods = 0, bool $precise = false): void
    {
        $this->h->input->mods = $mods;
        $this->h->input->mouse($x, $y);
        $this->h->input->push(['scroll', $x, $y, 0.0, $precise ? -$lines : -$lines * 10, $precise]);
        $this->frame();
        $this->h->input->mods = 0;
    }
}

/** Frames until $cond holds, at most 10 seconds. */
function termWait(TermTester $tt, string $what, Closure $cond): void
{
    $deadline = microtime(true) + 10;
    while (!$cond()) {
        if (microtime(true) > $deadline) {
            check(false, "timed out waiting for $what");
            return;
        }
        usleep(10000);
        $tt->frame(1);
    }
}

/** A terminal running $opts's command, /bin/sh by default, with a prompt of "$ " (mygo's shell). */
function termShell(Options $opts): Terminal
{
    if ($opts->command === []) { $opts->command = ['/bin/sh']; }
    array_push($opts->env, 'PS1=$ ', 'ENV=');
    return Terminal::new($opts);
}

/** The terminal's offset in its scrollback. */
function termOffset(Terminal $t): int { return $t->term->scrollbar()[1]; }

/** "line 000" to "line 099", a line each. */
function termLines(int $n): string
{
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s .= sprintf("line %03d\r\n", $i); }
    return $s;
}
