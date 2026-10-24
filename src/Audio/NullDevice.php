<?php
declare(strict_types=1);

namespace MyPHP\Audio;

use MyPHP\App;

/**
 * Plays nothing, on time, so that players still end when they would
 * have (mygo's nullDevice): every 10 ms it mixes what played meanwhile.
 * One standing in for a device that failed to open tries the device again
 * after two seconds. Without a running app, tests drive it with tick().
 * @internal
 */
final class NullDevice implements Device
{
    /** 10 ms of frames at a time. */
    public const FRAMES = Mixer::RATE / 100;
    private float $next;
    private ?float $retryAt = null;
    private bool $closed = false;

    /** @param ?float $now the clock, in seconds, for a device that tests drive; null for the app's */
    public function __construct(?float $now, bool $retry)
    {
        $this->next = $now ?? microtime(true);
        if ($retry) { $this->retryAt = $this->next + 2.0; }
    }

    public function start(): void
    {
        if (App::running()) { $this->schedule(); }
    }

    private function schedule(): void
    {
        App::after(self::FRAMES / Mixer::RATE, function (): void {
            if ($this->closed) { return; }
            $this->tick(microtime(true));
            if (!$this->closed) { $this->schedule(); }
        });
    }

    /** Mixes what played until $now, in seconds. */
    public function tick(float $now): void
    {
        if ($this->retryAt !== null && $now >= $this->retryAt) {
            Output::lost($this); // try the device again
            return;
        }
        if ($now - $this->next > 0.1) { $this->next = $now; } // behind, as after a sleep: don't hurry to catch up
        while ($this->next + self::FRAMES / Mixer::RATE <= $now + 1e-9 && !$this->closed) {
            Output::render(self::FRAMES);
            $this->next += self::FRAMES / Mixer::RATE;
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
