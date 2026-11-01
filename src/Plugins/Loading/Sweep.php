<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

/** The order of blocks's blocks (mygo's loading.Sweep). */
enum Sweep
{
    /** From the top left corner to the bottom right one. */
    case Diagonal;
    /** From the top row down. */
    case Rows;
    /** From the left column right. */
    case Columns;
}
