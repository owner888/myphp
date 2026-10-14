<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Offset of a scrolling container, kept by the app across frames. */
final class ScrollState
{
    public float $y = 0;
    /** Set by layout each frame: content height minus viewport height. */
    public float $maxY = 0;
}
