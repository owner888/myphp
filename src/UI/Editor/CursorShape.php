<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * The shape of the carets (mygo's editor.CursorShape), as Zed's: a bar
 * before the character at the caret; a block covering the character after
 * it, a space's width at the end of a line, translucent so that the
 * character shows, outlined without the keyboard focus, as Vim's and Zed's;
 * or an underline under the character after it.
 */
enum CursorShape: int
{
    case CursorBar = 0;
    case CursorBlock = 1;
    case CursorUnderline = 2;
}
