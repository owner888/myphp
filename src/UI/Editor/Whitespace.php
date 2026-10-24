<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Where spaces and tabs show (mygo's editor.Whitespace): within selections, nowhere, everywhere, at the ends of lines, or but single spaces between words. */
enum Whitespace: int
{
    case WhitespaceSelection = 0;
    case WhitespaceNone = 1;
    case WhitespaceAll = 2;
    case WhitespaceTrailing = 3;
    case WhitespaceBoundary = 4;
}
