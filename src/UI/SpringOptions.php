<?php
declare(strict_types=1);

namespace MyPHP\UI;

use InvalidArgumentException;

/**
 * A spring, as one perceives it or physically (mygo's SpringOptions).
 *
 * $duration and $bounce describe it as SwiftUI and Motion do: $duration,
 * in seconds, is about how long it takes to arrive (0.5 when zero), and
 * $bounce how much it overshoots, from 0, settling without overshooting,
 * toward 1, bouncing for ever; below 0 it arrives more slowly still.
 *
 * $mass, $stiffness and $damping describe it physically instead, as react
 * spring does (1, 170 and 26 when zero), when $stiffness or $damping is
 * set; setting them with $duration or $bounce throws.
 *
 * $precision is how close to its target, in the value's units, the spring
 * rests there: 0.001 when zero. $velocity, in value units per second, is
 * the velocity the value takes when its target changes in the call, as
 * that of a pointer letting go of what it dragged; zero keeps the value's
 * own.
 */
final class SpringOptions
{
    public const DEFAULT_DURATION = 0.5;

    public function __construct(
        public readonly float $duration = 0.0,
        public readonly float $bounce = 0.0,
        public readonly float $mass = 0.0,
        public readonly float $stiffness = 0.0,
        public readonly float $damping = 0.0,
        public readonly float $precision = 0.0,
        public readonly float $velocity = 0.0,
    ) {}

    /**
     * The mass, stiffness and damping of the spring, and its precision.
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function physics(): array
    {
        Motion::finite('spring', $this->duration, $this->bounce, $this->mass, $this->stiffness, $this->damping, $this->precision, $this->velocity);
        if ($this->mass < 0 || $this->stiffness < 0 || $this->damping < 0 || $this->precision < 0 || $this->duration < 0) {
            throw new InvalidArgumentException('spring options must not be negative');
        }
        $m = $this->mass ?: 1.0;
        $eps = $this->precision ?: 0.001;
        if ($this->stiffness != 0 || $this->damping != 0) {
            if ($this->duration != 0 || $this->bounce != 0) {
                throw new InvalidArgumentException('a spring takes duration and bounce or stiffness and damping, not both');
            }
            $k = $this->stiffness ?: 170.0;
            $c = $this->damping ?: 26.0;
        } else {
            if ($this->bounce <= -1 || $this->bounce >= 1) {
                throw new InvalidArgumentException('spring bounce must be between -1 and 1');
            }
            $d = $this->duration ?: self::DEFAULT_DURATION;
            // SwiftUI's: the period of the undamped spring is the duration,
            // and the damping ratio 1 - bounce, or 1 / (1 + bounce) below 0.
            $w = 2 * M_PI / $d;
            $ratio = $this->bounce < 0 ? 1 / (1 + $this->bounce) : 1 - $this->bounce;
            $k = $w * $w * $m;
            $c = 2 * $ratio * $w * $m;
        }
        $w = $k / $m; $a = $c / $m;
        if (!is_finite($w) || !is_finite($a) || $w == 0 || $a == 0) {
            throw new InvalidArgumentException('spring options exceed numerical range');
        }
        return [$m, $k, $c, $eps];
    }
}
