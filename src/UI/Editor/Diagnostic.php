<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A problem with a range of the text, $start to $end, which shows underlined, in the hover and the scroll bar (mygo's Diagnostic). */
final class Diagnostic
{
    /** How bad it is. */
    public const ERROR = 0, WARNING = 1, INFO = 2, HINT = 3;

    public function __construct(public int $start, public int $end, public int $severity = self::ERROR, public string $message = '', public string $source = '') {}
}
