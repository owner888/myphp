<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** How a diff viewer marks changed lines (mygo's editor.DiffIndicators): a bar at the gutter's edge, striped for deletions; + and - before the lines; their background alone. */
enum DiffIndicators: int
{
    case DiffBars = 0;
    case DiffClassic = 1;
    case DiffNoIndicators = 2;
}
