<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A Highlighter knowing indentation: indentAfter returns how many levels more than the line holding $offset (fewer, negative) a line that Return opens there is indented, or null when it does not know. */
interface Indenter
{
    public function indentAfter(int $offset): ?int;
}
