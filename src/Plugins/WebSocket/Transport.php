<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

/**
 * The bytes under a connection, read and written without blocking: a
 * libcurl connection (CurlTransport), or a PHP stream (the tests' server).
 *
 * @internal
 */
interface Transport
{
    /** What arrived, '' when nothing did yet; null once the other side closed. */
    public function read(): ?string;

    /** Writes what it can of $bytes now, and returns how many it wrote. */
    public function write(string $bytes): int;

    public function close(): void;
}
