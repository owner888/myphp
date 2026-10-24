<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** How a diff viewer highlights what changed within lines (mygo's editor.DiffInline): words, joining those one character apart, as "a b c" changed reads as one; each word; from the first word changed to the last; characters; nothing. */
enum DiffInline: int
{
    case DiffInlineWordAlt = 0;
    case DiffInlineWord = 1;
    case DiffInlineWordLine = 2;
    case DiffInlineChar = 3;
    case DiffInlineNone = 4;
}
