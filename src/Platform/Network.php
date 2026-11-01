<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** The state of the network connection (mygo's platform.Network). */
interface Network
{
    /** Starts reporting AppHandler::networkChanged, which may come with the status unchanged. */
    public function watch(): void;

    public function status(): \MyPHP\NetworkStatus;
}
