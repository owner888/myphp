<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

/** A value's part of a pie (mygo's chart.Slice), from the angle start to end, in radians clockwise from twelve o'clock; pad is the gap Chart::sector leaves between it and the next. */
final class Slice
{
    public function __construct(public readonly float $start, public readonly float $end, public readonly float $pad = 0.0) {}

    /** The angle halfway through the slice, where its label goes. */
    public function mid(): float
    {
        return ($this->start + $this->end) / 2;
    }
}
