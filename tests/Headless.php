<?php
// A headless harness for the UI: fake text metrics (7 points a character,
// 16 high), a runtime fed events as the view would, and the small helpers
// the tests share. No window, no Metal, no ObjC: what runs here is Layout,
// Runtime and Context. Run every test with php tests/run.php.
declare(strict_types=1);

namespace MyPHP\Tests;

use Closure;
use MyPHP\UI\{Context, Input, Key, Layout, Node, Runtime, TextMetrics};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && !str_starts_with($c, 'MyPHP\\Tests\\') && (require __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'));

final class Metrics implements TextMetrics
{
    public function ascent(float $size, int $style = 0): float { return 12.0; }
    public function measure(string $s, float $size, int $style = 0): array { return [mb_strlen($s) * 7.0, 16.0]; }
    public function xForIndex(string $s, float $size, int $i): float { return $i * 7.0; }
    public function indexForX(string $s, float $size, float $x): int { return (int)($x / 7); }
}

final class Headless
{
    public Input $input;
    public Runtime $rt;
    public Metrics $metrics;
    public ?Node $root = null;
    private Closure $view;

    /** $view builds the UI with the Context given; $w and $h are the window's size. */
    public function __construct(Closure $view, public float $w = 600, public float $h = 400)
    {
        $this->metrics = new Metrics;
        $this->input = new Input;
        $this->input->mouseInside = true;
        $this->rt = new Runtime($this->input, $this->metrics);
        $this->view = $view;
    }

    /** Runs $n frames (two by default: one takes the events, the next shows what they changed) and returns the root. */
    public function frame(int $n = 2): Node
    {
        for ($i = 0; $i < $n; $i++) {
            $this->root = $this->rt->frame(function () {
                $c = new Context($this->metrics, $this->rt, $this->w, $this->h);
                ($this->view)($c);
                Layout::measure($c->root);
                Layout::arrange($c->root, 0, 0, $this->w, $this->h);
                return $c->root;
            });
        }
        return $this->root;
    }

    /** A left click (down and up) at ($x, $y), with $mods (Key::SHIFT, Key::COMMAND) and $clicks (2 for a double click). */
    public function click(float $x, float $y, int $mods = 0, int $clicks = 1): Node
    {
        $this->input->mouse($x, $y);
        $this->input->push(['down', $x, $y, 0, $clicks, $mods]);
        $this->input->push(['up', $x, $y, 0, $clicks, $mods]);
        return $this->frame();
    }

    /** The mouse pressed at ($x, $y) and held. */
    public function press(float $x, float $y): Node
    {
        $this->input->mouse($x, $y);
        $this->input->push(['down', $x, $y, 0, 1, 0]);
        return $this->frame();
    }

    /** The mouse, held, moved to ($x, $y). */
    public function drag(float $x, float $y): Node
    {
        $this->input->mouse($x, $y);
        $this->input->push(['drag', $x, $y]);
        return $this->frame();
    }

    /** The mouse released at ($x, $y). */
    public function release(float $x, float $y): Node
    {
        $this->input->push(['up', $x, $y, 0, 1, 0]);
        return $this->frame();
    }

    /** The pointer moved to ($x, $y). */
    public function hover(float $x, float $y): Node
    {
        $this->input->mouse($x, $y);
        return $this->frame();
    }

    /** A key as the view sends it: the key event, then the text system's selector — taken by the focused field's TextState first, as the view does, else pushed as a command. */
    public function key(int $code, string $selector, int $mods = 0, string $chars = ''): Node
    {
        $this->input->push(['key', $chars, $code, $mods]);
        if (!($this->input->focus?->command($selector) ?? false)) {
            $this->input->push(['command', $selector]);
        }
        return $this->frame();
    }

    /** A shortcut's key with modifiers (Cmd+G): the key event alone, which no selector follows. */
    public function shortcut(string $char, int $mods): Node
    {
        $this->input->push(['key', $char, 0, $mods]);
        return $this->frame();
    }

    /** Text typed: into the focused field's TextState, as the view does, else inserted at the focused control. */
    public function type(string $s): Node
    {
        $this->input->push(['key', $s, 0, 0]);
        if ($this->input->focus !== null) { $this->input->focus->insert($s); } else { $this->input->push(['insert', $s]); }
        return $this->frame();
    }

    public function tab(bool $back = false): Node { return $this->key(Key::TAB, $back ? 'insertBacktab:' : 'insertTab:', $back ? Key::SHIFT : 0, "\t"); }
    public function enter(int $mods = 0): Node { return $this->key(Key::RETURN, 'insertNewline:', $mods, "\r"); }
    public function escape(): Node { return $this->key(Key::ESCAPE, 'cancelOperation:', 0, "\e"); }
    public function up(int $mods = 0): Node { return $this->key(Key::UP, $mods & Key::SHIFT ? 'moveUpAndModifySelection:' : 'moveUp:', $mods); }
    public function down(int $mods = 0): Node { return $this->key(Key::DOWN, $mods & Key::SHIFT ? 'moveDownAndModifySelection:' : 'moveDown:', $mods); }
    public function left(): Node { return $this->key(Key::LEFT, 'moveLeft:'); }
    public function right(): Node { return $this->key(Key::RIGHT, 'moveRight:'); }
    public function home(): Node { return $this->key(Key::HOME, 'moveToBeginningOfDocument:'); }
    public function end(): Node { return $this->key(Key::END, 'moveToEndOfDocument:'); }
    public function pageDown(): Node { return $this->key(Key::PAGE_DOWN, 'pageDown:'); }
    public function space(): Node { return $this->type(' '); }

    /** The rect node $n had last frame: x, y, w, h. */
    public function rect(Node $n): array { return $this->rt->rectOf($n->id) ?? throw new \RuntimeException('no rect for ' . self::label($n)); }

    /** The middle of node $n's last-frame rect. */
    public function middle(Node $n): array { $r = $this->rect($n); return [$r[0] + $r[2] / 2, $r[1] + $r[3] / 2]; }

    /** A click in the middle of node $n. */
    public function clickOn(Node $n, int $mods = 0, int $clicks = 1): Node { [$x, $y] = $this->middle($n); return $this->click($x, $y, $mods, $clicks); }

    /** The node with $id in the tree, or null. */
    public function find(int $id, ?Node $from = null): ?Node
    {
        $from ??= $this->root;
        if ($from === null) { return null; }
        if ($from->id === $id) { return $from; }
        foreach ($from->kids as $k) { if (($r = $this->find($id, $k)) !== null) { return $r; } }
        return null;
    }

    /** The focused node, or null. */
    public function focused(): ?Node { return $this->rt->focus === null ? null : $this->find($this->rt->focus); }

    /** A short name for a node: its kind, its text or its first child's. */
    public static function label(?Node $n): string
    {
        if ($n === null) { return 'none'; }
        $k = ['C', 'T', 'B', 'X', 'I', 'A', 'M', 'R', 'CB', 'RD', 'SL', 'SE', 'P', 'SW', 'SP'][$n->kind] ?? (string)$n->kind;
        $t = $n->text ?? ($n->kids[0]->text ?? '');
        return $k . ($t !== '' ? "($t)" : '');
    }

    /** The Tab stops from the focus now, round until one repeats: their labels. @return list<string> */
    public function tabStops(int $max = 60): array
    {
        $seen = []; $stops = [];
        for ($i = 0; $i < $max; $i++) {
            $this->tab();
            $f = $this->rt->focus;
            if ($f === null || isset($seen[$f])) { break; }
            $seen[$f] = true;
            $stops[] = self::label($this->focused());
        }
        return $stops;
    }

    /**
     * The widgets example's view and state, loaded without its window:
     * [$view (Closure(Context)), &$s] — take $s by reference ($s = &$w[1])
     * for the view to see what the test sets. The example's file is read up to
     * App::whenReady, its constants defined, its icons read from its folder.
     */
    public static function widgets(): array
    {
        $path = __DIR__ . '/../examples/widgets/main.php';
        $src = file_get_contents($path) ?: throw new \RuntimeException($path);
        $src = substr($src, 0, strpos($src, 'App::whenReady') ?: throw new \RuntimeException('App::whenReady'));
        $src = str_replace(['declare(strict_types=1);', '__DIR__'], ['', var_export(dirname($path), true)], $src);
        $src = preg_replace('/^const (\w+) = (.*);$/m', 'defined(\'$1\') || define(\'$1\', $2);', $src);
        $s = null;
        (static function () use ($src, &$s) { eval('?>' . $src); })();
        return [static function (Context $c) use (&$s) { view($c, $s); }, &$s];
    }
}

/** Fails the test unless $cond, with $what. */
function check(bool $cond, string $what): void
{
    global $checks, $failures;
    $checks++;
    if (!$cond) { $failures[] = $what; echo "  FAIL: $what\n"; }
}

/** Fails unless $got equals $want. */
function same(mixed $want, mixed $got, string $what): void
{
    check($want === $got, "$what: expected " . json_encode($want) . ", got " . json_encode($got));
}
