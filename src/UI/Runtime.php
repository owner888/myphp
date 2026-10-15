<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * Frame-to-frame state for immediate mode: last frame's rectangles by node
 * id, so that this frame's build can answer clicked()/hovered() before it
 * is laid out, and the input events this frame consumes.
 *
 * Node ids are the order of creation in the build, as in a view whose
 * structure does not change between frames; conditional UI keeps ids
 * stable with Context::key, and list rows are keyed by their index.
 */
final class Runtime
{
    /** @var array<int, array{0: float, 1: float, 2: float, 3: float}> id => x, y, w, h */
    private array $rects = [];
    /** @var array<int, Scrollable> id => scroll state, from last frame */
    private array $scrolls = [];
    /** @var array<int, array{0: bool, 1: bool}> id => scrolls horizontally, vertically */
    private array $scrollAxes = [];
    /** @var array<int, TextState> text areas by id, from last frame: the wheel scrolls them too */
    private array $areas = [];
    /** Set by a frame that needs another one right after (a list left a gap). */
    public bool $needsFrame = false;
    private array $clicks = [];     // [x, y] of left mouse-ups this frame
    private array $rightClicks = []; // [x, y] of right mouse-ups this frame
    private array $doubleClicks = []; // [x, y] of second presses this frame
    private bool $consumed = false;
    public int $passes = 0;
    /** The theme's scroll bar width, for hit-testing thumbs; Window sets it. */
    public float $scrollbarWidth = 6;
    /** Opens a URL with the system, for links; App sets it (App::openURL). */
    public Closure $openURL;
    /** @var array<int, string> nodes with tooltips by id, from last frame */
    private array $tips = [];
    /** When the pointer last moved, and the tip to show: text, x, y (set once the pointer rested on a node). */
    private float $pointerRestAt = 0;
    private array $lastPointer = [-1.0, -1.0];
    public ?array $tip = null;
    public const TIP_DELAY = 0.6;
    /** The pointer the window should show over the hovered node: 'arrow' or 'pointer' (links). */
    public string $cursor = 'arrow';
    private bool $animating = false;
    /** A thumb being dragged: its state, the pointer's last y and content points per thumb point. */
    private ?array $drag = null;
    private int $lastFrame = 0;
    /** @var array<int, ListState> id => lists whose rows can be chosen, from last frame */
    private array $lists = [];

    /** Keyboard focus: the node id, the ids that can take it (last frame, in order) and the fields. */
    public ?int $focus = null;
    private array $focusables = [];
    /** @var array<int, array{0: TextState, 1: float, 2: float, 3: float, 4: float, 5: float}> id => state, text x, text y, line h, size, inner width (text areas) */
    private array $fields = [];
    /** Caret blinking: when it last restarted, and what the focused field looked like then. */
    private float $blinkStart = 0;
    private ?array $blinkSeen = null;
    public const BLINK = 0.5;
    /** After this long without edits the caret stays on and stops asking for frames. */
    public const BLINK_FOR = 30.0;
    private ?int $activated = null;
    /** Text drawn in a field starts this far from its left edge. */
    public const FIELD_INSET = 8.0;

    public function __construct(public Input $input, private ?TextMetrics $metrics = null)
    {
        $input->metrics = $metrics;
        $this->openURL = fn(string $url) => null;
    }

