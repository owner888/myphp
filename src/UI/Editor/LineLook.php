<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** How a Decor shows a line (mygo's lineLook): colors are [rgb, alpha], alpha 0 for none. */
final class LineLook
{
    public function __construct(
        public array $bg = [0, 0.0], public array $gutter = [0, 0.0], public array $number = [0, 0.0],
        public array $bar = [0, 0.0],
        /** The bar is striped, as deletions' are. */
        public bool $stripes = false,
        /** "+" or "-" in classic indicators. */
        public string $sign = '',
    ) {}

    /** The color of the words the diff changed in the line. @return array{0: int, 1: float} */
    public function word(Theme $ct): array
    {
        return $this->bar[1] == 0 ? $ct->c['occurrence'] : [$this->bar[0], $this->bar[1] * 0.22];
    }
}
