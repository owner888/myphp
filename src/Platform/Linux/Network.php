<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\NetworkStatus;
use MyPHP\Platform;
use MyPHP\Platform\AppHandler;

/**
 * The network as GLib's GNetworkMonitor sees it (mygo's linux network.go):
 * a singleton GLib keeps current, its network-changed signal the event.
 */
final class Network implements Platform\Network
{
    public function __construct(private readonly AppHandler $h) {}

    /** Online is a default route, of IPv4 or IPv6, as macOS's satisfied path. */
    public function status(): NetworkStatus
    {
        $f = Gtk::ffi();
        $m = $f->g_network_monitor_get_default();
        return $m === null ? new NetworkStatus : new NetworkStatus((bool)$f->g_network_monitor_get_network_available($m), (bool)$f->g_network_monitor_get_network_metered($m));
    }

    public function watch(): void
    {
        $m = Gtk::ffi()->g_network_monitor_get_default();
        if ($m !== null) {
            Gtk::connect($m, 'network-changed', 'event', function ($monitor, $available, $data): int {
                $this->h->networkChanged($this->status());
                return 0;
            });
        }
    }
}