    /**
     * Builds the frame: drains input, runs $build (again if a click changed
     * state while building, at most three times), records the layout.
     * $build(Context) must build and lay out the tree and return its root.
     */
    public function frame(callable $build): Node
    {
        $this->clicks = [];
        $this->rightClicks = [];
        $this->doubleClicks = [];
        $this->slid = [];
        $this->steps = [];
        $this->activated = null;
        $now = hrtime(true);
        $dt = $this->lastFrame === 0 ? 1 / 60 : min(0.1, ($now - $this->lastFrame) / 1e9);
        $this->lastFrame = $now;
        $pointer = [$this->input->mouseX, $this->input->mouseY];
        if ($pointer !== $this->lastPointer) {
            $this->lastPointer = $pointer;
            $this->pointerRestAt = $now / 1e9;
            $this->tip = null;
        }
        foreach ($this->scrolls as $state) {
            $state->glide($dt);
        }
        foreach ($this->input->events as $e) {
            switch ($e[0]) {
                case 'down':
                    $this->input->mouseDown = true;
                    if ($e[3] !== 0) {
                        break;
                    }
                    if ($this->grabThumb($e[1], $e[2])) {
                        break;
                    }
                    if ($e[4] >= 2) {
                        $this->doubleClicks[] = [$e[1], $e[2]];
                    }
                    $this->pressAt($e[1], $e[2]);
                    foreach ($this->sliders as $id => $r) {
                        if ($e[1] >= $r[0] && $e[1] < $r[0] + $r[2] && $e[2] >= $r[1] && $e[2] < $r[1] + $r[3]) {
                            $this->sliding = $id;
                            $this->slideTo($id, $e[1]);
                        }
                    }
                    break;
                case 'up':
                    $this->input->mouseDown = false;
                    $this->sliding = null;
                    if ($this->drag !== null) {
                        $this->drag = null; // releasing the thumb is not a click
                    } elseif ($e[3] === 1) { $this->rightClicks[] = [$e[1], $e[2]]; } else { $this->clicks[] = [$e[1], $e[2]]; }
                    break;
                case 'drag':
                    if ($this->sliding !== null && isset($this->sliders[$this->sliding])) {
                        $this->slideTo($this->sliding, $e[1]);
                    } elseif ($this->drag !== null) {
                        [$state, $last, $ratio, $horizontal] = $this->drag;
                        if ($horizontal) {
                            $state->scrollByX(($e[1] - $last) * $ratio);
                            $this->drag[1] = $e[1];
                        } else {
                            $state->scrollBy(($e[2] - $last) * $ratio);
                            $this->drag[1] = $e[2];
                        }
                    } elseif ($this->focus !== null && isset($this->fields[$this->focus]) && $this->input->mouseDown) {
                        $this->placeCaret($this->focus, $e[1], $e[2], true);
                    }
                    break;
                case 'scroll': $this->scrollAt($e[1], $e[2], $e[3], $e[4]); break;
                case 'command': $this->command($e[1]); break;
                case 'insert':
                    if ($e[1] === ' ' && $this->focus !== null && !isset($this->fields[$this->focus])) {
                        $this->activated = $this->focus; // space presses a focused button
                    }
                    break;
            }
        }
        $this->input->events = [];
        for ($pass = 0; $pass < 3; $pass++) {
            $this->consumed = false;
            $root = $build();
            $this->passes = $pass + 1;
            if (!$this->consumed) {
                break;
            }
            $this->clicks = [];
            $this->rightClicks = [];
            $this->doubleClicks = [];
        }
        $this->rects = [];
        $this->sliders = [];
        $this->scrolls = [];
        $this->scrollAxes = [];
        $this->areas = [];
        $this->tips = [];
        $this->cursor = 'arrow';
        $this->animating = false;
        $this->focusables = [];
        $this->fields = [];
        $this->lists = [];
        $this->needsFrame = false;
        $this->record($root);
        $this->needsFrame = $this->needsFrame || $this->animating;
        // A tip: the pointer rested on a node with one for TIP_DELAY.
        $over = null;
        foreach ($this->tips as $id => $text) {
            if ($this->hovered($id)) {
                $over = [$id, $text];
            }
        }
        if ($over !== null && $this->tip === null && $now / 1e9 - $this->pointerRestAt >= self::TIP_DELAY) {
            $this->tip = [$over[1], $this->input->mouseX, $this->input->mouseY];
        }
        $this->tipPending = $over !== null && $this->tip === null;
        if ($this->focus !== null && !isset($this->rects[$this->focus])) {
            $this->focus = null; // the focused node was not built this frame
        }
        $this->input->focus = null;
        $this->input->caretAt = null;
        if ($this->focus !== null && isset($this->fields[$this->focus])) {
            [$state, $tx, $ty, $lh, $size, $width] = $this->fields[$this->focus];
            $this->input->focus = $state;
            $this->input->focusText = [$tx, $ty, $lh, $size];
            if ($state->multiline && $this->metrics !== null) {
                $m = $this->metrics;
                $this->input->caretAt = function (int $byte) use ($state, $m, $size, $width, $tx, $ty, $lh): array {
                    [$x, $y] = $state->layout($m, $size, $width)->caret($m, $byte);
                    return [$tx + $x, $ty + $y - $state->scrollY, 1.0, $lh];
                };
            }
            $seen = [$state, $state->text, $state->cursor, $state->anchor, $state->marked];
            if ($this->blinkSeen !== $seen) {
                $this->blinkSeen = $seen;
                $this->blinkStart = $now / 1e9;
            }
        } else {
            $this->blinkSeen = null;
        }
        return $root;
    }

