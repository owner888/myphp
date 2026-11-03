<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use MyPHP\Native\Android;
use MyPHP\NetworkStatus;
use MyPHP\Platform;

/**
 * Android network state via ConnectivityManager.
 */
final class Network implements Platform\Network
{
    public function watch(): void {}

    public function status(): NetworkStatus
    {
        $conn = Android::ffi()->myphp_is_network_connected();
        return new NetworkStatus(
            online: $conn > 0,
            metered: false,
            type: $conn > 0 ? 'wifi' : 'none'
        );
    }
}
