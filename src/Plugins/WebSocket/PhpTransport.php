<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

use Fiber;
use MyPHP\Native\NetProxy;
use MyPHP\Net\Async\Loop;
use MyPHP\Net\Async\Socket;
use MyPHP\Net\Http\Proxy;
use RuntimeException;
use Throwable;

/**
 * A connection MyPHP's own network stack makes (Net\Async\Socket, through the proxy the system names, TLS by PHP's
 * openssl), in place of CurlTransport when Http::transport() === 'php': connected in a fiber of its own as the
 * service's generator steps, and its underlying non-blocking stream handed to StreamTransport once connected.
 *
 * @internal
 */
final class PhpTransport implements Transport
{
    private ?StreamTransport $inner = null;
    private ?Throwable $error = null;
    private bool $closed = false;

    /** Starts connecting to $url, http or https. */
    public function __construct(string $url)
    {
        $u = parse_url($url);
        $tls = strtolower((string)($u['scheme'] ?? '')) === 'https';
        !$tls || extension_loaded('openssl') || throw new RuntimeException('websocket: https over the php transport needs PHP\'s openssl extension');
        $host = trim((string)($u['host'] ?? ''), '[]');
        $port = isset($u['port']) ? (int)$u['port'] : ($tls ? 443 : 80);
        $proxy = match ($p = NetProxy::decide($url)) {
            null => null,
            false => Proxy::fromEnvironment(getenv()),
            default => Proxy::parse($p),
        };
        $direct = $proxy === null || $proxy->bypasses($host);
        new Fiber(function () use ($direct, $proxy, $host, $port, $tls): void {
            try {
                $socket = $direct ? Socket::connect($host, $port, $tls) : $proxy->open($host, $port, $tls);
                $stream = (new \ReflectionProperty(Socket::class, 'stream'))->getValue($socket);
                if ($this->closed) {
                    $socket->close();
                    return;
                }
                $this->inner = new StreamTransport($stream);
            } catch (Throwable $e) {
                $this->error = $e;
            }
        })->start();
    }

    /** Null while connecting, 0 once connected, 1 when it failed. */
    public function connected(): ?int
    {
        if ($this->inner === null && $this->error === null) {
            Loop::get()->turn();
        }
        return $this->inner !== null ? 0 : ($this->error !== null ? 1 : null);
    }

    public function error(int $code): string
    {
        return $this->error?->getMessage() ?? '';
    }

    public function read(): ?string
    {
        return $this->inner?->read();
    }

    public function write(string $bytes): int
    {
        return $this->inner?->write($bytes) ?? 0;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->inner?->close();
    }
}
