<?php
declare(strict_types=1);

namespace MyPHP;

/** Files dropped onto a window: their paths and where, in points from the content's top left. */
final class FileDropEvent
{
    /** @param list<string> $paths */
    public function __construct(public readonly Window $window, public readonly array $paths, public readonly float $x, public readonly float $y) {}
}
