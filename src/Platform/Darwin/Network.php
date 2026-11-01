<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use MyPHP\NetworkStatus;
use MyPHP\Native\NetworkMonitor;
use MyPHP\Platform;

/** Network.framework's path monitor (mygo's darwin network.go). */
final class Network implements Platform\Network
{
    public function __construct(private readonly Backend $b) {}

    public function watch(): void
    {
        $h = $this->b->h;
        NetworkMonitor::$onPath = static fn(NetworkStatus $s) => $h->networkChanged($s);
    }

    public function status(): NetworkStatus
    {
        return NetworkMonitor::status();
    }
}
