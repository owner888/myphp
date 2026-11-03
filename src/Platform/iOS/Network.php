<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\NetworkStatus;
use MyPHP\Platform;

final class Network implements Platform\Network
{
    public function watch(): void
    {
    }

    public function status(): NetworkStatus
    {
        return new NetworkStatus(
            online: true,
            metered: false,
            type: 'wifi',
        );
    }
}
