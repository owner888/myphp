<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** How many rows beyond the first each line wraps into (mygo's wrapRows): per line, with a Fenwick tree of the sums. */
final class WrapRows
{
    public const UNKNOWN = 0xffff;

    public bool $on = false;
    /** Changes with any line's rows. */
    public int $version = 0;
    /** @var list<int> per line; UNKNOWN when not measured */
    public array $extra = [];
    /** @var list<int> Fenwick tree of the extra rows, 1-based */
    private array $tree = [];
    /** The tree needs building. */
    private bool $stale = false;
    /** Lines measured. */
    public int $known = 0;

    public function reset(int $n, bool $on): void
    {
        $this->on = $on;
        if (!$on) { $this->extra = []; $this->tree = []; return; }
        $this->extra = $n > 0 ? array_fill(0, $n, self::UNKNOWN) : [];
        $this->stale = true; $this->known = 0;
        $this->version++;
    }

    public function rows(int $line): int
    {
        if (!$this->on || $line < 0 || $line >= count($this->extra) || $this->extra[$line] === self::UNKNOWN) { return 1; }
        return $this->extra[$line] + 1;
    }

    public function set(int $line, int $rows): void
    {
        if (!$this->on || $line < 0 || $line >= count($this->extra)) { return; }
        $old = $this->extra[$line];
        $v = min(max($rows - 1, 0), self::UNKNOWN - 1);
        if ($old === $v) { return; }
        $this->extra[$line] = $v;
        $this->version++;
        if ($old === self::UNKNOWN) { $this->known++; $old = 0; }
        if (!$this->stale) {
            for ($i = $line + 1, $n = count($this->extra); $i <= $n; $i += $i & -$i) { $this->tree[$i] += $v - $old; }
        }
    }

    private function build(): void
    {
        $n = count($this->extra);
        $this->tree = array_fill(0, $n + 1, 0);
        foreach ($this->extra as $i => $e) {
            if ($e !== self::UNKNOWN) { $this->tree[$i + 1] += $e; }
        }
        for ($i = 1; $i <= $n; $i++) {
            $j = $i + ($i & -$i);
            if ($j <= $n) { $this->tree[$j] += $this->tree[$i]; }
        }
        $this->stale = false;
    }

    /** The extra rows of the lines before $line. */
    public function before(int $line): int
    {
        if (!$this->on) { return 0; }
        if ($this->stale) { $this->build(); }
        $s = 0;
        for ($i = min($line, count($this->extra)); $i > 0; $i -= $i & -$i) { $s += $this->tree[$i]; }
        return $s;
    }

    /** Follows a change of lines: the lines from $at to $at+$removed replaced by $added ones, which are not measured. */
    public function splice(int $at, int $removed, int $added): void
    {
        if (!$this->on) { return; }
        $at = min($at, count($this->extra));
        $end = min($at + $removed, count($this->extra));
        for ($i = $at; $i < $end; $i++) { if ($this->extra[$i] !== self::UNKNOWN) { $this->known--; } }
        array_splice($this->extra, $at, $end - $at, $added > 0 ? array_fill(0, $added, self::UNKNOWN) : []);
        $this->stale = true;
        $this->version++;
    }
}
