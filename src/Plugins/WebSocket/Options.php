<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

use Closure;

/** Configure the plugin (mygo's websocket.Options). */
final class Options
{
    /** @param ?Closure(Request): bool $allow */
    public function __construct(
        /** Decides whether pages may open a connection with the handshake request, when set; a connection it refuses fails. */
        public ?Closure $allow = null,
        /** The most bytes a received message may have: 64 MiB when 0. A bigger message fails the connection. */
        public int $maxMessageSize = 0,
        /** How long a connection the page closed waits for the server to finish the closing handshake, in seconds: 5 when 0. */
        public float $closeTimeout = 0,
    ) {}
}
