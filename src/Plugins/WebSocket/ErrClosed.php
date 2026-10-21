<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

/**
 * The connection is closed, or a close frame was sent (mygo's errClosed).
 *
 * @internal
 */
final class ErrClosed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('websocket: connection closed');
    }
}
