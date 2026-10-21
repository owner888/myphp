<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/** How headings of one level look (mygo's richtext.HeadingStyle). */
final class HeadingStyle
{
    /** @param ?array{0: int, 1: float} $color */
    public function __construct(
        /** Their size in ems, and their weight: 700. */
        public float $size = 0,
        public int $weight = 0,
        /** Colors them, the text color by default. */
        public ?array $color = null,
        /** The room above them in ems of their size, unless they start the text: 0.6. That below them in ems of the text: 0.25. */
        public float $top = 0,
        public float $bottom = 0,
    ) {}
}
