<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** How a diff viewer lays out its two texts (mygo's editor.DiffLayout): the old on the left and the new on the right, their lines aligned; or the new text, with the lines deleted from the old above where they were. */
enum DiffLayout: int
{
    case DiffSplit = 0;
    case DiffUnified = 1;
}
