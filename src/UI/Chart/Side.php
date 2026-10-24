<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

/** A side of a plot, where an axis goes (mygo's chart.Side). */
enum Side
{
    case Left;
    case Right;
    case Top;
    case Bottom;
}