    /** Whether the focused field's caret is in the shown half of its blink (always, once it has rested a while). */
    public function caretVisible(): bool
    {
        $phase = hrtime(true) / 1e9 - $this->blinkStart;
        return $phase >= self::BLINK_FOR || ((int)floor($phase / self::BLINK)) % 2 === 0;
    }

    private bool $tipPending = false;

    /** Seconds until the window should draw a frame of its own accord (a caret toggle, a tip due), or null. */
    public function nextWake(): ?float
    {
        $blink = $this->nextBlink();
        if (!$this->tipPending) {
            return $blink;
        }
        $tip = max(0.0, self::TIP_DELAY - (hrtime(true) / 1e9 - $this->pointerRestAt));
        return $blink === null ? $tip : min($blink, $tip);
    }

    /** Seconds until the caret next toggles, or null when nothing blinks. */
    public function nextBlink(): ?float
    {
        if ($this->blinkSeen === null) {
            return null;
        }
        $phase = hrtime(true) / 1e9 - $this->blinkStart;
        return $phase >= self::BLINK_FOR ? null : self::BLINK - fmod($phase, self::BLINK);
    }

    private function record(Node $n): void
    {
        $this->rects[$n->id] = [$n->x, $n->y, $n->w, $n->h];
        if ($n->kind === Node::BUTTON || $n->kind === Node::INPUT || $n->kind === Node::TEXTAREA || $n->focusable) {
            $this->focusables[] = $n->id;
            if ($n->focusable && $n->id === $this->focus) {
                $n->focused = true; // the painter shows the ring; buttons and fields set this while building
            }
        }
        if ($n->kind === Node::SLIDER) {
            $this->sliders[$n->id] = [$n->x, $n->y, $n->w, $n->h];
        }
        if ($n->tooltip !== null) {
            $this->tips[$n->id] = $n->tooltip;
        }
        if ($n->url !== null && $this->hovered($n->id)) {
            $this->cursor = 'pointer';
        }
        if ($n->kind === Node::PROGRESS && $n->fraction < 0) {
            $this->animating = true; // an indeterminate bar moves every frame
        }
        if ($n->wrapWidth !== null && abs($n->w - $n->wrapWidth) > 0.5) {
            $this->needsFrame = true; // wrapped at last frame's width; this frame's differs
        }
        if ($n->kind === Node::INPUT && $n->edit !== null) {
            [, $lh] = $this->metrics?->measure('X', $n->fontSize) ?? [0, $n->h];
            $this->fields[$n->id] = [$n->edit, $n->x + self::FIELD_INSET, $n->y + ($n->h - $lh) / 2, $lh, $n->fontSize, $n->w - 2 * self::FIELD_INSET];
        } elseif ($n->kind === Node::TEXTAREA && $n->edit !== null) {
            $this->areas[$n->id] = $n->edit;
            [, $lh] = $this->metrics?->measure('X', $n->fontSize) ?? [0, $n->h];
            $this->fields[$n->id] = [$n->edit, $n->x + self::FIELD_INSET, $n->y + self::FIELD_INSET, $lh, $n->fontSize, $n->w - 2 * self::FIELD_INSET];
        }
        if ($n->scroll !== null) {
            $this->scrolls[$n->id] = $n->scroll;
            $this->scrollAxes[$n->id] = [$n->scrollX, $n->scrollY];
            if ($n->scroll->moving() || ($n->scroll instanceof ListState && $n->scroll->gap > 0)) {
                $this->needsFrame = true;
            }
            if ($n->scroll instanceof ListState && $n->scroll->selected !== null) {
                $this->focusables[] = $n->id;
                $this->lists[$n->id] = $n->scroll;
            }
        }
        foreach ($n->kids as $k) {
            $this->record($k);
        }
    }

