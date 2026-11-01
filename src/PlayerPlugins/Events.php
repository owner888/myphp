<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;

/**
 * A player's events, as IINA's EventController: listeners by name
 * ("iina.file-loaded", "mpv.seek", "mpv.pause.changed"), each an id, called
 * with the event's data as it is emitted; and the properties observed for
 * their "mpv.<name>.changed", whose values poll() compares as the player
 * changes (mpv's observe_property).
 */
final class Events
{
    /** @var array<string, array<string, Closure>> */
    private array $listeners = [];
    /** @var array<string, ?string> the properties observed, their values last seen */
    private array $observed = [];

    /** Whether $name has listeners. */
    public function has(string $name): bool
    {
        return ($this->listeners[$name] ?? []) !== [];
    }

    /** Listens to $name: the listener's id. */
    public function add(string $name, Closure $fn): string
    {
        $id = bin2hex(random_bytes(8));
        $this->listeners[$name][$id] = $fn;
        return $id;
    }

    /** Whether listener $id of $name was there to remove. */
    public function remove(string $id, string $name): bool
    {
        if (!isset($this->listeners[$name][$id])) { return false; }
        unset($this->listeners[$name][$id]);
        return true;
    }

    /** Calls $name's listeners with $data. */
    public function emit(string $name, mixed ...$data): void
    {
        foreach ($this->listeners[$name] ?? [] as $fn) { $fn(...$data); }
    }

    /** Observes property $name for its "mpv.<name>.changed" (once). */
    public function observe(string $name, ?string $now = null): void
    {
        if (!array_key_exists($name, $this->observed)) { $this->observed[$name] = $now; }
    }

    /** @return list<string> the properties observed */
    public function observed(): array
    {
        return array_keys($this->observed);
    }

    /**
     * Each property observed whose value ($value of its name: its string,
     * null for none) is not the one last seen: its "mpv.<name>.changed"
     * emitted with the new value (typed()).
     * @param Closure(string): ?string $value
     */
    public function poll(Closure $value): void
    {
        foreach ($this->observed as $name => $last) {
            $now = $value($name);
            if ($now === $last) { continue; }
            $this->observed[$name] = $now;
            $this->emit("mpv.$name.changed", self::typed($now));
        }
    }

    /** A property's value as mpv gives it to IINA's plugins: a number, yes and no a bool, else the string; null for none. */
    public static function typed(?string $v): mixed
    {
        return match (true) {
            $v === null => null,
            $v === 'yes' => true,
            $v === 'no' => false,
            is_numeric($v) => (float)$v,
            default => $v,
        };
    }
}
