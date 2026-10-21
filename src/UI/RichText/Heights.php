<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * Sums the heights of the paragraphs of an editor (mygo's richtext
 * heights), measured as they are laid out and estimated for the others, in
 * two Fenwick trees: the heights measured, and how many are not, which the
 * estimate multiplies. The top of a paragraph, and the paragraph at a
 * height, take O(log n) whatever the estimate.
 *
 * @internal
 */
final class Heights
{
    /** @var list<float> 1-based */
    private array $measured = [0.0];
    /** @var list<int> */
    private array $unknown = [0];
    public float $est = 0.0;

    /** Sums the heights $hs, 0 for those not measured. @param list<float> $hs */
    public function reset(array $hs): void
    {
        $n = count($hs);
        $this->measured = array_fill(0, $n + 1, 0.0);
        $this->unknown = array_fill(0, $n + 1, 0);
        foreach ($hs as $i => $x) {
            if ($x > 0) { $this->measured[$i + 1] = (float)$x; } else { $this->unknown[$i + 1] = 1; }
        }
        for ($i = 1; $i <= $n; $i++) {
            if (($j = $i + ($i & -$i)) <= $n) {
                $this->measured[$j] += $this->measured[$i];
                $this->unknown[$j] += $this->unknown[$i];
            }
        }
    }

    /** Changes the height measured of paragraph $p by $dm, and the count of those not measured by $du. */
    public function add(int $p, float $dm, int $du): void
    {
        for ($i = $p + 1, $n = count($this->measured); $i < $n; $i += $i & -$i) {
            $this->measured[$i] += $dm;
            $this->unknown[$i] += $du;
        }
    }

    /** The top of paragraph $p: the height of those before it. */
    public function top(int $p): float
    {
        $m = 0.0; $u = 0;
        for ($i = min($p, count($this->measured) - 1); $i > 0; $i -= $i & -$i) {
            $m += $this->measured[$i];
            $u += $this->unknown[$i];
        }
        return $m + $u * $this->est;
    }

    /** The paragraph at $y, the last for $y past the end. */
    public function at(float $y): int
    {
        $n = count($this->measured) - 1;
        if ($n <= 0) { return 0; }
        $pos = 0; $sum = 0.0;
        for ($step = 1 << (strlen(decbin($n)) - 1); $step > 0; $step >>= 1) {
            if (($next = $pos + $step) <= $n) {
                $s = $sum + $this->measured[$next] + $this->unknown[$next] * $this->est;
                if ($s <= $y) { $pos = $next; $sum = $s; }
            }
        }
        return min($pos, $n - 1);
    }

    /** The sum of the heights measured and how many are not. @return array{0: float, 1: int} */
    public function totals(): array
    {
        $m = 0.0; $u = 0;
        for ($i = count($this->measured) - 1; $i > 0; $i -= $i & -$i) {
            $m += $this->measured[$i];
            $u += $this->unknown[$i];
        }
        return [$m, $u];
    }
}
