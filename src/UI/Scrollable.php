<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** State a scrolling container keeps across frames; the wheel, the thumb and glides call scrollBy. */
interface Scrollable
{
    /** Moves the content by $dy points (positive scrolls toward the end). */
    public function scrollBy(float $dy): void;

    /**
     * How far it is scrolled, how far it can go and the view's height, in
     * points: what the scroll bar's thumb is drawn from. Estimates for a
     * list whose rows are not all measured.
     * @return array{0: float, 1: float, 2: float}
     */
    public function extent(): array;

    /** Advances a smooth scroll by $dt seconds; true while it is still on its way. */
    public function glide(float $dt): bool;

    /** Whether a smooth scroll is on its way. */
    public function moving(): bool;

    /** Ends a smooth scroll where it is (the user took over). */
    public function stop(): void;
}
