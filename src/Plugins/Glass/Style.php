<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Glass;

/** The material of Glass (mygo's glass.Style). */
enum Style: int
{
    /** Frosts what shows through and lightens it, or darkens it in dark mode, so that what is on the glass reads over anything: for bars, buttons and panels. Larger panes are frostier. */
    case Regular = 0;
    /** Barely blurs nor tones what shows through, for glass over photos and video, where what is on it brings its own contrast. */
    case Clear = 1;
}
