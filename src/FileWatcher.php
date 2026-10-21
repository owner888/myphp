<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use InvalidArgumentException;
use MyPHP\Native\FileWatch\{Backend, Backends, Scan};
use Throwable;

/**
 * A watch App::watchFiles() started (mygo's FileWatcher). It looks at what
 * the system reported when next() is called, on the main thread: changes
 * that came meanwhile wait in the system's queue, and the debounce starts
 * once next() sees them.
 */
final class FileWatcher
{
    /** @var ?list<FileEvent> the changes waiting for next(), null when none do: the watch doesn't look again until next() takes them */
    private ?array $batch = null;
    private bool $stopped = false;
    private ?Throwable $err = null;
    private bool $done = false;
    private Backend $native;
    private Scan $scan;
    /** When the changes seen have settled (a debounce running), and when the first of them came. */
    private ?float $deadline = null;
    private float $first = 0;
    /** Changes settled while a batch waited for next(). */
    private bool $due = false;
    private Closure $unquit;

    /**
     * @internal App::watchFiles() starts it
     * @param ?Closure(string, bool): bool $ignore
     */
    public function __construct(private readonly string $root, private readonly float $debounce, private readonly float $maxDelay, bool $recursive, ?Closure $ignore, int $maxEntries)
    {
        $this->native = Backends::open();
        $this->scan = new Scan($root, $recursive, $ignore, $maxEntries, $this->native, fn(): bool => $this->stopped);
        try {
            $events = $this->scan->start();
        } catch (Throwable $e) {
            $this->native->close();
            throw $e;
        }
        $this->emit($events);
        // The app stops its watches when it quits.
        $this->unquit = App::onQuit(fn() => $this->close());
    }

    /**
     * The path the watch watches, absolute and with its symlinks resolved
     * (macOS's /tmp is /private/tmp), to which the paths of events are
     * relative.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Waits for the next changes and returns them, a batch of the events
     * that one look found once changes stopped for the debounce. The watch
     * doesn't look again until next() has taken a batch, so nothing is lost
     * when next() is called late: what changed meanwhile comes in the next
     * batch.
     *
     * It returns null once the watch is closed, or ended because its
     * directory went away (mygo's io.EOF), and throws the error that
     * stopped it otherwise. With a $timeout in seconds, it returns [] when
     * no batch came in that time, at once for 0 (mygo's context deadline).
     * While the app runs it keeps processing events as it waits.
     *
     * @return ?list<FileEvent>
     */
    public function next(float $timeout = INF): ?array
    {
        if (is_nan($timeout)) {
            throw new InvalidArgumentException('myphp: NaN watch timeout');
        }
        $until = microtime(true) + max(0.0, $timeout);
        while (true) {
            if ($this->batch !== null) {
                $batch = $this->batch;
                $this->batch = null;
                $this->take();
                return $batch;
            }
            if ($this->stopped) {
                if ($this->err !== null) {
                    throw $this->err;
                }
                return null;
            }
            $wake = $this->deadline === null ? $until : min($until, $this->deadline);
            $app = App::running();
            $this->drive($app ? 0.0 : max(0.0, $wake - microtime(true)));
            if ($this->batch !== null || $this->stopped) {
                continue;
            }
            if (microtime(true) >= $until) {
                return [];
            }
            if ($app) {
                $at = min($wake, microtime(true) + 0.05);
                App::wait(fn(): bool => microtime(true) >= $at);
            }
        }
    }

    /**
     * Stops the watch, releases what it holds and drops the changes not yet
     * returned by next(), which then returns null.
     */
    public function close(): void
    {
        $this->finish(null, true);
    }

    /** Whether the watch has stopped and released its handles (mygo's Done). */
    public function done(): bool
    {
        return $this->done;
    }

    /** The error that stopped the watch: null while it runs, and once it was closed or its directory went away. */
    public function err(): ?Throwable
    {
        return $this->err;
    }

    /** Takes what the system reported, waiting up to $block seconds, and looks once changes settled. */
    private function drive(float $block): void
    {
        try {
            $notices = $this->native->poll($block);
        } catch (Throwable $e) {
            $this->finish($e);
            return;
        }
        foreach ($notices as $n) {
            try {
                $this->scan->mark($n);
            } catch (Throwable $e) {
                $this->finish($e);
                return;
            }
            if ($this->scan->pending()) {
                $this->schedule();
            }
        }
        if ($this->deadline !== null && microtime(true) >= $this->deadline) {
            $this->deadline = null;
            if ($this->batch !== null) {
                $this->due = true;
            } else {
                $this->look();
            }
        }
    }

    /** (Re)starts the debounce, which never runs past maxDelay from the first change. */
    private function schedule(): void
    {
        $now = microtime(true);
        if ($this->deadline === null) {
            $this->first = $now;
        }
        $this->deadline = $now + min($this->debounce, $this->first + $this->maxDelay - $now);
    }

    /** A batch was taken: changes that settled meanwhile are looked at now. */
    private function take(): void
    {
        if ($this->due && $this->deadline === null) {
            $this->look();
        }
    }

    /** Looks at what changed and reports it, or ends the watch. */
    private function look(): void
    {
        $this->due = false;
        try {
            $this->emit($this->scan->update());
        } catch (Throwable $e) {
            if ($this->stopped) {
                return;
            }
            if ($this->scan->gone()) {
                // The directory went away: say so, then end as closed.
                $this->emit($this->scan->goneEvents());
                $this->finish(null, false);
                return;
            }
            $this->finish($e);
        }
    }

    /** Keeps events for next(). @param list<FileEvent> $events */
    private function emit(array $events): void
    {
        if ($events === [] || $this->stopped) {
            return;
        }
        $this->batch = [...($this->batch ?? []), ...$events];
    }

    private function finish(?Throwable $err, bool $discard = true): void
    {
        if ($discard) {
            $this->batch = null;
        }
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $this->err = $err;
        $this->deadline = null;
        $this->native->close();
        ($this->unquit)();
        $this->done = true;
    }
}
