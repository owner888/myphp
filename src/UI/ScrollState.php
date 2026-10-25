<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Offsets of a scrolling container, kept by the app across frames: y down, and x right when it scrolls horizontally. */
final class ScrollState implements Scrollable
{
    public float $y = 0;
    public float $x = 0;
    /** Set by layout each frame: content minus viewport on each axis, and the viewport. */
    public float $maxY = 0;
    public float $maxX = 0;
    public float $viewport = 0;
    public float $viewportW = 0;
    private ?float $target = null;
    /** How a scrollTo() glides: its animation, and the channel driving it by the frame's clock; null for the default glide. */
    private ?Animation $animation = null;
    private ?MotionChannel $motion = null;

    public function scrollBy(float $dy): void
    {
        $this->y = max(0.0, min($this->maxY, $this->y + $dy));
    }

    public function scrollByX(float $dx): void
    {
        $this->x = max(0.0, min($this->maxX, $this->x + $dx));
    }

    /** How far it is scrolled right, how far it can go and the view's width. */
    public function extentX(): array
    {
        return [$this->x, $this->maxX, $this->viewportW];
    }

    /** Scrolls to $y points (INF is the end): gliding there over the next frames, as $how says (an Animation, by the frame's clock, as mygo's ScrollTo), or the default glide for true; at once for false. */
    public function scrollTo(float $y, bool|Animation $how = true): void
    {
        $this->motion = null;
        if ($how === false) {
            $this->target = null; $this->animation = null;
            $this->y = max(0.0, min($this->maxY, $y));
            return;
        }
        $this->target = $y;
        $this->animation = $how instanceof Animation ? $how : null;
    }

    public function extent(): array
    {
        return [$this->y, $this->maxY, $this->viewport];
    }

    public function glide(float $dt, int $now): bool
    {
        if ($this->target === null) {
            return false;
        }
        $to = max(0.0, min($this->maxY, $this->target));
        if ($this->animation !== null) {
            if ($this->motion === null) {
                $this->motion = new MotionChannel($this->y, $now);
                $this->motion->headFor($to, $this->animation, $now);
            } elseif ($this->motion->goal() != $to) { // the end moved, as the content grew
                $this->motion->headFor($to, $this->animation, $now);
            }
            $this->motion->advance($now);
            $this->y = max(0.0, min($this->maxY, $this->motion->value));
            if (!$this->motion->moving && !$this->motion->pending) {
                $this->y = $to;
                $this->stop();
                return false;
            }
            return true;
        }
        $d = $to - $this->y;
        if (abs($d) < 0.5) {
            $this->y = $to;
            $this->target = null;
            return false;
        }
        $this->y += $d * Glide::step($dt);
        return true;
    }

    public function moving(): bool
    {
        return $this->target !== null;
    }

    public function stop(): void
    {
        $this->target = null; $this->animation = null; $this->motion = null;
    }
}
