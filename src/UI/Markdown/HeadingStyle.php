<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

/** How headings of one level look (mygo's HeadingStyle). */
final class HeadingStyle
{
    public function __construct(
        /** Their size in ems, and their weight. */
        public float $size = 0,
        public int $weight = 0,
        /** @var ?array{0: int, 1: float} their color, [0xRRGGBB, alpha]: Theme::$text when null */
        public ?array $color = null,
        /** The room above and below them in ems, which replaces Theme::$blockSpacing around them. */
        public float $top = 0,
        public float $bottom = 0,
        /** Draws a line below them, as GitHub does below headings of levels 1 and 2. */
        public bool $rule = false,
    ) {}
}
