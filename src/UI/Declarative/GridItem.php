<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/** A column of a LazyVGrid, as SwiftUI's GridItem: adaptive, as many as fit cells $minimum wide. */
final class GridItem
{
    private function __construct(public readonly ?float $minimum) {}

    public static function adaptive(float $minimum): self { return new self($minimum); }
}
