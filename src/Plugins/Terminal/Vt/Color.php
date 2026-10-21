<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

/** The color of a style (mygo's vt.Color): none (the default), an index of the palette, or an RGB color, 0xRRGGBB. @internal */
final class Color
{
    public const NONE = 0, PALETTE = 1, RGB = 2;

    public function __construct(public int $kind = self::NONE, public int $index = 0, public int $rgb = 0) {}
}
