<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** How bad a diagnostic is (mygo's editor.Severity), which its color says. */
enum Severity: int
{
    case SeverityError = 0;
    case SeverityWarning = 1;
    case SeverityInfo = 2;
    case SeverityHint = 3;
}
