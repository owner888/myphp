<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

use Closure;

/** The ticks along the sides of a plot, whose labels go beside it (mygo's chart.Axes): a scale's ticks, or ticks of your own. */
final class Axes
{
    /**
     * @param list<Tick> $left
     * @param list<Tick> $right
     * @param list<Tick> $top
     * @param list<Tick> $bottom
     * @param ?Closure(Side, Tick): void $label builds the label of a tick on a side, centered on the tick; null shows its label as text. Labels sit in boxes of no width or height, so keep their text on one line with noWrap().
     */
    public function __construct(
        public array $left = [],
        public array $right = [],
        public array $top = [],
        public array $bottom = [],
        public ?Closure $label = null,
    ) {}
}
