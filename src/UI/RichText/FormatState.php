<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/** Whether a format holds for a selection: all of it, none, or some of it, as a toolbar's button shows it (mygo's richtext.FormatState). */
enum FormatState: int
{
    case FormatOff = 0;
    case FormatOn = 1;
    case FormatMixed = 2;
}
