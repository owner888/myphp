<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** State a scrolling container keeps across frames; the wheel calls scrollBy. */
interface Scrollable
{
    /** Moves the content by $dy points (positive scrolls toward the end). */
    public function scrollBy(float $dy): void;
}
