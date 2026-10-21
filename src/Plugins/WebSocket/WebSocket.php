<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

use MyPHP\Plugin;

/**
 * The WebSocket plugin (mygo's plugins/websocket): pages open WebSocket
 * connections from PHP with the WebSocket class of its TypeScript client
 * (client/index.ts, @myphp-plugins/websocket), which has the API of the
 * browser's. Unlike the browser's, it may send any header with the opening
 * handshake, such as Authorization or Cookie, and is not subject to the
 * page's Content Security Policy.
 *
 *     App::use(WebSocket::plugin());
 *
 *     // the page
 *     import { WebSocket } from "@myphp-plugins/websocket";
 *     const ws = new WebSocket("wss://example.com/live", [], { headers: { authorization: `Bearer ${token}` } });
 *     ws.onmessage = (e) => console.log(e.data);
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
