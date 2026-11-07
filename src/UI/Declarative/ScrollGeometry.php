<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/** Where a scroll is, as SwiftUI's ScrollGeometry: its content's offset [x, y], its content's size and its container's [width, height]. */
final class ScrollGeometry
{
    /**
     * @param array{0: float, 1: float} $contentOffset
     * @param array{0: float, 1: float} $contentSize
     * @param array{0: float, 1: float} $containerSize
     */
    public function __construct(public readonly array $contentOffset, public readonly array $contentSize, public readonly array $containerSize) {}

    /** How far through it is scrolled, 0 to 1, down. */
    public function fraction(): float
    {
        $range = $this->contentSize[1] - $this->containerSize[1];
        return $range > 0 ? max(0.0, min(1.0, $this->contentOffset[1] / $range)) : 0.0;
    }
}
