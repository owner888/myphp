<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

/**
 * Fails the connection with a close code (RFC 6455, section 7.1.7;
 * mygo's failure).
 *
 * @internal
 */
final class Failure extends \RuntimeException
{
    public function __construct(public readonly int $closeCode, string $message)
    {
        parent::__construct($message);
    }

    public static function protocol(string $message): self
    {
        return new self(Conn::CLOSE_PROTOCOL_ERROR, $message);
    }
}
