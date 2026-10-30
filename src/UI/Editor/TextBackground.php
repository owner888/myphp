<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Fills bytes $start to $end of the text with $color, [rgb, alpha]
 * (mygo's TextBackground). Ranges past the end of the text, as an edit
 * leaves them until they are computed again, are cut to it; empty ones
 * fill nothing. A range across lines fills the text of each, not past its
 * end.
 */
final class TextBackground
{
    public function __construct(public int $start, public int $end, public array $color) {}
}
