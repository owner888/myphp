<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Frame-to-frame state for immediate mode: last frame's rectangles by node
 * id, so that this frame's build can answer clicked()/hovered() before it
 * is laid out, and the input events this frame consumes.
 *
 * Node ids are the order of creation in the build, as in a view whose
 * structure does not change between frames; conditional UI keeps ids
 * stable by giving nodes explicit keys (not yet implemented).
 */
final class Runtime
{
    /** @var array<int, array{0: float, 1: float, 2: float, 3: float}> id => x, y, w, h */
    private array $rects = [];
    /** @var array<int, Scrollable> id => scroll state, from last frame */
    private array $scrolls = [];
    /** Set by a frame that needs another one right after (a list left a gap). */
    public bool $needsFrame = false;
    private array $clicks = [];     // [x, y] of left mouse-ups this frame
    private array $rightClicks = []; // [x, y] of right mouse-ups this frame
    private bool $consumed = false;
    public int $passes = 0;

    /** Keyboard focus: the node id, the ids that can take it (last frame, in order) and the fields. */
    public ?int $focus = null;
    private array $focusables = [];
    /** @var array<int, array{0: TextState, 1: float, 2: float, 3: float, 4: float}> id => state, text x, text y, line h, size */
    private array $fields = [];
    private ?int $activated = null;
    /** Text drawn in a field starts this far from its left edge. */
    public const FIELD_INSET = 8.0;

    public function __construct(public Input $input, private ?TextMetrics $metrics = null)
    {
        $input->metrics = $metrics;
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
        $this->activated = null;
        foreach ($this->input->events as $e) {
            switch ($e[0]) {
                case 'down':
                    $this->input->mouseDown = true;
                    if ($e[3] === 0) {
                        $this->pressAt($e[1], $e[2]);
                    }
                    break;
                case 'up':
                    $this->input->mouseDown = false;
                    if ($e[3] === 1) { $this->rightClicks[] = [$e[1], $e[2]]; } else { $this->clicks[] = [$e[1], $e[2]]; }
                    break;
                case 'drag':
                    if ($this->focus !== null && isset($this->fields[$this->focus]) && $this->input->mouseDown) {
                        $this->placeCaret($this->focus, $e[1], true);
                    }
                    break;
                case 'scroll': $this->scrollAt($e[1], $e[2], $e[4]); break;
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
        }
        $this->rects = [];
        $this->scrolls = [];
        $this->focusables = [];
        $this->fields = [];
        $this->needsFrame = false;
        $this->record($root);
        if ($this->focus !== null && !isset($this->rects[$this->focus])) {
            $this->focus = null; // the focused node was not built this frame
        }
        $this->input->focus = null;
        if ($this->focus !== null && isset($this->fields[$this->focus])) {
            [$state, $tx, $ty, $lh, $size] = $this->fields[$this->focus];
            $this->input->focus = $state;
            $this->input->focusText = [$tx, $ty, $lh, $size];
        }
        return $root;
    }

    private function record(Node $n): void
    {
        $this->rects[$n->id] = [$n->x, $n->y, $n->w, $n->h];
        if ($n->kind === Node::BUTTON || $n->kind === Node::INPUT) {
            $this->focusables[] = $n->id;
        }
        if ($n->kind === Node::INPUT && $n->edit !== null) {
            [, $lh] = $this->metrics?->measure('X', $n->fontSize) ?? [0, $n->h];
            $this->fields[$n->id] = [$n->edit, $n->x + self::FIELD_INSET, $n->y + ($n->h - $lh) / 2, $lh, $n->fontSize];
        }
        if ($n->scroll !== null) {
            $this->scrolls[$n->id] = $n->scroll;
            if ($n->scroll instanceof ListState && $n->scroll->gap > 0) {
                $this->needsFrame = true;
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

    /** A left press: focuses the field or button under it, placing the caret in a field. */
    private function pressAt(float $x, float $y): void
    {
        foreach ($this->focusables as $id) {
            $r = $this->rects[$id];
            if ($x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3]) {
                $this->focus = $id;
                if (isset($this->fields[$id])) {
                    $this->placeCaret($id, $x, false);
                }
                return;
            }
        }
    }

    private function placeCaret(int $id, float $x, bool $extend): void
    {
        [$state, $tx, , , $size] = $this->fields[$id];
        if ($this->metrics === null) {
            return;
        }
        $byte = $this->metrics->indexForX($state->text, $size, $x - $tx);
        $state->setCursor($byte, $extend);
    }

    /** Text system commands the field did not take: focus moves and activation. */
    private function command(string $selector): void
    {
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

    public function hovered(int $id): bool
    {
        $r = $this->rects[$id] ?? null;
        $x = $this->input->mouseX; $y = $this->input->mouseY;
        return $r !== null && $this->input->mouseInside
            && $x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3];
    }

    /** Scrolls the innermost scrolling container under (x, y) by dy points. */
    private function scrollAt(float $x, float $y, float $dy): void
    {
        $best = null; $bestArea = INF;
        foreach ($this->scrolls as $id => $state) {
            $r = $this->rects[$id];
            if ($x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3] && $r[2] * $r[3] < $bestArea) {
                $best = $state; $bestArea = $r[2] * $r[3];
            }
        }
        $best?->scrollBy(-$dy);
    }
}
