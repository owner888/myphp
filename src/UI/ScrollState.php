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

    /** Scrolls to $y points (INF is the end), gliding there over the next frames unless $smooth is false. */
    public function scrollTo(float $y, bool $smooth = true): void
    {
        if ($smooth) {
            $this->target = $y;
        } else {
            $this->target = null;
            $this->y = max(0.0, min($this->maxY, $y));
        }
    }

    public function extent(): array
    {
        return [$this->y, $this->maxY, $this->viewport];
    }

    public function glide(float $dt): bool
    {
        if ($this->target === null) {
            return false;
        }
        $to = max(0.0, min($this->maxY, $this->target));
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
        $this->target = null;
    }
}
