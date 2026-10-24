<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

/** A value's stretch in a stack, from lo up to hi (mygo's chart.Segment). */
final class Segment
{
    public function __construct(public readonly float $lo, public readonly float $hi) {}
}
