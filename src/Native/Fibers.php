<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use Fiber;
use MyPHP\App;

/**
 * Work that waits, as a goroutine of mygo's does, on the main thread: code
 * App::go() runs in a Fiber, and a wait (sleep) suspends the fiber until
 * the run loop resumes it (App::after). Code that waits outside a fiber
 * turns the run loop while it does (App::wait), or sleeps when the app
 * does not run. Without the run loop, fibers wait in a queue that drain()
 * runs (tests, scripts).
 *
 * @internal
 */
final class Fibers
{
    /** @var list<array{0: float, 1: Fiber}> fibers waiting without the run loop, by when they go on */
    private static array $queue = [];

    /** Runs $fn in a fiber of its own, at once, until its first wait. */
    public static function go(Closure $fn): void
    {
        (new Fiber($fn))->start();
    }

    /** Waits $seconds, letting the rest of the app run meanwhile. */
    public static function sleep(float $seconds): void
    {
        $fiber = Fiber::getCurrent();
        $seconds = max(0.0, $seconds);
        if ($fiber === null) {
            if (App::running()) {
                $at = microtime(true) + $seconds;
                App::wait(fn(): bool => microtime(true) >= $at);
            } else {
                usleep((int)($seconds * 1e6));
            }
            return;
        }
        if (App::running()) {
            App::after($seconds, fn() => $fiber->resume());
        } else {
            self::$queue[] = [microtime(true) + $seconds, $fiber];
        }
        Fiber::suspend();
    }

    /** Waits until $done() holds, looking again every $interval seconds, backing off to $max. */
    public static function until(Closure $done, float $interval = 0.005, float $max = 0.05): void
    {
        while (!$done()) {
            self::sleep($interval);
            $interval = min($interval * 2, $max);
        }
    }

    /** Runs the fibers that wait without the run loop, until none does. */
    public static function drain(): void
    {
        self::runUntil(fn(): bool => false, INF);
    }

    /** Runs the fibers that wait without the run loop until $done() holds, none waits, or $timeout seconds passed; returns whether $done() held. */
    public static function runUntil(\Closure $done, float $timeout = 5): bool
    {
        $end = microtime(true) + $timeout;
        while (!$done()) {
            if (self::$queue === [] || microtime(true) >= $end) {
                return false;
            }
            usort(self::$queue, fn(array $a, array $b): int => $a[0] <=> $b[0]);
            [$at, $fiber] = array_shift(self::$queue);
            $wait = min($at, $end) - microtime(true);
            if ($wait > 0) {
                usleep((int)($wait * 1e6));
            }
            if ($at > microtime(true)) {
                array_unshift(self::$queue, [$at, $fiber]); // not due yet: the time is up
                continue;
            }
            $fiber->resume();
        }
        return true;
    }
}
