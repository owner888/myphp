<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game\Gamepad;

/** What a pad's controls read at one time (mygo's gamepad.State). */
final class State
{
    /** How far an analog trigger must go to be pressed. */
    private const PRESS_THRESHOLD = 0.25;

    /**
     * @param list<float> $buttons from 0 to 1 by Button: 0 or 1 but for the triggers
     * @param list<float> $axes from -1 to 1 by Axis, as the pad reports them, without a dead zone
     */
    public function __construct(
        public array $buttons = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        public array $axes = [0, 0, 0, 0],
    ) {}

    /** How far $b is pressed, from 0 to 1. */
    public function value(Button $b): float
    {
        return (float)($this->buttons[$b->value] ?? 0);
    }

    /** Whether $b is pressed: a trigger a quarter of the way. */
    public function pressed(Button $b): bool
    {
        return $this->value($b) >= self::PRESS_THRESHOLD;
    }

    /** Whether $b is pressed now and was not in $last, the state read before. */
    public function justPressed(self $last, Button $b): bool
    {
        return $this->pressed($b) && !$last->pressed($b);
    }

    /** Whether $b was pressed in $last and is not now. */
    public function justReleased(self $last, Button $b): bool
    {
        return !$this->pressed($b) && $last->pressed($b);
    }

    /** The raw value of $a, from -1 to 1. */
    public function axis(Axis $a): float
    {
        return (float)($this->axes[$a->value] ?? 0);
    }

    /**
     * Where the left stick is, each coordinate from -1 to 1, right and down
     * positive, with a radial dead zone: 0 near the middle, then from 0 at
     * Gamepad::DEADZONE to 1 at the rim, the same in every direction.
     * @return array{0: float, 1: float}
     */
    public function leftStick(): array
    {
        return self::stick($this->axis(Axis::LeftX), $this->axis(Axis::LeftY));
    }

    /** Where the right stick is, as leftStick(). @return array{0: float, 1: float} */
    public function rightStick(): array
    {
        return self::stick($this->axis(Axis::RightX), $this->axis(Axis::RightY));
    }

    /** @return array{0: float, 1: float} */
    private static function stick(float $x, float $y): array
    {
        $m = hypot($x, $y);
        if ($m < Gamepad::DEADZONE) {
            return [0.0, 0.0];
        }
        $k = min(($m - Gamepad::DEADZONE) / (1 - Gamepad::DEADZONE), 1) / $m;
        return [$x * $k, $y * $k];
    }
}
