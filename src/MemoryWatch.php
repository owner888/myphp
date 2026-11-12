<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Watches what the process holds, while the app runs in development: every
 * 10 seconds it collects PHP's cycles and logs the memory left (debug, the
 * "memory" subsystem), and warns when it comes near memory_limit or grows
 * sample after sample, a leak's signature, so that a leak shows in the log
 * the minute it starts rather than as "Allowed memory size exhausted" much
 * later. A sample that collects many cycles warns too: something builds a
 * cycle every frame (a callback a node keeps that holds the node, or the
 * Context), garbage the collector frees only in runs long enough to drop
 * frames (docs/architecture.md).
 */
final class MemoryWatch
{
    /** Seconds between samples. */
    public const EVERY = 10.0;
    /** The share of memory_limit at which it warns, and the share below which it warns again once it went over. */
    public const NEAR = 0.75, CLEAR = 0.6;
    /** Samples in a row that each grew (a minute of them), and how much they grew together, that make a leak's signature. */
    public const GROWING = 6, GREW = 1 << 20;
    /** Cycles one sample's collection frees that tell of a cycle made over and over: a frame's tree whose nodes' callbacks hold them makes hundreds a frame. */
    public const CYCLES = 1000;

    /** @var list<int> the live bytes of the last samples, oldest first */
    private array $live = [];
    private bool $near = false;
    private int $peak = 0;

    /** $limit: memory_limit in bytes, -1 for none. */
    public function __construct(public readonly int $limit) {}

    /** Starts watching, once, in development. */
    public static function start(): void
    {
        static $started = false;
        if ($started || !App::isDev()) {
            return;
        }
        $started = true;
        // Whether the compiled code counts in "live": in opcache's shared memory it does not, without opcache it does (the
        // framework's few MB), so that two platforms' numbers compare only with the same answer.
        $st = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        Logger::debug(is_array($st) && ($st['opcache_enabled'] ?? false)
            ? 'opcache caches the compiled code: "live" is the app\'s data'
            : sprintf('no opcache: "live" counts the compiled code of the %d files loaded too', count(get_included_files())), 'memory');
        $w = new self(self::bytes((string)ini_get('memory_limit')));
        $tick = null;
        $tick = static function () use ($w, &$tick): void {
            $freed = gc_collect_cycles();
            $w->sample(memory_get_usage(), $freed);
            App::after(self::EVERY, $tick);
        };
        App::after(self::EVERY, $tick);
    }

    /**
     * Takes a sample: $live bytes held once $freed cycles were collected.
     * Logs the line, and returns the warnings it logged.
     * @return list<string>
     */
    public function sample(int $live, int $freed): array
    {
        $before = $this->live === [] ? null : array_last($this->live);
        $this->live[] = $live;
        if (count($this->live) > self::GROWING + 1) {
            array_shift($this->live);
        }
        $this->peak = max($this->peak, $live);
        $mb = static fn(int $b): string => number_format($b / 1048576, 1, '.', '');
        Logger::debug(sprintf('%s MB live%s, peak %s MB%s; %d cycles collected', $mb($live),
            $before === null ? '' : sprintf(' (%+.1f MB in %d s)', ($live - $before) / 1048576, self::EVERY), $mb($this->peak),
            $this->limit > 0 ? ' of ' . $mb($this->limit) . ' MB (memory_limit)' : '', $freed), 'memory');
        $warnings = [];
        if ($this->limit > 0) {
            if (!$this->near && $live >= self::NEAR * $this->limit) {
                $this->near = true;
                $warnings[] = sprintf('%s MB live, %d%% of memory_limit (%s MB): the app runs out of memory at %s MB', $mb($live), (int)round(100 * $live / $this->limit), $mb($this->limit), $mb($this->limit));
            } elseif ($this->near && $live < self::CLEAR * $this->limit) {
                $this->near = false;
            }
        }
        if (count($this->live) === self::GROWING + 1) {
            $grew = true;
            for ($i = 1; $i <= self::GROWING; $i++) {
                $grew = $grew && $this->live[$i] > $this->live[$i - 1];
            }
            $by = $this->live[self::GROWING] - $this->live[0];
            if ($grew && $by >= self::GREW) {
                $warnings[] = sprintf('memory grew in each of the last %d samples, %s MB in %d s, after collecting: something keeps what it no longer needs (a leak)', self::GROWING, $mb($by), (int)(self::GROWING * self::EVERY));
                $this->live = [$live]; // a minute more of growth to warn again
            }
        }
        if ($freed >= self::CYCLES) {
            $warnings[] = "$freed cycles collected in " . (int)self::EVERY . ' s: something makes a cycle over and over, such as a callback a node keeps (draw, drawOver) that holds the node or the Context';
        }
        foreach ($warnings as $w) {
            Logger::warn($w, 'memory');
        }
        return $warnings;
    }

    /** The bytes of a php.ini size: 128M, 1G, 512K, a number, or -1 for none. */
    public static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int)$v;
        return match (strtoupper(substr($v, -1))) {
            'G' => $n << 30,
            'M' => $n << 20,
            'K' => $n << 10,
            default => $n,
        };
    }
}
