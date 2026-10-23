<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use MyPHP\{App, NetworkStatus};

/**
 * The app's one path monitor of Network.framework (mygo's darwin
 * network.go), started by the first status or watch. Its handler runs on
 * the main queue, which the run loop drains, as PHP runs on the main
 * thread only; status() turns the run loop until the first path comes.
 *
 * @internal
 */
final class NetworkMonitor
{
    /** nw_path_status_satisfied: the path has a usable route to send and receive data on. */
    private const SATISFIED = 1;
    private static ?FFI $c = null;
    private static ?NetworkStatus $status = null;
    /** @var ?\Closure(NetworkStatus): void what hears the paths after the first */
    public static ?\Closure $onPath = null;

    private static function start(): void
    {
        if (self::$c !== null) {
            return;
        }
        $c = self::$c = FFI::cdef('
            void* nw_path_monitor_create(void);
            void nw_path_monitor_set_update_handler(void* monitor, void* handler);
            void nw_path_monitor_set_queue(void* monitor, void* queue);
            void nw_path_monitor_start(void* monitor);
            int nw_path_get_status(void* path);
            bool nw_path_is_expensive(void* path);
            bool nw_path_is_constrained(void* path);
            extern char _dispatch_main_q;
        ', '/System/Library/Frameworks/Network.framework/Network');
        $m = $c->nw_path_monitor_create();
        if ($m === null) {
            self::$status = new NetworkStatus;
            return;
        }
        $block = ObjC::block('p', function ($path) use ($c): void {
            $s = new NetworkStatus($c->nw_path_get_status($path) === self::SATISFIED, $c->nw_path_is_expensive($path) || $c->nw_path_is_constrained($path));
            $first = self::$status === null;
            self::$status = $s;
            if (!$first && self::$onPath !== null) {
                (self::$onPath)($s);
            }
        }, once: false);
        $c->nw_path_monitor_set_update_handler($m, $block); // the monitor copies the handler
        $c->nw_path_monitor_set_queue($m, FFI::addr($c->_dispatch_main_q));
        $c->nw_path_monitor_start($m); // the monitor lives as long as the app
    }

    public static function status(): NetworkStatus
    {
        self::start();
        $end = microtime(true) + 1;
        // The first path comes within milliseconds; never hang the app on a monitor that does not answer.
        App::wait(fn(): bool => self::$status !== null || microtime(true) >= $end);
        return self::$status ?? new NetworkStatus;
    }
}
