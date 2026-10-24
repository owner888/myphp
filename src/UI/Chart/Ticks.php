<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

/**
 * The tick spacing of d3-array's ticks: steps of 1, 2 or 5 times a power
 * of ten, the closest to (hi - lo) / count; a step below 1 as the negative
 * of its inverse, so that ticks such as 0.3 come out exact.
 *
 * @internal
 */
final class Ticks
{
    /** @return array{0: float, 1: float, 2: float} the first and last tick's index and the step */
    public static function spec(float $lo, float $hi, float $count): array
    {
        $step = ($hi - $lo) / max(0.0, $count);
        $power = floor(log10($step));
        $e = $step / 10 ** $power;
        $factor = $e >= sqrt(50) ? 10 : ($e >= sqrt(10) ? 5 : ($e >= sqrt(2) ? 2 : 1));
        if ($power < 0) {
            $inc = 10 ** -$power / $factor;
            [$i1, $i2] = [round($lo * $inc), round($hi * $inc)];
            if ($i1 / $inc < $lo) { $i1++; }
            if ($i2 / $inc > $hi) { $i2--; }
            $inc = -$inc;
        } else {
            $inc = 10 ** $power * $factor;
            [$i1, $i2] = [round($lo / $inc), round($hi / $inc)];
            if ($i1 * $inc < $lo) { $i1++; }
            if ($i2 * $inc > $hi) { $i2--; }
        }
        if ($i2 < $i1 && 0.5 <= $count && $count < 2) {
            return self::spec($lo, $hi, $count * 2);
        }
        return [(float)$i1, (float)$i2, (float)$inc];
    }

    /** The round values between $lo and $hi, in their order. @return list<float> */
    public static function values(float $lo, float $hi, float $count): array
    {
        if (!($count > 0) || is_nan($lo) || is_nan($hi) || is_infinite($lo) || is_infinite($hi)) {
            return [];
        }
        if ($lo == $hi) {
            return [$lo];
        }
        $reverse = $hi < $lo;
        if ($reverse) {
            [$lo, $hi] = [$hi, $lo];
        }
        [$i1, $i2, $inc] = self::spec($lo, $hi, $count);
        if (!($i2 >= $i1) || is_infinite($inc) || is_nan($inc)) {
            return [];
        }
        $n = (int)($i2 - $i1) + 1;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $k = $reverse ? $n - 1 - $i : $i;
            $out[] = $inc < 0 ? ($i1 + $k) / -$inc : ($i1 + $k) * $inc;
        }
        return $out;
    }

    /** The step between ticks, or its negative inverse below 1, as d3-array's tickIncrement. */
    public static function increment(float $lo, float $hi, float $count): float
    {
        return self::spec($lo, $hi, $count)[2];
    }

    /** The step between ticks, signed as hi - lo. */
    public static function step(float $lo, float $hi, float $count): float
    {
        $reverse = $hi < $lo;
        if ($reverse) {
            [$lo, $hi] = [$hi, $lo];
        }
        $inc = self::increment($lo, $hi, $count);
        if ($inc < 0) {
            $inc = 1 / -$inc;
        }
        return $reverse ? -$inc : $inc;
    }
}
