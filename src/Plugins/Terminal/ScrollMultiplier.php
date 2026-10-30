<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** How far scrolling goes (Options::$scrollMultiplier), as Ghostty's mouse-scroll-multiplier = precision:…,discrete:… (mygo's ScrollMultiplier). */
final class ScrollMultiplier
{
    public function __construct(
        /** Scales a touchpad's scrolling, and other scrolling by distance: 1 when 0. */
        public float $precision = 0.0,
        /** How many rows a notch of a mouse wheel scrolls: 3 when 0. */
        public float $discrete = 0.0,
    ) {}

    /** The rows a scroll of $dy points (a wheel's: notches of $line points) scrolls, with cells $ch points high. @internal */
    public function rows(float $dy, float $ch, bool $precise, float $line): float
    {
        if ($precise) {
            return ($this->precision > 0 ? $dy * $this->precision : $dy) / $ch;
        }
        return $dy / $line * ($this->discrete > 0 ? $this->discrete : 3.0);
    }
}
