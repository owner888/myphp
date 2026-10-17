<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use InvalidArgumentException;

/**
 * Easings map the time an animation has run, from 0 to 1 of its
 * duration, to how far its value has gone, as CSS's timing functions do
 * (mygo's Easing): closures of a float to a float. linear, easeIn, easeOut
 * and easeInOut are easings, as first-class callables
 * (Easing::easeOut(...)); bounce and cubicBezier make more.
 */
final class Easing
{
    /** Moves at one speed. */
    public static function linear(float $t): float { return $t; }

    /** Starts slowly (a cubic). */
    public static function easeIn(float $t): float { return $t * $t * $t; }

    /** Ends slowly (a cubic), as Context::animate moves. */
    public static function easeOut(float $t): float
    {
        $u = 1 - $t;
        return 1 - $u * $u * $u;
    }

    /** Starts and ends slowly (a cubic). */
    public static function easeInOut(float $t): float
    {
        if ($t < 0.5) {
            return 4 * $t * $t * $t;
        }
        $u = 2 - 2 * $t;
        return 1 - $u * $u * $u / 2;
    }

    /** An easing that goes along $ease and comes back, in the same time, as a pulse does. */
    public static function bounce(Closure $ease): Closure
    {
        return fn(float $t): float => $t < 0.5 ? $ease(2 * $t) : $ease(2 - 2 * $t);
    }

    /**
     * The easing of the cubic Bézier curve from (0, 0) to (1, 1) through
     * the control points ($x1, $y1) and ($x2, $y2), as CSS's
     * cubic-bezier(): $x1 and $x2 within 0 and 1.
     */
    public static function cubicBezier(float $x1, float $y1, float $x2, float $y2): Closure
    {
        Motion::finite('easing', $x1, $y1, $x2, $y2);
        if ($x1 < 0 || $x1 > 1 || $x2 < 0 || $x2 > 1) {
            throw new InvalidArgumentException("cubicBezier's x1 and x2 must be within 0 and 1");
        }
        $cx = 3 * $x1;
        $bx = 3 * ($x2 - $x1) - $cx;
        $ax = 1 - $cx - $bx;
        $cy = 3 * $y1;
        $by = 3 * ($y2 - $y1) - $cy;
        $ay = 1 - $cy - $by;
        $curveX = fn(float $t): float => (($ax * $t + $bx) * $t + $cx) * $t;
        return function (float $x) use ($ax, $bx, $cx, $ay, $by, $cy, $curveX): float {
            if ($x <= 0 || $x >= 1) {
                return $x;
            }
            // Newton's method, then bisection where the slope is too flat.
            $t = $x;
            for ($i = 0; $i < 8; $i++) {
                $dx = (3 * $ax * $t + 2 * $bx) * $t + $cx;
                if (abs($dx) < 1e-6) {
                    break;
                }
                $e = $curveX($t) - $x;
                if (abs($e) < 1e-7) {
                    return (($ay * $t + $by) * $t + $cy) * $t;
                }
                $t -= $e / $dx;
            }
            $lo = 0.0; $hi = 1.0;
            $t = $x;
            for ($i = 0; $i < 40; $i++) {
                $v = $curveX($t);
                if (abs($v - $x) < 1e-7) {
                    break;
                }
                if ($v < $x) { $lo = $t; } else { $hi = $t; }
                $t = ($lo + $hi) / 2;
            }
            return (($ay * $t + $by) * $t + $cy) * $t;
        };
    }
}
