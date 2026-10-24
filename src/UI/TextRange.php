<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Styles runes $start up to $end of an input's text in $color at $alpha, as a message field shows the mentions in what is typed (mygo's TextRange; Node::textRanges). */
final class TextRange
{
    public function __construct(
        public int $start,
        public int $end,
        public int $color,
        public float $alpha = 1.0,
    ) {}
}
