<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use Closure;
use MyPHP\Plugins\Terminal\Pty\Pty;

/**
 * Writes what is typed to the program in order (mygo's inputQueue), as
 * much as it takes at each turn of the poll: a program that does not read
 * its input must not block the terminal, which answers its queries.
 *
 * @internal
 */
final class InputQueue
{
    /** @var list<array{0: string, 1: ?Closure}> input to write, and what to run once it is written */
    private array $queue = [];
    private bool $closed = false;

    public function push(string $p): void { $this->pushDone($p, null); }

    /** Queues $p, and $done to run once it is written. */
    public function pushDone(string $p, ?Closure $done): void
    {
        if (!$this->closed) { $this->queue[] = [$p, $done]; }
    }

    public function close(): void
    {
        $this->closed = true;
        $this->queue = [];
    }

    /** Whether nothing waits to be written. */
    public function empty(): bool { return $this->queue === []; }

    /** Writes what $w takes now, running what waited for it; returns whether it wrote anything. */
    public function flush(Pty|Conn $w): bool
    {
        $wrote = false;
        while (!$this->closed && $this->queue !== []) {
            [$p, $done] = $this->queue[0];
            if ($p !== '') {
                try {
                    $n = $w->write($p);
                } catch (\Throwable) {
                    $this->close();
                    return $wrote;
                }
                if ($n > 0) { $wrote = true; }
                if ($n < strlen($p)) {
                    $this->queue[0][0] = substr($p, $n);
                    return $wrote; // the program reads the rest later
                }
            }
            array_shift($this->queue);
            if ($done !== null) { $done(); }
        }
        return $wrote;
    }
}
