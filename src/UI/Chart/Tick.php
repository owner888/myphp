<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

/** A value a scale marks along an axis (mygo's chart.Tick): where it is, in a fraction of the plot, its value, and its label. */
final class Tick
{
    public function __construct(public readonly float $at, public readonly float $value, public readonly string $label) {}
}
