<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Replaces the bytes $start to $end of a Buffer's text with $text (mygo's editor.Edit). */
final class Edit
{
    public function __construct(public int $start, public int $end, public string $text = '') {}
}
