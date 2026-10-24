<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

use Closure;

/**
 * Maps numbers from lo to hi onto the plot, lo at its start (mygo's
 * chart.Linear), as d3's scaleLinear and Swift Charts' automatic scales of
 * numbers. Values outside the domain go outside the plot unless $clamp.
 *
 *     $y = (new Linear(0, 87))->nice(10); // 0 to 90, ticks every 10
 *     $bar = $y->span(0, 42);
 */
final class Linear
{
    /** @param ?Closure(float): string $format labels the ticks; null labels them with as many decimals as their step needs */
    public function __construct(
        public float $lo,
        public float $hi,
        /** Keeps values outside the domain at its ends. */
        public bool $clamp = false,
        public ?Closure $format = null,
    ) {}

    /** Where $v is along the plot. */
    public function at(float $v): float
    {
        if ($this->hi == $this->lo) {
            return 0.5;
        }
        $f = ($v - $this->lo) / ($this->hi - $this->lo);
        return $this->clamp ? max(0.0, min(1.0, $f)) : $f;
    }

    /** The stretch from $a to $b along the plot, as a bar's from 0 to its value. */
    public function span(float $a, float $b): Span
    {
        return new Span($this->at($a), $this->at($b));
    }

    /** The value at $f along the plot, as where the pointer is. */
    public function invert(float $f): float
    {
        $v = $this->lo + $f * ($this->hi - $this->lo);
        return $this->clamp ? max(min($this->lo, $this->hi), min(max($this->lo, $this->hi), $v)) : $v;
    }

    /** The domain extended to round values, those of about $count ticks, as d3's nice: 0 to 87 becomes 0 to 90 for 10 ticks, and 0 to 100 for 5. */
    public function nice(int $count): self
    {
        [$lo, $hi] = [$this->lo, $this->hi];
        $reverse = $hi < $lo;
        if ($reverse) {
            [$lo, $hi] = [$hi, $lo];
        }
        $prev = null;
        for ($i = 0; $i < 10; $i++) {
            $step = Ticks::increment($lo, $hi, $count);
            if ($step === $prev) {
                break;
            }
            if ($step > 0) {
                [$lo, $hi] = [floor($lo / $step) * $step, ceil($hi / $step) * $step];
            } elseif ($step < 0) {
                [$lo, $hi] = [ceil($lo * $step) / $step, floor($hi * $step) / $step];
            } else {
                return clone $this;
            }
            $prev = $step;
        }
        $s = clone $this;
        [$s->lo, $s->hi] = $reverse ? [$hi, $lo] : [$lo, $hi];
        return $s;
    }

    /** About $count round values within the domain, 1, 2 or 5 times a power of ten apart, as d3's ticks: 0, 20, 40, 60, 80 and 100 for 0 to 100 and 5. @return list<Tick> */
    public function ticks(int $count): array
    {
        $format = $this->format;
        if ($format === null) {
            $step = abs(Ticks::step($this->lo, $this->hi, $count));
            $decimals = 0;
            if ($step > 0 && $step < 1) {
                $decimals = max(0, (int)-floor(log10($step) + 1e-9));
                if ($step * 10 ** $decimals != round($step * 10 ** $decimals)) {
                    $decimals++;
                }
            }
            $format = fn(float $v): string => number_format($v == 0 ? 0.0 : $v, $decimals, '.', ''); // no -0
        }
        return array_map(fn(float $v): Tick => new Tick($this->at($v), $v, $format($v)), Ticks::values($this->lo, $this->hi, $count));
    }
}
