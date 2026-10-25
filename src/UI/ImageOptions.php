<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * How Painter::imageRegion draws a part of a bitmap (mygo's ImageOptions):
 * $flipX mirrors it left to right, $flipY top to bottom; $tint (0xRRGGBB)
 * at $tintAlpha multiplies its colors and alpha, as a colored filter
 * would, null for none.
 */
final class ImageOptions
{
    public function __construct(
        public bool $flipX = false,
        public bool $flipY = false,
        public ?int $tint = null,
        public float $tintAlpha = 1.0,
    ) {}
}
