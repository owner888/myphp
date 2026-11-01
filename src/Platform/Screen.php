<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** The monitors (mygo's platform.Screen). */
interface Screen
{
    /** @return list<\MyPHP\Display> the primary one first */
    public function displays(): array;

    public function cursorPoint(): \MyPHP\Point;
}
