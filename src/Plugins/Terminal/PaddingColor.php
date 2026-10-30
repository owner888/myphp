<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** What the padding around a terminal's grid shows (Options::$paddingColor), as Ghostty's window-padding-color (mygo's terminal.PaddingColor). */
enum PaddingColor: int
{
    /** The terminal's background, or nothing when it is transparent. */
    case PaddingBackground = 0;
    /**
     * The backgrounds of the cells nearest to the padding, extended into
     * it: a row's beside it, the top and the bottom rows' above and below
     * the grid, as Ghostty's extend: those of rows whose every cell has a
     * background of its own, as a full-screen program painting its own
     * background has, so that a shell's highlights, a program's tabs or
     * the box it draws keep to their cells. A lone character in inverse
     * video, as a program draws its own cursor, keeps its background in
     * its cell.
     */
    case PaddingExtend = 1;
    /** The backgrounds of every row's cells nearest to the padding, as Ghostty's extend-always, but for a lone character in inverse video's. */
    case PaddingExtendAlways = 2;
}
