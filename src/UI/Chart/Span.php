<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

/**
 * A stretch of a chart's plot along one axis, in fractions of it (mygo's
 * chart.Span): from the left edge (0) to the right (1) along x, and from
 * the bottom (0) to the top (1) along y, the way values grow. Scales
 * return spans for the marks: a bar's band along x, its stretch from 0 to
 * its value along y.
 */
final class Span
{
    public function __construct(public readonly float $lo, public readonly float $hi) {}

    /** The middle of the span. */
    public function mid(): float
    {
        return ($this->lo + $this->hi) / 2;
    }
}
