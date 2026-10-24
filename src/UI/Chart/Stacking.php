<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

/** How Chart::stack piles series up (mygo's chart.Stacking), as Swift Charts' stacking methods. */
enum Stacking
{
    /** Values piled up from 0, and negative values down from it. */
    case Standard;
    /** Piled up as Standard does, in shares of the sum of their sizes: the stacks of positive values go from 0 to 1. */
    case Normalized;
    /** Piled up around 0, as a streamgraph. */
    case Center;
}
