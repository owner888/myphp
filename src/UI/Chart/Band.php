<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

/**
 * Divides the plot into a band for each of its labels (mygo's
 * chart.Band), as d3's scaleBand and Swift Charts' scales of categories:
 * the bars of a bar chart, one for each month.
 *
 *     $x = (new Band(...$months))->padding(0.2, 0.1);
 *     $bar = $x->band(3); // April's
 */
final class Band
{
    /** @var list<string> */
    public array $labels;
    /** The room between bands, and before the first and after the last, in fractions of a band's step. */
    public float $inner = 0.0, $outer = 0.0;
    /** Where the bands go in the room the outer padding leaves: 0 at the start, 0.5 in the middle, 1 at the end. */
    public float $align = 0.5;

    public function __construct(string ...$labels)
    {
        $this->labels = array_values($labels);
    }

    /** The room between bands, and before and after them, in fractions of a band's step: 0.2 leaves a fifth of each step between bars. */
    public function padding(float $inner, float $outer): self
    {
        $b = clone $this;
        [$b->inner, $b->outer] = [max(0.0, min(1.0, $inner)), max(0.0, $outer)];
        return $b;
    }

    /** The distance from a band to the next, and where the first starts. @return array{0: float, 1: float} */
    private function step(): array
    {
        $n = count($this->labels);
        $step = 1 / max(1.0, $n - $this->inner + 2 * $this->outer);
        return [$step, (1 - $step * ($n - $this->inner)) * $this->align];
    }

    /** The stretch of the $i-th band. */
    public function band(int $i): Span
    {
        [$step, $start] = $this->step();
        $lo = $start + $step * $i;
        return new Span($lo, $lo + $this->width());
    }

    /** The width of a band, in a fraction of the plot. */
    public function width(): float
    {
        return $this->step()[0] * (1 - $this->inner);
    }

    /** The middle of the $i-th band. */
    public function at(int $i): float
    {
        return $this->band($i)->mid();
    }

    /** The band at $f along the plot, the one whose step holds it, so that the room between bars belongs to a bar; null outside of every band's step. */
    public function index(float $f): ?int
    {
        if ($this->labels === []) {
            return null;
        }
        [$step, $start] = $this->step();
        $k = ($f - $start + $step * $this->inner / 2) / $step; // a band's step starts half the inner padding before it
        return $k < 0 || $k >= count($this->labels) ? null : (int)$k;
    }

    /** A tick in the middle of each band, labeled with its label. @return list<Tick> */
    public function ticks(): array
    {
        $out = [];
        foreach ($this->labels as $i => $l) {
            $out[] = new Tick($this->at($i), $i, $l);
        }
        return $out;
    }
}
