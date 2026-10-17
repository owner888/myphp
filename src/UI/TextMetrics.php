<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** What layout and text editing need from a text engine. */
interface TextMetrics
{
    /** @return array{0: float, 1: float} width and line height in points; $style is Font bits */
    public function measure(string $text, float $size, int $style = 0): array;

    /** The baseline's distance from the line's top, for $size and $style. */
    public function ascent(float $size, int $style = 0): float;

    /** The x of the caret before byte offset $byte of $text, in points from its left. */
    public function xForIndex(string $text, float $size, int $byte, int $style = 0): float;

    /** The byte offset whose caret is nearest to $x points from the left. */
    public function indexForX(string $text, float $size, float $x, int $style = 0): int;
}
