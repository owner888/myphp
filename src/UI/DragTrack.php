<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The last moves of a press, to tell how fast it goes (mygo's dragTrack):
 * in the window's axes, from where the press began ($ox, $oy) by ($wx,
 * $wy). $far tells that the press went Runtime::DRAG_START points from
 * there.
 */
final class DragTrack
{
    public float $wx = 0.0, $wy = 0.0;
    public bool $far = false;
    /** @var list<array{0: int, 1: float, 2: float}> the moves kept, newest at $last: when (nanoseconds), wx, wy */
    private array $samples = [];
    public int $n = 0;
    private int $last = 0;
    /** Where the pointer was at the last move, to tell the next one's. */
    private float $px, $py;

    /** Starts tracking a press at the window's point ($ox, $oy), at $now. */
    public function __construct(int $now, public readonly float $ox, public readonly float $oy)
    {
        $this->px = $ox; $this->py = $oy;
        $this->add($now);
    }

    private function add(int $now): void
    {
        $this->last = ($this->last + 1) % 8;
        $this->samples[$this->last] = [$now, $this->wx, $this->wy];
        $this->n = min($this->n + 1, 8);
    }

    /** Adds a move of the pointer to the window's point ($x, $y), at $now. */
    public function move(int $now, float $x, float $y): void
    {
        $dx = $x - $this->px; $dy = $y - $this->py;
        if ($dx == 0 && $dy == 0) {
            return; // as with the release's position
        }
        $this->px = $x; $this->py = $y;
        $this->wx += $dx; $this->wy += $dy;
        $this->far = $this->far || $this->wx * $this->wx + $this->wy * $this->wy >= Runtime::DRAG_START * Runtime::DRAG_START;
        $this->add($now);
    }

    /** How fast the press went over its last 100 ms, in the window's points per second: none once it has not moved for 50 ms. @return array{0: float, 1: float} */
    public function velocity(int $now): array
    {
        if ($this->n < 2) {
            return [0.0, 0.0];
        }
        $newest = $this->samples[$this->last];
        if ($now - $newest[0] > 50_000_000) {
            return [0.0, 0.0];
        }
        $oldest = $newest;
        for ($i = 1; $i < $this->n; $i++) {
            $s = $this->samples[($this->last - $i + 8) % 8];
            if ($newest[0] - $s[0] > 100_000_000) {
                break;
            }
            $oldest = $s;
        }
        $dt = ($newest[0] - $oldest[0]) / 1e9;
        if ($dt <= 0) {
            return [0.0, 0.0];
        }
        return [($newest[1] - $oldest[1]) / $dt, ($newest[2] - $oldest[2]) / $dt];
    }
}
