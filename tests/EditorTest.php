<?php
// A code editor in a headless window, for the tests of the editor.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Node};
use MyPHP\UI\Editor\{Buffer, Languages, Selection, State};

/** An editor in a headless window, focused, with the text given: | in it marks the carets, and [ ] selections. */
final class EditorTest
{
    public State $st;
    public Headless $h;
    public ?Node $node = null;

    public function __construct(string $lang, string $text, float $w = 600, float $h = 400)
    {
        [$plain, $sels] = self::parseCarets($text);
        $this->st = new State(new Buffer($plain));
        if ($lang !== '') { $this->st->buffer->setLanguage(Languages::named($lang) ?? throw new \RuntimeException("no language $lang")); }
        $this->h = new Headless(function (Context $c) {
            $this->node = $this->st->view($c);
            $this->node->grow = 1;
        }, $w, $h);
        $this->h->paints = true;
        if ($sels !== []) { $this->st->setSelections(...$sels); }
        $this->h->frame(1);
        $this->h->rt->focusOn($this->node->id); // AutoFocus
        $this->h->frame();
    }

    /** The carets and selections out of $text. @return array{0: string, 1: list<Selection>} */
    public static function parseCarets(string $text): array
    {
        $b = ''; $sels = []; $open = -1;
        for ($i = 0; $i < strlen($text); $i++) {
            switch ($text[$i]) {
                case '|': $sels[] = new Selection(strlen($b), strlen($b)); break;
                case '[': $open = strlen($b); break;
                case ']': $sels[] = new Selection($open, strlen($b)); break;
                default: $b .= $text[$i];
            }
        }
        return [$b, $sels];
    }

    /** The text with its carets and selections, as parseCarets reads them. */
    public function show(): string
    {
        $text = (string)$this->st->buffer;
        $sels = $this->st->selections();
        $b = '';
        for ($i = 0; $i <= strlen($text); $i++) {
            // A selection ending here first, then those starting.
            foreach ($sels as $s) { if (!$s->empty() && $s->end() === $i) { $b .= ']'; } }
            foreach ($sels as $s) {
                if ($s->empty() && $s->head === $i) { $b .= '|'; } elseif (!$s->empty() && $s->start() === $i) { $b .= '['; }
            }
            if ($i < strlen($text)) { $b .= $text[$i]; }
        }
        return $b;
    }

    public function want(string $want, string $what): void { same($want, $this->show(), $what); }

    /** A key pressed, with the characters it types for the key bindings that look at them. */
    public function key(int $mods, int $key, string $chars = ''): void { $this->h->key($key, '', $mods, $chars); }

    /** Typed, a character at a time, as the text input client gets it (no key events: mygo's Tester.Type). */
    public function typ(string $s): void
    {
        foreach (mb_str_split($s, 1, 'UTF-8') as $r) {
            $this->h->input->client?->replaceText(null, $r);
            $this->h->frame();
        }
    }

    public function run(int $cmd): void { $this->st->run($cmd); $this->h->frame(); }

    /** The pointer moved to x, y; pressed there and released. */
    public function move(float $x, float $y): void
    {
        $this->h->input->mouse($x, $y);
        $this->h->input->push([$this->h->input->mouseDown ? 'drag' : 'move', $x, $y]);
        $this->h->frame();
    }

    public function cursor(): \MyPHP\UI\Cursor { return $this->h->rt->cursor; }

    /**
     * The color [r, g, b] the last frame painted at $x, $y of the window:
     * its fills and rings, over white, in their order, as the GPU blends
     * them; glyphs are left out.
     */
    public function pixel(float $x, float $y): array
    {
        $list = $this->h->paint();
        $c = [255.0, 255.0, 255.0];
        for ($i = 0; $i < $list->n; $i++) {
            $q = []; for ($k = 0; $k < 16; $k++) { $q[$k] = $list->buf[$i * 16 + $k]; }
            [$qx, $qy, $qw, $qh] = $q; $kind = (int)$q[9];
            if ($kind !== 0 && $kind !== 4) { continue; }
            if ($x < $qx || $y < $qy || $x >= $qx + $qw || $y >= $qy + $qh) { continue; }
            if ($x < $q[12] || $y < $q[13] || $x >= $q[12] + $q[14] || $y >= $q[13] + $q[15]) { continue; }
            if ($kind === 4) { // a ring: its band along the edges
                $t = $q[10];
                if ($x >= $qx + $t && $y >= $qy + $t && $x < $qx + $qw - $t && $y < $qy + $qh - $t) { continue; }
            }
            $a = $q[7];
            for ($k = 0; $k < 3; $k++) { $c[$k] = $c[$k] * (1 - $a) + $q[4 + $k] * 255 * $a; }
        }
        return array_map(fn(float $v) => (int)round($v), $c);
    }
}
