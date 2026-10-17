<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** A half-open range of UTF-16 code units (mygo's TextInputRange): the units of native text services, converted at the storage boundary. */
final class TextInputRange
{
    public function __construct(public int $start = 0, public int $end = 0) {}

    /** The range with its ends in order, at or above 0. */
    public function normalized(): self
    {
        $a = max(0, $this->start); $b = max(0, $this->end);
        return new self(min($a, $b), max($a, $b));
    }
}
