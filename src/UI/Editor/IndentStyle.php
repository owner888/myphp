<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** What indenting inserts (mygo's editor.IndentStyle): as the language's files usually do (Go's tabs), spaces or tabs. */
enum IndentStyle: int
{
    case IndentAuto = 0;
    case IndentSpaces = 1;
    case IndentTabs = 2;
}
