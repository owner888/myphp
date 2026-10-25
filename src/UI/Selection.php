<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * The rows chosen in a list, table or grid that lets the user choose
 * several (ListState::$selection, GridState::$selection), as mygo's
 * Selection: held by the keys of their items (ListState::$key), so the
 * choice stays with its items as rows come, go and move; without a key,
 * rows are their indices. The keys of items gone stay until removed. A
 * click chooses one, Cmd-click adds a row or takes it out, Shift-click
 * chooses those from the row last chosen to it, as Shift with the keys
 * that move the choice does, and Cmd+A chooses all.
 */
final class Selection
{
    /** @var array<int|string, true> */
    private array $keys = [];
    /** The row Shift extends from (pivot), and how far it extended last (span), as mygo's: Shift chooses in place of those it last chose. */
    public int $pivot = -1, $span = 0;
    /** The key of the pivot's item, which the pivot follows as rows come and go (mygo's anchor key). */
    private int|string|null $pivotKey = null;

    public function has(int|string $key): bool { return isset($this->keys[$key]); }
    public function count(): int { return count($this->keys); }
    public function add(int|string $key): void { $this->keys[$key] = true; }
    public function remove(int|string $key): void { unset($this->keys[$key]); }
    public function clear(): void { $this->keys = []; }
    /** @return list<int|string> the keys chosen, in no order */
    public function all(): array { return array_keys($this->keys); }

    /** Chooses $key, or takes it out; whether that changed the choice. */
    public function set(int|string $key, bool $on): bool
    {
        if ($this->has($key) === $on) { return false; }
        $on ? $this->add($key) : $this->remove($key);
        return true;
    }

    /**
     * Chooses row $i as a click with $mods does, among $n rows keyed by
     * $key: alone, with Cmd added or taken out, with Shift those from the
     * pivot to it (mygo's listFrame.click). Returns whether the choice
     * changed; $lead becomes $i.
     */
    public function click(int $i, int $mods, int $n, Closure $key, int &$lead): bool
    {
        $lead = $i;
        if ($mods & Key::SHIFT) { return $this->extend($i, $n, $key); }
        if ($mods & Key::COMMAND) { $this->pivotAt($i, $key); return $this->set($key($i), !$this->has($key($i))); }
        return $this->choose($i, $key);
    }

    /** Makes row $i the choice alone; whether that changed it. */
    public function choose(int $i, Closure $key): bool
    {
        $k = $key($i); $changed = false;
        if ($this->count() !== 1 || !$this->has($k)) { $this->clear(); $this->add($k); $changed = true; }
        $this->pivotAt($i, $key);
        return $changed;
    }

    /** Chooses the rows from the pivot to $i, as Shift does on macOS: in place of those it last chose. */
    public function extend(int $i, int $n, Closure $key): bool
    {
        $p = $this->pivot($n, $key);
        if ($p < 0) { return $this->choose($i, $key); }
        $lo = min($p, $i); $hi = max($p, $i); $changed = false;
        $last = max(0, min($p + $this->span, $n - 1));
        for ($j = min($p, $last); $j <= max($p, $last); $j++) {
            if ($j < $lo || $j > $hi) { $changed = $this->set($key($j), false) || $changed; }
        }
        for ($j = $lo; $j <= $hi; $j++) { $changed = $this->set($key($j), true) || $changed; }
        $this->span = $i - $p;
        return $changed;
    }

    /** Makes row $i the pivot, extended from anew. */
    public function pivotAt(int $i, Closure $key): void
    {
        $this->pivot = $i; $this->span = 0; $this->pivotKey = $key($i);
    }

    /** Where the pivot's item is among $n rows, the rows above it having come or gone; -1 for none, or gone. */
    private function pivot(int $n, Closure $key): int
    {
        $p = $this->pivot;
        if ($this->pivotKey === null) { return $p >= 0 && $p < $n ? $p : -1; }
        if ($p >= 0 && $p < $n && $key($p) === $this->pivotKey) { return $p; }
        for ($j = 0; $j < $n; $j++) {
            if ($key($j) === $this->pivotKey) { return $this->pivot = $j; }
        }
        return -1;
    }

    /** Chooses every one of $n rows (Cmd+A); whether that changed the choice. */
    public function selectAll(int $n, Closure $key): bool
    {
        $changed = false;
        for ($j = 0; $j < $n; $j++) { $changed = $this->set($key($j), true) || $changed; }
        return $changed;
    }
}
