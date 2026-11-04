<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\iOS;
use MyPHP\NetworkStatus;
use MyPHP\Platform;

final class Network implements Platform\Network
{
    public function watch(): void
    {
    }

    public function status(): NetworkStatus
    {
        try {
            $f = iOS::ffi();
            $conn = $f->myphp_ios_is_network_connected() > 0;
            $cell = $f->myphp_ios_is_network_cellular() > 0;
            return new NetworkStatus(
                online: $conn,
                metered: $cell,
                type: !$conn ? 'none' : ($cell ? 'cellular' : 'wifi'),
            );
        } catch (\Throwable $e) {
            return new NetworkStatus(
                online: true,
                metered: false,
                type: 'wifi',
            );
        }
    }
}
