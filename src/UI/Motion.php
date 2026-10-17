<?php
declare(strict_types=1);

namespace MyPHP\UI;

use InvalidArgumentException;

/**
 * What values that move need (mygo's motion.go): the helpers a view
 * computes targets with, rubberband() and project(), and the solving of
 * springs and playheads that MotionChannel and KeyframeSequence share.
 * Times are nanoseconds, as Runtime::$now; the API takes seconds.
 */
final class Motion
{
    /** Seconds as nanoseconds. */
    public static function ns(float $seconds): int
    {
        return (int)round($seconds * 1e9);
    }

    /** Throws unless $v are finite. */
    public static function finite(string $what, float ...$v): void
    {
        foreach ($v as $n) {
            if (!is_finite($n)) {
                throw new InvalidArgumentException("$what values must be finite");
            }
        }
    }

    /**
     * $value held within $lo and $hi as iOS holds content dragged past its
     * edges: beyond them, it goes less and less far, as on a rubber band,
     * the less the larger $dimension is (the size of what is dragged,
     * along the drag).
     */
    public static function rubberband(float $value, float $lo, float $hi, float $dimension): float
    {
        $band = function (float $over) use ($dimension): float {
            if ($dimension <= 0) {
                return 0.0;
            }
            $c = 0.55;
            return (1 - 1 / ($over * $c / $dimension + 1)) * $dimension;
        };
        if ($value < $lo) {
            return $lo - $band($lo - $value);
        }
        if ($value > $hi) {
            return $hi + $band($value - $hi);
        }
        return $value;
    }

    /**
     * Where a value going at $velocity, in its units per second, comes to
     * rest as iOS's scroll views slow down (their normal deceleration
     * rate): where a fling would carry it, to choose the target a spring
     * takes it to (SpringOptions::$velocity).
     */
    public static function project(float $value, float $velocity): float
    {
        $rate = 0.998; // kept of the velocity each millisecond
        return $value + $velocity / 1000 * $rate / (1 - $rate);
    }

    /**
     * Where a damped spring at $x from its rest, going at $v, is after $dt
     * seconds, and its velocity there, solved analytically, so that
     * frames, however far apart, follow the same trajectory.
     * @return array{0: float, 1: float}
     */
    public static function solveSpring(float $x, float $v, float $m, float $k, float $c, float $dt): array
    {
        if ($x == 0 && $v == 0) {
            return [0.0, 0.0];
        }
        $w = sqrt($k / $m);
        $a = ($c / $m) / 2;
        $d = $a * $a - $w * $w;
        if (abs($d) < 1e-8 * $w * $w) {
            $b = $v + $a * $x;
            $decay = exp(-$a * $dt);
            return [($x + $b * $dt) * $decay, ($v - $a * $b * $dt) * $decay];
        }
        if ($d < 0) {
            $f = sqrt(-$d);
            $sn = sin($f * $dt); $cs = cos($f * $dt);
            $b = ($v + $a * $x) / $f;
            $decay = exp(-$a * $dt);
            $y = $x * $cs + $b * $sn;
            return [$y * $decay, ($f * (-$x * $sn + $b * $cs) - $a * $y) * $decay];
        }
        $f = sqrt($d);
        $r1 = -$w * $w / ($a + $f);
        $r2 = -$a - $f;
        $c1 = ($v - $r2 * $x) / ($r1 - $r2);
        $c2 = $x - $c1;
        $u = $c1 * exp($r1 * $dt); $z = $c2 * exp($r2 * $dt);
        return [$u + $z, $r1 * $u + $r2 * $z];
    }

    /**
     * Folds how long an animation has played, $raw nanoseconds, into the
     * time it shows: where it is within its duration $d, repeating and
     * alternating as asked, which time through it is, for what starts
     * from a value of its own the first time, and whether it came to rest
     * at its end. A duration of zero plays on, for an animation whose
     * duration is not known yet.
     * @return array{0: int, 1: int, 2: bool}
     */
    public static function playhead(int $raw, int $d, int $repeat, bool $alternate, int $repeatDelay): array
    {
        if ($raw <= 0) {
            return [0, 0, false];
        }
        if ($d <= 0) {
            return [$raw, 0, false];
        }
        $plays = $repeat + 1; // zero when it plays for ever
        $period = max($d + $repeatDelay, $d);
        $iteration = intdiv($raw, $period); $rest = $raw % $period;
        if ($repeat !== KeyframeOptions::REPEAT_FOREVER && ($iteration >= $plays || ($iteration === $plays - 1 && $rest >= $d))) {
            if ($alternate && $plays % 2 === 0) {
                return [0, $iteration, true]; // it came back to where it started
            }
            return [$d, $iteration, true];
        }
        $at = min($rest, $d);
        if ($alternate && $iteration % 2 === 1) {
            $at = $d - $at;
        }
        return [$at, $iteration, false];
    }

    /**
     * The color $k of the way from $a to $b, each [rgb, alpha], mixed with
     * premultiplied alpha, so that a color fading from transparent keeps
     * its hue (mygo's mixPremultiplied).
     * @param array{0: int, 1: float} $a
     * @param array{0: int, 1: float} $b
     * @return array{0: int, 1: float}
     */
    public static function mixPremultiplied(array $a, array $b, float $k): array
    {
        [$ac, $aa] = $a; [$bc, $ba] = $b;
        $alpha = $aa + ($ba - $aa) * $k;
        if ($alpha <= 0) {
            return [0, 0.0];
        }
        $ch = function (int $shift) use ($ac, $bc, $aa, $ba, $k, $alpha): int {
            $x = ($ac >> $shift & 255) * $aa; $y = ($bc >> $shift & 255) * $ba;
            return (int)max(0, min(255, ($x + ($y - $x) * $k) / $alpha + 0.5)) << $shift;
        };
        return [$ch(16) | $ch(8) | $ch(0), $alpha];
    }
}
