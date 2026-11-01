<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;

/**
 * IINA's iina.ws (JavascriptAPIWebSocketController): a WebSocket server of
 * the plugin's (WebSocketServer) made on a port, started; its state, its
 * connections come and their states and messages told to the handlers
 * set (one of each, null none); text sent to a connection (as a binary
 * message, IINA's). The server stops with the instance.
 */
final class WsApi extends Api
{
    private ?WebSocketServer $server = null;
    private ?Closure $stateHandler = null;
    private ?Closure $messageHandler = null;
    private ?Closure $newConnHandler = null;
    private ?Closure $connStateHandler = null;

    public function __construct(Instance $instance)
    {
        parent::__construct($instance);
        $instance->onCleanUp(function (): void { $this->server?->stop(); $this->server = null; });
    }

    /** Makes the server, on $options['port'] (one made before stopped). @param array<string, mixed> $options */
    public function createServer(array $options): void
    {
        $this->server?->stop();
        $this->server = null;
        $port = $options['port'] ?? null;
        if (!is_int($port) || $port < 0 || $port > 65535) { $this->fail('ws.createServer: port not specified'); }
        $i = $this->instance;
        $this->server = new WebSocketServer($port, "{$i->plugin->identifier}.ws",
            fn(string $state, ?array $error) => $this->stateHandler === null ? null : $i->call($this->stateHandler, ...($error === null ? [$state] : [$state, $error])),
            fn(string $conn, array $info) => $this->newConnHandler === null ? null : $i->call($this->newConnHandler, $conn, $info),
            fn(string $conn, string $state, ?array $error) => $this->connStateHandler === null ? null : $i->call($this->connStateHandler, $conn, ...($error === null ? [$state] : [$state, $error])),
            fn(string $conn, string $data) => $this->messageHandler === null ? null : $i->call($this->messageHandler, $conn, new WsMessage($data)),
        );
    }

    /** Starts the server made. */
    public function startServer(): void
    {
        $s = $this->server ?? $this->fail('ws.startServer: server not created');
        if ($s->state !== 'setup') { $this->fail('ws.startServer: server is not in ready state'); }
        $s->start();
    }

    /** @param ?Closure(string, ?array<string, string>=): mixed $handler */
    public function onStateUpdate(?Closure $handler): void { $this->stateHandler = $handler; }

    /** @param ?Closure(string, WsMessage): mixed $handler */
    public function onMessage(?Closure $handler): void { $this->messageHandler = $handler; }

    /** @param ?Closure(string, array<string, ?string>): mixed $handler */
    public function onNewConnection(?Closure $handler): void { $this->newConnHandler = $handler; }

    /** @param ?Closure(string, string, ?array<string, string>=): mixed $handler */
    public function onConnectionStateUpdate(?Closure $handler): void { $this->connStateHandler = $handler; }

    /** Sends $text to connection $conn: "success", "no_connection" where there is none; throws where it could not. */
    public function sendText(string $conn, string $text): string
    {
        $s = $this->server ?? throw new PluginError('server does not exist');
        if (!$s->has($conn)) { return 'no_connection'; }
        $error = $s->send($conn, $text);
        if ($error !== null) { throw new PluginError($error); }
        return 'success';
    }
}
