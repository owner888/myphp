<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/**
 * Polylines, as mygo's internal/svg polys: polyline i is the points from
 * $starts[i] to $starts[i+1] in $pts (x, y, x, y…), the last up to the
 * end; $closed[i] says whether it is closed. $begin is where the polyline
 * being built begins, while $building.
 */
final class Polys
{
    /** @var list<float> */
    public array $pts = [];
    /** @var list<int> offsets into $pts */
    public array $starts = [];
    /** @var list<bool> */
    public array $closed = [];
    public int $begin = 0;
    public bool $building = false;

    public function reset(): void { $this->pts = []; $this->starts = []; $this->closed = []; $this->building = false; }

    public function count(): int { return count($this->starts); }

    /** The offsets where polyline $i starts and ends in $pts. @return array{0: int, 1: int} */
    public function range(int $i): array
    {
        return [$this->starts[$i], $this->starts[$i + 1] ?? count($this->pts)];
    }

    /** Begins a polyline at (x, y), ending the one being built. */
    public function start(float $x, float $y): void
    {
        $this->end(false);
        $this->begin = count($this->pts); $this->building = true;
        $this->pts[] = $x; $this->pts[] = $y;
    }

    public function add(float $x, float $y): void { $this->pts[] = $x; $this->pts[] = $y; }

    /** Ends the polyline being built. One of a single point, a move with nothing drawn, is dropped. */
    public function end(bool $closed): void
    {
        if (!$this->building) { return; }
        $this->building = false;
        if (count($this->pts) - $this->begin < 4) { array_splice($this->pts, $this->begin); return; }
        $this->starts[] = $this->begin; $this->closed[] = $closed;
    }

    public function transform(array $m): void
    {
        [$m0, $m1, $m2, $m3, $m4, $m5] = $m;
        $pts = &$this->pts;
        for ($i = 0, $n = count($pts); $i < $n; $i += 2) {
            $x = $pts[$i]; $y = $pts[$i + 1];
            $pts[$i] = $m0 * $x + $m2 * $y + $m4; $pts[$i + 1] = $m1 * $x + $m3 * $y + $m5;
        }
    }
}
