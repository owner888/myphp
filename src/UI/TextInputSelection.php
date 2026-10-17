<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The primary selection reported to native input methods (mygo's TextInputSelection): $reversed places its caret at $range->start. */
final class TextInputSelection
{
    public function __construct(public TextInputRange $range, public bool $reversed = false) {}

    public function caret(): int { return $this->reversed ? $this->range->start : $this->range->end; }
}
