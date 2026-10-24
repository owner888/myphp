<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A problem with a range of the text, $start to $end, which shows underlined, in the hover and the scroll bar (mygo's Diagnostic). */
final class Diagnostic
{
    public function __construct(public int $start, public int $end, public Severity $severity = Severity::SeverityError, public string $message = '', public string $source = '') {}
}
