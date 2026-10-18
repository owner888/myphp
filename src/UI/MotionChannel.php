<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * A value that animations move, by a node and a key (mygo's channel):
 * along a curve or a spring, toward $target, from $value and $velocity
 * (units per second) at $at, in nanoseconds.
 */
final class MotionChannel
{
    public float $velocity = 0.0;
    public float $target;
    public bool $moving = false;
    /** The spring's mass, stiffness and damping, and its precision. */
    public float $m = 0.0, $k = 0.0, $c = 0.0, $eps = 0.0;
    /** The curve's, which went from $from at $start for $duration nanoseconds. */
    public bool $curve = false;
    public float $from = 0.0;
    public int $start = 0, $duration = 0;
    public ?Closure $ease = null;
    /** A target that the value goes to after a delay, from $pendingAt, with $pendingAnim. */
    public bool $pending = false;
    public float $pendingTo = 0.0;
    public int $pendingAt = 0;
    public ?Animation $pendingAnim = null;
    /** The frame in which the value came to rest at its target after moving there. */
    public int $rested = 0;
    /** That Context::jump set the value at $at. */
    public bool $jumped = false;

    public function __construct(public float $value, public int $at)
    {
        $this->target = $value;
    }

    /** Where the value is headed: the target it goes to after a delay, or its target. */
    public function goal(): float
    {
        return $this->pending ? $this->pendingTo : $this->target;
    }

    /** Heads the value, as it is at $at, for $target as $a says. */
    public function retarget(float $target, Animation $a): void
    {
        $this->target = $target; $this->moving = true; $this->jumped = false;
        if ($a->duration > 0) {
            $this->curve = true; $this->from = $this->value; $this->start = $this->at;
            $this->duration = Motion::ns($a->duration); $this->ease = $a->ease ?? Easing::easeOut(...);
            return;
        }
        $this->curve = false;
        [$this->m, $this->k, $this->c, $this->eps] = $a->spring->physics();
        if ($a->spring->velocity != 0) {
            $this->velocity = $a->spring->velocity;
        }
    }

    /** Sends the value from where it is at $now to $to, as $a says (mygo's headFor). */
    public function headFor(float $to, Animation $a, int $now): void
    {
        $this->advance($now);
        if ($to == $this->goal() && ($this->moving || $this->pending || $this->value == $to)) {
            return;
        }
        if ($a->delay > 0) {
            $this->pending = true; $this->pendingTo = $to; $this->pendingAt = $now + Motion::ns($a->delay); $this->pendingAnim = $a;
        } else {
            $this->pending = false;
            $this->retarget($to, $a);
        }
    }

    /** Moves the value, and where it goes, by $d, as what it is relative to moves: it goes on as it went. */
    public function shift(float $d): void
    {
        $this->value += $d; $this->target += $d; $this->from += $d; $this->pendingTo += $d;
    }

    /** Scales the value, where it goes and how fast, from $was to $to, as what it is a part of grows or shrinks: it goes on as it went, in proportion; a value at $was goes exactly to $to. */
    public function scale(float $was, float $to): void
    {
        $this->value = $this->value * $to / $was;
        $this->velocity = $this->velocity * $to / $was;
        $this->target = $this->target * $to / $was;
        $this->from = $this->from * $to / $was;
        $this->pendingTo = $this->pendingTo * $to / $was;
    }

    /** Moves the value to $now, heading for a pending target when its delay is over on the way. */
    public function advance(int $now): void
    {
        if ($this->pending && $now >= $this->pendingAt) {
            $this->step($this->pendingAt);
            $this->pending = false;
            $this->retarget($this->pendingTo, $this->pendingAnim);
        }
        $this->step($now);
    }

    /** Moves the value along its curve or spring to $now. */
    public function step(int $now): void
    {
        $dt = ($now - $this->at) / 1e9;
        if ($dt <= 0) {
            $this->at = $now; // the same time, or a clock set back
            return;
        }
        $this->at = $now;
        $jumped = $this->jumped;
        $this->jumped = false;
        if (!$this->moving) {
            // A value that jump() moved keeps going as it did for a moment, for an animation to take over.
            if (!$jumped || $dt > 0.1) {
                $this->velocity = 0.0;
            }
            return;
        }
        if ($this->curve) {
            $d = $this->duration / 1e9;
            $t = ($now - $this->start) / 1e9 / $d;
            if ($t >= 1) {
                $this->value = $this->target; $this->velocity = 0.0; $this->moving = false;
                return;
            }
            $t = max($t, 0.0);
            $span = $this->target - $this->from;
            $ease = $this->ease;
            $this->value = $this->from + $span * $ease($t);
            // The slope of the easing, for a spring taking over.
            $h = 1e-3;
            $lo = max($t - $h, 0.0); $hi = min($t + $h, 1.0);
            $this->velocity = $span * ($ease($hi) - $ease($lo)) / ($hi - $lo) / $d;
            return;
        }
        [$x, $v] = Motion::solveSpring($this->value - $this->target, $this->velocity, $this->m, $this->k, $this->c, $dt);
        $this->value = $this->target + $x; $this->velocity = $v;
        if (abs($x) <= $this->eps && abs($v) <= $this->eps * sqrt($this->k / $this->m)) {
            $this->value = $this->target; $this->velocity = 0.0; $this->moving = false;
        }
    }
}
