<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Marks a line in the gutter, as a breakpoint or a bookmark: a dot of its color, [rgb, alpha] (mygo's GutterMark). */
final class GutterMark
{
    public function __construct(public int $line, public array $color) {}
}
