<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What the window received since the last frame, in points, y down.
 * The native view fills it; Runtime drains it at the start of a frame.
 */
final class Input
{
    public float $mouseX = -1, $mouseY = -1;
    public bool $mouseInside = false;
    public bool $mouseDown = false;
    /** @var list<array> ['down'|'up'|'scroll'|'key', ...] in arrival order */
    public array $events = [];

    public function mouse(float $x, float $y): void
    {
        $this->mouseX = $x; $this->mouseY = $y; $this->mouseInside = true;
    }

    public function push(array $event): void
    {
        $this->events[] = $event;
    }
}
