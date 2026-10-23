<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\NetworkMonitor;

/** Whether the computer is online (mygo's Network). */
final class Network
{
    /** @var array<int, Closure(NetworkStatus): void> */
    private static array $onChanged = [];
    private static int $next = 0;
    private static bool $watching = false;
    /** What the listeners last saw, so that they hear of changes only: the system reports changes of routes and interfaces that leave the status as it was. */
    private static ?NetworkStatus $last = null;

    /** The computer's network state (mygo's Status), once the app runs: the first call waits for the system's first answer, which comes within milliseconds. */
    public static function status(): NetworkStatus
    {
        App::running() || throw new \RuntimeException('myphp: Network::status needs the running app (call it from App::whenReady)');
        return NetworkMonitor::status();
    }

    /** Calls $fn with the new state when the computer's network state changes (mygo's OnChanged). Returns a function that removes it. */
    public static function onChanged(Closure $fn): Closure
    {
        $k = self::$next++;
        self::$onChanged[$k] = $fn;
        if (!self::$watching) {
            self::$watching = true;
            App::whenReady(function (): void {
                NetworkMonitor::$onPath = self::changed(...);
                self::$last = NetworkMonitor::status();
            });
        }
        return function () use ($k): void { unset(self::$onChanged[$k]); };
    }

    /** Takes a status from the monitor, and calls the listeners when it differs from the last. @internal */
    public static function changed(NetworkStatus $s): void
    {
        if (self::$last !== null && self::$last->online === $s->online && self::$last->metered === $s->metered) {
            return;
        }
        self::$last = $s;
        foreach (self::$onChanged as $fn) {
            $fn($s);
        }
    }
}
