<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Text shown in a line that is not part of it, as an inlay hint of a type or a parameter's name: at $offset, muted (mygo's Inlay). */
final class Inlay
{
    public function __construct(public int $offset, public string $text) {}
}
