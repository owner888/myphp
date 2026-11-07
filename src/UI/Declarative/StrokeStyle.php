<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/** How a line is stroked, as SwiftUI's StrokeStyle: its width, and dashes along it ([4, 4]; a shape's outline is dashed by the engine's own pattern). */
final class StrokeStyle
{
    /** @param list<float> $dash */
    public function __construct(public readonly float $lineWidth = 1, public readonly array $dash = []) {}
}
