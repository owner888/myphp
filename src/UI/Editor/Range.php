<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** A range of a text's bytes, $start to $end (mygo's editor.Range). */
final class Range
{
    public function __construct(public int $start = 0, public int $end = 0) {}
}
