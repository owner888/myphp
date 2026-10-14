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
    private array $clicks = [];     // [x, y] of mouse-ups this frame
    private bool $consumed = false;
    public int $passes = 0;

    public function __construct(public Input $input) {}

    /**
     * Builds the frame: drains input, runs $build (again if a click changed
     * state while building, at most three times), records the layout.
     * $build(Context) must build and lay out the tree and return its root.
     */
    public function frame(callable $build): Node
    {
        $this->clicks = [];
        foreach ($this->input->events as $e) {
            switch ($e[0]) {
                case 'down': $this->input->mouseDown = true; break;
                case 'up': $this->input->mouseDown = false; $this->clicks[] = [$e[1], $e[2]]; break;
                case 'scroll': $this->scrollAt($e[1], $e[2], $e[4]); break;
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
        }
        $this->rects = [];
        $this->scrolls = [];
        $this->needsFrame = false;
        $this->record($root);
        return $root;
    }

    private function record(Node $n): void
    {
        $this->rects[$n->id] = [$n->x, $n->y, $n->w, $n->h];
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

    /** Whether a mouse-up this frame landed on the node this id had last frame. */
    public function clicked(int $id): bool
    {
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
