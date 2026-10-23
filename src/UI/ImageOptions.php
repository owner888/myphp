<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** How Painter::imageRegion draws a part of a bitmap (mygo's ImageOptions): $flipX mirrors it left to right, $flipY top to bottom. */
final class ImageOptions
{
    public function __construct(
        public bool $flipX = false,
        public bool $flipY = false,
    ) {}
}
