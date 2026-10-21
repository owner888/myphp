<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

/** How a cell's text shows (mygo's vt.Style). @internal */
final class Style
{
    public const UNDERLINE_NONE = 0, UNDERLINE_SINGLE = 1, UNDERLINE_DOUBLE = 2, UNDERLINE_CURLY = 3, UNDERLINE_DOTTED = 4, UNDERLINE_DASHED = 5;

    public function __construct(
        public Color $fg = new Color, public Color $bg = new Color, public Color $underlineColor = new Color,
        public bool $bold = false, public bool $italic = false, public bool $faint = false, public bool $blink = false,
        public bool $inverse = false, public bool $invisible = false, public bool $strikethrough = false, public bool $overline = false,
        public int $underline = self::UNDERLINE_NONE,
    ) {}
}
