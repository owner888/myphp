<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/** What a paragraph is an item of (mygo's richtext.ListKind). */
enum ListKind: int
{
    case NoList = 0;
    /** An unordered list. */
    case Bullets = 1;
    /** An ordered list, numbered from 1 among the items of one level next to each other. */
    case Numbers = 2;
    /** A checklist, whose items are checked or not. */
    case Tasks = 3;
}
