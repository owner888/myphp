<?php
// A headless harness for the UI: fake text metrics (7 points a character,
// 16 high), a runtime fed events as the view would, and the small helpers
// the tests share. No window, no Metal, no ObjC: what runs here is Layout,
// Runtime and Context. Run every test with php tests/run.php.
declare(strict_types=1);

namespace MyPHP\Tests;

use Closure;
use MyPHP\UI\{AccessTree, Context, DisplayList, Image, Input, Key, Layout, Node, Painter, Path, Runtime, SVG, TextPainter, Theme};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && !str_starts_with($c, 'MyPHP\\Tests\\') && (require __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'));

/** The system's icons where there are none to draw (Linux without GTK): each a clear square of its size, so that a view of SF Symbols builds as it would. */
final class StandInSymbols implements \MyPHP\Platform\Symbols
{
    public function exists(string $name): bool { return true; }
    public function draw(string $name, float $size, float $weight, int $rgb, float $scale): array
    {
        $px = max(1, (int)round($size * $scale));
        return [$px, $px, str_repeat("\0", $px * $px * 4)];
    }
}

/** A text engine of 7 × 16 cells: a glyph quad a character, images one atlas pixel, the scale 1. */
final class Metrics implements TextPainter
{
    public function ascent(float $size, int $style = 0): float { return 12.0; }
    /** @var array<int, float> the advance of a character in a style (Font bits) other than 7, for a test of faces; and the texts paint() and paintRuns() painted, [text, style, x], while not null */
    public array $advances = [];
    public ?array $painted = null;
    private function adv(int $style): float { return $this->advances[$style] ?? 7.0; }
    public function measure(string $s, float $size, int $style = 0): array { return [mb_strlen($s) * $this->adv($style), 16.0]; }
    /** @var array<string, float> min-content widths by text, kept as the Mac's line cache keeps them with the line */
    private array $mins = [];
    public function minContentWidth(string $s, float $size, int $style = 0): float
    {
        if (!isset($this->mins[$s])) { $r = \MyPHP\Text\System::runes($s); $this->mins[$s] = \MyPHP\Text\LineBreaks::widest($r, array_map(fn(int $i) => $i * 7.0, range(0, count($r)))); }
        return $this->mins[$s];
    }
    public function xForIndex(string $s, float $size, int $i, int $style = 0): float { return mb_strlen(substr($s, 0, $i)) * $this->adv($style); }
    public function indexForX(string $s, float $size, float $x, int $style = 0): int { return strlen(mb_substr($s, 0, max(0, min((int)round($x / $this->adv($style)), mb_strlen($s))))); }
    public function paint(DisplayList $list, string $text, float $size, float $x, float $y, int $rgb, float $alpha = 1.0, int $style = 0): void
    {
        if ($this->painted !== null) { $this->painted[] = [$text, $style, $x]; }
        for ($i = 0, $n = mb_strlen($text); $i < $n; $i++) { $list->glyph($x + $i * 7, $y, 7, 16, $rgb, $alpha, 0, 0); }
    }
    public function paintRuns(DisplayList $list, string $text, float $size, float $x, float $y, int $style, \Closure $colorAt): void
    {
        $byte = 0; $a = $this->adv($style);
        if ($this->painted !== null) { $this->painted[] = [$text, $style, $x]; }
        foreach (mb_str_split($text) as $i => $r) { [$rgb, $alpha] = $colorAt($byte); $list->glyph($x + $i * $a, $y, $a, 16, $rgb, $alpha, 0, 0); $byte += strlen($r); }
    }
    /** Each glyph's advance by its size, from the ascent above the baseline down, solid. */
    public function glyphCoverage(array $glyphs, float $x, float $y, int $w, int $h, float $scale): string
    {
        $out = str_repeat("\0", $w * $h);
        foreach ($glyphs as $g) {
            $x0 = max(0, (int)round(($x + $g->x) * $scale)); $x1 = min($w, (int)round(($x + $g->x + $g->advance) * $scale));
            $y0 = max(0, (int)round(($y + $g->y - 0.8 * $g->size) * $scale)); $y1 = min($h, (int)round(($y + $g->y + 0.2 * $g->size) * $scale));
            for ($r = $y0; $r < $y1; $r++) {
                if ($x1 > $x0) { $out = substr_replace($out, str_repeat("\xff", $x1 - $x0), $r * $w + $x0, $x1 - $x0); }
            }
        }
        return $out;
    }
    public function imageSlot(Image $image): array { return ['u' => 0, 'v' => 0, 'w' => 1, 'h' => 1, 'page' => 0]; }
    public function iconQuad(SVG $svg, float $x, float $y, float $w, float $h, float $rotate = 0.0): ?array { return [$x, $y, $w, $h, 0, 0]; }
    public function svgSlot(SVG $svg, int $w, int $h, int $current): array { return ['u' => 0, 'v' => 0, 'w' => $w, 'h' => $h, 'page' => 0]; }
    /** The scale the painter reports: what a test of pixels sets. */
    public float $scale = 1.0;
    public function scale(): float { return $this->scale; }
    public function drawnMark(): int { return 0; } // its glyphs and images are drawn from no atlas: what was drawn is drawn again as it was
    public function drawnSince(int $mark): array { return []; }
    public function drawnAgain(array $drawn): bool { return true; }
    private ?\MyPHP\Text\System $system = null;
    public function textSystem(): \MyPHP\Text\System { return $this->system ??= new \MyPHP\Text\System(new \MyPHP\Text\StubEngine()); }
    public function paintLayout(DisplayList $list, \MyPHP\Text\Layout $l, float $x, float $y, \Closure $colorAt, float $stroke = 0.0, float $blur = 0.0, bool $cutOut = false): void
    {
        foreach ($l->lines as $line) {
            $grow = $stroke + 3 * $blur; // an outline's glyph grown by its stroke all round, a blurred one by three sigmas
            foreach ($line->glyphs as $g) {
                if (!$g->object && ([$rgb, $alpha] = $colorAt($g->cluster))[1] > 0) { $list->glyph($x + $g->x - $grow, $y + $line->y - $grow, $g->advance + 2 * $grow, $line->height + 2 * $grow, $rgb, $alpha, 0, 0); }
            }
        }
    }
}

final class Headless
{
    public Input $input;
    public Runtime $rt;
    public Metrics $metrics;
    public ?Node $root = null;
    public Theme $theme;
    /** The haptics the view played, in order (Runtime::haptic). @var list<string> */
    public array $haptics = [];
    private ?DisplayList $list = null;
    private Closure $view;

    private static bool $symbolsChecked = false;

    /** The system's symbols, or stand-ins where it has none to draw (a Linux without GTK's icon theme): the tests of views using Image(systemName:) run there too. */
    private static function symbolsOrStandIns(): void
    {
        if (self::$symbolsChecked) { return; }
        self::$symbolsChecked = true;
        $backend = \MyPHP\App::backend();
        try { $backend->symbols()->draw('gearshape', 16, 0, 0, 1); } catch (\Throwable) {
            (new \ReflectionProperty($backend, 'symbols'))->setValue($backend, new StandInSymbols());
        }
    }

    /** $view builds the UI with the Context given; $w and $h are the window's size. */
    public function __construct(Closure $view, public float $w = 600, public float $h = 400)
    {
        self::symbolsOrStandIns();
        $this->metrics = new Metrics;
        $this->input = new Input;
        $this->input->mouseInside = true;
        $this->rt = new Runtime($this->input, $this->metrics);
        $this->rt->strict = true; // a duplicate key throws where it was given
        $this->rt->playHaptic = function (string $kind): void { $this->haptics[] = $kind; }; // recorded, not played
        \MyPHP\UI\Editor\Editor::$text = $this->metrics;
        $this->theme = Theme::dark();
        $this->view = $view;
        $this->rt->onAccess = function (AccessTree $t) { array_push($this->told, ...$t->announcements); };
        $this->rt->startDataDrag = function (\MyPHP\Transfer\Data $data, mixed $local, \MyPHP\Transfer\DragOptions $o, float $x, float $y): ?\Throwable {
            $this->dataDrag = ['data' => $data->snapshot(), 'local' => $local, 'options' => $o, 'x' => $x, 'y' => $y];
            return null;
        };
        $this->rt->cancelDataDrag = fn() => $this->finishDataDrag(new \MyPHP\Transfer\Result(canceled: true, escaped: true, point: new \MyPHP\Point(10, 20)));
        $this->rt->setDropFormats = function (array $formats): void { $this->dropFormats = $formats; };
        $this->rt->popupMenu = function (array $items, float $x, float $y, bool $rtl, Closure $chosen): void {
            $this->menu = ['items' => $items, 'at' => [$x, $y], 'rtl' => $rtl, 'chosen' => $chosen];
        };
        $this->rt->snapshot = fn(float $x, float $y, int $w, int $h) => Image::fromPixels($w, $h, str_repeat("\0", $w * $h * 4));
    }

    /** The menu the runtime showed and the user has not closed: its items (MyPHP\MenuItem), where it showed, and what takes the choice (mygo's headless menu). @var ?array{items: list<\MyPHP\MenuItem>, at: array{0: float, 1: float}, rtl: bool, chosen: Closure} */
    public ?array $menu = null;

    /** The labels of the menu showing, "-" for a separator, "# label" for a header, or null for none (mygo's Tester.Menu). @return ?list<string> */
    public function menuLabels(): ?array
    {
        return $this->menu === null ? null : array_map(fn(\MyPHP\MenuItem $it) => match ($it->type) { \MyPHP\MenuItem::SEPARATOR => '-', \MyPHP\MenuItem::HEADER => "# $it->label", default => $it->label }, $this->menu['items']);
    }

    /** Chooses the item at $path (a label, then a submenu's) of the menu showing, which closes, and runs a frame; false when there is no such item, or it is disabled (mygo's ChooseMenuItem). */
    public function chooseMenuItem(string ...$path): bool
    {
        $items = $this->menu['items'] ?? [];
        $it = null;
        foreach ($path as $label) {
            $it = null;
            foreach ($items as $candidate) { if ($candidate->label === $label && $candidate->type !== \MyPHP\MenuItem::SEPARATOR && $candidate->type !== \MyPHP\MenuItem::HEADER) { $it = $candidate; break; } }
            if ($it === null) { return false; }
            $items = $it->submenu;
        }
        if ($it === null || $it->disabled || $it->submenu !== []) { return false; }
        $chosen = $this->menu['chosen'];
        $this->menu = null;
        $chosen((int)$it->id);
        $this->frame();
        return true;
    }

    /** Closes the menu showing without a choice. */
    public function closeMenu(): void { $this->menu = null; }

    /** A click of the secondary button at ($x, $y). */
    public function rightClick(float $x, float $y): Node
    {
        $this->input->mouse($x, $y);
        $this->input->push(['down', $x, $y, 1, 1, 0]);
        $this->input->push(['up', $x, $y, 1, 1, 0]);
        return $this->frame();
    }

    /** The native drag the host was asked to start (mygo's headless host): its data as the drag snapshots it, its local value, options and where it started; null when none is on. */
    public ?array $dataDrag = null;
    /** @var list<string> the formats the view was told to take */
    public array $dropFormats = [];

    /** Ends the native drag on, as the system would, telling its source $r (mygo's finishDataDrag). */
    public function finishDataDrag(\MyPHP\Transfer\Result $r): void
    {
        $d = $this->dataDrag;
        $this->dataDrag = null;
        if ($d !== null && $d['options']->done !== null) { ($d['options']->done)($r); }
    }

    /** $e over ($x, $y), then a frame: whether a node takes it. */
    public function dataOver(float $x, float $y, \MyPHP\UI\DataDragEvent $e): bool
    {
        $took = $this->rt->dataDragOver($x / $this->rt->frameZoom, $y / $this->rt->frameZoom, $e); // the window's points, as the window maps them
        $this->frame();
        return $took;
    }

    /** $e dropped at ($x, $y), then a frame: whether a node took it. */
    public function dataDrop(float $x, float $y, \MyPHP\UI\DataDragEvent $e): bool
    {
        $took = $this->rt->dataDrop($x / $this->rt->frameZoom, $y / $this->rt->frameZoom, $e);
        $this->frame();
        return $took;
    }

    /** @var list<string> what the trees for assistive technology announced (mygo's told) */
    public array $told = [];

    /** Whether each frame is painted too, as a window paints it: for what works out its state as it paints, as a code editor. */
    public bool $paints = false;

    /** Runs $n frames (two by default: one takes the events, the next shows what they changed) and returns the root. */
    public function frame(int $n = 2): Node
    {
        for ($i = 0; $i < $n; $i++) {
            $in = $this->rt->inspector;
            $this->root = $this->rt->frame(function () use ($in) {
                $in?->startPass();
                [$w, $h] = [$this->w / $this->rt->frameZoom, $this->h / $this->rt->frameZoom]; // zoomed (Context::setZoom), as a window lays it out; the tester's points stay the window's
                $c = new Context($this->metrics, $this->rt, $in?->contentWidth($w) ?? $w, $h, $this->theme);
                ($this->view)($c);
                if ($in !== null && $in->open) { \MyPHP\UI\InspectorPanel::build($c, $this->rt, $in, $w, $h); $in->lap(0); }
                Layout::layout($c, $w, $h);
                if ($in !== null && $in->open) { $in->lap(1); $in->snapshot($this->rt, $c->root); }
                return $c->root;
            });
            $this->rt->builtAt([$this->w, $this->h, 1.0]);
            $this->metrics->textSystem()->endFrame(); // as a window's frame ages its text layouts (Text::beginFrame), or the cache only grows
            if ($this->paints || ($in !== null && $in->open)) { $this->paint(); } // as a window paints it: the inspector's highlight, and its times
        }
        return $this->root;
    }

    /**
     * The frame a window draws next, as the window decides (Window's draw
     * callback): one that paints the last frame's nodes again when only
     * drawings asked for it and nothing changed since (Runtime::mayRepaint,
     * a frame asked for only to paint again, $repaintAsked), else one built
     * anew; painted, and what it asks for next (Runtime::pace). Whether it
     * only painted again.
     */
    public function windowFrame(bool $repaintAsked = true, float $hz = 60.0): bool
    {
        $repaint = $this->root !== null && $repaintAsked && $this->rt->mayRepaint([$this->w, $this->h, 1.0]);
        $parts = false;
        if ($repaint) {
            $this->rt->beginRepaint();
            if (!$this->noParts && $this->list !== null) { // the drawings that asked alone, as the window paints them (Painter::repaintParts)
                $this->rt->painting = true;
                try { $parts = Painter::repaintParts($this->rt, $this->list, $this->metrics); } finally { $this->rt->painting = false; }
            }
        } else {
            $paints = $this->paints; $this->paints = false;
            try { $this->frame(1); } finally { $this->paints = $paints; }
        }
        if (!$parts) { $this->paint(); }
        $this->rt->pace($hz);
        return $repaint;
    }

    /** The list the last frame painted, null before any. */
    public function painted(): ?DisplayList
    {
        return $this->list;
    }

    /** Frames that only paint again paint the window whole, never in parts: to compare (mygo's noParts). */
    public bool $noParts = false;

    /** Paints everything, skipping nothing whose ink shows nowhere (mygo's noCull), to compare. */
    public bool $noCull = false;

    /** Paints the root into a display list over an FFI buffer of $capacity quads, clipped to the window as the Window does, and returns the list (its ->n is the quad count). */
    public function paint(int $capacity = 65536): DisplayList
    {
        $ffi = \FFI::cdef();
        $this->list ??= new DisplayList($ffi->new('float[' . ($capacity * DisplayList::FLOATS_PER_RECT) . ']'), $capacity, $ffi->new('float[' . (4096 * DisplayList::FLOATS_PER_TRANSFORM) . ']'), 4096);
        $this->list->pathQuad ??= fn(Path $p, float $w, ?Closure $visible = null) => ($b = $p->bounds()) === null ? [] : [[$b[0], $b[1], $b[2] - $b[0], $b[3] - $b[1], 0, 0]]; // a path's quad is its bounds; no mask
        $this->list->reset(); $this->list->zoom = $this->rt->frameZoom;
        $this->rt->webPlaces = []; $this->rt->videoPlaces = []; $this->rt->materialPlaces = []; $this->rt->underPaints = []; $this->rt->overPaints = []; // as the window's paint
        $this->list->pushClip(0, 0, $this->w / $this->rt->frameZoom, $this->h / $this->rt->frameZoom);
        $root = $this->root ?? $this->frame();
        $this->list->cull = !$this->noCull; // as the window's paint, which skips what shows nowhere
        $this->rt->painting = true;
        Painter::beginParts($this->rt, $this->list);
        try {
            Painter::paint($root, $this->list, $this->metrics, $this->theme, $this->rt);
        } finally {
            $this->list->cull = false;
            $this->rt->painting = false;
        }
        if (($in = $this->rt->inspector) !== null && $in->open) {
            \MyPHP\UI\InspectorOverlay::paint($this->list, $this->metrics, $this->rt, $in, $this->h / $this->rt->frameZoom);
            $in->lap(2);
        }
        Painter::dragCopy($this->list, $this->metrics, $this->theme, $this->rt);
        Painter::tooltip($root, $this->list, $this->metrics, $this->theme, $this->rt); // the text tips, last, as the window paints them
        Painter::endParts($this->rt, $this->list);
        return $this->list;
    }

    /** A left click (down and up) at ($x, $y), with $mods (Key::SHIFT, Key::COMMAND) and $clicks (2 for a double click). */
    public function click(float $x, float $y, int $mods = 0, int $clicks = 1): Node
    {
        $this->input->mouse($x, $y);
        $this->input->push(['down', $x, $y, 0, $clicks, $mods]);
        $this->input->push(['up', $x, $y, 0, $clicks, $mods]);
        return $this->frame();
    }

    /** A notch of a mouse wheel scrolling ($dx, $dy) with the pointer at ($x, $y), whose scroll container glides there over a moment (Runtime::wheelScroll), then a frame. */
    public function wheel(float $x, float $y, float $dx, float $dy): Node
    {
        $this->input->mouse($x, $y);
        $this->input->push(['scroll', $x, $y, $dx, $dy, false]);
        return $this->frame(1);
    }

    /** The mouse pressed at ($x, $y), with $mods held, and held. */
    public function press(float $x, float $y, int $mods = 0): Node
    {
        $this->input->mouse($x, $y);
        $this->input->push(['down', $x, $y, 0, 1, $mods]);
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
        if ($this->input->textKey($code, $chars, $mods)) { return $this->frame(); } // the field's onTextKey took it, as the view offers it
        if ($this->input->push(['key', $chars, $code, $mods])) { return $this->frame(); } // taken as it came
        if ($this->input->client !== null) { // to a text input client, which the view gives no commands; with input methods off, the key types its characters
            if (!$this->input->inputMethod && $chars !== '' && ord($chars[0]) >= 0x20 && ord($chars[0]) !== 0x7f) { $this->input->client->replaceText(null, $chars); }
            return $this->frame();
        }
        if (!($this->input->focus?->command($selector) ?? false)) {
            $this->input->push(['command', $selector]);
        }
        return $this->frame();
    }

    /** A key pressed and held, as a game takes it (mygo's Tester.KeyDown): its release comes with keyUp(). */
    public function keyDown(int $code): Node
    {
        $this->input->push(['key', '', $code, 0]);
        return $this->frame();
    }

    public function keyUp(int $code): Node
    {
        $this->input->push(['keyup', '', $code, 0]);
        return $this->frame();
    }

    /**
     * A shortcut's key with modifiers (Cmd+G), as the window sends it: the key event with the code of the key that
     * types $char on a US keyboard, so that shortcut(Key::G, …) and shortcut('g', …) both take it; Command and one of
     * the Edit menu's keys (A, C, X, V, Z, Y) its command instead, as the Edit menu and the Linux window send them, which
     * a focused field takes (Cmd+A selects its text) and the rest turn back into the key (mygo's Tester.Key).
     */
    public function shortcut(string $char, int $mods): Node
    {
        $code = self::usCode($char);
        if (($mods & Key::COMMAND) !== 0 && $code !== null && ($edit = \MyPHP\Platform\Linux\Keys::editCommand($code, $mods)) !== null) {
            $this->input->push(['edit', $edit]);
            return $this->frame();
        }
        $this->input->push(['key', $char, $code ?? -1, $mods]);
        return $this->frame();
    }

    /** The code of the key that types $char on a US keyboard (Key::usChar's), or null. */
    private static function usCode(string $char): ?int
    {
        $char = strtolower($char);
        if ($char === ' ') { return Key::SPACE; }
        for ($code = 0; $code < 128; $code++) { if (Key::usChar($code) === $char) { return $code; } }
        return null;
    }

    /** Text typed: into the focused field's TextState, as the view does, else inserted at the focused control. */
    public function type(string $s): Node
    {
        if ($this->input->push(['key', $s, 0, 0])) { return $this->frame(); }
        if ($this->input->client !== null) {
            $this->input->client->replaceText(null, $s); // the view's text input client
        } elseif ($this->input->focus !== null) {
            $this->input->focus->insert($s);
        } else {
            $this->input->composing = ''; // committed, as the view's insertText
            $this->input->push(['insert', $s]);
        }
        return $this->frame();
    }

    /** An input method composing $s, its caret at UTF-16 unit $caret, as the view's text input client gets it: nothing while input methods are off. */
    public function compose(string $s, int $caret): Node
    {
        if ($this->input->client !== null && $this->input->inputMethod) { $this->input->client->setMarkedText(null, $s, new \MyPHP\UI\TextInputRange($caret, $caret)); }
        elseif ($this->input->focus === null && $this->input->textCaret !== null) { $this->input->composing = $s; $this->input->push(['compose', $s, mb_strlen(substr($s, 0, \MyPHP\UI\TextState::byteIndex($s, $caret)), 'UTF-8')]); } // a node taking text (Node::textCaret)
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

    /** The rect node $n had last frame: x, y, w, h, in the window's points (zoomed, Context::setZoom: the content's times the zoom, as mygo's Tester.Find). */
    public function rect(Node $n): array { return $this->rt->windowRect($this->rt->rectOf($n->id) ?? throw new \RuntimeException('no rect for ' . self::label($n))); }

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

    /** The texts the tree shows, in order: of texts, buttons and the like, but not in an inert page. @return list<string> */
    public function texts(?Node $from = null): array
    {
        $from ??= $this->root;
        if ($from?->inert) { return []; } // a page going away has nothing to find
        $shown = $from?->kind === Node::RICH ? $from->plainText() : $from?->text; // a rich text's, then that of the text inside it
        $out = $shown !== null && $shown !== '' ? [$shown] : [];
        foreach ($from?->kids ?? [] as $k) { array_push($out, ...$this->texts($k)); }
        return $out;
    }

    /** The first node showing $text, or null. */
    public function byText(string $text, ?Node $from = null): ?Node
    {
        $from ??= $this->root;
        if ($from === null || $from->inert) { return null; }
        if (($from->kind === Node::RICH ? $from->plainText() : $from->text) === $text) { return $from; }
        foreach ($from->kids as $k) { if (($r = $this->byText($text, $k)) !== null) { return $r; } }
        return null;
    }

    /** A click on the switch titled $label, which must be there: a form's switch takes a tap, its title none, as UIKit's and AppKit's. */
    public function clickSwitch(string $label): Node
    {
        $find = function (Node $n) use (&$find, $label): ?Node {
            if ($n->kind === Node::SWITCH && $n->label === $label) { return $n; }
            foreach ($n->kids as $k) { if (($f = $find($k)) !== null) { return $f; } }
            return null;
        };
        return $this->clickOn(($this->root !== null ? $find($this->root) : null) ?? throw new \RuntimeException("no switch $label"));
    }

    /** A click in the middle of the node showing $text, which must be there. */
    public function clickText(string $text): Node
    {
        return $this->clickOn($this->byText($text) ?? throw new \RuntimeException("no $text in " . json_encode($this->texts(), JSON_UNESCAPED_UNICODE)));
    }

    /**
     * The first node, in the order of the tree, labeled $label for assistive technology or showing it, or null
     * (mygo's Tester.Find): its label(), else the name its field gives it (a control in field() is named by the
     * label, which comes before it), else its text. Under $from, what is inside it only.
     */
    public function byLabel(string $label, ?Node $from = null): ?Node
    {
        $from ??= $this->root;
        if ($from === null || $from->inert) { return null; }
        $f = $from->nameFrom;
        $name = $from->label !== '' ? $from->label : ($f !== null ? ($f->label !== '' ? $f->label : ($f->kind === Node::RICH ? $f->plainText() : (string)$f->text)) : '');
        if ($name === '') { $name = $from->kind === Node::RICH ? $from->plainText() : (string)$from->text; }
        if ($name === $label) { return $from; }
        foreach ($from->kids as $k) { if (($r = $this->byLabel($label, $k)) !== null) { return $r; } }
        return null;
    }

    /** A click in the middle of the node labeled $label (byLabel), which must be there. */
    public function clickLabel(string $label): Node
    {
        return $this->clickOn($this->byLabel($label) ?? throw new \RuntimeException("no $label in " . json_encode($this->texts(), JSON_UNESCAPED_UNICODE)));
    }

    /** The focused node, or null. */
    public function focused(): ?Node { return $this->rt->focus === null ? null : $this->find($this->rt->focus); }

    /** A short name for a node: its kind, its text or its first child's. */
    public static function label(?Node $n): string
    {
        if ($n === null) { return 'none'; }
        $k = ['C', 'T', 'B', 'X', 'I', 'A', 'M', 'R', 'CB', 'RD', 'SL', 'SE', 'P', 'SW', 'SP'][$n->kind] ?? (string)$n->kind;
        $shown = fn(?Node $n): ?string => $n === null ? null : ($n->kind === Node::RICH ? $n->plainText() : $n->text); // a text laid out as rich text (tabular digits) too
        $t = $shown($n) ?? ($shown($n->kids[0] ?? null) ?? '');
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
        $s = null;
        self::example('widgets', 'App::whenReady', [], $s);
        $view = self::view('widgets', $s);
        return [static function (Context $c) use ($view) { $view($c); }, &$s];
    }

    /**
     * The files example on folder $folder, loaded without its window and
     * menu: [$view (Closure(Context)), $files (its Files)].
     */
    public static function files(string $folder): array
    {
        $files = null;
        self::example('files', 'App::setMenu', [$folder], $files);
        return [self::view('files', $files), $files];
    }

    /** The piano example's view and its Piano, loaded without its window: [$view (Closure(Context)), $piano]. */
    public static function piano(): array
    {
        $p = null;
        self::example('piano', 'App::setName', [], $p);
        return [self::view('piano', $p), $p];
    }

    /**
     * Example $name loaded without its window: its file up to $stop, its
     * view, which each example's view.php returns, and its state $s:
     * [$view (Closure(Context), null without a state), $s].
     */
    public static function load(string $name, string $stop = 'App::setName', array $args = []): array
    {
        $s = null;
        self::example($name, $stop, $args, $s);
        return [is_object($s) ? self::view($name, $s) : null, $s]; // read up to $stop for its classes alone (flyout, todo), no state
    }

    /**
     * The view example $name's view.php (or $file) returns, run bound to $state as
     * Content::file runs it, in the example's namespace (self::example). @return Closure(Context): void
     */
    public static function view(string $name, object $state, string $file = 'view.php'): Closure
    {
        $view = Closure::bind(function () use ($name, $file) { return eval('?>' . Headless::viewSource($name, $file)); }, $state, (new \ReflectionClass($state))->isInternal() ? null : $state::class)();
        return static function (Context $c) use ($view, $state): void { $c->state = $state; \MyPHP\UI\Content::render($view, $c); }; // as Content::file: the pages of a Router's table bound to it too
    }

    /**
     * The helpers example $name's view.php makes before it returns its view
     * (its pages, its sidebar…), bound to $state, by name, the view as
     * 'view': for a test of one part. @return array<string, Closure>
     */
    public static function helpers(string $name, object $state): array
    {
        $src = preg_replace('/\nreturn (function|fn|static function)/', "\n\$view = $1", self::viewSource($name)) . "\nreturn array_filter(get_defined_vars(), fn(\$v) => \$v instanceof \\Closure);\n";
        return Closure::bind(function () use ($src) { return eval('?>' . $src); }, $state, (new \ReflectionClass($state))->isInternal() ? null : $state::class)();
    }

    /** Example $name's view.php in its namespace (self::example), its folder for __DIR__. @internal */
    public static function viewSource(string $name, string $file = 'view.php'): string
    {
        $path = __DIR__ . "/../examples/$name/$file";
        $src = file_get_contents($path);
        if ($src === false) { throw new \RuntimeException($path); }
        require_once __DIR__ . '/../src/UI/Declarative/functions.php';
        return str_replace(['declare(strict_types=1);', '__DIR__'], ['namespace MyPHP\\Examples\\' . self::namespaceOf($name) . '; use Closure, DateTimeImmutable, InvalidArgumentException, RuntimeException, Throwable;' . self::declarativeUses($src), var_export(dirname($path), true)], $src);
    }

    /** The global enums of the declarative views, which a view file in an example's namespace names as the app's global ones. */
    private const DECLARATIVE = ['View', 'Font', 'FontDesign', 'Weight', 'Color', 'Alignment', 'HorizontalAlignment', 'VerticalAlignment', 'TextAlignment', 'Edge', 'KeyEquivalent', 'EventModifiers', 'ButtonStyle', 'ButtonRole', 'ToggleStyle', 'PickerStyle', 'DatePickerStyle', 'DatePickerComponents', 'TextSelectability', 'TextFieldStyle', 'ListStyle', 'NavigationSplitViewVisibility', 'ColorScheme', 'DynamicTypeSize', 'TabBarMinimizeBehavior', 'PresentationDetent', 'KeyPressResult', 'KeyPress', 'GeometryProxy', 'ScrollViewProxy', 'SortOrder', 'Binding', 'ScrollGeometry', 'ScrollPosition', 'GridItem', 'UnitPoint', 'WindowDragGesture', 'Axis', 'TitleDisplayMode', 'NavigationPath', 'TabViewStyle', 'PlottableValue', 'MarkDimension', 'AxisMarks', 'StrokeStyle', 'MarkStackingMethod', 'InterpolationMethod', 'AnnotationPosition', 'AxisMarkPosition', 'Visibility', 'TimelineViewContext', 'Animation', 'AnyTransition', 'DragGesture', 'DragGestureValue', 'CGSize', 'CGPoint', 'KeyframeTimeline', 'GraphicsContext', 'Path', 'UnitCurve', 'CoordinateSpace', 'LayoutDirection'];

    /** The use line naming the declarative globals $src does not name of its own, for $src in an example's namespace. */
    private static function declarativeUses(string $src): string
    {
        preg_match_all('/^use [^;]+;/m', $src, $m);
        $named = preg_replace('/\\w+\\s+as\\s+/', '', implode(' ', $m[0])); // "Font as Face" names Face, not Font
        $global = array_filter(self::DECLARATIVE, fn(string $n) => !preg_match('/\\b' . $n . '\\b/', $named));
        return $global === [] ? '' : ' use ' . implode(', ', $global) . ';';
    }

    /** The namespace of example $name: "counter-native" is CounterNative. */
    private static function namespaceOf(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));
    }

    /**
     * Runs example $name's file up to $stop, with $args as its arguments, its
     * constants defined, its folder as __DIR__ and its functions in
     * MyPHP\Examples\<Name> (each example declares a view()); $state gets the
     * variable the file leaves ($s for widgets, $files for files).
     */
    /** @var array<string, true> the examples loaded, whose classes are declared */
    private static array $loaded = [];

    /** $src without its classes, enums, interfaces, traits, functions and constants at the top level: the code that makes the state. */
    private static function withoutDeclarations(string $src): string
    {
        $tk = \PhpToken::tokenize($src);
        $out = '';
        for ($i = 0, $n = count($tk), $depth = 0; $i < $n; $i++) {
            $t = $tk[$i];
            if ($depth === 0 && $t->is([T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT, T_FUNCTION]) && !($t->is(T_FUNCTION) && self::anonymous($tk, $i))) {
                $out = (string)preg_replace('/(?:final|abstract|readonly|\s|\/\*\*.*?\*\/)*$/s', '', $out); // its modifiers and doc comment go with it
                for ($open = 0, $i++; $i < $n; $i++) { // to the end of its body
                    if ($tk[$i]->id === 123 || $tk[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) { $open++; } // { and }, not a brace in a string's text ("delete {{$list}}")
                    elseif ($tk[$i]->id === 125 && --$open === 0) { break; }
                }
                continue;
            }
            if ($depth === 0 && $t->is(T_CONST)) { // a constant, defined already: to its semicolon
                for ($nest = 0; $i < $n && !($tk[$i]->text === ';' && $nest === 0); $i++) {
                    if (in_array($tk[$i]->text, ['(', '[', '{'], true)) { $nest++; } elseif (in_array($tk[$i]->text, [')', ']', '}'], true)) { $nest--; }
                }
                continue;
            }
            if ($t->id === 123 || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) { $depth++; } elseif ($t->id === 125) { $depth--; }
            $out .= $t->text;
        }
        return $out;
    }

    /** Whether the function keyword at $i makes a closure (function (…) or function &(…)), not a named function. @param list<\PhpToken> $tk */
    private static function anonymous(array $tk, int $i): bool
    {
        for ($j = $i + 1; $tk[$j]->is(T_WHITESPACE) || $tk[$j]->text === '&'; $j++) {}
        return $tk[$j]->text === '(';
    }

    private static function example(string $name, string $stop, array $args, mixed &$state): void
    {
        $path = __DIR__ . "/../examples/$name/main.php";
        $src = file_get_contents($path);
        if ($src === false) { throw new \RuntimeException($path); }
        $at = strpos($src, $stop); // 0 is a place: not ?:
        if ($at === false) { throw new \RuntimeException($stop); }
        $src = substr($src, 0, $at);
        // In a namespace of its own, the global classes the examples use (functions and constants fall back on their own) must be named.
        require_once __DIR__ . '/../src/UI/Declarative/functions.php';
        $src = str_replace(['declare(strict_types=1);', '__DIR__'], ['namespace MyPHP\\Examples\\' . self::namespaceOf($name) . '; use Closure, DateTimeImmutable, InvalidArgumentException, RuntimeException, Throwable;' . self::declarativeUses($src), var_export(dirname($path), true)], $src);
        // Its constants in its namespace too, as two examples name theirs alike (WINDOW_W); a line declaring several stays a const of the namespace.
        $src = preg_replace('/^const (\w+) = ((?:(?!, [A-Z_0-9]+ = ).)*);$/m', 'defined(__NAMESPACE__ . \'\\\\$1\') || define(__NAMESPACE__ . \'\\\\$1\', $2);', $src);
        if (isset(self::$loaded[$name])) { $src = self::withoutDeclarations($src); } // loaded before (another test): its classes are there, its state made anew
        self::$loaded[$name] = true;
        $argv = [$path, ...$args];
        $var = preg_match('~Content::file\(__DIR__ \. \'/view\.php\', \$(\w+)~', (string)file_get_contents($path), $m) ? $m[1] : 's'; // the state its view is bound to
        (static function () use ($src, $argv, &$state, $var) { eval('?>' . $src); $state = $s ?? $$var ?? $files ?? null; })();
    }
}

/**
 * Serves the files of $dir as a server of films does (testdata/range_server.php
 * under php -S: ranges, ?rate=, ?norange, ?fail); its base URL, and a
 * function that stops it. @return array{0: string, 1: \Closure(): void}
 */
function rangeServer(string $dir): array
{
    $port = freePort(); // nothing listens on it: an earlier test's server (its workers outlive it a moment) would answer of its own folder
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/testdata/range_server.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, null, ['PHP_CLI_SERVER_WORKERS' => '4', 'MYPHP_FILES' => $dir] + getenv());
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) { usleep(20000); }
    return ["http://127.0.0.1:$port", static function () use ($server): void { proc_terminate($server); }];
}

/**
 * A port of the loopback no server listens on, for a test's own: the system picks it. A port worked out from the pid
 * found another server now and then (a developer's own, on 19xxx), whose 404s failed the test.
 */
function freePort(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $error) ?: throw new \RuntimeException("no free port: $error");
    $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $port;
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

/** Fails unless $got is within 1e-3 of $want (mygo's closeFloat). */
function closeFloat(float $want, float $got, string $what): void
{
    check(abs($got - $want) <= 1e-3 * max(1.0, abs($want)), "$what: expected $want, got $got");
}

/** A harness whose frames happen at $now, nanoseconds (mygo's clockTester). */
function poseTester(\Closure $view, ?int &$now, float $w, float $h): Headless
{
    $now = 1_000_000 * 1_000_000_000;
    $t = new Headless($view, $w, $h);
    $t->rt->clock = function () use (&$now): int { return $now; };
    $t->frame(1);
    return $t;
}
