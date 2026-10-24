<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Whether a border is solid or dashed (mygo's BorderStyle: BorderSolid, BorderDashed). */
enum BorderStyle
{
    case Solid;
    /** Each side in an odd number of dashes and gaps of equal length, about three widths, starting and ending with a dash. */
    case Dashed;
}
