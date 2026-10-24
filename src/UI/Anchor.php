<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A point of a box, which Node::attach and Node::attachTo put one box's
 * on another's (mygo's Anchor): its corners, the middles of its sides and
 * its center; Start and End are the left and the right, or the reverse in
 * a node laid out right to left.
 */
enum Anchor
{
    case TopLeft;
    case Top;
    case TopRight;
    case Left;
    case Center;
    case Right;
    case BottomLeft;
    case Bottom;
    case BottomRight;
    case TopStart;
    case Start;
    case BottomStart;
    case TopEnd;
    case End;
    case BottomEnd;

    /** The anchor as the box's own sides: Start and End to the left or right as $rtl says. */
    public function physical(bool $rtl): self
    {
        return match ($this) {
            self::TopStart => $rtl ? self::TopRight : self::TopLeft,
            self::Start => $rtl ? self::Right : self::Left,
            self::BottomStart => $rtl ? self::BottomRight : self::BottomLeft,
            self::TopEnd => $rtl ? self::TopLeft : self::TopRight,
            self::End => $rtl ? self::Left : self::Right,
            self::BottomEnd => $rtl ? self::BottomLeft : self::BottomRight,
            default => $this,
        };
    }

    /** Where the point is across and down the box, as fractions of its width and height. @return array{0: float, 1: float} */
    public function fractions(): array
    {
        return match ($this) {
            self::TopLeft => [0.0, 0.0], self::Top => [0.5, 0.0], self::TopRight => [1.0, 0.0],
            self::Left => [0.0, 0.5], self::Center => [0.5, 0.5], self::Right => [1.0, 0.5],
            self::BottomLeft => [0.0, 1.0], self::Bottom => [0.5, 1.0], self::BottomRight => [1.0, 1.0],
            default => $this->physical(false)->fractions(),
        };
    }
}
