<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** How the gutter numbers lines (mygo's editor.LineNumbers). */
enum LineNumbers: int
{
    case LineNumbersOn = 0;
    /** The caret's line its own number, the others their distance from it. */
    case LineNumbersRelative = 1;
    case LineNumbersOff = 2;
}
