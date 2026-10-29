<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Logger;

/**
 * Frame statistics (mygo's frameStats): with MYPHP_FRAME_STATS set, a
 * window logs each frame slower than a threshold, with how long each part
 * took, how many passes built it, what the process came to hold
 * meanwhile, the text layouts made and whether PHP's collector ran.
 * Unset, frames measure nothing. MYPHP_STATS, a line a second, stays as
 * it was.
 */
final class FrameStats
{
    /** The parts of a frame. */
    public const BUILD = 0, LAYOUT = 1, PAINT = 2, PRESENT = 3;
    /** Writes the line of a frame; tests replace it. Null writes it to the standard error. */
    public static ?Closure $log = null;
    /** How many passes built the frame, which the window sets. */
    public int $passes = 0;
    private float $start = 0.0, $last = 0.0;
    /** @var array{0: float, 1: float, 2: float, 3: float} seconds of each part */
    private array $laps = [0.0, 0.0, 0.0, 0.0];
    private int $memory = 0, $runs = 0, $layouts = 0;

    /** $threshold: how long, in seconds, a frame takes before it is logged. */
    public function __construct(public readonly float $threshold) {}

    /**
     * The threshold MYPHP_FRAME_STATS sets, in seconds, and whether it is
     * set (mygo's frameStatsSetting): 1 (or true, on, yes) logs frames over
     * 8 ms, another number those over that many milliseconds, all every
     * frame; anything else logs those over 8 ms, saying so.
     * @return array{0: float, 1: bool}
     */
    public static function setting(string $v): array
    {
        $v = strtolower(trim($v));
        return match (true) {
            in_array($v, ['', '0', 'false', 'off', 'no'], true) => [0.0, false],
            in_array($v, ['1', 'true', 'on', 'yes'], true) => [0.008, true],
            $v === 'all' => [0.0, true],
            is_numeric($v) && (float)$v >= 0 => [(float)$v / 1000, true],
            default => (function () use ($v): array {
                Logger::warn("MYPHP_FRAME_STATS=\"$v\" is not a number of milliseconds: logging frames over 8 ms", 'frames');
                return [0.008, true];
            })(),
        };
    }

    /** The statistics MYPHP_FRAME_STATS asks for, or null while it is unset. */
    public static function fromEnv(): ?self
    {
        [$threshold, $on] = self::setting((string)getenv('MYPHP_FRAME_STATS'));
        return $on ? new self($threshold) : null;
    }

    /** Starts measuring a frame; $layouts is how many layouts the text system made so far. */
    public function begin(int $layouts): void
    {
        $this->laps = [0.0, 0.0, 0.0, 0.0];
        $this->passes = 0;
        $this->memory = memory_get_usage();
        $this->runs = gc_status()['runs'];
        $this->layouts = $layouts;
        $this->start = $this->last = hrtime(true) / 1e9;
    }

    /** Ends a part of the frame, which adds up over the passes of a frame. */
    public function lap(int $phase): void
    {
        $now = hrtime(true) / 1e9;
        $this->laps[$phase] += $now - $this->last;
        $this->last = $now;
    }

    /** Logs frame $frame when it took the threshold or longer, and returns the line, or null; $path says what drew it. */
    public function end(int $frame, int $layouts, string $path): ?string
    {
        $total = hrtime(true) / 1e9 - $this->start;
        if ($total < $this->threshold) {
            return null;
        }
        $ms = static fn(float $s): string => number_format($s * 1000, 1, '.', '');
        $other = $total - array_sum($this->laps);
        $passes = $this->passes > 1 ? " ({$this->passes} passes)" : '';
        $runs = gc_status()['runs'] - $this->runs;
        $gc = $runs > 0 ? "GC: $runs runs" : 'no GC';
        $line = sprintf('frame %d took %s ms: build %s%s, layout %s, paint %s, present %s (%s), other %s; meanwhile the process came to hold %+.1f KB, %d text layouts; %s',
            $frame, $ms($total), $ms($this->laps[self::BUILD]), $passes, $ms($this->laps[self::LAYOUT]), $ms($this->laps[self::PAINT]), $ms($this->laps[self::PRESENT]), $path, $ms($other),
            (memory_get_usage() - $this->memory) / 1024, $layouts - $this->layouts, $gc);
        self::$log !== null ? (self::$log)($line) : Logger::debug($line, 'frames');
        return $line;
    }
}
