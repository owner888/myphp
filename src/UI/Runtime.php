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
    /** Moves the window with the mouse (1 click) or zooms or minimizes it (2) for a press on a Node::dragWindow handle; the window sets it. */
    public ?Closure $dragWindow = null;
    /** The drag handles of last frame (id => rect), and the links (ids), which keep their presses from a handle. */
    private array $handles = [], $links = [];
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
    /** Steppers built this frame, by id, for the arrow keys. */
    private array $steppers = [];
    /** Home (-1) or End (1) pressed on a focused stepper this frame, by id. */
    private array $jumps = [];
    /** The press the mouse is still down from: [x, y, start seconds, ticks given, on a stepper, last x, last y dragged() reported, (7) the node being dragged], or null. */
    private ?array $hold = null;
    /** Where the mouse went down this frame, for pressed(); a popover closes on a press outside it. */
    private array $presses = [];
    /** Keys pressed this frame: [characters, key code, modifiers, taken by a focused control]. */
    private array $keys = [];
    /** Each node's place in last frame's record order, and the place of the last node that blocks what was built before it (a modal's backdrop): nodes before it are inert. */
    private array $order = [];
    private int $barrier = 0, $counter = 0;
    /** The overlays of the frame (a popover, a menu, a combobox's options: nodes placed at the root with Node::at), as [order, x, y, w, h]: a point in one reaches no node built before it. */
    private array $overlays = [];
    /** Focus groups: each focusable's group id, the groups' orientation, selects flag and member on, and the member each group focused last. */
    private array $memberOf = [], $groups = [], $groupLast = [];
    /** State a composite keeps across frames by its node's id (a sidebar's scroll offset and items), as mygo's local state. */
    private array $locals = [];
    /** Toasts showing: [message, seconds left, when last aged], oldest first, the newest TOASTS at most. */
    private array $toasts = [];
    public const TOASTS = 3, TOAST_FOR = 4.0;
    private const HOLD_DELAY = 0.4, HOLD_RATE = 0.08;
    /** A thumb being dragged: its state, the pointer's last y and content points per thumb point. */
    private ?array $drag = null;
    private int $lastFrame = 0;
    /** @var array<int, ListState> id => lists whose rows can be chosen, from last frame */
    private array $lists = [];

    /** Keyboard focus: the node id, the ids that can take it (last frame, in order) and the fields. */
    public ?int $focus = null;
    /** Whether the focus came from the keyboard (Tab, the arrows, Enter, Space) and so shows a ring, as mygo's focusVisible; a press hides it. */
    private bool $focusVisible = false;
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
    /** What was typed this frame at a focused control that is no field. */
    private string $typed = '';
    private float $typedAt = 0.0;
    /** Text drawn in a field starts this far from its left edge. */
    /** A field's padding, mygo's Padding(Space(1.5), Space(2.5)): FIELD_INSET at the sides, FIELD_INSET_Y above and below the text of an area. */
    public const FIELD_INSET = 10.0, FIELD_INSET_Y = 6.0;

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
        $this->jumps = [];
        $this->keys = [];
        $this->presses = [];
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
                    if ($this->handles !== [] && $this->dragWindow !== null && $this->onHandle($e[1], $e[2])) {
                        ($this->dragWindow)($e[4]); // a title bar the view draws: the window moves, or zooms on a double click
                        break;
                    }
                    if ($e[4] >= 2) {
                        $this->doubleClicks[] = [$e[1], $e[2]];
                    }
                    $this->presses[] = [$e[1], $e[2]];
                    $this->pressAt($e[1], $e[2]);
                    if ($e[4] >= 2 && $this->focus !== null && isset($this->fields[$this->focus])) {
                        $this->fields[$this->focus][0]->{$e[4] === 2 ? 'selectWord' : 'selectLine'}(); // a double click takes the word, a triple the line
                    }
                    $this->hold = [$e[1], $e[2], $now / 1e9, 0, false, $e[1], $e[2]];
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
                    $this->hold = null;
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
                case 'key': $this->keys[] = [$e[1], $e[2], $e[3] & Key::MODIFIERS, false]; break;
                case 'command': // what the text system made of the key just pushed; a focused control taking it keeps it from shortcut()
                    if ($this->command($e[1]) && $this->keys !== []) {
                        $this->keys[array_key_last($this->keys)][3] = true;
                    }
                    break;
                case 'insert':
                    if ($this->focus !== null && isset($this->fields[$this->focus]) && $this->keys !== []) {
                        $this->keys[array_key_last($this->keys)][3] = true; // typed into the field
                    } elseif ($e[1] === ' ' && $this->focus !== null && !isset($this->fields[$this->focus]) && hrtime(true) / 1e9 - $this->typedAt >= 1.0) {
                        $this->activated = $this->focus; // space presses a focused button; within a second of letters typed at it, it is typed too (mygo's typePause)
                        $this->focusVisible = true;
                        if ($this->keys !== []) { $this->keys[array_key_last($this->keys)][3] = true; }
                    } elseif ($this->focus !== null && !isset($this->fields[$this->focus])) {
                        $this->typed .= $e[1]; // typed at a focused control: a time input's segment takes digits (typed())
                        if ($this->keys !== []) { $this->keys[array_key_last($this->keys)][3] = true; }
                    }
                    break;
            }
        }
        $this->input->events = [];
        $this->steppers = []; // the keys above used last frame's; this frame's build registers again
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
        $this->activated = null; // taken by clicked() during the build, or dropped
        $this->typed = '';
        $this->rects = [];
        $this->order = [];
        $this->barrier = 0; $this->counter = 0; $this->overlays = []; $this->handles = []; $this->links = [];
        $this->sliders = [];
        $this->scrolls = [];
        $this->scrollAxes = [];
        $this->areas = [];
        $this->tips = [];
        $this->cursor = 'arrow';
        $this->animating = false;
        $this->focusables = [];
        $this->memberOf = []; $this->groups = [];
        $this->fields = [];
        $this->lists = [];
        $this->needsFrame = false;
        $this->record($root);
        if ($this->focus !== null && !$this->reachable($this->focus)) {
            $this->focus = null; // the focus was behind what now blocks it: Tab goes into that
        }
        $this->needsFrame = $this->needsFrame || $this->animating || ($this->hold[4] ?? false); // a held stepper keeps stepping
        // A tip: the pointer rested on a node with one for TIP_DELAY.
        $over = null;
        foreach ($this->tips as $id => $text) {
            if ($this->hovered($id)) {
                $over = [$id, $text];
            }
        }
        if ($over !== null && $this->tip === null && $now / 1e9 - $this->pointerRestAt >= self::TIP_DELAY) {
            $this->tip = [$over[1], ...$this->rects[$over[0]]]; // the text and the anchor's rect
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

    /** Seconds until the window should draw a frame of its own accord (a caret toggle, a tip due, a toast going), or null. */
    public function nextWake(): ?float
    {
        $wake = $this->nextBlink();
        if ($this->tipPending) {
            $tip = max(0.0, self::TIP_DELAY - (hrtime(true) / 1e9 - $this->pointerRestAt));
            $wake = $wake === null ? $tip : min($wake, $tip);
        }
        foreach ($this->toasts as $t) {
            $wake = $wake === null ? $t[1] : min($wake, $t[1]);
        }
        return $wake;
    }

    /** The state kept for node $id across frames, made by $make the first time. */
    public function local(int $id, Closure $make): mixed
    {
        return $this->locals[$id] ??= $make();
    }

    /** Presses node $id as a click would, from outside a build (a toolbar's overflow menu choosing it): clicked($id) reports it next frame. */
    public function press(int $id): void
    {
        $this->activated = $id;
    }

    /** Shows $message as a toast for TOAST_FOR seconds (mygo's Toast): one already showing shows anew; others stack above it, TOASTS at most. */
    public function toast(string $message): void
    {
        $now = hrtime(true) / 1e9;
        foreach ($this->toasts as $i => $t) {
            if ($t[0] === $message) {
                $this->toasts[$i] = [$message, self::TOAST_FOR, $now];
                return;
            }
        }
        $this->toasts[] = [$message, self::TOAST_FOR, $now];
        $this->toasts = array_slice($this->toasts, -self::TOASTS);
    }

    /**
     * The toasts showing, oldest first, aged by the time since they were
     * last asked for — unless $paused (the pointer rests on one), when
     * their time stops.
     * @return list<string>
     */
    public function toasts(bool $paused): array
    {
        $now = hrtime(true) / 1e9;
        foreach ($this->toasts as $i => $t) {
            if (!$paused) {
                $t[1] -= $now - $t[2];
            }
            $t[2] = $now;
            if ($t[1] <= 0) {
                unset($this->toasts[$i]);
            } else {
                $this->toasts[$i] = $t;
            }
        }
        $this->toasts = array_values($this->toasts);
        return array_column($this->toasts, 0);
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

    private function record(Node $n, ?Node $parent = null, int $group = 0): void
    {
        $this->rects[$n->id] = [$n->x, $n->y, $n->w, $n->h];
        $this->order[$n->id] = ++$this->counter;
        if ($n->dragsWindow) {
            $this->handles[$n->id] = [$n->x, $n->y, $n->w, $n->h];
        }
        if ($n->absolute && $parent !== null && $this->order[$parent->id] === 1) { // placed at the root: over what was built before it
            $this->overlays[] = [$this->counter, $n->x, $n->y, $n->w, $n->h];
        }
        if ($n->blocks) {
            $this->barrier = $this->counter;
        }
        if ($n->focusGroup !== 0 && $group === 0) { // groups inside a group are part of it
            $group = $n->id;
            $this->groups[$group] = ['orient' => $n->focusGroup, 'selects' => $n->groupSelects, 'on' => null];
        }
        if ($n->kind === Node::BUTTON || $n->kind === Node::INPUT || $n->kind === Node::TEXTAREA || $n->focusable) {
            $this->focusables[] = $n->id;
            if ($group !== 0) {
                $this->memberOf[$n->id] = $group;
                if ($n->on && $this->groups[$group]['on'] === null) { $this->groups[$group]['on'] = $n->id; }
            }
            if ($n->focusable && $n->id === $this->focus) {
                $n->focused = true; // buttons and fields set this while building
            }
        }
        if ($n->focused && $n->id === $this->focus) {
            $n->focusVisible = $this->focusVisible && !$n->ownRing; // the painter rings it, unless it rings itself
        }
        if ($n->kind === Node::SLIDER) {
            $this->sliders[$n->id] = [$n->x, $n->y, $n->w, $n->h];
        }
        if ($n->tooltip !== null) {
            $this->tips[$n->id] = $n->tooltip;
        }
        if ($n->url !== null) { $this->links[] = $n->id; }
        if ($n->url !== null && $this->hovered($n->id)) {
            $this->cursor = 'pointer';
        } elseif (($n->kind === Node::INPUT || $n->kind === Node::TEXTAREA) && $this->hovered($n->id)) {
            $this->cursor = 'ibeam';
        } elseif ($n->cursor !== null && $this->hovered($n->id)) {
            $this->cursor = $n->cursor;
        }
        if (($n->kind === Node::PROGRESS && $n->fraction < 0) || $n->kind === Node::SPINNER) {
            $this->animating = true; // an indeterminate bar or a spinner moves every frame
        }
        if ($n->wraps ? abs($n->w - $n->wrapWidth) > 0.5 : ($n->wrapWidth !== null && $parent !== null && abs($parent->w - 2 * $parent->padX() - $n->wrapWidth) > 0.5)) {
            $this->needsFrame = true; // wrapped at the width it, or its container, had last frame; this frame's differs
        }
        if ($n->kind === Node::INPUT && $n->edit !== null) {
            [, $lh] = $this->metrics?->measure('X', $n->fontSize) ?? [0, $n->h];
            $this->fields[$n->id] = [$n->edit, $n->x + self::FIELD_INSET + $n->insetL, $n->y + ($n->h - $lh) / 2, $lh, $n->fontSize, $n->w - 2 * self::FIELD_INSET - $n->insetL - $n->insetR];
        } elseif ($n->kind === Node::TEXTAREA && $n->edit !== null) {
            $this->areas[$n->id] = $n->edit;
            [, $lh] = $this->metrics?->measure('X', $n->fontSize) ?? [0, $n->h];
            $this->fields[$n->id] = [$n->edit, $n->x + self::FIELD_INSET, $n->y + self::FIELD_INSET_Y, $lh, $n->fontSize, $n->w - 2 * self::FIELD_INSET];
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
            $this->record($k, $n, $group);
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
            if ($this->under($id, $x, $y) && $r[2] * $r[3] < $bestArea) {
                $best = $id; $bestArea = $r[2] * $r[3];
            }
        }
        if ($best === null) {
            return;
        }
        if ($best !== $this->focus) {
            $this->focus = $best;
            $this->focusVisible = false;
        }
        if (isset($this->fields[$best])) {
            $this->placeCaret($best, $x, $y, false);
        }
    }

    /** A press on a scroll bar's thumb starts dragging it; the thumb takes a few points around it. */
    private function grabThumb(float $x, float $y): bool
    {
        $g = Scrollbar::GRAB;
        foreach ($this->scrolls as $id => $state) {
            if (!$this->reachable($id)) {
                continue;
            }
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
        $page = max(1, (int)(($this->rects[$this->focus][3] - 2 * self::FIELD_INSET_Y) / $lh) - 1);
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
    private function command(string $selector): bool
    {
        if ($this->areaCommand($selector)) {
            return true;
        }
        if ($this->focus !== null && !isset($this->fields[$this->focus])) {
            $this->focusVisible = true; // a key on a focused control: the ring shows
        }
        if ($this->focus !== null && isset($this->lists[$this->focus])) {
            $l = $this->lists[$this->focus];
            $page = max(1, (int)($l->viewport / max(1.0, $l->estimate)) - 1);
            switch ($selector) {
                case 'moveUp:': $l->choose(max(0, $l->selected - 1)); return true;
                case 'moveDown:': $l->choose($l->selected + 1); return true;
                case 'pageUp:': case 'scrollPageUp:': $l->choose(max(0, $l->selected - $page)); return true;
                case 'pageDown:': case 'scrollPageDown:': $l->choose($l->selected + $page); return true;
                case 'moveToBeginningOfDocument:': case 'scrollToBeginningOfDocument:': $l->choose(0); return true;
                case 'moveToEndOfDocument:': case 'scrollToEndOfDocument:': $l->choose($l->count - 1); return true;
                case 'insertNewline:': if ($l->selected >= 0) { $l->submit(); } return true;
            }
        }
        if ($this->focus !== null && isset($this->sliders[$this->focus])) {
            switch ($selector) {
                case 'moveLeft:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) - 1; return true;
                case 'moveRight:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) + 1; return true;
            }
        }
        if ($this->focus !== null && isset($this->steppers[$this->focus])) {
            switch ($selector) {
                case 'moveUp:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) + 1; return true;
                case 'moveDown:': $this->steps[$this->focus] = ($this->steps[$this->focus] ?? 0) - 1; return true;
                case 'moveToBeginningOfDocument:': $this->jumps[$this->focus] = -1; return true;
                case 'moveToEndOfDocument:': $this->jumps[$this->focus] = 1; return true;
            }
        }
        if ($this->focus !== null && $this->groupKey($selector)) {
            return true;
        }
        switch ($selector) {
            case 'insertTab:': $this->moveFocus(1); return true;
            case 'insertBacktab:': $this->moveFocus(-1); return true;
            case 'cancelOperation:': // Escape: a control lets the focus go; a field keeps it (a search field clears on it); the window still gets the key
                if ($this->focus !== null && !isset($this->fields[$this->focus])) { $this->focus = null; }
                return false;
            case 'insertNewline:':
                if ($this->focus !== null && !isset($this->fields[$this->focus])) {
                    $this->activated = $this->focus;
                    return true;
                }
        }
        return false;
    }

    private function moveFocus(int $dir): void
    {
        if ($this->focusables === []) {
            return;
        }
        $reachable = array_values(array_filter($this->focusables, $this->reachable(...)));
        if ($reachable === []) {
            return;
        }
        // The stops of Tab: a focusable on its own, or a focus group as one at its first member's place.
        $stops = [];
        foreach ($reachable as $id) {
            $g = $this->memberOf[$id] ?? 0;
            if ($g === 0) { $stops[] = $id; } elseif (!in_array($g, $stops, true)) { $stops[] = $g; }
        }
        $at = $this->focus === null ? 0 : ($this->memberOf[$this->focus] ?? $this->focus);
        $i = (int)array_search($at, $stops, true);
        if ($this->focus === null) { $i = -1; }
        $n = count($stops);
        $to = $stops[(($i + $dir) % $n + $n) % $n];
        if (isset($this->groups[$to])) { // into a group: the member that had the focus last, else the one on, else the first
            $last = $this->groupLast[$to] ?? null;
            $to = $last !== null && ($this->memberOf[$last] ?? 0) === $to ? $last : ($this->groups[$to]['on'] ?? $this->firstMember($to, $reachable));
        }
        $this->focusTo($to);
    }

    private function firstMember(int $group, array $reachable): int
    {
        foreach ($reachable as $id) { if (($this->memberOf[$id] ?? 0) === $group) { return $id; } }
        return $group;
    }

    /** Gives the keyboard focus to $id, visibly, remembering it for its group. */
    private function focusTo(int $id): void
    {
        $this->focus = $id;
        $this->focusVisible = true;
        if (isset($this->memberOf[$id])) { $this->groupLast[$this->memberOf[$id]] = $id; }
        if (isset($this->fields[$id])) {
            $this->fields[$id][0]->selectAll();
        }
    }

    /** The arrows, Home and End inside a focus group: among its members, round; with selects, the move clicks the member. */
    private function groupKey(string $selector): bool
    {
        $g = $this->memberOf[$this->focus] ?? 0;
        if ($g === 0) { return false; }
        $o = $this->groups[$g]['orient'];
        $dir = match ($selector) {
            'moveLeft:' => $o & Node::HORIZONTAL ? -1 : 0, 'moveRight:' => $o & Node::HORIZONTAL ? 1 : 0,
            'moveUp:' => $o & Node::VERTICAL ? -1 : 0, 'moveDown:' => $o & Node::VERTICAL ? 1 : 0,
            'moveToBeginningOfDocument:' => -INF, 'moveToEndOfDocument:' => INF, default => 0,
        };
        if ($dir === 0) { return false; }
        $members = array_values(array_filter($this->focusables, fn($id) => ($this->memberOf[$id] ?? 0) === $g && $this->reachable($id)));
        $n = count($members); $i = (int)array_search($this->focus, $members, true);
        $to = $members[is_infinite($dir) ? ($dir < 0 ? 0 : $n - 1) : (($i + $dir) % $n + $n) % $n];
        $this->focusTo($to);
        if ($this->groups[$g]['selects']) { $this->activated = $to; }
        return true;
    }

    /** Whether a mouse-up this frame landed on the node this id had last frame, or Enter/Space pressed it while focused. */
    public function clicked(int $id): bool
    {
        if ($this->activated === $id) {
            $this->activated = null;
            $this->consumed = true;
            return true;
        }
        foreach ($this->clicks as $i => [$x, $y]) {
            if ($this->under($id, $x, $y)) {
                unset($this->clicks[$i]);
                $this->consumed = true;
                return true;
            }
        }
        return false;
    }

    /** The modifiers of the Return or Enter key pressed this frame (a field's Enter with Shift: a find bar's previous match), 0 when none was. */
    public function submitMods(): int
    {
        foreach ($this->keys as $k) { if ($k[1] === Key::RETURN || $k[1] === Key::ENTER) { return $k[2]; } }
        return 0;
    }

    /** What was typed this frame while node $id had the focus, for a control that is no field (a time input's segment); nothing otherwise. */
    public function typed(int $id): string
    {
        if ($this->focus !== $id || $this->typed === '') { return ''; }
        $this->consumed = true;
        $typed = $this->typed; $this->typed = ''; $this->typedAt = hrtime(true) / 1e9; // one taker, once; the pass that follows sees what it did
        return $typed;
    }

    /** Whether Enter or Space pressed the focused node $id this frame (a click from the keyboard, not the mouse); clicked() reports both. */
    public function activated(int $id): bool
    {
        if ($this->activated === $id) { $this->activated = null; $this->consumed = true; return true; }
        return false;
    }

    /** Gives node $id the focus from code, as mygo's Focus; the ring shows when $visible, as after a key. */
    public function focusOn(int $id, bool $visible = false): void
    {
        $this->focus = $id;
        if ($visible) { $this->focusVisible = true; }
    }

    /** Whether the mouse went down this frame on the node this id had last frame (mygo's Pressed, at the press). */
    public function pressed(int $id): bool
    {
        foreach ($this->presses as [$x, $y]) {
            if ($this->under($id, $x, $y)) {
                return true;
            }
        }
        return false;
    }

    /** Whether the mouse went down anywhere in the window this frame. */
    public function pressedAnywhere(): bool
    {
        return $this->presses !== [];
    }

    /** Whether a second press of a double click this frame landed on the node this id had last frame. */
    public function doubleClicked(int $id): bool
    {
        foreach ($this->doubleClicks as $i => [$x, $y]) {
            if ($this->under($id, $x, $y)) {
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
        foreach ($this->rightClicks as $i => [$x, $y]) {
            if ($this->under($id, $x, $y)) {
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

    /** @var array<int, int> choices menus made for selects, by node id, until the select reads them */
    private array $choices = [];

    /** A select's menu chose option $i (called from the menu's action, after the popup returned). */
    public function chose(int $id, int $i): void
    {
        $this->choices[$id] = $i;
    }

    /** The option a select's menu chose since it last asked, or null. */
    public function chosen(int $id): ?int
    {
        $i = $this->choices[$id] ?? null;
        unset($this->choices[$id]);
        return $i;
    }

    /** Whether slider $id is being held (pressed, dragging). */
    public function sliding(int $id): bool
    {
        return $this->sliding === $id;
    }

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

    /**
     * Registers stepper $id for the keys and reports its steps this frame:
     * the arrow keys', plus a press on its upper (up) or lower (down)
     * half, repeated while held — after HOLD_DELAY, one every HOLD_RATE,
     * as AppKit's.
     */
    public function stepper(int $id): int
    {
        $this->steppers[$id] = true;
        $n = $this->steps[$id] ?? 0;
        unset($this->steps[$id]); // given once, whatever passes the frame builds
        $dir = $this->holding($id);
        if ($dir !== 0) {
            $held = hrtime(true) / 1e9 - $this->hold[2];
            $due = 1 + ($held > self::HOLD_DELAY ? (int)(($held - self::HOLD_DELAY) / self::HOLD_RATE) : 0);
            $n += ($due - $this->hold[3]) * $dir;
            $this->hold[3] = $due;
            $this->hold[4] = true;
        }
        return $n;
    }

    /**
     * How far the pointer moved since this was last asked while the mouse
     * is down from a press on node $id (its last-frame rect): [dx, dy] in
     * points, or null when it is not being dragged (mygo's Dragged). A
     * drag counts as consumed input: the frame builds again with what it
     * moved.
     */
    public function dragged(int $id): ?array
    {
        if ($this->hold === null) {
            return null;
        }
        if (($this->hold[7] ?? null) !== $id) { // not bound to this node yet: bind the press to the first node asked for whose rect holds it
            if (isset($this->hold[7])) {
                return null; // another node's drag
            }
            [$x, $y] = $this->hold;
            if (!$this->under($id, $x, $y)) {
                return null;
            }
            $this->hold[7] = $id; // the drag stays this node's as it moves out from under the press
        }
        $d = [$this->input->mouseX - $this->hold[5], $this->input->mouseY - $this->hold[6]];
        $this->hold[5] = $this->input->mouseX; $this->hold[6] = $this->input->mouseY;
        if ($d[0] != 0 || $d[1] != 0) {
            $this->consumed = true;
        }
        return $d;
    }

    /** Which half of stepper $id the mouse is held down on: 1 the upper, -1 the lower, 0 neither. */
    public function holding(int $id): int
    {
        $r = $this->rects[$id] ?? null;
        if ($this->hold === null || $r === null || !$this->reachable($id)) {
            return 0;
        }
        [$x, $y] = $this->hold;
        if ($x < $r[0] || $x >= $r[0] + $r[2] || $y < $r[1] || $y >= $r[1] + $r[3]) {
            return 0;
        }
        return $y < $r[1] + $r[3] / 2 ? 1 : -1;
    }

    /**
     * Whether $key (a Key code, or a character such as 'a') was pressed
     * this frame with exactly $mods (Key::SHIFT|CONTROL|OPTION|COMMAND),
     * wherever the focus is, unless a focused control took it first (a
     * field its typing and moves, a button Enter and Space, a list the
     * arrows), as mygo's Context.Shortcut.
     */
    public function shortcut(int|string $key, int $mods = 0): bool
    {
        $field = $this->focus !== null && isset($this->fields[$this->focus]);
        $free = $field && $this->fields[$this->focus][0]->multiline ? [Key::ESCAPE] : [Key::ESCAPE, Key::UP, Key::DOWN]; // what a focused field leaves: a single line has no use for Up and Down
        if ($field && $this->fields[$this->focus][0]->leaveEmptyBackspace && $this->fields[$this->focus][0]->text === '') { $free[] = Key::DELETE; }
        foreach ($this->keys as $i => $k) {
            if ($k[3] || $k[2] !== $mods) {
                continue;
            }
            if ($field && ($k[2] & (Key::COMMAND | Key::CONTROL)) === 0 && !in_array($k[1], $free, true)) {
                continue; // the text system had it: typing, moves, deletes
            }
            if (is_int($key) ? $k[1] === $key : strtolower($k[0]) === strtolower($key)) {
                $this->keys[$i][3] = true; // one taker, as a click has; what it changed shows in this frame's next pass
                $this->consumed = true;
                return true;
            }
        }
        return false;
    }

    /** Home (-1) or End (1) pressed on focused stepper $id this frame, or 0. */
    public function jumped(int $id): int
    {
        $j = $this->jumps[$id] ?? 0;
        unset($this->jumps[$id]);
        return $j;
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

    /** Whether node $id has the focus and it shows (came from the keyboard). */
    public function focusVisible(int $id): bool
    {
        return $this->focus === $id && $this->focusVisible;
    }

    /** Whether the pointer and the keys reach node $id: nothing blocks it (a modal built after it does). */
    public function reachable(int $id): bool
    {
        return ($this->order[$id] ?? 0) >= $this->barrier;
    }

    /** Whether a press at (x, y) is a drag handle's: on one, and on no focusable, slider, scroll or link inside it (those keep their presses, as mygo's interactive flags). */
    private function onHandle(float $x, float $y): bool
    {
        $on = false;
        foreach ($this->handles as $id => $r) { if ($this->under($id, $x, $y)) { $on = true; break; } }
        if (!$on) { return false; }
        foreach ($this->focusables as $id) { if ($this->under($id, $x, $y)) { return false; } }
        foreach ($this->sliders as $id => $r) { if ($this->under($id, $x, $y)) { return false; } }
        foreach ($this->scrolls as $id => $st) { if ($this->under($id, $x, $y)) { return false; } }
        foreach ($this->links as $id) { if ($this->under($id, $x, $y)) { return false; } }
        return true;
    }

    /** Whether (x, y) is on the node this id had last frame: in its rect, reachable, and not under an overlay built after it. */
    private function under(int $id, float $x, float $y): bool
    {
        $r = $this->rects[$id] ?? null;
        if ($r === null || !$this->reachable($id) || $x < $r[0] || $x >= $r[0] + $r[2] || $y < $r[1] || $y >= $r[1] + $r[3]) {
            return false;
        }
        $o = $this->order[$id];
        foreach ($this->overlays as [$at, $ox, $oy, $ow, $oh]) {
            if ($at > $o && $x >= $ox && $x < $ox + $ow && $y >= $oy && $y < $oy + $oh) {
                return false;
            }
        }
        return true;
    }

    public function hovered(int $id): bool
    {
        return $this->input->mouseInside && $this->under($id, $this->input->mouseX, $this->input->mouseY);
    }

    /** Scrolls the innermost scrolling container under (x, y) by the wheel's dx and dy points, on the axes it scrolls. */
    private function scrollAt(float $x, float $y, float $dx, float $dy): void
    {
        $best = null; $bestArea = INF; $axes = [false, true];
        foreach ($this->scrolls as $id => $state) {
            $r = $this->rects[$id];
            if ($this->under($id, $x, $y) && $r[2] * $r[3] < $bestArea) {
                $best = $state; $bestArea = $r[2] * $r[3]; $axes = $this->scrollAxes[$id];
            }
        }
        foreach ($this->areas as $id => $state) { // a text area inside a scrolling container takes the wheel over it
            $r = $this->rects[$id];
            if ($this->under($id, $x, $y) && $r[2] * $r[3] < $bestArea) {
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
