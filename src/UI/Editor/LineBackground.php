<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Fills lines $first to $last, from 0, with $color, [rgb, alpha] (mygo's
 * LineBackground). The lines are those of the text when it is set: an
 * edit adding or removing lines above them doesn't move them.
 */
final class LineBackground
{
    public function __construct(public int $first, public int $last, public array $color) {}
}
