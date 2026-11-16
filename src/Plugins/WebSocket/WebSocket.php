<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

use MyPHP\Plugin;

/**
 * The WebSocket plugin: opens WebSocket connections from PHP via system libcurl
 * with custom handshake headers (such as Authorization or Cookie).
 *
 *     App::use(WebSocket::plugin());
 *
 * Connections close when their page navigates away or its window closes.
 * libcurl, the system's, makes them, driven on the run loop.
 */
final class WebSocket
{
    /** The plugin with the default options (mygo's websocket.Plugin). */
    public static function plugin(): Plugin
    {
        return self::new(new Options);
    }

    /** The plugin with options (mygo's websocket.New). */
    public static function new(Options $opts): Plugin
    {
        return new Plugin('websocket', new Service($opts));
    }
}
