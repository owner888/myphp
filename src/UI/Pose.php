<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Where a node shows, from its place in the layout, which
 * Node::animatePose moves it to (mygo's Pose): moved by $x and $y points,
 * scaled by $scale around its transform origin (0 keeps its size, as 1
 * does), turned by $rotate degrees clockwise, and faded by $fade, from 0,
 * opaque, to 1, gone.
 */
final class Pose
{
    public function __construct(
        public readonly float $x = 0.0,
        public readonly float $y = 0.0,
        public readonly float $scale = 0.0,
        public readonly float $rotate = 0.0,
        public readonly float $fade = 0.0,
    ) {}

    /** Its components, in the order of a pose's channels. @return array{0: float, 1: float, 2: float, 3: float, 4: float} */
    public function vals(): array
    {
        return [$this->x, $this->y, $this->scale == 0 ? 1.0 : $this->scale, $this->rotate, max(0.0, min($this->fade, 1.0))];
    }
}
