<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

use RuntimeException;

/**
 * A PHP stream as a Transport, non-blocking: the tests' server's side.
 *
 * @internal
 */
final class StreamTransport implements Transport
{
    /** @param resource $stream */
    public function __construct(private $stream)
    {
        stream_set_blocking($stream, false);
    }

    public function read(): ?string
    {
        $data = @fread($this->stream, 65536);
        if ($data === false) {
            throw new RuntimeException('websocket: read failed');
        }
        if ($data === '' && feof($this->stream)) {
            return null;
        }
        return $data;
    }

    public function write(string $bytes): int
    {
        $n = @fwrite($this->stream, $bytes);
        if ($n === false) {
            throw new RuntimeException('websocket: write failed');
        }
        return $n;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