    public function focused(int $id): bool
    {
        return $this->focus === $id;
    }

    /** A left press: focuses the innermost field, button or list under it, placing the caret in a field. */
    private function pressAt(float $x, float $y): void
    {
        $best = null; $bestArea = INF;
        foreach ($this->focusables as $id) {
            $r = $this->rects[$id];
            if ($x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3] && $r[2] * $r[3] < $bestArea) {
                $best = $id; $bestArea = $r[2] * $r[3];
            }
        }
        if ($best === null) {
            return;
        }
        $this->focus = $best;
        if (isset($this->fields[$best])) {
            $this->placeCaret($best, $x, $y, false);
        }
    }

    /** A press on a scroll bar's thumb starts dragging it; the thumb takes a few points around it. */
    private function grabThumb(float $x, float $y): bool
    {
        $g = Scrollbar::GRAB;
        foreach ($this->scrolls as $id => $state) {
            $r = $this->rects[$id];
            [$horizontal, $vertical] = $this->scrollAxes[$id];
            foreach ([[false, $vertical], [true, $horizontal]] as [$axis, $on]) {
                if (!$on) {
                    continue;
                }
                $extent = $axis ? $state->extentX() : $state->extent();
                $t = Scrollbar::thumb($r, $extent, $this->scrollbarWidth, $axis);
                if ($t === null || $x < $t[0] - $g || $x >= $t[0] + $t[2] + $g || $y < $t[1] - $g || $y >= $t[1] + $t[3] + $g) {
                    continue;
                }
                $state->stop();
                $this->drag = [$state, $axis ? $x : $y, Scrollbar::pointsPerThumbPoint($r, $extent, $axis), $axis];
                return true;
            }
        }
        return false;
    }

    /** Whether this scroll state's thumb is being dragged. */
    public function dragging(Scrollable $state): bool
    {
        return $this->drag !== null && $this->drag[0] === $state;
    }

    private function placeCaret(int $id, float $x, float $y, bool $extend): void
    {
        [$state, $tx, $ty, , $size, $width] = $this->fields[$id];
        if ($this->metrics === null) {
            return;
        }
        if ($state->multiline) {
            $byte = $state->layout($this->metrics, $size, $width)->hit($this->metrics, $x - $tx, $y - $ty + $state->scrollY);
        } else {
            $byte = $this->metrics->indexForX($state->text, $size, $x - $tx);
        }
        $state->setCursor($byte, $extend);
    }

    /** Line-wise commands of a focused text area: moves by wrapped lines, Enter inserts a line. */
    private function areaCommand(string $selector): bool
    {
        if ($this->focus === null || !isset($this->fields[$this->focus]) || $this->metrics === null) {
            return false;
        }
        [$state, , , $lh, $size, $width] = $this->fields[$this->focus];
        if (!$state->multiline) {
            return false;
        }
        $extend = str_ends_with($selector, 'AndModifySelection:');
        $base = $extend ? substr($selector, 0, -strlen('AndModifySelection:')) . ':' : $selector;
        if (!in_array($base, TextState::BY_LINE, true)) {
            return false;
        }
        $m = $this->metrics;
        $layout = $state->layout($m, $size, $width);
        $line = $layout->lineOf($state->cursor);
        [$a, $b] = $layout->lines[$line];
        $end = $b > $a && $state->text[$b - 1] === "\n" ? $b - 1 : $b;
        $page = max(1, (int)(($this->rects[$this->focus][3] - 2 * self::FIELD_INSET) / $lh) - 1);
        $toLine = function (int $l) use ($state, $layout, $m, $extend) {
            [$x, ] = $layout->caret($m, $state->cursor);
            $state->setCursor($layout->hit($m, $x, ($l + 0.5) * $layout->lineHeight), $extend);
        };
        switch ($base) {
            case 'insertNewline:': $state->insert("\n"); break;
            case 'moveUp:': if ($line === 0) { $state->setCursor(0, $extend); } else { $toLine($line - 1); } break;
            case 'moveDown:': if ($line === count($layout->lines) - 1) { $state->setCursor(strlen($state->text), $extend); } else { $toLine($line + 1); } break;
            case 'pageUp:': $toLine(max(0, $line - $page)); break;
            case 'pageDown:': $toLine(min(count($layout->lines) - 1, $line + $page)); break;
            case 'moveToBeginningOfLine:': case 'moveToLeftEndOfLine:': $state->setCursor($a, $extend); break;
            case 'moveToEndOfLine:': case 'moveToRightEndOfLine:': $state->setCursor($end, $extend); break;
            case 'deleteToBeginningOfLine:': if (!$state->hasSelection()) { $state->anchor = $a; } $state->insert(''); break;
        }
        return true;
    }

    /** Text system commands the field did not take: focus moves, activation, list navigation. */
    private function command(string $selector): void
    {
        if ($this->areaCommand($selector)) {
            return;
        }
        if ($this->focus !== null && isset($this->lists[$this->focus])) {
            $l = $this->lists[$this->focus];
            $page = max(1, (int)($l->viewport / max(1.0, $l->estimate)) - 1);
            switch ($selector) {
                case 'moveUp:': $l->choose(max(0, $l->selected - 1)); return;
                case 'moveDown:': $l->choose($l->selected + 1); return;
                case 'pageUp:': case 'scrollPageUp:': $l->choose(max(0, $l->selected - $page)); return;
                case 'pageDown:': case 'scrollPageDown:': $l->choose($l->selected + $page); return;
                case 'moveToBeginningOfDocument:': case 'scrollToBeginningOfDocument:': $l->choose(0); return;
                case 'moveToEndOfDocument:': case 'scrollToEndOfDocument:': $l->choose($l->count - 1); return;
                case 'insertNewline:': if ($l->selected >= 0) { $l->submit(); } return;
            }
        }
        if ($this->focus !== null && isset($this->sliders[$this->focus])) {
            switch ($selector) {
                case 'moveLeft:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) - 1; return;
                case 'moveRight:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) + 1; return;
            }
        }
        switch ($selector) {
            case 'insertTab:': $this->moveFocus(1); break;
            case 'insertBacktab:': $this->moveFocus(-1); break;
            case 'cancelOperation:': $this->focus = null; break;
            case 'insertNewline:':
                if ($this->focus !== null && !isset($this->fields[$this->focus])) {
                    $this->activated = $this->focus;
                }
                break;
        }
    }

    private function moveFocus(int $dir): void
    {
        if ($this->focusables === []) {
            return;
        }
        $i = $this->focus === null ? -1 : (int)array_search($this->focus, $this->focusables, true);
        $n = count($this->focusables);
        $this->focus = $this->focusables[(($i + $dir) % $n + $n) % $n];
        if (isset($this->fields[$this->focus])) {
            $this->fields[$this->focus][0]->selectAll();
        }
    }

    /** Whether a mouse-up this frame landed on the node this id had last frame, or Enter/Space pressed it while focused. */
    public function clicked(int $id): bool
    {
        if ($this->activated === $id) {
            $this->activated = null;
            $this->consumed = true;
            return true;
        }
        $r = $this->rects[$id] ?? null;
        if ($r === null) {
            return false;
        }
        foreach ($this->clicks as $i => [$x, $y]) {
            if ($x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3]) {
                unset($this->clicks[$i]);
                $this->consumed = true;
                return true;
            }
        }
        return false;
    }

    /** Whether a second press of a double click this frame landed on the node this id had last frame. */
    public function doubleClicked(int $id): bool
    {
        $r = $this->rects[$id] ?? null;
        if ($r === null) {
            return false;
        }
        foreach ($this->doubleClicks as $i => [$x, $y]) {
            if ($x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3]) {
                unset($this->doubleClicks[$i]);
                $this->consumed = true;
                return true;
            }
        }
        return false;
    }

    /** Whether a right mouse-up this frame landed on the node this id had last frame. */
    public function rightClicked(int $id): bool
    {
        $r = $this->rects[$id] ?? null;
        if ($r === null) {
            return false;
        }
        foreach ($this->rightClicks as $i => [$x, $y]) {
            if ($x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3]) {
                unset($this->rightClicks[$i]);
                $this->consumed = true;
                return true;
            }
        }
        return false;
    }

    /** The rect this node had last frame: x, y, w, h. */
    public function rectOf(int $id): ?array
    {
        return $this->rects[$id] ?? null;
    }

    /** @var array<int, array{0: float, 1: float, 2: float, 3: float}> sliders by id, from last frame */
    private array $sliders = [];
    private ?int $sliding = null;
    /** @var array<int, float> id => where the pointer set a slider this frame, as a share of its width */
    private array $slid = [];
    /** @var array<int, int> id => arrow steps on a focused slider this frame */
    private array $steps = [];

    /** Where the pointer put slider $id this frame, 0..1, or null. */
    public function slid(int $id): ?float
    {
        return $this->slid[$id] ?? null;
    }

    /** How many steps the arrow keys moved slider $id this frame. */
    public function stepped(int $id): int
    {
        return $this->steps[$id] ?? 0;
    }

    private function slideTo(int $id, float $x): void
    {
        $r = $this->sliders[$id];
        $this->slid[$id] = max(0.0, min(1.0, ($x - $r[0]) / max(1.0, $r[2])));
    }

    /** The width this node had last frame, for text that wraps at the width the layout gives it. */
    public function widthOf(int $id): ?float
    {
        return isset($this->rects[$id]) ? $this->rects[$id][2] : null;
    }

    public function hovered(int $id): bool
    {
        $r = $this->rects[$id] ?? null;
        $x = $this->input->mouseX; $y = $this->input->mouseY;
        return $r !== null && $this->input->mouseInside
            && $x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3];
    }

    /** Scrolls the innermost scrolling container under (x, y) by the wheel's dx and dy points, on the axes it scrolls. */
    private function scrollAt(float $x, float $y, float $dx, float $dy): void
    {
        $best = null; $bestArea = INF; $axes = [false, true];
        foreach ($this->scrolls as $id => $state) {
            $r = $this->rects[$id];
            if ($x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3] && $r[2] * $r[3] < $bestArea) {
                $best = $state; $bestArea = $r[2] * $r[3]; $axes = $this->scrollAxes[$id];
            }
        }
        foreach ($this->areas as $id => $state) { // a text area inside a scrolling container takes the wheel over it
            $r = $this->rects[$id];
            if ($x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3] && $r[2] * $r[3] < $bestArea) {
                $best = $state; $bestArea = $r[2] * $r[3];
            }
        }
        if ($best === null) {
            return;
        }
        if ($best instanceof TextState) {
            $best->scrollY = max(0.0, $best->scrollY - $dy); // the painter clamps it to the text
            return;
        }
        $best->stop();
        if ($axes[1] && $dy != 0) {
            $best->scrollBy(-$dy);
        }
        if ($axes[0] && $dx != 0) {
            $best->scrollByX(-$dx);
        }
    }
}
