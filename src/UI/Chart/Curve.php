<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

/** How a line goes from a point to the next (mygo's chart.Curve), as Swift Charts' interpolation methods and d3's curves. */
enum Curve
{
    /** Straight lines. */
    case Linear;
    /** A smooth curve that keeps between the values of each two points, never going past them, as d3's curveMonotoneX (Steffen's method). */
    case Monotone;
    /** A point's value kept until the next point, as a value that holds until it changes. */
    case StepAfter;
    /** A point's value taken on from the point before it. */
    case StepBefore;
    /** The value changing halfway between points. */
    case Step;
}
