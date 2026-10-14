<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** What layout needs from a text engine. */
interface TextMetrics
{
    /** @return array{0: float, 1: float} width and line height in points */
    public function measure(string $text, float $size): array;
}
