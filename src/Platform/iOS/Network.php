<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\iOS;
use MyPHP\NetworkStatus;
use MyPHP\Platform;

final class Network implements Platform\Network
{
    public bool $watching = false;

    public function watch(): void
    {
        $this->watching = true;
    }

    public function status(): NetworkStatus
    {
        $f = iOS::ffi();
        $conn = $f->myphp_ios_is_network_connected() > 0;
        $cell = $f->myphp_ios_is_network_cellular() > 0;
        $exp = $f->myphp_ios_is_network_expensive() > 0;
        return new NetworkStatus(
            online: $conn,
            metered: $cell || $exp,
            type: !$conn ? 'none' : ($cell ? 'cellular' : 'wifi'),
        );
    }
}
