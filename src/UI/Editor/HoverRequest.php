<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Asks what to show for the text at $offset under the pointer, or at the caret (mygo's HoverRequest). */
final class HoverRequest
{
    public function __construct(public int $offset, public int $version) {}
}
