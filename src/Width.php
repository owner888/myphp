<?php
declare(strict_types=1);

namespace MyPHP;

/** How long the names of months, weekdays and day periods are (mygo's i18n.Width). */
enum Width
{
    /** "Monday", "January". */
    case Wide;
    /** "Mon", "Jan". */
    case Abbreviated;
    /** "Mo": weekdays only, the abbreviated name for months. */
    case Short;
    /** "M", "J". */
    case Narrow;
}
